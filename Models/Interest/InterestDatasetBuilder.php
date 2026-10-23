<?php
declare(strict_types=1);

/** Resolves settled telemetry rows into bounded, weighted training examples. */
final class InterestDatasetBuilder {
	private const SETTLEMENT_MS = 3_600_000;
	private const MAX_EXAMPLES = 20_000;
	private const QUICK_SKIP_MS = 2_000;

	public function __construct(
		private readonly InterestTrainingDataSource $source,
		private readonly InterestFeatureExtractor $extractor,
	) {
	}

	/**
	 * @param list<int> $trackedFeedIds
	 * @return array{examples:list<array<string,mixed>>,positive_count:int,negative_count:int}
	 */
	public function build(int $nowMs, array $trackedFeedIds): array {
		$rows = $this->source->recentTelemetry($trackedFeedIds, self::MAX_EXAMPLES);
		$entryIds = array_values(array_unique(array_map(
			static fn (array $row): string => (string)($row['entry_id'] ?? ''),
			$rows
		)));
		$metadata = $this->source->metadataForEntryIds($entryIds);
		$tracked = array_fill_keys($trackedFeedIds, true);
		$examples = [];
		$positiveCount = 0;
		$negativeCount = 0;

		foreach ($rows as $row) {
			$entryId = (string)($row['entry_id'] ?? '');
			$feedId = (int)($row['feed_id'] ?? 0);
			if (!ctype_digit($entryId) || !isset($tracked[$feedId])) {
				continue;
			}
			$updatedAt = $this->milliseconds((int)($row['updated_at'] ?? 0));
			if ($updatedAt <= 0) {
				continue;
			}
			$live = $metadata[$entryId] ?? null;
			$title = is_array($live) ? (string)$live['title'] : '';
			if ($title === '') {
				$title = is_string($row['entry_title'] ?? null) ? $row['entry_title'] : '';
			}
			if (trim($title) === '') {
				continue;
			}

			$label = $this->label($row, $live);
			if ($label === null) {
				continue;
			}
			[$value, $weight, $strongPositive, $requiresSettlement] = $label;
			if ($requiresSettlement && $updatedAt > $nowMs - self::SETTLEMENT_MS) {
				continue;
			}
			$examples[] = [
				'entry_id' => $entryId,
				'feed_id' => $feedId,
				'label' => $value,
				'weight' => $weight,
				'strong_positive' => $strongPositive,
				'features' => $this->extractor->extract($title, $feedId),
				'updated_at' => $updatedAt,
				'time_spent_ms' => $row['time_spent_ms'] === null ? null : (int)$row['time_spent_ms'],
			];
			if ($value === 1) {
				$positiveCount++;
			} else {
				$negativeCount++;
			}
		}

		usort($examples, static function (array $left, array $right): int {
			return [$left['updated_at'], (int)$left['entry_id']] <=> [$right['updated_at'], (int)$right['entry_id']];
		});
		if (count($examples) > self::MAX_EXAMPLES) {
			$examples = array_slice($examples, -self::MAX_EXAMPLES);
		}

		return [
			'examples' => $examples,
			'positive_count' => $positiveCount,
			'negative_count' => $negativeCount,
		];
	}

	/** @param array<string,mixed> $row @param array<string,mixed>|null $live @return array{int,float,bool,bool}|null */
	private function label(array $row, ?array $live): ?array {
		$feedback = $row['explicit_feedback'] ?? null;
		if ((int)$feedback === 1 || (int)$feedback === -1) {
			$value = (int)$feedback;
			return [$value, 3.0, $value === 1, false];
		}
		$favorite = is_array($live) && array_key_exists('favorite', $live)
			? $live['favorite']
			: ($row['favorite_state'] === null ? null : (bool)$row['favorite_state']);
		if ($favorite === true) {
			return [1, 3.0, true, false];
		}
		if ((int)($row['link_opened'] ?? 0) === 1) {
			return [1, 2.0, true, true];
		}
		$time = $row['time_spent_ms'] ?? null;
		if (($row['measurement'] ?? '') === 'full'
				&& $row['link_opened'] !== null && (int)$row['link_opened'] === 0
				&& is_numeric($time) && (int)$time <= self::QUICK_SKIP_MS) {
			return [-1, 1.0, false, true];
		}
		return null;
	}

	private function milliseconds(int $value): int {
		return $value > 0 && $value < 1_000_000_000_000 ? $value * 1000 : $value;
	}
}
