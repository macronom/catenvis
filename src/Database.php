<?php

declare(strict_types=1);

namespace Catenvis;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Uses prepared statements exclusively.
 */
final class Database {
	private PDO $pdo;

	/**
	 * @param array<string, mixed> $config DB configuration (host, port, database, user, password, charset).
	 */
	public function __construct(array $config) {
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			(string) $config['host'],
			(int) ($config['port'] ?? 3306),
			(string) $config['database'],
			(string) ($config['charset'] ?? 'utf8mb4')
		);

		$this->pdo = new PDO($dsn, (string) $config['user'], (string) $config['password'], [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		]);
	}

	/**
	 * Runs a statement and returns the PDOStatement.
	 *
	 * @param array<string, mixed>|list<mixed> $params Bound parameters.
	 */
	public function run(string $sql, array $params = []): PDOStatement {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);

		return $stmt;
	}

	/**
	 * Returns all result rows of a query.
	 *
	 * @param array<string, mixed>|list<mixed> $params
	 * @return list<array<string, mixed>>
	 */
	public function fetchAll(string $sql, array $params = []): array {
		/** @var list<array<string, mixed>> $rows */
		$rows = $this->run($sql, $params)->fetchAll();

		return $rows;
	}

	/**
	 * Returns the first result row or null.
	 *
	 * @param array<string, mixed>|list<mixed> $params
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(string $sql, array $params = []): ?array {
		$row = $this->run($sql, $params)->fetch();

		return $row === false ? null : $row;
	}

	/**
	 * Returns the first column value of the first row.
	 *
	 * @param array<string, mixed>|list<mixed> $params
	 */
	public function fetchValue(string $sql, array $params = []): mixed {
		$value = $this->run($sql, $params)->fetchColumn();

		return $value === false ? null : $value;
	}

	/**
	 * Runs a writing statement and returns the number of affected rows.
	 *
	 * @param array<string, mixed>|list<mixed> $params
	 */
	public function execute(string $sql, array $params = []): int {
		return $this->run($sql, $params)->rowCount();
	}

	/**
	 * Returns the last inserted auto-increment ID.
	 */
	public function lastInsertId(): int {
		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * Runs a callback within a transaction.
	 *
	 * @template T
	 * @param callable(self): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed {
		$this->pdo->beginTransaction();
		try {
			$result = $callback($this);
			$this->pdo->commit();

			return $result;
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			throw $e;
		}
	}
}
