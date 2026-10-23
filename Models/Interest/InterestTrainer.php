<?php
declare(strict_types=1);

/** Fits and validates a deterministic weighted Bernoulli log-odds model. */
final class InterestTrainer {
	private const MIN_POSITIVE = 20;
	private const MIN_NEGATIVE = 50;
	private const MIN_DOCUMENT_FREQUENCY = 3;
	private const MAX_VOCABULARY = 5_000;
	private const ALPHA = 1.0;
	private const MAX_TRAINING_SECONDS = 5.0;
	private const MAX_ADDITIONAL_MEMORY = 134_217_728;
	private float $resourceStartedAt = 0.0;
	private int $resourceStartedMemory = 0;

	/** @param array{examples:list<array<string,mixed>>} $dataset @return array<string,mixed> */
	public function train(array $dataset, int $nowMs): array {
		$started = microtime(true);
		$this->resourceStartedAt = $started;
		$this->resourceStartedMemory = memory_get_usage(true);
		$examples = $dataset['examples'];
		usort($examples, static function (array $left, array $right): int {
			return [(int)$left['updated_at'], (int)$left['entry_id']] <=> [(int)$right['updated_at'], (int)$right['entry_id']];
		});
		$positiveCount = count(array_filter($examples, static fn (array $example): bool => (int)$example['label'] === 1));
		$negativeCount = count($examples) - $positiveCount;
		if ($positiveCount < self::MIN_POSITIVE || $negativeCount < self::MIN_NEGATIVE) {
			return [
				'status' => 'insufficient_data',
				'artifact' => null,
				'artifact_json' => null,
				'metrics' => ['positive_samples' => $positiveCount, 'negative_samples' => $negativeCount],
			];
		}

		$split = max(1, min(count($examples) - 1, (int)floor(count($examples) * 0.8)));
		$training = array_slice($examples, 0, $split);
		$validation = array_slice($examples, $split);
		$artifact = $this->fit($training);
		$validationResult = $this->validate($validation, $artifact);
		$artifact['decision_threshold'] = $validationResult['threshold'];
		$artifact['quality_gate_passed'] = $validationResult['quality_gate_passed'];
		ksort($artifact, SORT_STRING);
		$artifactJson = json_encode(
			$artifact,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
		);

		$metrics = [
			'positive_samples' => $positiveCount,
			'negative_samples' => $negativeCount,
			'training_samples' => count($training),
			'validation_samples' => count($validation),
			'vocabulary_size' => count($artifact['weights']),
			'low_interest_precision' => $validationResult['precision'],
			'low_interest_recall' => $validationResult['recall'],
			'strong_positive_false_negatives' => $validationResult['strong_positive_false_negatives'],
			'predicted_low' => $validationResult['predicted_low'],
			'quality_gate_passed' => $validationResult['quality_gate_passed'],
			'duration_ms' => (int)round((microtime(true) - $started) * 1000),
			'memory_peak_bytes' => memory_get_peak_usage(true),
			'artifact_bytes' => strlen($artifactJson),
			'trained_at' => $nowMs,
		];

		return ['status' => 'trained', 'artifact' => $artifact, 'artifact_json' => $artifactJson, 'metrics' => $metrics];
	}

	/** @param list<array<string,mixed>> $examples @return array<string,mixed> */
	private function fit(array $examples): array {
		$documentFrequency = [];
		$positiveFeatureWeight = [];
		$negativeFeatureWeight = [];
		$positiveWeight = 0.0;
		$negativeWeight = 0.0;
		foreach ($examples as $index => $example) {
			if ($index % 128 === 0) {
				$this->checkResources();
			}
			$weight = (float)$example['weight'];
			$positive = (int)$example['label'] === 1;
			if ($positive) {
				$positiveWeight += $weight;
			} else {
				$negativeWeight += $weight;
			}
			foreach (array_unique($example['features']) as $feature) {
				$documentFrequency[$feature] = ($documentFrequency[$feature] ?? 0) + 1;
				if ($positive) {
					$positiveFeatureWeight[$feature] = ($positiveFeatureWeight[$feature] ?? 0.0) + $weight;
				} else {
					$negativeFeatureWeight[$feature] = ($negativeFeatureWeight[$feature] ?? 0.0) + $weight;
				}
			}
		}

		$weights = [];
		$absentBiases = [];
		foreach ($documentFrequency as $feature => $frequency) {
			if ($frequency < self::MIN_DOCUMENT_FREQUENCY) {
				continue;
			}
			$positiveProbability = (($positiveFeatureWeight[$feature] ?? 0.0) + self::ALPHA) / ($positiveWeight + 2 * self::ALPHA);
			$negativeProbability = (($negativeFeatureWeight[$feature] ?? 0.0) + self::ALPHA) / ($negativeWeight + 2 * self::ALPHA);
			$weights[$feature] = $this->logit($positiveProbability) - $this->logit($negativeProbability);
			$absentBiases[$feature] = log((1 - $positiveProbability) / (1 - $negativeProbability));
		}
		uksort($weights, static function (string $left, string $right) use ($weights): int {
			return (abs($weights[$right]) <=> abs($weights[$left])) ?: strcmp($left, $right);
		});
		$weights = array_slice($weights, 0, self::MAX_VOCABULARY, true);
		ksort($weights, SORT_STRING);

		$bias = log(($positiveWeight + self::ALPHA) / ($negativeWeight + self::ALPHA));
		foreach (array_keys($weights) as $feature) {
			$bias += $absentBiases[$feature];
		}

		return [
			'artifact_version' => 1,
			'algorithm' => 'weighted_bernoulli_log_odds_v1',
			'normalization' => 'title_nfkc_v1',
			'feature_version' => 'title_terms_cjk_feed_v1',
			'explanation_labels' => [
				'b' => 'phrase',
				'c2' => 'CJK 2-gram',
				'c3' => 'CJK 3-gram',
				'feed' => 'feed',
				'w' => 'word',
			],
			'bias' => $bias,
			'weights' => $weights,
			'decision_threshold' => null,
			'quality_gate_passed' => false,
		];
	}

	/** @param list<array<string,mixed>> $validation @param array<string,mixed> $artifact @return array<string,mixed> */
	private function validate(array $validation, array $artifact): array {
		$scored = [];
		$totalNegative = 0;
		foreach ($validation as $example) {
			$score = $this->scoreFeatures($example['features'], $artifact);
			$scored[] = ['score' => $score, 'label' => (int)$example['label'], 'strong_positive' => (bool)$example['strong_positive']];
			if ((int)$example['label'] === -1) {
				$totalNegative++;
			}
		}
		usort($scored, static fn (array $left, array $right): int => $left['score'] <=> $right['score']);
		$best = null;
		if (count($validation) >= 50) {
			$predictedLow = 0;
			$correctLow = 0;
			$strongFalseNegatives = 0;
			foreach ($scored as $index => $row) {
				if ($index % 128 === 0) {
					$this->checkResources();
				}
				$predictedLow++;
				$correctLow += $row['label'] === -1 ? 1 : 0;
				$strongFalseNegatives += $row['strong_positive'] ? 1 : 0;
				$nextScore = $scored[$index + 1]['score'] ?? null;
				if ($nextScore !== null && $nextScore === $row['score']) {
					continue;
				}
				$threshold = $row['score'];
				$precision = $correctLow / $predictedLow;
				if ($predictedLow < 20 || $precision < 0.95 || $strongFalseNegatives > 0) {
					continue;
				}
				$candidate = [
					'threshold' => $threshold,
					'predicted_low' => $predictedLow,
					'correct_low' => $correctLow,
					'precision' => $precision,
					'strong_positive_false_negatives' => $strongFalseNegatives,
				];
				if ($best === null || $this->betterThreshold($candidate, $best)) {
					$best = $candidate;
				}
			}
		}
		if ($best === null) {
			return [
				'threshold' => null,
				'predicted_low' => 0,
				'precision' => null,
				'recall' => null,
				'strong_positive_false_negatives' => 0,
				'quality_gate_passed' => false,
			];
		}
		return [
			'threshold' => $best['threshold'],
			'predicted_low' => $best['predicted_low'],
			'precision' => $best['precision'],
			'recall' => $totalNegative > 0 ? $best['correct_low'] / $totalNegative : null,
			'strong_positive_false_negatives' => $best['strong_positive_false_negatives'],
			'quality_gate_passed' => true,
		];
	}

	/** @param array<string,int|float> $candidate @param array<string,int|float> $best */
	private function betterThreshold(array $candidate, array $best): bool {
		return $candidate['predicted_low'] > $best['predicted_low']
			|| ($candidate['predicted_low'] === $best['predicted_low'] && $candidate['precision'] > $best['precision'])
			|| ($candidate['predicted_low'] === $best['predicted_low'] && $candidate['precision'] === $best['precision']
				&& $candidate['threshold'] < $best['threshold']);
	}

	/** @param list<string> $features @param array<string,mixed> $artifact */
	private function scoreFeatures(array $features, array $artifact): float {
		$linear = (float)$artifact['bias'];
		foreach (array_unique($features) as $feature) {
			$linear += (float)($artifact['weights'][$feature] ?? 0.0);
		}
		$linear = max(-35.0, min(35.0, $linear));
		return 1.0 / (1.0 + exp(-$linear));
	}

	private function logit(float $probability): float {
		$probability = max(1.0e-9, min(1 - 1.0e-9, $probability));
		return log($probability / (1 - $probability));
	}

	private function checkResources(): void {
		if (microtime(true) - $this->resourceStartedAt > self::MAX_TRAINING_SECONDS
				|| memory_get_usage(true) - $this->resourceStartedMemory > self::MAX_ADDITIONAL_MEMORY) {
			throw new RuntimeException('training_resource_limit');
		}
	}
}
