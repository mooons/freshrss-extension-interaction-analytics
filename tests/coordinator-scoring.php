<?php
declare(strict_types=1);

$freshRssPath = getenv('FRESHRSS_PATH');
if (!is_string($freshRssPath) || $freshRssPath === '') {
	fwrite(STDERR, "FRESHRSS_PATH is required.\n");
	exit(1);
}
require $freshRssPath . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require_once __DIR__ . '/../Models/Interest/InterestFeatureExtractor.php';
require_once __DIR__ . '/../Models/Interest/InterestTrainingDataSource.php';
require_once __DIR__ . '/../Models/Interest/InterestDatasetBuilder.php';
require_once __DIR__ . '/../Models/Interest/InterestTrainer.php';
require_once __DIR__ . '/../Models/Interest/InterestScoringEngine.php';
require_once __DIR__ . '/../Models/Interest/InterestSchemaMigrator.php';
require_once __DIR__ . '/../Models/Interest/InterestModelDAO.php';
require_once __DIR__ . '/../Models/Interest/InterestModelCoordinator.php';
require_once __DIR__ . '/../Models/Interest/InterestScorer.php';

function coordinatorAssert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

final class CoordinatorTrainingSource implements InterestTrainingDataSource {
	/** @param list<array<string,mixed>> $rows @param array<string,array<string,mixed>> $metadata */
	public function __construct(private array $rows, private array $metadata) {
	}

	#[\Override]
	public function recentTelemetry(array $feedIds, int $limit): array {
		return array_slice($this->rows, 0, $limit);
	}

	#[\Override]
	public function metadataForEntryIds(array $entryIds): array {
		return array_intersect_key($this->metadata, array_fill_keys($entryIds, true));
	}
}

$now = 2_000_000_000_000;
$old = $now - 7_200_000;
$rows = [];
$metadata = [];
for ($i = 0; $i < 300; $i++) {
	$id = (string)($i + 1);
	$positive = $i % 3 === 0;
	$feedId = $i % 2 === 0 ? 7 : 9;
	$rows[] = [
		'entry_id' => $id,
		'feed_id' => $feedId,
		'entry_title' => null,
		'explicit_feedback' => $positive ? 1 : -1,
		'favorite_state' => null,
		'link_opened' => null,
		'measurement' => 'full',
		'time_spent_ms' => null,
		'updated_at' => $old + $i,
	];
	$metadata[$id] = [
		'title' => $positive ? 'Loved recurring topic' : 'Boring recurring bundle',
		'favorite' => false,
		'feed_id' => $feedId,
		'feed_name' => 'Example feed',
	];
}
$source = new CoordinatorTrainingSource($rows, $metadata);
$extractor = new InterestFeatureExtractor([7 => 'Keylol', 9 => 'Keylol']);
$pdo = new Minz_PdoSqlite('sqlite::memory:');
$migrator = new InterestSchemaMigrator('test', $pdo);
coordinatorAssert($migrator->ensureCurrent($now)['ok'], 'Schema setup failed');
$modelDao = new InterestModelDAO('test', $pdo);
coordinatorAssert($modelDao->markDirty(300, $now), 'Could not prepare dirty model state');

$settings = static fn (): array => [
	'enabled' => true,
	'tracking_enabled' => true,
	'auto_train' => true,
	'tracked_feed_ids' => [7, 9],
];
$coordinator = new InterestModelCoordinator(
	$modelDao,
	new InterestDatasetBuilder($source, $extractor),
	new InterestTrainer(),
	$settings,
);
$first = $coordinator->trainIfDue($now + 1);
$second = $coordinator->trainIfDue($now + 2);
coordinatorAssert($first['status'] === 'trained', 'A due maintenance run must train server-side');
coordinatorAssert($second['status'] === 'not_due', 'Repeated maintenance calls must be idempotent');

$scorer = new InterestScorer($modelDao, $source, new InterestScoringEngine($extractor), $settings);
$scores = $scorer->scoreEntryIds(['1', '2']);
coordinatorAssert($scores['status'] === 'ok' && count($scores['entries']) === 2, 'Scorer must return one authenticated batch result');
coordinatorAssert($scores['entries']['1']['score'] > $scores['entries']['2']['score'], 'Scoring must use the published server model');
$cached = $scorer->scoreEntryIds(['1', '2']);
coordinatorAssert($cached['entries']['1']['cached'] === true && $cached['entries']['2']['cached'] === true, 'Second score batch must use the model-version cache');
coordinatorAssert($cached['entries']['1']['feed_id'] === 7 && $cached['entries']['2']['feed_id'] === 9, 'Shared learning must preserve the original feed IDs in cached scores');
coordinatorAssert($modelDao->resetDerived(300, true, $now + 3), 'Changing source groups must reset derived data');
coordinatorAssert($scorer->scoreEntryIds(['1', '2'])['status'] === 'no_model', 'Old scores must be unavailable until retraining');
coordinatorAssert($modelDao->state()['force_retrain'] === 1, 'Changing source groups must queue retraining');
coordinatorAssert($coordinator->trainIfDue($now + 4)['status'] === 'trained', 'Existing records must train again after a group change');
$modelVersion = (int)$modelDao->state()['model_version'];
coordinatorAssert($modelDao->storeScores([
	'999' => ['feed_id' => 8, 'score' => 0.01, 'band' => 'low', 'positive_reasons' => [], 'negative_reasons' => ['private']],
], $modelVersion, $now + 5), 'Could not prepare an untracked cached score');
coordinatorAssert($scorer->scoreEntryIds(['999'])['entries'] === [], 'Cached scores from untracked feeds must not cross the tracked-feed scope');

$insufficientPdo = new Minz_PdoSqlite('sqlite::memory:');
$insufficientMigrator = new InterestSchemaMigrator('test-insufficient', $insufficientPdo);
coordinatorAssert($insufficientMigrator->ensureCurrent($now)['ok'], 'Insufficient-data schema setup failed');
$insufficientDao = new InterestModelDAO('test-insufficient', $insufficientPdo);
coordinatorAssert($insufficientDao->markDirty(69, $now), 'Could not prepare insufficient model state');
$insufficientSource = new CoordinatorTrainingSource(array_slice($rows, 0, 69), array_slice($metadata, 0, 69, true));
$insufficientCoordinator = new InterestModelCoordinator(
	$insufficientDao,
	new InterestDatasetBuilder($insufficientSource, $extractor),
	new InterestTrainer(),
	$settings,
);
$insufficientFirst = $insufficientCoordinator->trainIfDue($now + 10);
$insufficientSecond = $insufficientCoordinator->trainIfDue($now + 21_600_011);
coordinatorAssert($insufficientFirst['status'] === 'insufficient_data', 'An inadequate dataset must report insufficient data');
coordinatorAssert($insufficientSecond['status'] === 'not_due', 'Unchanged insufficient data must not retrain repeatedly');

$agedPdo = new Minz_PdoSqlite('sqlite::memory:');
$agedMigrator = new InterestSchemaMigrator('test-aged', $agedPdo);
coordinatorAssert($agedMigrator->ensureCurrent($now)['ok'], 'Aged-dirty schema setup failed');
$agedDao = new InterestModelDAO('test-aged', $agedPdo);
coordinatorAssert($agedDao->markDirty(1, $now - 86_400_001), 'Could not prepare aged dirty model state');
$agedCoordinator = new InterestModelCoordinator(
	$agedDao,
	new InterestDatasetBuilder($source, $extractor),
	new InterestTrainer(),
	$settings,
);
coordinatorAssert($agedCoordinator->trainIfDue($now)['status'] === 'trained', 'A dirty first model must train after 24 hours even with fewer than 25 pending changes');

echo "Coordinator and scoring tests passed.\n";
