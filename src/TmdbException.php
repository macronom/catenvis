<?php

declare(strict_types=1);

namespace Catenvis;

use RuntimeException;
use Throwable;

/**
 * Thrown by TmdbClient on a failed API request. Carries the HTTP status code
 * (0 for transport-level failures) so callers can tell a permanent "gone"
 * (404/410) from a transient error without parsing the message.
 */
final class TmdbException extends RuntimeException {
	public function __construct(string $message, private readonly int $statusCode = 0, ?Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * HTTP status of the failed response, or 0 for a transport-level failure
	 * (network error, timeout, retries exhausted).
	 */
	public function statusCode(): int {
		return $this->statusCode;
	}

	/**
	 * Whether the series/resource is permanently gone from TMDB (removed or
	 * merged) - as opposed to a transient failure that should be retried.
	 */
	public function isGone(): bool {
		return $this->statusCode === 404 || $this->statusCode === 410;
	}
}
