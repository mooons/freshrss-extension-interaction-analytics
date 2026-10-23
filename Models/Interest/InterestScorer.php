<?php
declare(strict_types=1);

/** Batched scoring module; cache behavior is invisible at its interface. */
final class InterestScorer {
	/** @param Closure():array{enabled:bool,tracking_enabled:bool,auto_train:bool,tracked_feed_ids:list<int>} $settings */
	public function __construct(
		private readonly InterestModelDAO $modelDao,
		private readonly InterestTrainingDataSource $entrySource,
		private readonly InterestScoringEngine $engine,
		private readonly Closure $settings,
	) {
	}

	/** @param list<string> $entryIds @return array{status:string,model_version:int,entries:array<string,array<string,mixed>>} */
	public function scoreEntryIds(array $entryIds): array {
		$settings = ($this->settings)();
		if (!$settings['enabled'] || !$settings['tracking_enabled']) {
			return ['status' => 'disabled', 'model_version' => 0, 'entries' => []];
		}
		$entryIds = array_slice(array_values(array_filter(
			array_unique(array_map('strval', $entryIds)),
			static fn (string $id): bool => ctype_digit($id)
		)), 0, 250);
		$state = $this->modelDao->state();
		if ($state === []) {
			return ['status' => 'schema_unavailable', 'model_version' => 0, 'entries' => []];
		}
		$modelVersion = (int)$state['model_version'];
		$model = $this->modelDao->currentModel($state);
		if ($model === null) {
			return ['status' => 'no_model', 'model_version' => $modelVersion, 'entries' => []];
		}
		$tracked = array_fill_keys($settings['tracked_feed_ids'], true);
		$cached = $this->modelDao->cachedScores($entryIds, $modelVersion);
		$cached = array_filter(
			$cached,
			static fn (array $score): bool => isset($tracked[(int)($score['feed_id'] ?? 0)])
		);
		$missing = array_values(array_diff($entryIds, array_keys($cached)));
		$newScores = [];
		if ($missing !== []) {
			$metadata = $this->entrySource->metadataForEntryIds($missing);
			foreach ($missing as $entryId) {
				$entry = $metadata[$entryId] ?? null;
				if (!is_array($entry) || !isset($tracked[(int)($entry['feed_id'] ?? 0)])) {
					continue;
				}
				$score = $this->engine->score([
					'entry_id' => $entryId,
					'feed_id' => (int)$entry['feed_id'],
					'title' => (string)$entry['title'],
					'feed_name' => (string)($entry['feed_name'] ?? ('Feed #' . $entry['feed_id'])),
				], $model);
				$score['feed_id'] = (int)$entry['feed_id'];
				$score['cached'] = false;
				$newScores[$entryId] = $score;
			}
			$this->modelDao->storeScores($newScores, $modelVersion, (int)round(microtime(true) * 1000));
		}
		$entries = $cached + $newScores;
		uksort($entries, static fn (string $left, string $right): int => (int)$left <=> (int)$right);
		return ['status' => 'ok', 'model_version' => $modelVersion, 'entries' => $entries];
	}
}
