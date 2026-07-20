<?php
declare(strict_types=1);

/**
 * Persistence seam for the Interaction Analytics extension.
 *
 * The table intentionally has no foreign keys to FreshRSS entries or feeds:
 * those records may be purged while the user's telemetry is retained.
 */
final class InteractionAnalyticsDAO extends Minz_ModelPdo {
	private const TABLE = '`_interaction_analytics`';

	public function install(): string|true {
		$sql = <<<'SQL'
			CREATE TABLE IF NOT EXISTS `_interaction_analytics` (
				`entry_id` BIGINT NOT NULL PRIMARY KEY,
				`feed_id` INT NOT NULL,
				`feed_name` TEXT NULL,
				`feed_url` TEXT NULL,
				`entry_guid` TEXT NULL,
				`entry_title` TEXT NULL,
				`entry_link` TEXT NULL,
				`first_read_at` BIGINT NULL,
				`time_spent_ms` BIGINT NULL,
				`link_opened` SMALLINT NULL,
				`source` VARCHAR(32) NOT NULL DEFAULT 'web',
				`measurement` VARCHAR(32) NOT NULL DEFAULT 'full',
				`updated_at` BIGINT NOT NULL
			)
			SQL;
		try {
			if ($this->pdo->exec($sql) === false) {
				return 'Could not create the interaction analytics table.';
			}
			$indexName = '`_interaction_analytics_feed_index`';
			$indexSql = 'CREATE INDEX ' . $indexName . ' ON ' . self::TABLE . ' (`feed_id`)';
			if (in_array($this->pdo->dbType(), ['sqlite', 'pgsql'], true)) {
				$indexSql = 'CREATE INDEX IF NOT EXISTS ' . $indexName . ' ON ' . self::TABLE . ' (`feed_id`)';
			}
			// MySQL does not consistently support IF NOT EXISTS for indexes. A
			// duplicate index on a later install is harmless, so do not fail the
			// extension installation solely because it already exists.
			$this->pdo->exec($indexSql);
			return true;
		} catch (Throwable $e) {
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
			return 'Could not create the interaction analytics table.';
		}
	}

	/**
	 * Record browser telemetry as deltas. Existing metadata is retained when
	 * historical metadata preservation is later disabled.
	 *
	 * @param list<array<string,mixed>> $events
	 * @param list<int> $trackedFeedIds
	 */
	public function recordEvents(array $events, array $trackedFeedIds): bool {
		if ($events === []) {
			return true;
		}
		$tracked = array_fill_keys($trackedFeedIds, true);
		$inTransaction = $this->pdo->inTransaction();
		if (!$inTransaction) {
			$this->pdo->beginTransaction();
		}
		try {
			foreach ($events as $event) {
				$this->mergeEvent($event, $tracked);
			}
			if (!$inTransaction) {
				$this->pdo->commit();
			}
			return true;
		} catch (Throwable $e) {
			if (!$inTransaction && $this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Record read-state-only events observed through the GReader API.
	 *
	 * @param list<numeric-string> $ids
	 * @param list<int> $trackedFeedIds
	 */
	public function recordReadOnly(array $ids, array $trackedFeedIds, bool $preserveMetadata, int $observedAt): bool {
		if ($ids === []) {
			return true;
		}
		$entryDao = FreshRSS_Factory::createEntryDao();
		$feedDao = FreshRSS_Factory::createFeedDao();
		$feeds = $feedDao->listFeeds();
		$events = [];
		foreach ($entryDao->listByIds($ids, order: 'ASC') as $entry) {
			$feedId = $entry->feedId();
			if (!in_array($feedId, $trackedFeedIds, true)) {
				continue;
			}
			$feed = $feeds[$feedId] ?? null;
			$events[] = [
				'entry_id' => $entry->id(),
				'feed_id' => $feedId,
				'feed_name' => $preserveMetadata && $feed !== null ? $this->decode($feed->name()) : null,
				'feed_url' => $preserveMetadata && $feed !== null ? $feed->url(false) : null,
				'entry_guid' => $preserveMetadata ? $this->decode($entry->guid()) : null,
				'entry_title' => $preserveMetadata ? $this->decode($entry->title()) : null,
				'entry_link' => $preserveMetadata ? $this->decode($entry->link()) : null,
				'first_read_at' => $observedAt,
				'time_spent_ms' => null,
				'link_opened' => null,
				'source' => 'greader',
				'measurement' => 'read-state-only',
				'updated_at' => $observedAt,
			];
		}
		return $this->recordEvents($events, $trackedFeedIds);
	}

	/** @return array<string,array<string,mixed>> */
	public function summaryForEntryIds(array $ids): array {
		$ids = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => ctype_digit($id)));
		if ($ids === []) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$sql = 'SELECT * FROM ' . self::TABLE . ' WHERE `entry_id` IN (' . $placeholders . ')';
		$stm = $this->pdo->prepare($sql);
		if ($stm === false || !$stm->execute($ids)) {
			return [];
		}
		$result = [];
		while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
			$result[(string)$row['entry_id']] = $this->normaliseRow($row);
		}
		return $result;
	}

	/** @return list<array<string,mixed>> */
	public function feedSummaries(): array {
		$sql = <<<'SQL'
			SELECT feed_id,
				MAX(feed_name) AS feed_name,
				MAX(feed_url) AS feed_url,
				COUNT(*) AS entries,
				SUM(CASE WHEN time_spent_ms IS NOT NULL THEN 1 ELSE 0 END) AS measured_entries,
				COALESCE(SUM(time_spent_ms), 0) AS total_time_ms,
				AVG(time_spent_ms) AS average_time_ms,
				SUM(CASE WHEN link_opened = 1 THEN 1 ELSE 0 END) AS links_opened,
				SUM(CASE WHEN measurement = 'read-state-only' THEN 1 ELSE 0 END) AS unknown_entries
			FROM `_interaction_analytics`
			GROUP BY feed_id
			ORDER BY feed_name, feed_id
			SQL;
		$rows = $this->fetchAssoc($sql) ?? [];
		$feeds = FreshRSS_Factory::createFeedDao()->listFeeds();
		$result = [];
		foreach ($rows as $row) {
			$feedId = (int)$row['feed_id'];
			$feed = $feeds[$feedId] ?? null;
			$feedName = $this->decode((string)($row['feed_name'] ?: ($feed?->name() ?? '')));
			if ($feedName === '') {
				$feedName = 'Feed #' . $feedId;
			}
			$result[] = [
				'feed_id' => $feedId,
				'feed_name' => $feedName,
				'feed_url' => (string)($row['feed_url'] ?: ($feed?->url(false) ?? '')),
				'entries' => (int)$row['entries'],
				'measured_entries' => (int)$row['measured_entries'],
				'total_time_ms' => (int)$row['total_time_ms'],
				'average_time_ms' => $row['average_time_ms'] === null ? null : (float)$row['average_time_ms'],
				'links_opened' => (int)$row['links_opened'],
				'unknown_entries' => (int)$row['unknown_entries'],
			];
		}
		return $result;
	}

	/**
	 * @param list<int> $feedIds
	 * @return array{version:int,exported_at:string,feeds:list<array<string,mixed>>}
	 */
	public function export(array $feedIds, bool $includeContent): array {
		$rows = $this->rowsForFeeds($feedIds);
		$feedDao = FreshRSS_Factory::createFeedDao();
		$feeds = $feedDao->listFeeds();
		$entryIds = array_map(static fn (array $row): string => (string)$row['entry_id'], $rows);
		$entryMap = [];
		if ($entryIds !== []) {
			foreach (FreshRSS_Factory::createEntryDao()->listByIds($entryIds, order: 'ASC') as $entry) {
				$entryMap[$entry->id()] = $entry;
			}
		}

		$grouped = [];
		foreach ($rows as $row) {
			$feedId = (int)$row['feed_id'];
			$feed = $feeds[$feedId] ?? null;
			if (!isset($grouped[$feedId])) {
				$grouped[$feedId] = [
					'id' => $feedId,
					'name' => $this->decode((string)($row['feed_name'] ?: ($feed?->name() ?? 'Feed #' . $feedId))),
					'url' => (string)($row['feed_url'] ?: ($feed?->url(false) ?? '')),
					'entries' => [],
				];
			}
			$entry = $entryMap[(string)$row['entry_id']] ?? null;
			$title = $row['entry_title'];
			$guid = $row['entry_guid'];
			$link = $row['entry_link'];
			if ($entry !== null) {
				$title = $this->decode($entry->title());
				$guid = $this->decode($entry->guid());
				$link = $this->decode($entry->link());
			}
			$item = [
				'entry_id' => (string)$row['entry_id'],
				'guid' => $guid === null ? null : (string)$guid,
				'title' => $title === null ? null : (string)$title,
				'time_spent_ms' => $row['time_spent_ms'] === null ? null : (int)$row['time_spent_ms'],
				'first_read_at' => $row['first_read_at'] === null ? null : gmdate('c', intdiv((int)$row['first_read_at'], 1000)),
				'link_opened' => $row['link_opened'] === null ? null : (bool)$row['link_opened'],
				'source' => (string)$row['source'],
				'measurement' => (string)$row['measurement'],
			];
			if ($link !== null && $link !== '') {
				$item['link'] = (string)$link;
			}
			if ($includeContent && $entry !== null) {
				$item['content'] = $entry->content(true);
			}
			$grouped[$feedId]['entries'][] = $item;
		}
		return [
			'version' => 1,
			'exported_at' => gmdate('c'),
			'feeds' => array_values($grouped),
		];
	}

	/** @param list<int> $feedIds */
	public function delete(array $feedIds, bool $all): bool {
		if ($all) {
			return $this->pdo->exec('DELETE FROM ' . self::TABLE) !== false;
		}
		if ($feedIds === []) {
			return true;
		}
		$placeholders = implode(',', array_fill(0, count($feedIds), '?'));
		$stm = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE `feed_id` IN (' . $placeholders . ')');
		return $stm !== false && $stm->execute($feedIds);
	}

	/** @param array<string,mixed> $event @param array<int,bool> $tracked */
	private function mergeEvent(array $event, array $tracked): void {
		$entryId = (string)($event['entry_id'] ?? '');
		$feedId = (int)($event['feed_id'] ?? 0);
		if (!ctype_digit($entryId) || $feedId < 1) {
			return;
		}
		$existing = $this->findRow($entryId);
		if ($existing === null && !isset($tracked[$feedId])) {
			return;
		}
		if ($existing !== null && (int)$existing['feed_id'] !== $feedId) {
			return;
		}
		$incomingTime = $event['time_spent_ms'] === null ? null : max(0, (int)$event['time_spent_ms']);
		$oldTime = $existing === null || $existing['time_spent_ms'] === null ? null : (int)$existing['time_spent_ms'];
		$mergedTime = $incomingTime === null ? $oldTime : ($oldTime === null ? $incomingTime : $oldTime + $incomingTime);
		$incomingRead = $event['first_read_at'] === null ? null : max(0, (int)$event['first_read_at']);
		$oldRead = $existing === null || $existing['first_read_at'] === null ? null : (int)$existing['first_read_at'];
		$incomingLink = $event['link_opened'] === null ? null : ((bool)$event['link_opened'] ? 1 : 0);
		$oldLink = $existing === null || $existing['link_opened'] === null ? null : (int)$existing['link_opened'];
		$values = [
			'entry_id' => $entryId,
			'feed_id' => $feedId,
			'feed_name' => $this->mergedString($existing, 'feed_name', $event['feed_name'] ?? null),
			'feed_url' => $this->mergedString($existing, 'feed_url', $event['feed_url'] ?? null),
			'entry_guid' => $this->mergedString($existing, 'entry_guid', $event['entry_guid'] ?? null),
			'entry_title' => $this->mergedString($existing, 'entry_title', $event['entry_title'] ?? null),
			'entry_link' => $this->mergedString($existing, 'entry_link', $event['entry_link'] ?? null),
			'first_read_at' => $oldRead ?? $incomingRead,
			'time_spent_ms' => $mergedTime,
			'link_opened' => ($oldLink === 1 || $incomingLink === 1) ? 1 : ($incomingLink ?? $oldLink),
			'source' => ($existing !== null && $existing['measurement'] === 'full') ? 'web' : (string)($event['source'] ?? 'web'),
			'measurement' => ($existing !== null && $existing['measurement'] === 'full') ? 'full' : (string)($event['measurement'] ?? 'full'),
			'updated_at' => max((int)($event['updated_at'] ?? 0), time()),
		];
		if ($existing === null) {
			$sql = 'INSERT INTO ' . self::TABLE . ' (`entry_id`,`feed_id`,`feed_name`,`feed_url`,`entry_guid`,`entry_title`,`entry_link`,`first_read_at`,`time_spent_ms`,`link_opened`,`source`,`measurement`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
			$stm = $this->pdo->prepare($sql);
			if ($stm === false || !$stm->execute(array_values($values))) {
				throw new RuntimeException('Could not insert telemetry row.');
			}
			return;
		}
		$sql = 'UPDATE ' . self::TABLE . ' SET `feed_id`=?,`feed_name`=?,`feed_url`=?,`entry_guid`=?,`entry_title`=?,`entry_link`=?,`first_read_at`=?,`time_spent_ms`=?,`link_opened`=?,`source`=?,`measurement`=?,`updated_at`=? WHERE `entry_id`=?';
		$updateValues = [
			$values['feed_id'], $values['feed_name'], $values['feed_url'], $values['entry_guid'],
			$values['entry_title'], $values['entry_link'], $values['first_read_at'], $values['time_spent_ms'],
			$values['link_opened'], $values['source'], $values['measurement'], $values['updated_at'], $entryId,
		];
		$stm = $this->pdo->prepare($sql);
		if ($stm === false || !$stm->execute($updateValues)) {
			throw new RuntimeException('Could not update telemetry row.');
		}
	}

	/** @return array<string,mixed>|null */
	private function findRow(string $entryId): ?array {
		$res = $this->fetchAssoc('SELECT * FROM ' . self::TABLE . ' WHERE `entry_id`=:entry_id', [':entry_id' => $entryId]);
		return isset($res[0]) && is_array($res[0]) ? $res[0] : null;
	}

	/** @param array<string,mixed>|null $existing */
	private function mergedString(?array $existing, string $key, mixed $incoming): ?string {
		if (is_string($incoming) && $incoming !== '') {
			return $incoming;
		}
		$value = $existing[$key] ?? null;
		return is_string($value) && $value !== '' ? $value : null;
	}

	/** @param list<int> $feedIds @return list<array<string,mixed>> */
	private function rowsForFeeds(array $feedIds): array {
		if ($feedIds === []) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($feedIds), '?'));
		$stm = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE `feed_id` IN (' . $placeholders . ') ORDER BY `feed_id`, `entry_id`');
		if ($stm === false || !$stm->execute($feedIds)) {
			return [];
		}
		$rows = [];
		while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
			$rows[] = $this->normaliseRow($row);
		}
		return $rows;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normaliseRow(array $row): array {
		foreach (['feed_name', 'feed_url', 'entry_guid', 'entry_title', 'entry_link', 'source', 'measurement'] as $key) {
			if (isset($row[$key])) {
				$row[$key] = (string)$row[$key];
			}
		}
		foreach (['entry_id', 'feed_id', 'first_read_at', 'time_spent_ms', 'link_opened', 'updated_at'] as $key) {
			if (isset($row[$key]) && $row[$key] !== null) {
				$row[$key] = (int)$row[$key];
			}
		}
		return $row;
	}

	private function decode(string $value): string {
		return htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML5);
	}
}
