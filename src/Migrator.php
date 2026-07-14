<?php

declare(strict_types=1);

namespace Catenvis;

use PDOException;
use RuntimeException;

/**
 * Applies SQL delta migrations from sql/migrations/ in filename order and
 * records them in the schema_migrations table.
 *
 * sql/schema.sql always reflects the current full schema (fresh installs load
 * only that file, including marker INSERTs for migrations already folded in);
 * existing installations catch up by applying the pending delta files. Only
 * plain single-statement SQL separated by ';' is supported - no DELIMITER
 * switching, so no stored routines or triggers with compound bodies.
 */
final class Migrator {
	private const TRACKING_TABLE = 'schema_migrations';

	public function __construct(
		private readonly Database $db,
		private readonly string $migrationsDir
	) {
	}

	/**
	 * Migration files (*.sql basenames) in the given directory, byte-sorted -
	 * zero-padded numeric prefixes define the application order.
	 *
	 * @return list<string>
	 * @throws RuntimeException When the directory does not exist (broken checkout).
	 */
	public static function discoverMigrations(string $dir): array {
		if (!is_dir($dir)) {
			throw new RuntimeException("Migrations directory not found: $dir");
		}

		$names = [];
		foreach (scandir($dir) ?: [] as $entry) {
			if (str_ends_with($entry, '.sql') && is_file($dir . '/' . $entry)) {
				$names[] = $entry;
			}
		}
		sort($names);

		return $names;
	}

	/**
	 * Splits an SQL script into single statements on ';', respecting quoted
	 * strings ('...', "...", `...`) and comments (-- , #, block comments).
	 * Comments are stripped; empty statements are dropped.
	 *
	 * @return list<string>
	 */
	public static function splitStatements(string $sql): array {
		$statements = [];
		$buffer     = '';
		$length     = strlen($sql);
		$i          = 0;

		while ($i < $length) {
			$char = $sql[$i];

			// Line comments: "-- " (MySQL requires trailing whitespace) and "#".
			if ($char === '#' || ($char === '-' && substr($sql, $i, 2) === '--'
				&& ($i + 2 >= $length || strpos(" \t\n\r", $sql[$i + 2]) !== false))
			) {
				$newline = strpos($sql, "\n", $i);
				$i       = $newline === false ? $length : $newline;
				continue; // The newline itself is processed as a normal char.
			}

			// Block comments: replaced by a single space (token separator).
			if ($char === '/' && substr($sql, $i, 2) === '/*') {
				$end     = strpos($sql, '*/', $i + 2);
				$i       = $end === false ? $length : $end + 2;
				$buffer .= ' ';
				continue;
			}

			// Quoted strings and identifiers are copied verbatim.
			if ($char === "'" || $char === '"' || $char === '`') {
				$buffer .= $char;
				$i++;
				while ($i < $length) {
					// Backslash escapes apply inside strings, not identifiers.
					if ($char !== '`' && $sql[$i] === '\\' && $i + 1 < $length) {
						$buffer .= $sql[$i] . $sql[$i + 1];
						$i      += 2;
						continue;
					}
					if ($sql[$i] === $char) {
						// A doubled quote is an escaped quote, not the end.
						if ($i + 1 < $length && $sql[$i + 1] === $char) {
							$buffer .= $char . $char;
							$i      += 2;
							continue;
						}
						break;
					}
					$buffer .= $sql[$i];
					$i++;
				}
				if ($i < $length) {
					$buffer .= $char; // Closing quote.
					$i++;
				}
				continue;
			}

			if ($char === ';') {
				$statement = trim($buffer);
				if ($statement !== '') {
					$statements[] = $statement;
				}
				$buffer = '';
				$i++;
				continue;
			}

			$buffer .= $char;
			$i++;
		}

		$statement = trim($buffer);
		if ($statement !== '') {
			$statements[] = $statement;
		}

		return $statements;
	}

	/**
	 * All migration files shipped with this checkout, in application order.
	 *
	 * @return list<string>
	 */
	public function availableMigrations(): array {
		return self::discoverMigrations($this->migrationsDir);
	}

	/**
	 * Migrations recorded as applied in the database. Strictly read-only:
	 * returns an empty list when the tracking table does not exist yet.
	 *
	 * @return list<string>
	 */
	public function appliedMigrations(): array {
		$exists = (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM information_schema.tables
			 WHERE table_schema = DATABASE() AND table_name = ?',
			[self::TRACKING_TABLE]
		);
		if ($exists === 0) {
			return [];
		}

		$names = [];
		foreach ($this->db->fetchAll('SELECT migration FROM ' . self::TRACKING_TABLE . ' ORDER BY migration') as $row) {
			$names[] = (string) $row['migration'];
		}

		return $names;
	}

	/**
	 * Migration files not yet applied to the database, in application order.
	 *
	 * @return list<string>
	 */
	public function pendingMigrations(): array {
		return array_values(array_diff($this->availableMigrations(), $this->appliedMigrations()));
	}

	/**
	 * Applied migrations whose file no longer exists on disk - a renamed or
	 * deleted migration. Reported as a warning; renaming an applied migration
	 * would make it "pending" again under its new name.
	 *
	 * @return list<string>
	 */
	public function missingMigrations(): array {
		return array_values(array_diff($this->appliedMigrations(), $this->availableMigrations()));
	}

	/**
	 * Applies all pending migrations in order and records each one. Creates
	 * the tracking table if it is missing (first run on an existing install).
	 * Aborts on the first failure, leaving the failed migration unrecorded so
	 * a fixed version is retried on the next run.
	 *
	 * @param callable(string): void|null $onApply Called after each applied file.
	 * @return list<string> Migration names actually applied.
	 * @throws RuntimeException On unreadable, empty or failing migration files.
	 */
	public function migrate(?callable $onApply = null): array {
		$this->db->execute(
			'CREATE TABLE IF NOT EXISTS ' . self::TRACKING_TABLE . ' (
				migration  VARCHAR(255) NOT NULL,
				applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (migration)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);

		$applied = [];
		foreach ($this->pendingMigrations() as $name) {
			$path = $this->migrationsDir . '/' . $name;
			$sql  = file_get_contents($path);
			if ($sql === false) {
				throw new RuntimeException("Cannot read migration file: $path");
			}

			$statements = self::splitStatements($sql);
			if ($statements === []) {
				throw new RuntimeException("Migration $name contains no SQL statements.");
			}

			foreach ($statements as $index => $statement) {
				try {
					$this->db->execute($statement);
				} catch (PDOException $e) {
					throw new RuntimeException(
						sprintf('Migration %s failed at statement %d: %s', $name, $index + 1, $e->getMessage()),
						0,
						$e
					);
				}
			}

			$this->db->execute('INSERT INTO ' . self::TRACKING_TABLE . ' (migration) VALUES (?)', [$name]);
			$applied[] = $name;
			if ($onApply !== null) {
				$onApply($name);
			}
		}

		return $applied;
	}
}
