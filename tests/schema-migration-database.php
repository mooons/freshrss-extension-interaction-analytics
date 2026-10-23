<?php
declare(strict_types=1);

$freshRssPath = getenv('FRESHRSS_PATH');
$driver = getenv('TEST_DB_DRIVER');
if (!is_string($freshRssPath) || $freshRssPath === '' || !in_array($driver, ['mysql', 'pgsql'], true)) {
	fwrite(STDERR, "FRESHRSS_PATH and TEST_DB_DRIVER=mysql|pgsql are required.\n");
	exit(1);
}
require $freshRssPath . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require_once __DIR__ . '/../Models/Interest/InterestSchemaMigrator.php';

$dsn = (string)getenv('TEST_DB_DSN');
$user = (string)getenv('TEST_DB_USER');
$password = (string)getenv('TEST_DB_PASSWORD');
$pdo = $driver === 'mysql'
	? new Minz_PdoMysql($dsn, $user, $password)
	: new Minz_PdoPgsql($dsn, $user, $password);
$pdo->setPrefix('ia_ci_');

$drop = static function () use ($pdo): void {
	$pdo->exec('DROP TABLE IF EXISTS `_interaction_analytics_scores`');
	$pdo->exec('DROP TABLE IF EXISTS `_interaction_analytics_model`');
	$pdo->exec('DROP TABLE IF EXISTS `_interaction_analytics`');
};
$drop();
try {
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
	$migrator = new InterestSchemaMigrator('test', $pdo);
	$first = $migrator->ensureCurrent(2_000_000_000_000);
	$second = $migrator->ensureCurrent(2_000_000_000_001);
	if (!$first['ok'] || !$second['ok']) {
		throw new RuntimeException('Cross-database migration was not idempotent.');
	}
	foreach (['favorite_state', 'explicit_feedback', 'feedback_at'] as $column) {
		if ($pdo->query('SELECT `' . $column . '` FROM `_interaction_analytics` WHERE 1=0') === false) {
			throw new RuntimeException('Missing migrated column ' . $column);
		}
	}
	if ($pdo->query('SELECT `id` FROM `_interaction_analytics_model` WHERE `id`=1')?->fetchColumn() === false) {
		throw new RuntimeException('Missing initial model state.');
	}
	if ($pdo->query('SELECT `normalization_version` FROM `_interaction_analytics_model` WHERE 1=0') === false) {
		throw new RuntimeException('Missing model normalization-version column.');
	}
	echo "{$driver} schema migration tests passed.\n";
} finally {
	$drop();
}
