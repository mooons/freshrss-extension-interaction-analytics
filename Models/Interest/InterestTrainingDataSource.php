<?php
declare(strict_types=1);

/** Persistence seam used by the bounded training dataset module. */
interface InterestTrainingDataSource {
	/** @param list<int> $feedIds @return list<array<string,mixed>> */
	public function recentTelemetry(array $feedIds, int $limit): array;

	/**
	 * @param list<string> $entryIds
	 * @return array<string,array{title:string,favorite:?bool,feed_id?:int,feed_name?:string}>
	 */
	public function metadataForEntryIds(array $entryIds): array;
}
