<?php
declare(strict_types=1);

/** Orchestrates bounded maintenance training behind one idempotent interface. */
final class InterestModelCoordinator {
	private const MIN_AUTOMATIC_INTERVAL_MS = 21_600_000;
	private const MAX_MODEL_AGE_MS = 86_400_000;
	private const MIN_PENDING_CHANGES = 25;
	private const MAX_TRAINING_MS = 5_000;
	private const MAX_ADDITIONAL_MEMORY = 134_217_728;

	/** @param Closure():array{enabled:bool,tracking_enabled:bool,auto_train:bool,tracked_feed_ids:list<int>} $settings */
	public function __construct(
		private readonly InterestModelDAO $modelDao,
		private readonly InterestDatasetBuilder $datasetBuilder,
		private readonly InterestTrainer $trainer,
		private readonly Closure $settings,
	) {
	}

	/** @return array{status:string,message?:string,metrics?:array<string,mixed>} */
	public function trainIfDue(int $nowMs): array {
		$lease = null;
		try {
			$settings = ($this->settings)();
			if (!$settings['enabled'] || !$settings['tracking_enabled'] || $settings['tracked_feed_ids'] === []) {
				return ['status' => 'disabled'];
			}
			$state = $this->modelDao->state();
			if ($state === []) {
				return ['status' => 'schema_unavailable'];
			}
			$force = (int)($state['force_retrain'] ?? 0) === 1;
			if (!$force && !$settings['auto_train']) {
				return ['status' => 'not_due'];
			}
			if (!$force && !$this->automaticTrainingDue($state, $nowMs)) {
				return ['status' => 'not_due'];
			}

			$lease = $this->modelDao->claimLease($nowMs);
			if ($lease === null) {
				return ['status' => 'leased'];
			}
			$started = microtime(true);
			$startingMemory = memory_get_usage(true);
			$dataset = $this->datasetBuilder->build($nowMs, $settings['tracked_feed_ids']);
			$training = $this->trainer->train($dataset, $nowMs);
			$elapsedMs = (int)round((microtime(true) - $started) * 1000);
			$additionalMemory = max(0, memory_get_usage(true) - $startingMemory);
			if (is_array($training['metrics'] ?? null)) {
				$training['metrics']['duration_ms'] = $elapsedMs;
				$training['metrics']['additional_memory_bytes'] = $additionalMemory;
			}
			if ($elapsedMs > self::MAX_TRAINING_MS || $additionalMemory > self::MAX_ADDITIONAL_MEMORY) {
				$this->modelDao->finishWithoutModel($lease, 'training_resource_limit', $training['metrics'] ?? [], $nowMs);
				return ['status' => 'resource_limit'];
			}
			if (($training['status'] ?? '') !== 'trained') {
				$status = (string)($training['status'] ?? 'training_failed');
				$finished = $this->modelDao->finishWithoutModel(
					$lease,
					$status,
					$training['metrics'] ?? [],
					$nowMs,
					$status === 'insufficient_data'
				);
				if (!$finished) {
					return ['status' => 'failed', 'message' => 'training_state_update_failed'];
				}
				return ['status' => $status, 'metrics' => $training['metrics'] ?? []];
			}
			if (!$this->modelDao->publish($lease, $training, $nowMs)) {
				$this->modelDao->finishWithoutModel($lease, 'model_publication_failed', $training['metrics'] ?? [], $nowMs);
				return ['status' => 'failed', 'message' => 'model_publication_failed'];
			}
			return ['status' => 'trained', 'metrics' => $training['metrics']];
		} catch (Throwable $e) {
			$detail = substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage(), 0, 512);
			Minz_Log::error(__METHOD__ . ': ' . $detail);
			if (is_array($lease)) {
				$this->modelDao->finishWithoutModel($lease, 'training_failed', [], $nowMs);
			}
			return ['status' => 'failed', 'message' => 'training_failed'];
		}
	}

	/** @param array<string,mixed> $state */
	private function automaticTrainingDue(array $state, int $nowMs): bool {
		$lastAttempt = (int)($state['last_attempt_at'] ?? 0);
		if ($lastAttempt > 0 && $nowMs - $lastAttempt < self::MIN_AUTOMATIC_INTERVAL_MS) {
			return false;
		}
		$dirty = (int)($state['dirty_generation'] ?? 0) > (int)($state['trained_generation'] ?? 0);
		if (!$dirty) {
			return false;
		}
		if ((int)($state['pending_changes'] ?? 0) >= self::MIN_PENDING_CHANGES) {
			return true;
		}
		$dirtySince = (int)($state['dirty_since'] ?? 0);
		return $dirtySince > 0 && $nowMs - $dirtySince >= self::MAX_MODEL_AGE_MS;
	}
}
