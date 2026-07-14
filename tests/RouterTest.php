<?php

declare(strict_types=1);

namespace Catenvis\Tests;

use Catenvis\Request;
use Catenvis\Router;
use PHPUnit\Framework\TestCase;

/**
 * Covers route matching: static paths, {name} placeholders (single
 * segment), method matching, anchoring and handler invocation.
 */
final class RouterTest extends TestCase {
	public function testDispatchReturnsFalseWhenNoRouteMatches(): void {
		$router = new Router();
		$router->add('GET', '/series/{id}', static function (): void {});

		self::assertFalse($router->dispatch($this->request('GET', '/unknown')));
	}

	public function testStaticRouteMatchesAndInvokesHandler(): void {
		$router = new Router();
		$called = false;
		$router->add('GET', '/import', static function () use (&$called): void {
			$called = true;
		});

		self::assertTrue($router->dispatch($this->request('GET', '/import')));
		self::assertTrue($called);
	}

	public function testPlaceholderRouteExtractsParameter(): void {
		$router = new Router();
		$captured = null;
		$router->add('GET', '/series/{id}', static function (Request $request, array $params) use (&$captured): void {
			$captured = $params;
		});

		self::assertTrue($router->dispatch($this->request('GET', '/series/5')));
		self::assertSame(['id' => '5'], $captured);
	}

	public function testMultiplePlaceholdersAreExtractedInOrder(): void {
		$router = new Router();
		$captured = null;
		$router->add('GET', '/series/{id}/season/{number}', static function (Request $request, array $params) use (&$captured): void {
			$captured = $params;
		});

		self::assertTrue($router->dispatch($this->request('GET', '/series/5/season/2')));
		self::assertSame(['id' => '5', 'number' => '2'], $captured);
	}

	public function testMethodMismatchDoesNotMatch(): void {
		$router = new Router();
		$router->add('POST', '/follow', static function (): void {});

		self::assertFalse($router->dispatch($this->request('GET', '/follow')));
	}

	public function testMethodRegistrationIsCaseInsensitive(): void {
		$router = new Router();
		$called = false;
		$router->add('get', '/', static function () use (&$called): void {
			$called = true;
		});

		self::assertTrue($router->dispatch($this->request('GET', '/')));
		self::assertTrue($called);
	}

	public function testPlaceholderMatchesSingleSegmentOnly(): void {
		$router = new Router();
		$router->add('GET', '/series/{id}', static function (): void {});

		self::assertFalse($router->dispatch($this->request('GET', '/series/5/extra')));
	}

	public function testStaticRouteIsAnchored(): void {
		$router = new Router();
		$router->add('GET', '/series', static function (): void {});

		self::assertFalse($router->dispatch($this->request('GET', '/series/5')));
	}

	public function testHandlerReceivesTheDispatchedRequest(): void {
		$router = new Router();
		$request = $this->request('GET', '/import');
		$received = null;
		$router->add('GET', '/import', static function (Request $r, array $params) use (&$received): void {
			$received = $r;
		});

		$router->dispatch($request);

		self::assertSame($request, $received);
	}

	public function testFirstMatchingRouteWins(): void {
		$router = new Router();
		$order = [];
		$router->add('GET', '/series/{id}', static function () use (&$order): void {
			$order[] = 'first';
		});
		$router->add('GET', '/series/{slug}', static function () use (&$order): void {
			$order[] = 'second';
		});

		$router->dispatch($this->request('GET', '/series/5'));

		self::assertSame(['first'], $order);
	}

	private function request(string $method, string $path): Request {
		return new Request([], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path]);
	}
}
