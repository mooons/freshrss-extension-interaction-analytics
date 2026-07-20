<?php
declare(strict_types=1);

final class FreshExtension_interactionAnalytics_Controller extends FreshRSS_ActionController {
	private const EXTENSION_NAME = 'Interaction Analytics';

	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
		$this->view->_layout(null);
	}

	public function summaryAction(): void {
		$ids = $this->requestIds();
		$rows = $this->extension()->dao()->summaryForEntryIds($ids);
		$result = [];
		foreach ($rows as $id => $row) {
			$result[$id] = [
				'feed_id' => (int)$row['feed_id'],
				'time_spent_ms' => $row['time_spent_ms'] === null ? null : (int)$row['time_spent_ms'],
				'link_opened' => $row['link_opened'] === null ? null : (bool)$row['link_opened'],
				'first_read_at' => $row['first_read_at'] === null ? null : gmdate('c', intdiv((int)$row['first_read_at'], 1000)),
				'source' => (string)$row['source'],
				'measurement' => (string)$row['measurement'],
			];
		}
		$this->respond(['entries' => $result]);
	}

	public function recordAction(): void {
		if (!Minz_Request::isPost()) {
			Minz_Error::error(405);
			return;
		}
		$rawEvents = Minz_Request::paramArray('events');
		if (count($rawEvents) > 100) {
			Minz_Error::error(400);
			return;
		}
		$extension = $this->extension();
		if (!$extension->trackingEnabled()) {
			$this->respond(['ok' => true, 'accepted' => 0]);
		}
		$tracked = $extension->trackedFeedIds();
		$preserve = $extension->preserveHistoricalMetadata();
		$ids = [];
		foreach ($rawEvents as $event) {
			if (is_array($event) && is_string($event['entry_id'] ?? null) && ctype_digit($event['entry_id'])) {
				$ids[] = $event['entry_id'];
			}
		}
		$ids = array_values(array_unique($ids));
		$entryMap = [];
		foreach (FreshRSS_Factory::createEntryDao()->listByIds($ids, order: 'ASC') as $entry) {
			$entryMap[$entry->id()] = $entry;
		}
		$existingMap = $extension->dao()->summaryForEntryIds($ids);
		$feeds = FreshRSS_Factory::createFeedDao()->listFeeds();
		$events = [];
		$nowMs = (int)round(microtime(true) * 1000);
		foreach ($rawEvents as $rawEvent) {
			if (!is_array($rawEvent)) {
				continue;
			}
			$entryId = is_string($rawEvent['entry_id'] ?? null) ? $rawEvent['entry_id'] : '';
			if (!ctype_digit($entryId)) {
				continue;
			}
			$entry = $entryMap[$entryId] ?? null;
			$existing = $existingMap[$entryId] ?? null;
			$feedId = $entry?->feedId() ?? (int)($rawEvent['feed_id'] ?? 0);
			if ($feedId < 1 || ($entry === null && $existing === null)) {
				continue;
			}
			if ($entry !== null && $existing !== null && (int)$existing['feed_id'] !== $feedId) {
				continue;
			}
			$time = $rawEvent['time_spent_ms'] ?? null;
			$time = is_numeric($time) ? min(max((int)$time, 0), 604800000) : null;
			$readAt = $rawEvent['first_read_at'] ?? null;
			$readAt = is_numeric($readAt) ? min(max((int)$readAt, 0), $nowMs + 86400000) : null;
			$linkOpened = array_key_exists('link_opened', $rawEvent) && is_bool($rawEvent['link_opened'])
				? $rawEvent['link_opened'] : null;
			$feed = $feeds[$feedId] ?? null;
			$event = [
				'entry_id' => $entryId,
				'feed_id' => $feedId,
				'feed_name' => null,
				'feed_url' => null,
				'entry_guid' => null,
				'entry_title' => null,
				'entry_link' => null,
				'first_read_at' => $readAt,
				'time_spent_ms' => $time,
				'link_opened' => $linkOpened,
				'source' => 'web',
				'measurement' => 'full',
				'updated_at' => time(),
			];
			if ($preserve && $entry !== null) {
				$event['feed_name'] = $feed === null ? null : htmlspecialchars_decode($feed->name(), ENT_QUOTES | ENT_HTML5);
				$event['feed_url'] = $feed === null ? null : $feed->url(false);
				$event['entry_guid'] = htmlspecialchars_decode($entry->guid(), ENT_QUOTES | ENT_HTML5);
				$event['entry_title'] = htmlspecialchars_decode($entry->title(), ENT_QUOTES | ENT_HTML5);
				$event['entry_link'] = htmlspecialchars_decode($entry->link(), ENT_QUOTES | ENT_HTML5);
			}
			$events[] = $event;
		}
		$ok = $extension->dao()->recordEvents($events, $tracked);
		$this->respond(['ok' => $ok, 'accepted' => count($events)], $ok ? 200 : 500);
	}

	public function exportAction(): void {
		$all = Minz_Request::paramBoolean('all');
		$feedIds = array_values(array_unique(array_filter(Minz_Request::paramArrayInt('feed_ids'), static fn (int $id): bool => $id > 0)));
		if ($all) {
			$feedIds = array_map(static fn (array $row): int => (int)$row['feed_id'], $this->extension()->dao()->feedSummaries());
		}
		$data = $this->extension()->dao()->export($feedIds, Minz_Request::paramBoolean('include_content'));
		header('Content-Disposition: attachment; filename="interaction-analytics.json"');
		$this->respond($data);
	}

	public function deleteAction(): void {
		if (!Minz_Request::isPost()) {
			Minz_Error::error(405);
			return;
		}
		$all = Minz_Request::paramBoolean('all');
		$feedIds = array_values(array_unique(array_filter(Minz_Request::paramArrayInt('feed_ids'), static fn (int $id): bool => $id > 0)));
		$ok = $this->extension()->dao()->delete($feedIds, $all);
		$this->respond(['ok' => $ok], $ok ? 200 : 500);
	}

	/** @return list<string> */
	private function requestIds(): array {
		$raw = Minz_Request::paramString('ids');
		if ($raw === '') {
			return [];
		}
		$ids = array_values(array_filter(explode(',', $raw), static fn (string $id): bool => ctype_digit($id)));
		return array_slice(array_values(array_unique($ids)), 0, 250);
	}

	private function extension(): InteractionAnalyticsExtension {
		$extension = Minz_ExtensionManager::findExtension(self::EXTENSION_NAME);
		if (!$extension instanceof InteractionAnalyticsExtension) {
			Minz_Error::error(500);
			throw new RuntimeException('Interaction Analytics extension is unavailable.');
		}
		return $extension;
	}

	private function respond(array $payload, int $status = 200): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}
}
