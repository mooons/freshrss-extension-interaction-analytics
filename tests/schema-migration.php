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

function schemaAssert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$pdo = new Minz_PdoSqlite('sqlite::memory:');
$pdo->exec(<<<'SQL'
	CREATE TABLE `_interaction_analytics` (
		`entry_id` BIGINT NOT NULL PRIMARY KEY,
		`feed_id` INT NOT NULL,
		`feed_name` TEXT NULL,
		`feed_url` TEXT NULL,
		`entry_guid` TEXT NULL,
		`entry_title` TEXT NULL,
		`entry_link` TEXT NULL,
		`first_read_at` BIGINT NULL,
		`time_spent_ms` BIGINT NULL,
		`link_opened` SMALLINT NULL,
		`source` VARCHAR(32) NOT NULL DEFAULT 'web',
		`measurement` VARCHAR(32) NOT NULL DEFAULT 'full',
		`updated_at` BIGINT NOT NULL
	)
	SQL);
$pdo->exec("INSERT INTO `_interaction_analytics` (`entry_id`,`feed_id`,`source`,`measurement`,`updated_at`) VALUES (1,7,'web','full',1700000000)");

$migrator = new InterestSchemaMigrator('test', $pdo);
$first = $migrator->ensureCurrent(2_000_000_000_000);
$second = $migrator->ensureCurrent(2_000_000_000_001);
schemaAssert($first['ok'] && $second['ok'], 'Migration must succeed and be idempotent');

$columns = $pdo->query("PRAGMA table_info('interaction_analytics')")?->fetchAll(PDO::FETCH_COLUMN, 1) ?: [];
foreach (['favorite_state', 'explicit_feedback', 'feedback_at'] as $column) {
	schemaAssert(in_array($column, $columns, true), "Missing telemetry column {$column}");
}
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
schemaAssert(in_array('interaction_analytics_model', $tables, true), 'Missing model-state table');
schemaAssert(in_array('interaction_analytics_scores', $tables, true), 'Missing score-cache table');
$state = $pdo->query('SELECT * FROM `_interaction_analytics_model` WHERE `id`=1')?->fetch(PDO::FETCH_ASSOC);
schemaAssert(is_array($state) && (int)$state['model_version'] === 0, 'Missing initial model-state row');
schemaAssert(array_key_exists('normalization_version', $state), 'Missing model normalization-version column');
schemaAssert((int)$state['pending_changes'] === 1 && (int)$state['dirty_generation'] === 1, 'Existing telemetry must be pending after upgrade');

echo "Schema migration tests passed.\n";
