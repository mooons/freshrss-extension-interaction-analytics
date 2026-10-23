<?php
declare(strict_types=1);

/** Scores entry metadata and provides bounded human-readable explanations. */
final class InterestScoringEngine {
	public function __construct(private readonly InterestFeatureExtractor $extractor) {
	}

	/** @param array{entry_id:string,feed_id:int,title:string,feed_name?:string} $entry @param array<string,mixed> $artifact @return array<string,mixed> */
	public function score(array $entry, array $artifact): array {
		$features = $this->extractor->extract($entry['title'], $entry['feed_id']);
		$linear = (float)$artifact['bias'];
		$positive = [];
		$negative = [];
		foreach ($features as $feature) {
			$weight = (float)($artifact['weights'][$feature] ?? 0.0);
			$linear += $weight;
			if ($weight > 0) {
				$positive[$feature] = $weight;
			} elseif ($weight < 0) {
				$negative[$feature] = $weight;
			}
		}
		arsort($positive, SORT_NUMERIC);
		asort($negative, SORT_NUMERIC);
		$linear = max(-35.0, min(35.0, $linear));
		$score = 1.0 / (1.0 + exp(-$linear));
		$threshold = is_numeric($artifact['decision_threshold'] ?? null) ? (float)$artifact['decision_threshold'] : null;

		return [
			'score' => $score,
			'band' => $threshold === null ? 'unrated' : ($score <= $threshold ? 'low' : 'normal'),
			'positive_reasons' => $this->reasons($positive, $entry),
			'negative_reasons' => $this->reasons($negative, $entry),
		];
	}

	/** @param array<string,float> $weighted @param array<string,mixed> $entry @return list<string> */
	private function reasons(array $weighted, array $entry): array {
		$reasons = [];
		foreach (array_slice(array_keys($weighted), 0, 3) as $feature) {
			if (str_starts_with($feature, 'feed:')) {
				$group = $this->extractor->sourceGroup((int)$entry['feed_id']);
				$reasons[] = $group !== '' ? $group : (string)($entry['feed_name'] ?? ('feed #' . $entry['feed_id']));
			} else {
				$reasons[] = substr($feature, strpos($feature, ':') + 1);
			}
		}
		return $reasons;
	}
}
