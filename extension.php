<?php
declare(strict_types=1);

final class InteractionAnalyticsExtension extends Minz_Extension {
	private const CONFIG_TRACKING = 'tracking_enabled';
	private const CONFIG_FEEDS = 'tracked_feed_ids';
	private const CONFIG_PRESERVE = 'preserve_historical_metadata';
	private const CONFIG_DISPLAY = 'display_analytics';
	private const CONFIG_GREADER_INGESTION = 'greader_ingestion_enabled';
	private const CONFIG_SCHEMA_VERSION = 'schema_version';
	private const CONFIG_INTEREST_ENABLED = 'interest_model_enabled';
	private const CONFIG_INTEREST_AUTO_TRAIN = 'interest_model_auto_train';
	private const CONFIG_INTEREST_DISPLAY_MODE = 'interest_display_mode';
	private const CONFIG_INTEREST_FEEDBACK = 'interest_explicit_feedback';

	/** @var list<FreshRSS_Feed> */
	public array $feeds = [];
	/** @var list<array<string,int|string|null>> */
	public array $feed_summaries = [];
	public bool $tracking_enabled = false;
	public bool $preserve_historical_metadata = false;
	public bool $display_analytics = true;
	public bool $greader_ingestion_enabled = false;
	public bool $entries_read_available = false;
	public bool $interest_model_enabled = false;
	public bool $interest_model_auto_train = true;
	public string $interest_display_mode = 'score';
	public bool $interest_explicit_feedback = true;
	public bool $interest_schema_ready = false;
	public ?string $interest_schema_error = null;
	/** @var array<string,mixed> */
	public array $interest_model_status = [];
	/** @var list<int> */
	public array $tracked_feed_ids = [];

	private ?InteractionAnalyticsDAO $dao = null;
	private ?InterestSchemaMigrator $schemaMigrator = null;
	private ?InterestModelDAO $modelDao = null;
	private ?FreshRSSInterestTrainingDataSource $trainingSource = null;
	private ?InterestFeatureExtractor $featureExtractor = null;
	private ?InterestModelCoordinator $coordinator = null;
	private ?InterestScorer $scorer = null;

	#[\Override]
	public function init(): void {
		parent::init();
		$this->loadInterestClasses();
		$this->prepareInterestSchema();

		$this->registerController('interactionAnalytics');
		$this->registerViews();
		$this->registerTranslates();
		$this->registerHook(Minz_HookType::JsVars, [$this, 'addJsVars']);
		$entriesReadHook = $this->entriesReadHook();
		if ($entriesReadHook !== null && $this->greaderIngestionEnabled()) {
			$this->registerHook($entriesReadHook, [$this, 'recordApiRead']);
		}
		if ($this->interestSchemaReady() && $this->interestModelEnabled()) {
			$this->registerHook(Minz_HookType::EntriesFavorite, [$this, 'recordFavorite']);
			$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'runInterestMaintenance']);
		}

		$interestUi = $this->interestSchemaReady() && $this->interestModelEnabled() && $this->trackingEnabled()
			&& ($this->interestDisplayMode() === 'score' || $this->interestExplicitFeedback());
		if (!$this->isApiRequest() && ($this->displayAnalytics() || $this->trackingEnabled() || $interestUi)) {
			Minz_View::appendStyle($this->getFileUrl('interactionAnalytics.css'));
		}
		if (!$this->isApiRequest() && ($this->displayAnalytics() || $this->trackingEnabled())) {
			Minz_View::appendScript($this->getFileUrl('interactionAnalytics.js'));
		}
		if (!$this->isApiRequest() && $interestUi) {
			Minz_View::appendScript($this->getFileUrl('interestModel.js'));
		}
	}

	#[\Override]
	public function install(): string|true {
		try {
			$this->loadInterestClasses();
			$result = $this->schemaMigrator()->ensureCurrent($this->nowMs());
			if (!$result['ok']) {
				return $result['error'] ?? 'Could not install the Interaction Analytics schema.';
			}
			$this->setUserConfigurationValue(self::CONFIG_SCHEMA_VERSION, InterestSchemaMigrator::SCHEMA_VERSION);
			$this->interest_schema_ready = true;
			return true;
		} catch (Throwable $e) {
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
			return 'Could not install the Interaction Analytics schema.';
		}
	}

	#[\Override]
	public function uninstall(): string|true {
		// Telemetry and derived personal data are retained until explicitly deleted.
		return true;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->loadInterestClasses();
		$this->prepareInterestSchema();
		$this->registerTranslates();
		Minz_View::appendStyle($this->getFileUrl('interactionAnalytics.css'));
		Minz_View::appendScript($this->getFileUrl('configure.js'));

		if (FreshRSS_Auth::requestReauth()) {
			return;
		}

		if (Minz_Request::isPost()) {
			$previousFeedIds = $this->trackedFeedIds();
			$feedIds = array_values(array_unique(array_filter(
				Minz_Request::paramArrayInt(self::CONFIG_FEEDS),
				static fn (int $id): bool => $id > 0
			)));
			$currentFeedIds = array_keys(FreshRSS_Factory::createFeedDao()->listFeeds());
			$feedIds = array_values(array_intersect($feedIds, $currentFeedIds));
			$displayMode = Minz_Request::paramString(self::CONFIG_INTEREST_DISPLAY_MODE, plaintext: true);
			if (!in_array($displayMode, ['off', 'score'], true)) {
				$displayMode = 'score';
			}

			$this->setUserConfigurationValue(self::CONFIG_TRACKING, Minz_Request::paramBoolean(self::CONFIG_TRACKING));
			$this->setUserConfigurationValue(self::CONFIG_FEEDS, $feedIds);
			$this->setUserConfigurationValue(self::CONFIG_PRESERVE, Minz_Request::paramBoolean(self::CONFIG_PRESERVE));
			$this->setUserConfigurationValue(self::CONFIG_DISPLAY, Minz_Request::paramBoolean(self::CONFIG_DISPLAY));
			$this->setUserConfigurationValue(
				self::CONFIG_GREADER_INGESTION,
				$this->entriesReadAvailable() && Minz_Request::paramBoolean(self::CONFIG_GREADER_INGESTION)
			);
			$this->setUserConfigurationValue(
				self::CONFIG_INTEREST_ENABLED,
				$this->interestSchemaReady() && Minz_Request::paramBoolean(self::CONFIG_INTEREST_ENABLED)
			);
			$this->setUserConfigurationValue(self::CONFIG_INTEREST_AUTO_TRAIN, Minz_Request::paramBoolean(self::CONFIG_INTEREST_AUTO_TRAIN));
			$this->setUserConfigurationValue(self::CONFIG_INTEREST_DISPLAY_MODE, $displayMode);
			$this->setUserConfigurationValue(self::CONFIG_INTEREST_FEEDBACK, Minz_Request::paramBoolean(self::CONFIG_INTEREST_FEEDBACK));
			$previousFeedIdsSorted = $previousFeedIds;
			sort($previousFeedIdsSorted, SORT_NUMERIC);
			$feedIdsSorted = $feedIds;
			sort($feedIdsSorted, SORT_NUMERIC);
			if ($this->interestSchemaReady() && $previousFeedIdsSorted !== $feedIdsSorted) {
				$eligibleRows = $this->dao()->countTelemetryForFeeds($feedIds);
				if (!$this->modelDao()->resetDerived($eligibleRows, $eligibleRows > 0, $this->nowMs())) {
					Minz_Log::error(__METHOD__ . ': could not invalidate the interest model after tracked feeds changed');
				}
			}
			FreshRSS_UserDAO::touch();

			Minz_Request::good(_t('feedback.conf.updated'), [
				'c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()],
			]);
		}

		$this->tracking_enabled = $this->trackingEnabled();
		$this->preserve_historical_metadata = $this->preserveHistoricalMetadata();
		$this->display_analytics = $this->displayAnalytics();
		$this->entries_read_available = $this->entriesReadAvailable();
		$this->greader_ingestion_enabled = $this->greaderIngestionEnabled();
		$this->interest_model_enabled = $this->interestModelEnabled();
		$this->interest_model_auto_train = $this->interestModelAutoTrain();
		$this->interest_display_mode = $this->interestDisplayMode();
		$this->interest_explicit_feedback = $this->interestExplicitFeedback();
		$this->interest_schema_ready = $this->interestSchemaReady();
		$this->tracked_feed_ids = $this->trackedFeedIds();
		$this->feeds = array_values(FreshRSS_Factory::createFeedDao()->listFeeds());
		$this->feed_summaries = $this->dao()->feedSummaries();
		$this->interest_model_status = $this->modelStatus();
	}

	/** @param array<string,mixed> $vars @return array<string,mixed> */
	public function addJsVars(array $vars = []): array {
		$vars['interaction_analytics'] = [
			'tracking_enabled' => $this->trackingEnabled(),
			'display_analytics' => $this->displayAnalytics(),
			'greader_ingestion_enabled' => $this->greaderIngestionEnabled(),
			'tracked_feed_ids' => $this->trackedFeedIds(),
			'csrf' => FreshRSS_Auth::csrfToken(),
			'urls' => [
				'record' => Minz_Url::display(['c' => 'interactionAnalytics', 'a' => 'record'], 'raw'),
				'summary' => Minz_Url::display(['c' => 'interactionAnalytics', 'a' => 'summary'], 'raw'),
				'scores' => Minz_Url::display(['c' => 'interactionAnalytics', 'a' => 'scores'], 'raw'),
				'feedback' => Minz_Url::display(['c' => 'interactionAnalytics', 'a' => 'feedback'], 'raw'),
			],
			'interest' => [
				'enabled' => $this->interestSchemaReady() && $this->interestModelEnabled() && $this->trackingEnabled(),
				'display_mode' => $this->interestDisplayMode(),
				'explicit_feedback' => $this->interestExplicitFeedback(),
			],
			'i18n' => [
				'time' => _t('ext.interaction_analytics.badge_time'),
				'opened' => _t('ext.interaction_analytics.badge_link_opened'),
				'not_opened' => _t('ext.interaction_analytics.badge_link_not_opened'),
				'unknown' => _t('ext.interaction_analytics.badge_link_unknown'),
				'web_source' => _t('ext.interaction_analytics.badge_source_web'),
				'greader_source' => _t('ext.interaction_analytics.badge_source_greader'),
				'interest_score' => _t('ext.interaction_analytics.interest_score'),
				'interest_reasons' => _t('ext.interaction_analytics.interest_reasons'),
				'more_like_this' => _t('ext.interaction_analytics.more_like_this'),
				'less_like_this' => _t('ext.interaction_analytics.less_like_this'),
				'clear_feedback' => _t('ext.interaction_analytics.clear_feedback'),
				'feedback_failed' => _t('ext.interaction_analytics.feedback_failed'),
			],
		];
		return $vars;
	}

	/** @param array<int|string> $ids */
	public function recordApiRead(array $ids, bool $isRead): void {
		if (!$isRead || !$this->trackingEnabled() || !$this->greaderIngestionEnabled() || !$this->isGReaderRequest()) {
			return;
		}
		$ids = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => ctype_digit($id)));
		if ($ids !== []) {
			$this->dao()->recordReadOnly($ids, $this->trackedFeedIds(), $this->preserveHistoricalMetadata(), $this->nowMs());
		}
	}

	/** @param array<int|string> $ids */
	public function recordFavorite(array $ids, bool $isFavorite): void {
		if (!$this->interestSchemaReady() || !$this->interestModelEnabled() || !$this->trackingEnabled()) {
			return;
		}
		try {
			$this->dao()->recordFavorite(
				$ids,
				$isFavorite,
				$this->trackedFeedIds(),
				$this->preserveHistoricalMetadata(),
				$this->nowMs()
			);
		} catch (Throwable $e) {
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
		}
	}

	public function runInterestMaintenance(): void {
		try {
			$this->coordinator()->trainIfDue($this->nowMs());
		} catch (Throwable $e) {
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
		}
	}

	public function trackingEnabled(): bool {
		return $this->getUserConfigurationBool(self::CONFIG_TRACKING) ?? false;
	}

	public function preserveHistoricalMetadata(): bool {
		return $this->getUserConfigurationBool(self::CONFIG_PRESERVE) ?? false;
	}

	public function displayAnalytics(): bool {
		return $this->getUserConfigurationBool(self::CONFIG_DISPLAY) ?? true;
	}

	public function greaderIngestionEnabled(): bool {
		return $this->entriesReadAvailable()
			&& ($this->getUserConfigurationBool(self::CONFIG_GREADER_INGESTION) ?? false);
	}

	public function interestModelEnabled(): bool {
		return $this->getUserConfigurationBool(self::CONFIG_INTEREST_ENABLED) ?? false;
	}

	public function interestModelAutoTrain(): bool {
		return $this->getUserConfigurationBool(self::CONFIG_INTEREST_AUTO_TRAIN) ?? true;
	}

	public function interestDisplayMode(): string {
		$mode = $this->getUserConfigurationString(self::CONFIG_INTEREST_DISPLAY_MODE) ?? 'score';
		return in_array($mode, ['off', 'score'], true) ? $mode : 'score';
	}

	public function interestExplicitFeedback(): bool {
		return $this->getUserConfigurationBool(self::CONFIG_INTEREST_FEEDBACK) ?? true;
	}

	public function interestSchemaReady(): bool {
		return $this->interest_schema_ready;
	}

	public function entriesReadAvailable(): bool {
		return $this->entriesReadHook() !== null;
	}

	/** @return list<int> */
	public function trackedFeedIds(): array {
		$ids = $this->getUserConfigurationArray(self::CONFIG_FEEDS) ?? [];
		$ids = array_map(static fn ($id): int => (int)$id, $ids);
		return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
	}

	public function dao(): InteractionAnalyticsDAO {
		$this->loadInterestClasses();
		return $this->dao ??= new InteractionAnalyticsDAO($this->interestSchemaReady() ? $this->modelDao() : null);
	}

	public function modelDao(): InterestModelDAO {
		$this->loadInterestClasses();
		return $this->modelDao ??= new InterestModelDAO();
	}

	public function scorer(): InterestScorer {
		$this->loadInterestClasses();
		return $this->scorer ??= new InterestScorer(
			$this->modelDao(),
			$this->trainingSource(),
			new InterestScoringEngine($this->featureExtractor()),
			$this->interestSettings(...),
		);
	}

	/** @return array<string,mixed> */
	public function modelStatus(): array {
		if (!$this->interestSchemaReady()) {
			return ['status' => 'schema_unavailable', 'error' => $this->interest_schema_error];
		}
		$state = $this->modelDao()->state();
		if ($state === []) {
			return ['status' => 'schema_unavailable'];
		}
		$now = $this->nowMs();
		if (is_string($state['lease_token'] ?? null) && (int)($state['lease_expires_at'] ?? 0) > $now) {
			$status = 'running';
		} elseif ((int)($state['force_retrain'] ?? 0) === 1) {
			$status = 'requested';
		} elseif (($state['last_error'] ?? null) === 'insufficient_data') {
			$status = 'insufficient_data';
		} elseif (is_string($state['last_error'] ?? null) && $state['last_error'] !== '') {
			$status = 'failed';
		} elseif (is_string($state['weights_json'] ?? null) && $state['weights_json'] !== '') {
			if ($this->modelDao()->currentModel($state) === null) {
				$status = 'failed';
				$state['last_error'] = 'invalid_model_artifact';
			} else {
				$status = 'trained';
			}
		} else {
			$status = 'untrained';
		}
		$metrics = json_decode((string)($state['metrics_json'] ?? ''), true);
		$state['status'] = $status;
		$state['metrics'] = is_array($metrics) ? $metrics : [];
		unset($state['weights_json'], $state['metrics_json'], $state['lease_token']);
		return $state;
	}

	private function prepareInterestSchema(): void {
		if (($this->getUserConfigurationInt(self::CONFIG_SCHEMA_VERSION) ?? 0) >= InterestSchemaMigrator::SCHEMA_VERSION) {
			$this->interest_schema_ready = true;
			return;
		}
		try {
			$result = $this->schemaMigrator()->ensureCurrent($this->nowMs());
			$this->interest_schema_ready = $result['ok'];
			$this->interest_schema_error = $result['error'];
			if ($result['ok']) {
				$this->setUserConfigurationValue(self::CONFIG_SCHEMA_VERSION, InterestSchemaMigrator::SCHEMA_VERSION);
			}
		} catch (Throwable $e) {
			$this->interest_schema_ready = false;
			$this->interest_schema_error = 'Could not upgrade the Interaction Analytics interest-model schema.';
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
		}
	}

	private function schemaMigrator(): InterestSchemaMigrator {
		return $this->schemaMigrator ??= new InterestSchemaMigrator();
	}

	private function coordinator(): InterestModelCoordinator {
		return $this->coordinator ??= new InterestModelCoordinator(
			$this->modelDao(),
			new InterestDatasetBuilder($this->trainingSource(), $this->featureExtractor()),
			new InterestTrainer(),
			$this->interestSettings(...),
		);
	}

	private function trainingSource(): FreshRSSInterestTrainingDataSource {
		return $this->trainingSource ??= new FreshRSSInterestTrainingDataSource($this->dao());
	}

	private function featureExtractor(): InterestFeatureExtractor {
		return $this->featureExtractor ??= new InterestFeatureExtractor();
	}

	/** @return array{enabled:bool,tracking_enabled:bool,auto_train:bool,tracked_feed_ids:list<int>} */
	private function interestSettings(): array {
		return [
			'enabled' => $this->interestSchemaReady() && $this->interestModelEnabled(),
			'tracking_enabled' => $this->trackingEnabled(),
			'auto_train' => $this->interestModelAutoTrain(),
			'tracked_feed_ids' => $this->trackedFeedIds(),
		];
	}

	private function loadInterestClasses(): void {
		foreach ([
			'/Models/Interest/InterestFeatureExtractor.php',
			'/Models/Interest/InterestTrainingDataSource.php',
			'/Models/Interest/InterestSchemaMigrator.php',
			'/Models/Interest/InterestModelDAO.php',
			'/Models/InteractionAnalyticsDAO.php',
			'/Models/Interest/FreshRSSInterestTrainingDataSource.php',
			'/Models/Interest/InterestDatasetBuilder.php',
			'/Models/Interest/InterestTrainer.php',
			'/Models/Interest/InterestScoringEngine.php',
			'/Models/Interest/InterestModelCoordinator.php',
			'/Models/Interest/InterestScorer.php',
			] as $file) {
			require_once $this->getPath() . $file;
		}
	}

	/** @phpstan-ignore return.unusedType */
	private function entriesReadHook(): ?Minz_HookType {
		return Minz_HookType::tryFrom('entries_read');
	}

	private function isApiRequest(): bool {
		$script = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '';
		$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
		return str_contains($script, '/api/') || str_contains($uri, '/api/');
	}

	private function isGReaderRequest(): bool {
		$script = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '';
		$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
		return str_contains($script, 'greader.php') || str_contains($uri, 'greader.php');
	}

	private function nowMs(): int {
		return (int)round(microtime(true) * 1000);
	}
}
