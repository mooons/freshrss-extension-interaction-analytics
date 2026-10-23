<?php
declare(strict_types=1);

// Stub the request/configuration boundary; use the real extension and SQLite DAOs.
class Minz_Extension {
	public array $configuration = ['schema_version' => 1, 'tracked_feed_ids' => [7, 9]];
	public function init(): void {}
	public function install(): string|true {
		return true; }
	public function uninstall(): string|true {
		return true; }
	public function handleConfigureAction(): void {}
	public function getPath(): string {
		return dirname(__DIR__); }
	public function getFileUrl(string $file): string {
		return $file; }
	public function registerTranslates(): void {}
	public function getUserConfigurationArray(string $key): ?array {
		return $this->configuration[$key] ?? null; }
	public function getUserConfigurationBool(string $key): ?bool {
		return $this->configuration[$key] ?? null; }
	public function getUserConfigurationInt(string $key): ?int {
		return $this->configuration[$key] ?? null; }
	public function setUserConfigurationValue(string $key, mixed $value): void {
		$this->configuration[$key] = $value; }
	public function getName(): string {
		return 'Interaction Analytics'; }
}
class Minz_View {
	public static function appendStyle(string $url): void {}
	public static function appendScript(string $url): void {}
}
class FreshRSS_Auth {
	public static function requestReauth(): bool {
		return false; }
}
class FreshRSS_Factory {
	public static function createFeedDao(): object {
		return new class {
			public function listFeeds(): array {
				return [7 => null, 9 => null, 10 => null]; }
		};
	}
}
class FreshRSS_UserDAO {
	public static function touch(): void {}
	public static function ctouch(): bool {
		return true; }
}
class Minz_Log {
	public static array $errors = [];
	public static function error(string $message): void {
		self::$errors[] = $message; }
}
class Minz_Request {
	public static array $groups = [];
	public static function isPost(): bool {
		return true; }
	public static function paramArrayInt(string $key): array {
		return [7, 9]; }
	public static function paramArray(string $key, bool $plaintext = false): array {
		return self::$groups; }
	public static function paramString(string $key, bool $plaintext = false): string {
		return 'score'; }
	public static function paramBoolean(string $key): bool {
		return true; }
	public static function good(string $message, array $url = []): void {
		throw new RuntimeException('saved'); }
	public static function bad(string $message, array $url = []): void {
		throw new RuntimeException($message); }
}
function _t(string $key): string {
	return $key; }

$freshRssPath = getenv('FRESHRSS_PATH');
if (!is_string($freshRssPath) || $freshRssPath === '') {
	exit("FRESHRSS_PATH is required.\n");
}
require $freshRssPath . '/constants.php';
require LIB_PATH . '/lib_rss.php';
require __DIR__ . '/../extension.php';
foreach (glob(__DIR__ . '/../Models/Interest/*.php') ?: [] as $file) {
	// The data-source implementation requires its interface first.
	require_once __DIR__ . '/../Models/Interest/InterestTrainingDataSource.php';
	require_once $file;
}
require_once __DIR__ . '/../Models/InteractionAnalyticsDAO.php';

function settingsAssert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}
function saveGroups(InteractionAnalyticsExtension $extension, array $groups): string {
	Minz_Request::$groups = $groups;
	try {
		$extension->handleConfigureAction();
	} catch (RuntimeException $e) {
		return $e->getMessage();
	}
	return 'did not redirect';
}

$pdo = new Minz_PdoSqlite('sqlite::memory:');
$migrator = new InterestSchemaMigrator('test', $pdo);
settingsAssert($migrator->ensureCurrent(2_000_000_000_000)['ok'], 'Schema setup failed: ' . implode('; ', Minz_Log::$errors));
$modelDao = new InterestModelDAO('test', $pdo);
$dao = new InteractionAnalyticsDAO($modelDao, 'test', $pdo);
$pdo->exec("INSERT INTO `_interaction_analytics` (`entry_id`,`feed_id`,`updated_at`,`explicit_feedback`) VALUES (101,7,2000000000000,1)");
settingsAssert($modelDao->storeScores([
	'101' => ['feed_id' => 7, 'score' => 0.9, 'band' => 'normal', 'positive_reasons' => [], 'negative_reasons' => []],
], 0, 2_000_000_000_000), 'Score setup failed');
$extension = new InteractionAnalyticsExtension();
$extension->interest_schema_ready = true;
(new ReflectionProperty($extension, 'modelDao'))->setValue($extension, $modelDao);
(new ReflectionProperty($extension, 'dao'))->setValue($extension, $dao);
settingsAssert(saveGroups($extension, [9 => '  Keylol  ', 7 => 'Keylol', 999 => 'Ignore']) === 'saved', 'Valid groups must save');
settingsAssert($extension->interestSourceGroups() === [7 => 'Keylol', 9 => 'Keylol'], 'Saved groups must normalize names and whitelist current feeds');
settingsAssert($modelDao->cachedScores(['101'], 0) === [], 'Saving a new grouping must clear stale scores');
settingsAssert($modelDao->state()['force_retrain'] === 1, 'Grouping changes must queue retraining');
settingsAssert($dao->summaryForEntryIds(['101'])['101']['explicit_feedback'] === 1, 'Grouping changes must preserve source feedback');
$version = $modelDao->state()['model_version'];
settingsAssert(saveGroups($extension, [9 => 'KEYLOL', 7 => 'keylol']) === 'saved', 'Case-only changes must save');
settingsAssert($modelDao->state()['model_version'] === $version, 'Case and ordering changes must not reset the model');
$saved = $extension->configuration;
foreach ([[7 => ['nested']], [7 => str_repeat('a', 81)], [7 => str_repeat('ﬃ', 40)], [7 => "\xFF"]] as $invalid) {
	settingsAssert(str_ends_with(saveGroups($extension, $invalid), 'source_group_invalid'), 'Malformed group names must be rejected');
	settingsAssert($extension->configuration === $saved, 'Invalid requests must not save any settings');
}
$pdo->exec("CREATE TRIGGER fail_reset BEFORE UPDATE ON interaction_analytics_model BEGIN SELECT RAISE(ABORT, 'test reset failure'); END");
settingsAssert(str_ends_with(saveGroups($extension, [7 => 'Different']), 'model_settings_failed'), 'Reset failures must reject changed settings');
settingsAssert($extension->configuration === $saved, 'Failed model invalidation must keep the old grouping');
$pdo->exec('DROP TRIGGER fail_reset');
settingsAssert(saveGroups($extension, [7 => '', 9 => '']) === 'saved', 'Leaving a group must save');
settingsAssert($extension->interestSourceGroups() === [], 'Blank groups must restore separate feed weights');
settingsAssert($modelDao->state()['model_version'] > $version, 'Leaving a group must invalidate the old model');
echo "Source-group configuration and invalidation passed.\n";
