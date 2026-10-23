<?php
declare(strict_types=1);

require_once __DIR__ . '/../Models/Interest/InterestFeatureExtractor.php';
require_once __DIR__ . '/../Models/Interest/InterestTrainingDataSource.php';
require_once __DIR__ . '/../Models/Interest/InterestDatasetBuilder.php';
require_once __DIR__ . '/../Models/Interest/InterestTrainer.php';
require_once __DIR__ . '/../Models/Interest/InterestScoringEngine.php';

function failTest(string $message): never {
	fwrite(STDERR, $message . "\n");
	exit(1);
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
	if ($expected !== $actual) {
		failTest($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assertTrueValue(bool $actual, string $message): void {
	if (!$actual) {
		failTest($message);
	}
}

final class FakeInterestTrainingDataSource implements InterestTrainingDataSource {
	/** @param list<array<string,mixed>> $rows @param array<string,array<string,mixed>> $metadata */
	public function __construct(private array $rows, private array $metadata) {
	}

	#[\Override]
	public function recentTelemetry(array $feedIds, int $limit): array {
		$tracked = array_fill_keys($feedIds, true);
		return array_slice(array_values(array_filter(
			$this->rows,
			static fn (array $row): bool => isset($tracked[(int)$row['feed_id']])
		)), 0, $limit);
	}

	#[\Override]
	public function metadataForEntryIds(array $entryIds): array {
		return array_intersect_key($this->metadata, array_fill_keys($entryIds, true));
	}
}

$extractor = new InterestFeatureExtractor();
$latin = $extractor->extract('ＦｒｅｓｈＲＳＳ Bundle Deals!', 7);
assertTrueValue(in_array('w:freshrss', $latin, true), 'NFKC and case folding must normalize Latin words');
assertTrueValue(in_array('b:freshrss bundle', $latin, true), 'Adjacent Latin words must create bigrams');
assertTrueValue(in_array('feed:7', $latin, true), 'Feed identity must be a feature');

$cjk = $extractor->extract('新作ゲーム情報', 9);
assertTrueValue(in_array('c2:新作', $cjk, true), 'CJK character bigrams must be generated');
assertTrueValue(in_array('c3:新作ゲ', $cjk, true), 'CJK character trigrams must be generated');

$now = 2_000_000_000_000;
$old = $now - 7_200_000;
$recent = $now - 60_000;
$rows = [
	[
		'entry_id' => '1', 'feed_id' => 7, 'entry_title' => null, 'explicit_feedback' => -1,
		'favorite_state' => 1, 'link_opened' => 1, 'measurement' => 'full', 'time_spent_ms' => 9000, 'updated_at' => $old,
	],
	[
		'entry_id' => '2', 'feed_id' => 7, 'entry_title' => null, 'explicit_feedback' => null,
		'favorite_state' => 1, 'link_opened' => null, 'measurement' => 'read-state-only', 'time_spent_ms' => null, 'updated_at' => $old,
	],
	[
		'entry_id' => '3', 'feed_id' => 7, 'entry_title' => null, 'explicit_feedback' => null,
		'favorite_state' => null, 'link_opened' => 1, 'measurement' => 'full', 'time_spent_ms' => 5000, 'updated_at' => $old,
	],
	[
		'entry_id' => '4', 'feed_id' => 7, 'entry_title' => null, 'explicit_feedback' => null,
		'favorite_state' => null, 'link_opened' => 0, 'measurement' => 'full', 'time_spent_ms' => 0, 'updated_at' => $old,
	],
	[
		'entry_id' => '5', 'feed_id' => 7, 'entry_title' => 'Read only', 'explicit_feedback' => null,
		'favorite_state' => null, 'link_opened' => null, 'measurement' => 'read-state-only', 'time_spent_ms' => null, 'updated_at' => $old,
	],
	[
		'entry_id' => '6', 'feed_id' => 7, 'entry_title' => 'Recent skip', 'explicit_feedback' => null,
		'favorite_state' => null, 'link_opened' => 0, 'measurement' => 'full', 'time_spent_ms' => 1000, 'updated_at' => $recent,
	],
	[
		'entry_id' => '7', 'feed_id' => 7, 'entry_title' => null, 'explicit_feedback' => -1,
		'favorite_state' => null, 'link_opened' => null, 'measurement' => 'feedback-only', 'time_spent_ms' => null, 'updated_at' => $recent,
	],
	[
		'entry_id' => '8', 'feed_id' => 7, 'entry_title' => null, 'explicit_feedback' => null,
		'favorite_state' => 1, 'link_opened' => null, 'measurement' => 'favorite-only', 'time_spent_ms' => null, 'updated_at' => $recent,
	],
	[
		'entry_id' => '9', 'feed_id' => 7, 'entry_title' => 'Removed favorite', 'explicit_feedback' => null,
		'favorite_state' => 1, 'link_opened' => null, 'measurement' => 'favorite-only', 'time_spent_ms' => null, 'updated_at' => $old,
	],
];
$metadata = [
	'1' => ['title' => 'Explicit override', 'favorite' => true],
	'2' => ['title' => 'Favorite article', 'favorite' => true],
	'3' => ['title' => 'Publisher opened', 'favorite' => false],
	'4' => ['title' => 'Rapid skip', 'favorite' => false],
	'7' => ['title' => 'Rock &amp; Roll', 'favorite' => false],
	'8' => ['title' => 'Recent favorite', 'favorite' => true],
	'9' => ['title' => 'Removed favorite', 'favorite' => false],
];
$builder = new InterestDatasetBuilder(new FakeInterestTrainingDataSource($rows, $metadata), $extractor);
$dataset = $builder->build($now, [7]);
assertSameValue(6, count($dataset['examples']), 'Implicit rows must settle, while explicit feedback and favorites are immediately eligible');
assertSameValue(-1, $dataset['examples'][0]['label'], 'Explicit negative feedback must override positive signals');
assertSameValue(3.0, $dataset['examples'][0]['weight'], 'Explicit feedback must have weight 3');
assertSameValue(1, $dataset['examples'][1]['label'], 'Favorite must label read-state-only telemetry positive');
assertSameValue(2.0, $dataset['examples'][2]['weight'], 'Publisher opens must have weight 2');
assertSameValue(0, $dataset['examples'][3]['time_spent_ms'], 'Measured zero-time skips must remain valid examples');
$recentExplicit = array_values(array_filter(
	$dataset['examples'],
	static fn (array $example): bool => $example['entry_id'] === '7'
))[0] ?? null;
assertTrueValue(is_array($recentExplicit) && in_array('w:amp', $recentExplicit['features'], true), 'Decoded live titles must not be HTML-decoded a second time');

$examples = [];
for ($i = 0; $i < 300; $i++) {
	$positive = $i % 3 === 0;
	$examples[] = [
		'entry_id' => (string)($i + 1000),
		'feed_id' => 7,
		'title' => $positive ? 'Loved topic recurring' : 'Boring bundle recurring',
		'label' => $positive ? 1 : -1,
		'weight' => $positive ? 3.0 : 1.0,
		'strong_positive' => $positive,
		'features' => $extractor->extract($positive ? 'Loved topic recurring' : 'Boring bundle recurring', 7),
		'updated_at' => $old + $i,
		'time_spent_ms' => null,
	];
}
$trainer = new InterestTrainer();
$trainedA = $trainer->train(['examples' => $examples], $now);
$trainedB = $trainer->train(['examples' => $examples], $now);
assertSameValue('trained', $trainedA['status'], 'A sufficient balanced dataset must train');
assertSameValue($trainedA['artifact_json'], $trainedB['artifact_json'], 'Identical datasets must produce byte-stable artifacts');
$artifactKeys = array_keys($trainedA['artifact']);
$sortedArtifactKeys = $artifactKeys;
sort($sortedArtifactKeys, SORT_STRING);
assertSameValue($sortedArtifactKeys, $artifactKeys, 'Artifact keys must be serialized in lexical order');
assertTrueValue($trainedA['metrics']['quality_gate_passed'], 'Clearly separable validation data must pass the strict quality gate');
assertTrueValue($trainedA['artifact']['decision_threshold'] !== null, 'A passing model must publish a low-interest threshold');
assertSameValue('title_nfkc_v1', $trainedA['artifact']['normalization'], 'Artifact must identify its normalization version');
assertSameValue('title_terms_cjk_feed_v1', $trainedA['artifact']['feature_version'], 'Artifact must identify its feature version');

$engine = new InterestScoringEngine($extractor);
$liked = $engine->score(['entry_id' => '9001', 'feed_id' => 7, 'title' => 'Loved topic recurring'], $trainedA['artifact']);
$boring = $engine->score(['entry_id' => '9002', 'feed_id' => 7, 'title' => 'Boring bundle recurring'], $trainedA['artifact']);
assertTrueValue($liked['score'] > $boring['score'], 'Learned positive phrases must score above learned negative phrases');
assertTrueValue(in_array('boring', $boring['negative_reasons'], true), 'Scoring must explain a learned negative phrase');

echo "Interest model tests passed.\n";
