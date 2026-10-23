<?php
declare(strict_types=1);

/** Idempotent schema migration module for new and already-enabled users. */
final class InterestSchemaMigrator extends Minz_ModelPdo {
	public const SCHEMA_VERSION = 1;

	/** @return array{ok:bool,error:?string} */
	public function ensureCurrent(int $nowMs): array {
		try {
			$this->createTelemetryTable();
			$this->ensureColumn('`_interaction_analytics`', 'favorite_state', 'SMALLINT NULL');
			$this->ensureColumn('`_interaction_analytics`', 'explicit_feedback', 'SMALLINT NULL');
			$this->ensureColumn('`_interaction_analytics`', 'feedback_at', 'BIGINT NULL');
			$this->createModelTable();
			$this->ensureColumn('`_interaction_analytics_model`', 'normalization_version', 'VARCHAR(64) NULL');
			$this->createScoresTable();
			$this->ensureIndexes();
			$this->ensureInitialState($nowMs);
			return ['ok' => true, 'error' => null];
		} catch (Throwable $e) {
			Minz_Log::error(__METHOD__ . ': ' . $e->getMessage());
			return ['ok' => false, 'error' => 'Could not upgrade the Interaction Analytics interest-model schema.'];
		}
	}

	private function createTelemetryTable(): void {
		$this->executeDdl(<<<'SQL'
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
				`updated_at` BIGINT NOT NULL,
				`favorite_state` SMALLINT NULL,
				`explicit_feedback` SMALLINT NULL,
				`feedback_at` BIGINT NULL
			)
			SQL);
	}

	private function createModelTable(): void {
		$artifactType = $this->pdo->dbType() === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
		$this->executeDdl(<<<SQL
			CREATE TABLE IF NOT EXISTS `_interaction_analytics_model` (
				`id` SMALLINT NOT NULL PRIMARY KEY,
				`model_version` BIGINT NOT NULL DEFAULT 0,
				`algorithm_version` VARCHAR(64) NULL,
				`normalization_version` VARCHAR(64) NULL,
				`trained_at` BIGINT NULL,
				`last_attempt_at` BIGINT NULL,
				`positive_samples` INT NOT NULL DEFAULT 0,
				`negative_samples` INT NOT NULL DEFAULT 0,
				`validation_samples` INT NOT NULL DEFAULT 0,
				`vocabulary_size` INT NOT NULL DEFAULT 0,
				`decision_threshold` DOUBLE PRECISION NULL,
				`quality_gate_passed` SMALLINT NOT NULL DEFAULT 0,
				`weights_json` {$artifactType} NULL,
				`metrics_json` TEXT NULL,
				`dirty_generation` BIGINT NOT NULL DEFAULT 0,
				`trained_generation` BIGINT NOT NULL DEFAULT 0,
				`pending_changes` INT NOT NULL DEFAULT 0,
				`dirty_since` BIGINT NULL,
				`force_retrain` SMALLINT NOT NULL DEFAULT 0,
				`lease_token` VARCHAR(64) NULL,
				`lease_expires_at` BIGINT NULL,
				`last_error` VARCHAR(512) NULL,
				`updated_at` BIGINT NOT NULL
			)
			SQL);
	}

	private function createScoresTable(): void {
		$this->executeDdl(<<<'SQL'
			CREATE TABLE IF NOT EXISTS `_interaction_analytics_scores` (
				`entry_id` BIGINT NOT NULL PRIMARY KEY,
				`feed_id` INT NOT NULL,
				`model_version` BIGINT NOT NULL,
				`score` DOUBLE PRECISION NOT NULL,
				`band` VARCHAR(16) NOT NULL,
				`explanation_json` TEXT NULL,
				`scored_at` BIGINT NOT NULL
			)
			SQL);
	}

	private function ensureIndexes(): void {
		$this->ensureIndex('`_ia_feed_idx`', '`_interaction_analytics`', '`feed_id`');
		$this->ensureIndex('`_ia_updated_idx`', '`_interaction_analytics`', '`updated_at`');
		$this->ensureIndex('`_ia_score_feed_idx`', '`_interaction_analytics_scores`', '`feed_id`');
		$this->ensureIndex('`_ia_score_model_idx`', '`_interaction_analytics_scores`', '`model_version`');
	}

	private function ensureColumn(string $table, string $column, string $definition): void {
		if ($this->columnExists($table, $column)) {
			return;
		}
		try {
			$result = $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN `' . $column . '` ' . $definition);
		} catch (PDOException) {
			$result = false;
		}
		if ($result === false && !$this->columnExists($table, $column)) {
			throw new RuntimeException('Could not add schema column ' . $column . ': ' . $this->databaseError());
		}
	}

	/** @phpstan-impure */
	private function columnExists(string $table, string $column): bool {
		try {
			$statement = $this->pdo->query('SELECT `' . $column . '` FROM ' . $table . ' WHERE 1=0');
			return $statement !== false;
		} catch (PDOException) {
			return false;
		}
	}

	private function ensureIndex(string $name, string $table, string $columns): void {
		$sql = 'CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $columns . ')';
		if (in_array($this->pdo->dbType(), ['sqlite', 'pgsql'], true)) {
			$sql = 'CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $columns . ')';
		}
		try {
			$result = $this->pdo->exec($sql);
		} catch (PDOException $e) {
			if ($this->duplicateIndexMessage($e->getMessage()) && $this->indexExists($name, $table)) {
				return;
			}
			throw $e;
		}
		if ($result === false && !($this->duplicateIndexError() && $this->indexExists($name, $table))) {
			throw new RuntimeException('Could not create index ' . $name . ': ' . $this->databaseError());
		}
	}

	/** @phpstan-impure */
	private function indexExists(string $name, string $table): bool {
		$logicalName = trim($name, '`');
		$physicalName = str_starts_with($logicalName, '_')
			? $this->pdo->prefix() . substr($logicalName, 1)
			: $logicalName;
		try {
			switch ($this->pdo->dbType()) {
				case 'mysql':
					$statement = $this->pdo->query(
						'SHOW INDEX FROM ' . $table . ' WHERE `Key_name`=' . $this->pdo->quote($physicalName)
					);
					break;
				case 'pgsql':
					$statement = $this->pdo->prepare(
						'SELECT 1 FROM pg_indexes WHERE schemaname=current_schema() AND indexname=?'
					);
					if ($statement === false || !$statement->execute([$physicalName])) {
						return false;
					}
					break;
				default:
					$statement = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");
					if ($statement === false || !$statement->execute([$physicalName])) {
						return false;
					}
			}
			return $statement !== false && $statement->fetchColumn() !== false;
		} catch (PDOException) {
			return false;
		}
	}

	private function ensureInitialState(int $nowMs): void {
		if (!$this->stateExists()) {
			$statement = $this->pdo->prepare('INSERT INTO `_interaction_analytics_model` (`id`,`updated_at`) VALUES (1,?)');
			try {
				$inserted = $statement !== false && $statement->execute([$nowMs]);
			} catch (PDOException) {
				$inserted = false;
			}
			if (!$inserted && !$this->stateExists()) {
				throw new RuntimeException('Could not create initial model state: ' . $this->databaseError());
			}
		}
		$countStatement = $this->pdo->query('SELECT COUNT(*) FROM `_interaction_analytics`');
		$existingTelemetry = $countStatement === false ? 0 : (int)$countStatement->fetchColumn();
		if ($existingTelemetry > 0) {
			$seed = $this->pdo->prepare(<<<'SQL'
				UPDATE `_interaction_analytics_model`
				SET `dirty_generation`=1,`pending_changes`=?,`dirty_since`=?,`updated_at`=?
				WHERE `id`=1 AND `dirty_generation`=0 AND `trained_generation`=0 AND `pending_changes`=0 AND `weights_json` IS NULL
				SQL);
			if ($seed === false || !$seed->execute([$existingTelemetry, $nowMs, $nowMs])) {
				throw new RuntimeException('Could not seed existing telemetry for training.');
			}
		}
	}

	/** @phpstan-impure */
	private function stateExists(): bool {
		$statement = $this->pdo->query('SELECT `id` FROM `_interaction_analytics_model` WHERE `id`=1');
		return $statement !== false && $statement->fetchColumn() !== false;
	}

	private function executeDdl(string $sql): void {
		if ($this->pdo->exec($sql) === false) {
			throw new RuntimeException($this->databaseError());
		}
	}

	private function duplicateIndexError(): bool {
		$error = $this->pdo->errorInfo();
		$message = strtolower((string)($error[2] ?? ''));
		return in_array((string)($error[0] ?? ''), ['42S11', '42P07'], true) || $this->duplicateIndexMessage($message);
	}

	private function duplicateIndexMessage(string $message): bool {
		$message = strtolower($message);
		return str_contains($message, 'duplicate key name') || str_contains($message, 'already exists');
	}

	private function databaseError(): string {
		$error = $this->pdo->errorInfo();
		return substr((string)($error[2] ?? 'unknown database error'), 0, 512);
	}
}
