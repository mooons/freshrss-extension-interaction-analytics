<?php
declare(strict_types=1);

/** Persistence module for model state, leases, publication, and score cache. */
final class InterestModelDAO extends Minz_ModelPdo {
	private const MODEL_TABLE = '`_interaction_analytics_model`';
	private const SCORE_TABLE = '`_interaction_analytics_scores`';
	private const LEASE_MS = 600_000;

	/** @return array<string,mixed> */
	public function state(): array {
		$rows = $this->fetchAssoc('SELECT * FROM ' . self::MODEL_TABLE . ' WHERE `id`=1') ?? [];
		if (!isset($rows[0])) {
			return [];
		}
		$row = $rows[0];
		foreach ([
			'id', 'model_version', 'trained_at', 'last_attempt_at', 'positive_samples', 'negative_samples',
			'validation_samples', 'vocabulary_size', 'quality_gate_passed', 'dirty_generation',
			'trained_generation', 'pending_changes', 'dirty_since', 'force_retrain', 'lease_expires_at', 'updated_at',
			] as $key) {
			if (isset($row[$key])) {
				$row[$key] = (int)$row[$key];
			}
		}
		if (isset($row['decision_threshold'])) {
			$row['decision_threshold'] = (float)$row['decision_threshold'];
		}
		return $row;
	}

	public function markDirty(int $changes, int $nowMs): bool {
		if ($changes < 1) {
			return true;
		}
		$statement = $this->pdo->prepare(<<<'SQL'
			UPDATE `_interaction_analytics_model`
			SET `dirty_generation`=`dirty_generation`+1,
				`pending_changes`=`pending_changes`+?,
				`dirty_since`=COALESCE(`dirty_since`,?),
				`updated_at`=?
			WHERE `id`=1
			SQL);
		return $statement !== false && $statement->execute([$changes, $nowMs, $nowMs]);
	}

	public function requestTraining(int $nowMs): bool {
		$statement = $this->pdo->prepare(<<<'SQL'
			UPDATE `_interaction_analytics_model`
			SET `force_retrain`=1,`dirty_since`=COALESCE(`dirty_since`,?),`updated_at`=?
			WHERE `id`=1
			SQL);
		return $statement !== false && $statement->execute([$nowMs, $nowMs]);
	}

	/** @return array{token:string,dirty_generation:int,pending_changes:int}|null */
	public function claimLease(int $nowMs): ?array {
		$token = bin2hex(random_bytes(16));
		$statement = $this->pdo->prepare(<<<'SQL'
			UPDATE `_interaction_analytics_model`
			SET `lease_token`=?,`lease_expires_at`=?,`last_attempt_at`=?,`last_error`=NULL,`updated_at`=?
			WHERE `id`=1 AND (`lease_token` IS NULL OR `lease_expires_at` IS NULL OR `lease_expires_at`<=?)
			SQL);
		if ($statement === false || !$statement->execute([$token, $nowMs + self::LEASE_MS, $nowMs, $nowMs, $nowMs]) || $statement->rowCount() !== 1) {
			return null;
		}
		$state = $this->state();
		if (($state['lease_token'] ?? null) !== $token) {
			return null;
		}
		return [
			'token' => $token,
			'dirty_generation' => (int)$state['dirty_generation'],
			'pending_changes' => (int)$state['pending_changes'],
		];
	}

	/** @param array{token:string,dirty_generation:int,pending_changes:int} $lease @param array<string,mixed> $training */
	public function publish(array $lease, array $training, int $nowMs): bool {
		$artifact = is_array($training['artifact'] ?? null) ? $training['artifact'] : null;
		$artifactJson = is_string($training['artifact_json'] ?? null) ? $training['artifact_json'] : null;
		$metrics = is_array($training['metrics'] ?? null) ? $training['metrics'] : [];
		if ($artifact === null || $artifactJson === null || strlen($artifactJson) > 2_097_152) {
			return false;
		}
		$metricsJson = json_encode(
			$metrics,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
		);
		$statement = $this->pdo->prepare(<<<'SQL'
			UPDATE `_interaction_analytics_model`
			SET `model_version`=`model_version`+1,
				`algorithm_version`=?,`normalization_version`=?,`trained_at`=?,
				`positive_samples`=?,`negative_samples`=?,`validation_samples`=?,`vocabulary_size`=?,
				`decision_threshold`=?,`quality_gate_passed`=?,`weights_json`=?,`metrics_json`=?,
				`trained_generation`=?,
				`pending_changes`=CASE WHEN `pending_changes`>=? THEN `pending_changes`-? ELSE 0 END,
				`dirty_since`=CASE WHEN `dirty_generation`<=? THEN NULL ELSE ? END,
				`force_retrain`=0,`lease_token`=NULL,`lease_expires_at`=NULL,`last_error`=NULL,`updated_at`=?
			WHERE `id`=1 AND `lease_token`=?
			SQL);
		if ($statement === false) {
			return false;
		}
		return $statement->execute([
			(string)($artifact['algorithm'] ?? ''), (string)($artifact['normalization'] ?? ''), $nowMs,
			(int)($metrics['positive_samples'] ?? 0), (int)($metrics['negative_samples'] ?? 0),
			(int)($metrics['validation_samples'] ?? 0), (int)($metrics['vocabulary_size'] ?? 0),
			$artifact['decision_threshold'] ?? null, !empty($metrics['quality_gate_passed']) ? 1 : 0,
			$artifactJson, is_string($metricsJson) ? $metricsJson : null,
			$lease['dirty_generation'], $lease['pending_changes'], $lease['pending_changes'],
			$lease['dirty_generation'], $nowMs, $nowMs, $lease['token'],
		]) && $statement->rowCount() === 1;
	}

	/** @param array{token:string,dirty_generation:int,pending_changes:int} $lease */
	public function finishWithoutModel(array $lease, string $reason, array $metrics, int $nowMs, bool $consumeGeneration = false): bool {
		$reason = substr(preg_replace('/\s+/', ' ', $reason) ?? $reason, 0, 512);
		$sql = 'UPDATE ' . self::MODEL_TABLE
			. ' SET `positive_samples`=?,`negative_samples`=?,`validation_samples`=?,';
		$values = [
			(int)($metrics['positive_samples'] ?? 0), (int)($metrics['negative_samples'] ?? 0),
			(int)($metrics['validation_samples'] ?? 0),
		];
		if ($consumeGeneration) {
			$sql .= '`trained_generation`=?,`pending_changes`=CASE WHEN `pending_changes`>=? '
				. 'THEN `pending_changes`-? ELSE 0 END,'
				. '`dirty_since`=CASE WHEN `dirty_generation`<=? THEN NULL ELSE ? END,';
			array_push(
				$values,
				$lease['dirty_generation'],
				$lease['pending_changes'],
				$lease['pending_changes'],
				$lease['dirty_generation'],
				$nowMs
			);
		}
		$sql .= '`force_retrain`=0,`lease_token`=NULL,`lease_expires_at`=NULL,`last_error`=?,`updated_at`=? '
			. 'WHERE `id`=1 AND `lease_token`=?';
		array_push($values, $reason, $nowMs, $lease['token']);
		$statement = $this->pdo->prepare($sql);
		return $statement !== false && $statement->execute($values) && $statement->rowCount() === 1;
	}

	/** @return array<string,mixed>|null */
	public function currentModel(?array $state = null): ?array {
		$state ??= $this->state();
		$json = $state['weights_json'] ?? null;
		if (!is_string($json) || $json === '') {
			return null;
		}
		$model = json_decode($json, true);
		if (!is_array($model)
				|| (int)($model['artifact_version'] ?? 0) !== 1
				|| ($model['algorithm'] ?? '') !== 'weighted_bernoulli_log_odds_v1'
				|| ($model['normalization'] ?? '') !== 'title_nfkc_v1'
				|| ($model['feature_version'] ?? '') !== 'title_terms_cjk_feed_v1'
				|| !is_array($model['explanation_labels'] ?? null)
				|| !is_array($model['weights'] ?? null)) {
			return null;
		}
		return $model;
	}

	/** @param list<string> $entryIds @return array<string,array<string,mixed>> */
	public function cachedScores(array $entryIds, int $modelVersion): array {
		$entryIds = array_values(array_filter(array_unique($entryIds), static fn (string $id): bool => ctype_digit($id)));
		if ($entryIds === []) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($entryIds), '?'));
		$statement = $this->pdo->prepare(
			'SELECT * FROM ' . self::SCORE_TABLE . ' WHERE `model_version`=? AND `entry_id` IN (' . $placeholders . ')'
		);
		if ($statement === false || !$statement->execute(array_merge([$modelVersion], $entryIds))) {
			return [];
		}
		$result = [];
		while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
			$explanation = json_decode((string)($row['explanation_json'] ?? ''), true);
			$result[(string)$row['entry_id']] = [
				'feed_id' => (int)$row['feed_id'],
				'score' => (float)$row['score'],
				'band' => (string)$row['band'],
				'positive_reasons' => is_array($explanation['positive_reasons'] ?? null) ? $explanation['positive_reasons'] : [],
				'negative_reasons' => is_array($explanation['negative_reasons'] ?? null) ? $explanation['negative_reasons'] : [],
				'cached' => true,
			];
		}
		return $result;
	}

	/** @param array<string,array<string,mixed>> $scores */
	public function storeScores(array $scores, int $modelVersion, int $nowMs): bool {
		if ($scores === []) {
			return true;
		}
		$ids = array_values(array_filter(array_keys($scores), static fn (string $id): bool => ctype_digit($id)));
		if ($ids === []) {
			return true;
		}
		$ownsTransaction = !$this->pdo->inTransaction();
		if ($ownsTransaction) {
			$this->pdo->beginTransaction();
		}
		try {
			$delete = $this->pdo->prepare('DELETE FROM ' . self::SCORE_TABLE . ' WHERE `entry_id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
			$insert = $this->pdo->prepare(<<<'SQL'
				INSERT INTO `_interaction_analytics_scores`
					(`entry_id`,`feed_id`,`model_version`,`score`,`band`,`explanation_json`,`scored_at`)
				VALUES (?,?,?,?,?,?,?)
				SQL);
			if ($delete === false || !$delete->execute($ids) || $insert === false) {
				throw new RuntimeException('Could not prepare score-cache update.');
			}
			foreach ($ids as $id) {
				$score = $scores[$id];
				$explanation = json_encode([
					'positive_reasons' => $score['positive_reasons'] ?? [],
					'negative_reasons' => $score['negative_reasons'] ?? [],
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
				if (!$insert->execute([
					$id, (int)$score['feed_id'], $modelVersion, (float)$score['score'], (string)$score['band'],
					is_string($explanation) ? $explanation : null, $nowMs,
					])) {
					throw new RuntimeException('Could not store score cache row.');
				}
			}
			if ($ownsTransaction) {
				$this->pdo->commit();
			}
			return true;
		} catch (Throwable $e) {
			if ($ownsTransaction && $this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
			return false;
		}
	}

	public function deleteCachedEntry(string $entryId): bool {
		if (!ctype_digit($entryId)) {
			return false;
		}
		$statement = $this->pdo->prepare('DELETE FROM ' . self::SCORE_TABLE . ' WHERE `entry_id`=?');
		return $statement !== false && $statement->execute([$entryId]);
	}

	public function resetDerived(int $pendingChanges, bool $forceRetrain, int $nowMs): bool {
		$pendingChanges = max(0, $pendingChanges);
		$ownsTransaction = !$this->pdo->inTransaction();
		if ($ownsTransaction) {
			$this->pdo->beginTransaction();
		}
		try {
			if ($this->pdo->exec('DELETE FROM ' . self::SCORE_TABLE) === false) {
				throw new RuntimeException('Could not clear score cache.');
			}
			$sql = 'UPDATE ' . self::MODEL_TABLE
				. ' SET `model_version`=`model_version`+1,`algorithm_version`=NULL,`normalization_version`=NULL,'
				. '`trained_at`=NULL,`last_attempt_at`=NULL,'
				. '`positive_samples`=0,`negative_samples`=0,`validation_samples`=0,`vocabulary_size`=0,'
				. '`decision_threshold`=NULL,`quality_gate_passed`=0,`weights_json`=NULL,`metrics_json`=NULL,'
				. '`trained_generation`=`dirty_generation`,';
			$values = [];
			if ($forceRetrain && $pendingChanges > 0) {
				$sql .= '`dirty_generation`=`dirty_generation`+1,'
					. '`pending_changes`=CASE WHEN `pending_changes`>? THEN `pending_changes` ELSE ? END,'
					. '`dirty_since`=COALESCE(`dirty_since`,?),`force_retrain`=1,';
				$values = [$pendingChanges, $pendingChanges, $nowMs];
			} else {
				$sql .= '`pending_changes`=0,`dirty_since`=NULL,`force_retrain`=0,';
			}
			$sql .= '`lease_token`=NULL,`lease_expires_at`=NULL,`last_error`=NULL,`updated_at`=? WHERE `id`=1';
			$values[] = $nowMs;
			$statement = $this->pdo->prepare($sql);
			if ($statement === false || !$statement->execute($values)) {
				throw new RuntimeException('Could not reset model state.');
			}
			if ($ownsTransaction) {
				$this->pdo->commit();
			}
			return true;
		} catch (Throwable $e) {
			if ($ownsTransaction && $this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
			return false;
		}
	}
}
