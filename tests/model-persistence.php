<?php
declare(strict_types=1);

$freshRssPath = getenv('FRESHRSS_PATH');
if (!is_string($freshRssPath) || $freshRssPath === '') {
	fwrite(STDERR, "FRESHRSS_PATH is required.\n");
	exit(1);
}
require $freshRssPath . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require_once __DIR__ . '/../Models/Interest/InterestSchemaMigrator.php';
require_once __DIR__ . '/../Models/Interest/InterestModelDAO.php';

function persistenceAssert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$now = 2_000_000_000_000;
$pdo = new Minz_PdoSqlite('sqlite::memory:');
$migrator = new InterestSchemaMigrator('test', $pdo);
persistenceAssert($migrator->ensureCurrent($now)['ok'], 'Schema setup failed');
$dao = new InterestModelDAO('test', $pdo);

persistenceAssert($dao->markDirty(3, $now), 'Could not mark model dirty');
$lease = $dao->claimLease($now + 1);
persistenceAssert(is_array($lease), 'First worker must claim the lease');
persistenceAssert($dao->claimLease($now + 2) === null, 'Second worker must not claim an active lease');
persistenceAssert($dao->markDirty(2, $now + 3), 'Could not record telemetry during training');

$artifact = [
	'artifact_version' => 1,
	'algorithm' => 'weighted_bernoulli_log_odds_v1',
	'normalization' => 'title_nfkc_v1',
	'feature_version' => 'title_terms_cjk_feed_v1',
	'explanation_labels' => ['w' => 'word'],
	'bias' => 0.0,
	'weights' => ['w:liked' => 1.0],
	'decision_threshold' => 0.2,
	'quality_gate_passed' => true,
];
$artifactJson = json_encode($artifact, JSON_THROW_ON_ERROR);
$training = [
	'status' => 'trained',
	'artifact' => $artifact,
	'artifact_json' => $artifactJson,
	'metrics' => [
		'positive_samples' => 25,
		'negative_samples' => 60,
		'validation_samples' => 20,
		'vocabulary_size' => 1,
		'quality_gate_passed' => true,
	],
];
persistenceAssert($dao->publish($lease, $training, $now + 4), 'Lease owner must publish the model');
$state = $dao->state();
persistenceAssert((int)$state['model_version'] === 1, 'Publishing must advance model version');
persistenceAssert($state['normalization_version'] === 'title_nfkc_v1', 'Publication must persist the normalization version');
persistenceAssert((int)$state['trained_generation'] === 1, 'Publishing must record only the captured generation');
persistenceAssert((int)$state['dirty_generation'] === 2 && (int)$state['pending_changes'] === 2, 'Telemetry arriving during training must remain pending');
persistenceAssert((int)$state['dirty_since'] === $now + 4, 'Remaining telemetry must start a fresh dirty-age window after publication');
$currentModel = $dao->currentModel();
persistenceAssert(is_array($currentModel) && (float)$currentModel['weights']['w:liked'] === 1.0, 'Published artifact must be loadable');

$scores = [
	'101' => ['feed_id' => 7, 'score' => 0.9, 'band' => 'normal', 'positive_reasons' => ['liked'], 'negative_reasons' => []],
	'102' => ['feed_id' => 7, 'score' => 0.1, 'band' => 'low', 'positive_reasons' => [], 'negative_reasons' => ['boring']],
];
persistenceAssert($dao->storeScores($scores, 1, $now + 5), 'Score cache write failed');
$cached = $dao->cachedScores(['101', '102'], 1);
persistenceAssert(count($cached) === 2 && $cached['102']['band'] === 'low', 'Current model scores must be retrievable in one batch');
persistenceAssert($dao->cachedScores(['101', '102'], 2) === [], 'Scores from another model version must not be reused');

persistenceAssert($dao->markDirty(1, $now + 6), 'Could not dirty the published model');
$failedLease = $dao->claimLease($now + 7);
persistenceAssert(is_array($failedLease), 'A later training attempt must acquire a lease');
persistenceAssert($dao->finishWithoutModel($failedLease, 'training_failed', [], $now + 8), 'Could not record an isolated training failure');
persistenceAssert($dao->currentModel() !== null && (int)$dao->state()['model_version'] === 1, 'A failed training attempt must retain the last valid model');

persistenceAssert($dao->resetDerived(5, true, $now + 9), 'Derived-data reset failed');
$reset = $dao->state();
persistenceAssert((int)$reset['model_version'] === 2 && $dao->currentModel() === null, 'Reset must invalidate the artifact monotonically');
persistenceAssert((int)$reset['pending_changes'] === 5 && (int)$reset['force_retrain'] === 1, 'Reset must request retraining from remaining telemetry');
persistenceAssert($dao->cachedScores(['101', '102'], 2) === [], 'Reset must delete cached scores');

$expiredPdo = new Minz_PdoSqlite('sqlite::memory:');
$expiredMigrator = new InterestSchemaMigrator('test-expired', $expiredPdo);
persistenceAssert($expiredMigrator->ensureCurrent($now)['ok'], 'Expired-lease schema setup failed');
$expiredDao = new InterestModelDAO('test-expired', $expiredPdo);
persistenceAssert($expiredDao->markDirty(1, $now), 'Could not dirty expired-lease state');
$expiredFirst = $expiredDao->claimLease($now + 1);
$expiredSecond = $expiredDao->claimLease($now + 600_002);
persistenceAssert(
	is_array($expiredFirst) && is_array($expiredSecond) && $expiredFirst['token'] !== $expiredSecond['token'],
	'An expired lease must be recoverable by another maintenance worker'
);

echo "Model persistence tests passed.\n";
