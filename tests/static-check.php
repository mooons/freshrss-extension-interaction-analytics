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
	'Models/Interest/InterestSchemaMigrator.php',
	'Models/Interest/InterestModelDAO.php',
	'Models/Interest/InterestDatasetBuilder.php',
	'Models/Interest/InterestTrainer.php',
	'Models/Interest/InterestScorer.php',
	'static/interactionAnalytics.js',
	'static/interestModel.js',
	'static/configure.js',
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
foreach ([
	'Minz_HookType::EntriesFavorite',
	'Minz_HookType::FreshrssUserMaintenance',
	"interest_model_enabled",
	"interest_model_auto_train",
	"interest_display_mode",
	"interest_explicit_feedback",
	] as $interestRequirement) {
	if (!str_contains($extensionSource, $interestRequirement)) {
		exit("Missing interest-model extension wiring: {$interestRequirement}\n");
	}
}

$configureSource = (string)file_get_contents(__DIR__ . '/../configure.phtml');
if (!str_contains($configureSource, 'disabled aria-disabled="true"')) {
	exit("Missing unavailable GReader control state\n");
}
if (!str_contains($configureSource, 'greader_ingestion_unavailable')) {
	exit("Missing unavailable GReader tooltip\n");
}
if (!str_contains($configureSource, 'data-confirm-selected') || !str_contains($configureSource, 'interaction-delete-status')) {
	exit("Missing enhanced delete controls\n");
}
if (!str_contains($configureSource, 'requestTraining') || !str_contains($configureSource, 'deleteModel')) {
	exit("Missing model lifecycle controls\n");
}

$controllerSource = (string)file_get_contents(__DIR__ . '/../Controllers/interactionAnalyticsController.php');
foreach (['scoresAction', 'feedbackAction', 'requestTrainingAction', 'deleteModelAction'] as $action) {
	if (!str_contains($controllerSource, 'function ' . $action)) {
		exit("Missing controller action: {$action}\n");
	}
}

$schemaSource = (string)file_get_contents(__DIR__ . '/../Models/Interest/InterestSchemaMigrator.php');
foreach (['favorite_state', 'explicit_feedback', 'normalization_version', '_interaction_analytics_model', '_interaction_analytics_scores'] as $schemaRequirement) {
	if (!str_contains($schemaSource, $schemaRequirement)) {
		exit("Missing model schema: {$schemaRequirement}\n");
	}
}

$scriptSource = (string)file_get_contents(__DIR__ . '/../static/interactionAnalytics.js');
foreach ([
	'attributeFilter: [\'class\']',
	'attributeOldValue: true',
	'not_read',
	'markRead(node)',
	] as $requiredFallbackCode) {
	if (!str_contains($scriptSource, $requiredFallbackCode)) {
		exit("Missing read-state fallback code: {$requiredFallbackCode}\n");
	}
}

echo "Static extension checks passed.\n";
