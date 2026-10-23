<?php
declare(strict_types=1);

$freshRssPath = getenv('FRESHRSS_PATH');
if (!is_string($freshRssPath) || $freshRssPath === '') {
	fwrite(STDERR, "FRESHRSS_PATH is required.\n");
	exit(1);
}
require $freshRssPath . '/constants.php';
require LIB_PATH . '/lib_rss.php';
$hookSource = (string)file_get_contents($freshRssPath . '/lib/Minz/HookType.php');
$entryDaoSource = (string)file_get_contents($freshRssPath . '/app/Models/EntryDAO.php');
$greaderSource = (string)file_get_contents($freshRssPath . '/p/api/greader.php');
$mountedPath = $freshRssPath . '/extensions/xExtension-InteractionAnalytics';
if (realpath($mountedPath) !== realpath(__DIR__ . '/..')) {
	fwrite(STDERR, "The extension is not mounted in the FreshRSS 1.29.1 extensions directory.\n");
	exit(1);
}
$extensionSource = (string)file_get_contents($mountedPath . '/extension.php');

foreach (['EntriesFavorite', 'FreshrssUserMaintenance'] as $requiredHook) {
	if (!str_contains($hookSource, 'case ' . $requiredHook)) {
		fwrite(STDERR, "FreshRSS 1.29.1 is missing required hook {$requiredHook}.\n");
		exit(1);
	}
}
if (str_contains($hookSource, 'case EntriesRead')) {
	fwrite(STDERR, "The compatibility fixture is not FreshRSS 1.29.1: EntriesRead is unexpectedly present.\n");
	exit(1);
}
if (!str_contains($entryDaoSource, 'callHook(Minz_HookType::EntriesFavorite, $ids, $is_favorite)')) {
	fwrite(STDERR, "FreshRSS 1.29.1 favorite changes do not dispatch EntriesFavorite.\n");
	exit(1);
}
if (!str_contains($greaderSource, '$entryDAO->markFavorite($e_ids, true)')
		|| !str_contains($greaderSource, '$entryDAO->markFavorite($e_ids, false)')) {
	fwrite(STDERR, "FreshRSS 1.29.1 GReader favorites do not use the favorite DAO seam.\n");
	exit(1);
}
if (!str_contains($extensionSource, "Minz_HookType::tryFrom('entries_read')")
		|| str_contains($extensionSource, 'Minz_HookType::EntriesRead')) {
	fwrite(STDERR, "The extension no longer treats EntriesRead as optional.\n");
	exit(1);
}

require_once $mountedPath . '/extension.php';
$metadata = json_decode((string)file_get_contents($mountedPath . '/metadata.json'), true);
if (!is_array($metadata)) {
	fwrite(STDERR, "Could not load mounted extension metadata.\n");
	exit(1);
}
$metadata['path'] = $mountedPath;
$extension = new InteractionAnalyticsExtension($metadata);
if ($extension->entriesReadAvailable()) {
	fwrite(STDERR, "Mounted extension did not tolerate the optional absence of EntriesRead.\n");
	exit(1);
}

echo "FreshRSS 1.29.1 hook compatibility passed.\n";
