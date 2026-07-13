<?php

declare(strict_types=1);

namespace Catenvis;

/**
 * Type-safe access to request data (query, body, method, path).
 */
final class Request {
	/** @var array<string, mixed> */
	private array $query;
	/** @var array<string, mixed> */
	private array $body;
	private string $method;
	private string $path;

	/**
	 * @param array<string, mixed> $query  $_GET
	 * @param array<string, mixed> $body   $_POST
	 * @param array<string, mixed> $server $_SERVER
	 * @param string               $basePath Base URL path that is stripped from the path.
	 */
	public function __construct(array $query, array $body, array $server, string $basePath = '') {
		$this->query  = $query;
		$this->body   = $body;
		$this->method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

		$uri  = (string) ($server['REQUEST_URI'] ?? '/');
		$path = parse_url($uri, PHP_URL_PATH);
		$path = is_string($path) ? $path : '/';

		if ($basePath !== '' && str_starts_with($path, $basePath)) {
			$path = substr($path, strlen($basePath));
		}

		$this->path = '/' . trim(rawurldecode($path), '/');
	}

	/**
	 * Creates a Request instance from the PHP superglobals.
	 */
	public static function fromGlobals(string $basePath = ''): self {
		return new self($_GET, $_POST, $_SERVER, $basePath);
	}

	public function method(): string {
		return $this->method;
	}

	public function isPost(): bool {
		return $this->method === 'POST';
	}

	/**
	 * Normalized path without base URL, without trailing slash (except "/").
	 */
	public function path(): string {
		return $this->path;
	}

	public function getString(string $key, string $default = ''): string {
		$value = $this->body[$key] ?? $this->query[$key] ?? null;

		return is_scalar($value) ? trim((string) $value) : $default;
	}

	public function getInt(string $key, int $default = 0): int {
		$value = $this->body[$key] ?? $this->query[$key] ?? null;

		return is_scalar($value) && is_numeric($value) ? (int) $value : $default;
	}

	public function getBool(string $key): bool {
		$value = $this->body[$key] ?? $this->query[$key] ?? null;

		return in_array($value, ['1', 1, true, 'true', 'on'], true);
	}

	/**
	 * Returns an array of integer values (e.g. checkbox lists).
	 *
	 * @return list<int>
	 */
	public function getIntList(string $key): array {
		$value = $this->body[$key] ?? $this->query[$key] ?? null;
		if (!is_array($value)) {
			return [];
		}

		$result = [];
		foreach ($value as $item) {
			if (is_scalar($item) && is_numeric($item)) {
				$result[] = (int) $item;
			}
		}

		return $result;
	}
}
