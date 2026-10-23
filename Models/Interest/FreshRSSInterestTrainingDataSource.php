<?php
declare(strict_types=1);

/** Production adapter from FreshRSS persistence to the training dataset seam. */
final class FreshRSSInterestTrainingDataSource extends Minz_ModelPdo implements InterestTrainingDataSource {
	public function __construct(
		private readonly InteractionAnalyticsDAO $telemetryDao,
		?string $currentUser = null,
		?Minz_Pdo $currentPdo = null,
	) {
		parent::__construct($currentUser, $currentPdo);
	}

	#[\Override]
	public function recentTelemetry(array $feedIds, int $limit): array {
		return $this->telemetryDao->recentTelemetry($feedIds, $limit);
	}

	#[\Override]
	public function metadataForEntryIds(array $entryIds): array {
		$entryIds = array_values(array_filter(array_unique($entryIds), static fn (string $id): bool => ctype_digit($id)));
		if ($entryIds === []) {
			return [];
		}
		$rows = [];
		foreach (array_chunk($entryIds, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) as $chunk) {
			$statement = $this->pdo->prepare(
				'SELECT `id`,`title`,`is_favorite`,`id_feed` FROM `_entry` WHERE `id` IN ('
				. implode(',', array_fill(0, count($chunk), '?')) . ')'
			);
			if ($statement === false || !$statement->execute($chunk)) {
				continue;
			}
			while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
				$rows[] = $row;
			}
		}
		$feedIds = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['id_feed'], $rows)));
		$feedNames = [];
		foreach (array_chunk($feedIds, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) as $chunk) {
			$statement = $this->pdo->prepare(
				'SELECT `id`,`name` FROM `_feed` WHERE `id` IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
			);
			if ($statement === false || !$statement->execute($chunk)) {
				continue;
			}
			while (is_array($feed = $statement->fetch(PDO::FETCH_ASSOC))) {
				$feedNames[(int)$feed['id']] = htmlspecialchars_decode((string)$feed['name'], ENT_QUOTES | ENT_HTML5);
			}
		}
		$result = [];
		foreach ($rows as $row) {
			$feedId = (int)$row['id_feed'];
			$entryId = (string)$row['id'];
			$result[$entryId] = [
				'title' => htmlspecialchars_decode((string)$row['title'], ENT_QUOTES | ENT_HTML5),
				'favorite' => (int)$row['is_favorite'] === 1,
				'feed_id' => $feedId,
				'feed_name' => $feedNames[$feedId] ?? ('Feed #' . $feedId),
			];
		}
		return $result;
	}
}
