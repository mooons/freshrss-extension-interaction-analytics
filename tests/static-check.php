<?php
declare(strict_types=1);

$metadata = json_decode((string)file_get_contents(__DIR__ . '/../metadata.json'), true);
if (!is_array($metadata) || $metadata['entrypoint'] !== 'InteractionAnalytics' || $metadata['type'] !== 'user') {
	exit("Invalid extension metadata\n");
}

$required = [
	'extension.php',
	'configure.phtml',
	'Controllers/interactionAnalyticsController.php',
	'Models/InteractionAnalyticsDAO.php',
	'static/interactionAnalytics.js',
	'static/interactionAnalytics.css',
];
foreach ($required as $file) {
	if (!is_file(__DIR__ . '/../' . $file)) {
		exit("Missing {$file}\n");
	}
}

$extensionSource = (string)file_get_contents(__DIR__ . '/../extension.php');
if (str_contains($extensionSource, 'Minz_HookType::EntriesRead')) {
	exit("EntriesRead must remain optional for older FreshRSS releases\n");
}
if (!str_contains($extensionSource, "Minz_HookType::tryFrom('entries_read')")) {
	exit("Missing optional EntriesRead detection\n");
}
if (!str_contains($extensionSource, "greader_ingestion_enabled")) {
	exit("Missing GReader ingestion configuration\n");
}

$configureSource = (string)file_get_contents(__DIR__ . '/../configure.phtml');
if (!str_contains($configureSource, 'disabled aria-disabled="true"')) {
	exit("Missing unavailable GReader control state\n");
}
if (!str_contains($configureSource, 'greader_ingestion_unavailable')) {
	exit("Missing unavailable GReader tooltip\n");
}

echo "Static extension checks passed.\n";
