<?php
declare(strict_types=1);

final class InteractionAnalyticsExtension extends Minz_Extension {
	private const CONFIG_TRACKING = 'tracking_enabled';
	private const CONFIG_FEEDS = 'tracked_feed_ids';
	private const CONFIG_PRESERVE = 'preserve_historical_metadata';
	private const CONFIG_DISPLAY = 'display_analytics';
	private const CONFIG_GREADER_INGESTION = 'greader_ingestion_enabled';

	/** @var list<FreshRSS_Feed> */
	public array $feeds = [];
	/** @var list<array<string,int|string|null>> */
	public array $feed_summaries = [];
	public bool $tracking_enabled = false;
	public bool $preserve_historical_metadata = false;
	public bool $display_analytics = true;
	public bool $greader_ingestion_enabled = false;
	public bool $entries_read_available = false;
	/** @var list<int> */
	public array $tracked_feed_ids = [];

	private ?InteractionAnalyticsDAO $dao = null;

	#[\Override]
	public function init(): void {
		parent::init();

		require_once $this->getPath() . '/Models/InteractionAnalyticsDAO.php';
		$this->registerController('interactionAnalytics');
		$this->registerViews();
		$this->registerTranslates();
		$this->registerHook(Minz_HookType::JsVars, [$this, 'addJsVars']);
		$entriesReadHook = $this->entriesReadHook();
		if ($entriesReadHook !== null && $this->greaderIngestionEnabled()) {
			$this->registerHook($entriesReadHook, [$this, 'recordApiRead']);
		}

		if (!$this->isApiRequest() && ($this->displayAnalytics() || $this->trackingEnabled())) {
			Minz_View::appendStyle($this->getFileUrl('interactionAnalytics.css'));
			Minz_View::appendScript($this->getFileUrl('interactionAnalytics.js'));
		}
	}

	#[\Override]
	public function install(): string|true {
		return $this->dao()->install();
	}

	#[\Override]
	public function uninstall(): string|true {
		// Telemetry is intentionally retained. Users can delete it from the
		// extension configuration page.
		return true;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();

		if (FreshRSS_Auth::requestReauth()) {
			return;
		}

		if (Minz_Request::isPost()) {
			$feedIds = array_values(array_unique(array_filter(
				Minz_Request::paramArrayInt(self::CONFIG_FEEDS),
				static fn (int $id): bool => $id > 0
			)));
			$currentFeedIds = array_keys(FreshRSS_Factory::createFeedDao()->listFeeds());
			$feedIds = array_values(array_intersect($feedIds, $currentFeedIds));

			$this->setUserConfigurationValue(self::CONFIG_TRACKING, Minz_Request::paramBoolean(self::CONFIG_TRACKING));
			$this->setUserConfigurationValue(self::CONFIG_FEEDS, $feedIds);
			$this->setUserConfigurationValue(self::CONFIG_PRESERVE, Minz_Request::paramBoolean(self::CONFIG_PRESERVE));
			$this->setUserConfigurationValue(self::CONFIG_DISPLAY, Minz_Request::paramBoolean(self::CONFIG_DISPLAY));
			$this->setUserConfigurationValue(
				self::CONFIG_GREADER_INGESTION,
				$this->entriesReadAvailable() && Minz_Request::paramBoolean(self::CONFIG_GREADER_INGESTION)
			);
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
		$this->tracked_feed_ids = $this->trackedFeedIds();
		$this->feeds = array_values(FreshRSS_Factory::createFeedDao()->listFeeds());
		$this->feed_summaries = $this->dao()->feedSummaries();
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
			],
			'i18n' => [
				'time' => _t('ext.interaction_analytics.badge_time'),
				'opened' => _t('ext.interaction_analytics.badge_link_opened'),
				'not_opened' => _t('ext.interaction_analytics.badge_link_not_opened'),
				'unknown' => _t('ext.interaction_analytics.badge_link_unknown'),
				'web_source' => _t('ext.interaction_analytics.badge_source_web'),
				'greader_source' => _t('ext.interaction_analytics.badge_source_greader'),
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
		if ($ids === []) {
			return;
		}
		$this->dao()->recordReadOnly(
			$ids,
			$this->trackedFeedIds(),
			$this->preserveHistoricalMetadata(),
			(int)round(microtime(true) * 1000)
		);
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
		if (!class_exists(InteractionAnalyticsDAO::class, false)) {
			require_once $this->getPath() . '/Models/InteractionAnalyticsDAO.php';
		}
		return $this->dao ??= new InteractionAnalyticsDAO();
	}

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
}
