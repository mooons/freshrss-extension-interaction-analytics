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
require_once __DIR__ . '/../Models/InteractionAnalyticsDAO.php';
require_once __DIR__ . '/../Models/Interest/InterestTrainingDataSource.php';
require_once __DIR__ . '/../Models/Interest/FreshRSSInterestTrainingDataSource.php';

function telemetryAssert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$now = 2_000_000_000_000;
$pdo = new Minz_PdoSqlite('sqlite::memory:');
$migrator = new InterestSchemaMigrator('test', $pdo);
telemetryAssert($migrator->ensureCurrent($now)['ok'], 'Schema setup failed');
$modelDao = new InterestModelDAO('test', $pdo);
$telemetryDao = new InteractionAnalyticsDAO($modelDao, 'test', $pdo);
$pdo->exec('CREATE TABLE `_entry` (`id` BIGINT PRIMARY KEY,`title` TEXT NOT NULL,`is_favorite` SMALLINT NOT NULL,`id_feed` INT NOT NULL)');
$pdo->exec('CREATE TABLE `_feed` (`id` INT PRIMARY KEY,`name` TEXT NOT NULL)');
$pdo->exec("INSERT INTO `_entry` (`id`,`title`,`is_favorite`,`id_feed`) VALUES (201,'Rock &amp; Roll',1,7)");
$pdo->exec("INSERT INTO `_feed` (`id`,`name`) VALUES (7,'Feed &amp; News')");
$metadataSource = new FreshRSSInterestTrainingDataSource($telemetryDao, 'test', $pdo);
$metadata = $metadataSource->metadataForEntryIds(['201']);
telemetryAssert(
	($metadata['201']['title'] ?? null) === 'Rock & Roll' && ($metadata['201']['feed_name'] ?? null) === 'Feed & News'
		&& ($metadata['201']['favorite'] ?? null) === true,
	'The metadata adapter must batch only title, favorite, and feed fields without requiring article content'
);

$event = [
	'entry_id' => '101', 'feed_id' => 7, 'feed_name' => null, 'feed_url' => null,
	'entry_guid' => null, 'entry_title' => null, 'entry_link' => null,
	'first_read_at' => $now, 'time_spent_ms' => 0, 'link_opened' => false,
	'source' => 'web', 'measurement' => 'full', 'updated_at' => $now,
];
telemetryAssert($telemetryDao->recordEvents([$event], [7]), 'Could not store measured zero-time telemetry');
$row = $telemetryDao->summaryForEntryIds(['101'])['101'] ?? null;
telemetryAssert(is_array($row) && $row['time_spent_ms'] === 0 && $row['link_opened'] === 0, 'Zero-time and unopened states must remain distinct from null');
telemetryAssert((int)$modelDao->state()['pending_changes'] === 1, 'Telemetry must mark the model dirty in the same transaction');

$entry = new FreshRSS_Entry(7, 'guid-101', 'Interesting title', '', '', 'https://example.com/101');
$entry->_id('101');
telemetryAssert($telemetryDao->recordFeedback($entry, null, 1, false, $now + 1), 'Could not store explicit positive feedback');
$positive = $telemetryDao->summaryForEntryIds(['101'])['101'];
telemetryAssert($positive['explicit_feedback'] === 1 && $positive['time_spent_ms'] === 0, 'Feedback must not overwrite raw telemetry');
telemetryAssert($telemetryDao->recordFeedback($entry, null, 0, false, $now + 2), 'Could not clear explicit feedback');
$cleared = $telemetryDao->summaryForEntryIds(['101'])['101'];
telemetryAssert($cleared['explicit_feedback'] === null, 'Clear feedback must restore implicit labeling');

$readOnlyEvent = $event;
$readOnlyEvent['entry_id'] = '103';
$readOnlyEvent['source'] = 'greader';
$readOnlyEvent['measurement'] = 'read-state-only';
$readOnlyEvent['time_spent_ms'] = null;
$readOnlyEvent['link_opened'] = null;
telemetryAssert($telemetryDao->recordEvents([$readOnlyEvent], [7]), 'Could not store read-state-only telemetry');
$readOnlyEntry = new FreshRSS_Entry(7, 'guid-103', 'Read-only title', '', '', 'https://example.com/103');
$readOnlyEntry->_id('103');
telemetryAssert($telemetryDao->recordFeedback($readOnlyEntry, null, -1, false, $now + 3), 'Could not label read-state-only telemetry');
$labeledReadOnly = $telemetryDao->summaryForEntryIds(['103'])['103'];
telemetryAssert(
	$labeledReadOnly['measurement'] === 'read-state-only' && $labeledReadOnly['source'] === 'greader'
		&& $labeledReadOnly['explicit_feedback'] === -1,
	'Explicit feedback must preserve the raw GReader measurement and source'
);

$remainingEvent = $event;
$remainingEvent['entry_id'] = '102';
$remainingEvent['feed_id'] = 8;
$remainingEvent['updated_at'] = $now + 4;
telemetryAssert($telemetryDao->recordEvents([$remainingEvent], [8]), 'Could not store telemetry for a remaining feed');
telemetryAssert($telemetryDao->countTelemetryForFeeds([7]) === 2, 'Tracked-feed counts must include only selected feeds');
telemetryAssert($telemetryDao->countTelemetryForFeeds([8]) === 1, 'Tracked-feed counts must support model-scope changes');
telemetryAssert($telemetryDao->delete([7], false), 'Selected-feed deletion failed');
telemetryAssert($telemetryDao->countTelemetry() === 1, 'Selected-feed deletion must preserve unselected source telemetry');
$afterSelectedDelete = $modelDao->state();
telemetryAssert(
	$modelDao->currentModel() === null && (int)$afterSelectedDelete['pending_changes'] >= 1 && (int)$afterSelectedDelete['force_retrain'] === 1,
	'Selected-feed deletion must invalidate derived data and request retraining from remaining rows'
);
telemetryAssert($telemetryDao->delete([], true), 'All-data deletion failed');
$afterAllDelete = $modelDao->state();
telemetryAssert($telemetryDao->countTelemetry() === 0, 'All-data deletion must remove every telemetry row');
telemetryAssert(
	(int)$afterAllDelete['pending_changes'] === 0 && (int)$afterAllDelete['force_retrain'] === 0,
	'All-data deletion must leave an empty schema-state row without requested training'
);

echo "Telemetry interest persistence tests passed.\n";
