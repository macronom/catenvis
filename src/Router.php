<?php

declare(strict_types=1);

namespace Catenvis;

/**
 * Simple router. Supports static paths and named placeholders
 * of the form {name} (matches one segment without a slash).
 */
final class Router {
	/** @var list<array{method: string, regex: string, params: list<string>, handler: callable}> */
	private array $routes = [];

	/**
	 * Registers a route.
	 *
	 * @param string   $method  HTTP method (GET, POST).
	 * @param string   $pattern Path pattern, e.g. "/series/{id}".
	 * @param callable $handler Call: fn(Request, array<string,string> $params): void
	 */
	public function add(string $method, string $pattern, callable $handler): void {
		$params = [];
		$regex  = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$params): string {
			$params[] = $m[1];

			return '([^/]+)';
		}, $pattern);

		$this->routes[] = [
			'method'  => strtoupper($method),
			'regex'   => '#^' . $regex . '$#',
			'params'  => $params,
			'handler' => $handler,
		];
	}

	/**
	 * Finds the matching route and calls its handler.
	 * Returns false when no route matches (404).
	 */
	public function dispatch(Request $request): bool {
		foreach ($this->routes as $route) {
			if ($route['method'] !== $request->method()) {
				continue;
			}
			if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
				continue;
			}

			$values = array_slice($matches, 1);
			/** @var array<string, string> $params */
			$params = array_combine($route['params'], $values) ?: [];
			($route['handler'])($request, $params);

			return true;
		}

		return false;
	}
}
