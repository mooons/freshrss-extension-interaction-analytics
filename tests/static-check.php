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

echo "Static extension checks passed.\n";
