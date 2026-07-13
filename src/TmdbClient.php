<?php

declare(strict_types=1);

namespace Catenvis;

use RuntimeException;

/**
 * Minimal curl-based client for the TMDB API (v3 endpoints).
 *
 * Authentication preferably via v4 Read Access Token (Bearer),
 * alternatively via v3 api_key as a query parameter.
 */
final class TmdbClient {
	private const BASE_URL = 'https://api.themoviedb.org/3';

	private string $readAccessToken;
	private string $apiKey;
	private string $language;

	/**
	 * @param array<string, mixed> $config TMDB configuration.
	 */
	public function __construct(array $config) {
		$this->readAccessToken = (string) ($config['read_access_token'] ?? '');
		$this->apiKey          = (string) ($config['api_key'] ?? '');
		$this->language        = (string) ($config['language'] ?? 'de-DE');

		if ($this->readAccessToken === '' && $this->apiKey === '') {
			throw new RuntimeException('TMDB: neither read_access_token nor api_key configured.');
		}
	}

	/**
	 * ISO-639-1 code of the configured base request language (e.g. 'de-DE' -> 'de').
	 */
	public function baseLanguageCode(): string {
		return self::languageCode($this->language);
	}

	/**
	 * Extracts the ISO-639-1 code from a BCP 47 language tag.
	 */
	public static function languageCode(string $language): string {
		$code = strtolower(substr($language, 0, 2));

		return $code !== '' ? $code : 'en';
	}

	/**
	 * Searches series by title; scans all languages and alternative titles.
	 *
	 * @param string|null $language Language of the returned fields (default: configured language).
	 * @return list<array<string, mixed>> Result list (results) from /search/tv.
	 */
	public function search(string $query, ?string $language = null): array {
		$params = ['query' => $query, 'include_adult' => 'false'];
		if ($language !== null) {
			$params['language'] = $language;
		}
		$data = $this->request('/search/tv', $params);
		/** @var list<array<string, mixed>> $results */
		$results = is_array($data['results'] ?? null) ? $data['results'] : [];

		return $results;
	}

	/**
	 * Maps an IMDb ID (e.g. "tt0903747") exactly onto a TMDB series.
	 *
	 * @return array<string, mixed>|null The TMDB series match or null if none exists.
	 */
	public function findSeriesByImdbId(string $imdbId): ?array {
		$data = $this->request('/find/' . rawurlencode($imdbId), ['external_source' => 'imdb_id']);
		$tv = $data['tv_results'] ?? null;
		if (is_array($tv) && isset($tv[0]) && is_array($tv[0])) {
			return $tv[0];
		}

		return null;
	}

	/**
	 * Returns the detail data of a series (/tv/{id}) including external IDs (among them imdb_id).
	 *
	 * @return array<string, mixed>
	 */
	public function tvDetails(int $seriesId): array {
		return $this->request("/tv/$seriesId", ['append_to_response' => 'external_ids,translations']);
	}

	/**
	 * Returns the episodes of a season (/tv/{id}/season/{n}).
	 *
	 * @param string|null $language Language of the returned fields (default: configured language).
	 * @return array<string, mixed>
	 */
	public function season(int $seriesId, int $seasonNumber, ?string $language = null): array {
		$params = [];
		if ($language !== null) {
			$params['language'] = $language;
		}

		return $this->request("/tv/$seriesId/season/$seasonNumber", $params);
	}

	/**
	 * Performs a GET request and decodes the JSON response.
	 *
	 * @param array<string, string> $params Additional query parameters.
	 * @return array<string, mixed>
	 */
	private function request(string $endpoint, array $params = []): array {
		$params['language'] ??= $this->language;
		if ($this->readAccessToken === '' && $this->apiKey !== '') {
			$params['api_key'] = $this->apiKey;
		}

		$url = self::BASE_URL . $endpoint . '?' . http_build_query($params);

		$headers = ['Accept: application/json'];
		if ($this->readAccessToken !== '') {
			$headers[] = 'Authorization: Bearer ' . $this->readAccessToken;
		}

		$attempts = 0;
		$lastError = '';
		while ($attempts < 3) {
			$attempts++;
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_FAILONERROR    => false,
			]);
			$body   = curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$errno  = curl_errno($ch);
			$err    = curl_error($ch);
			curl_close($ch);

			if ($errno !== 0) {
				$lastError = "curl error: $err";
				continue;
			}
			if ($status === 429) {
				$lastError = 'TMDB rate limit (429)';
				continue;
			}
			if ($status < 200 || $status >= 300) {
				throw new RuntimeException("TMDB request failed ($status) for $endpoint");
			}

			$decoded = json_decode((string) $body, true);
			if (!is_array($decoded)) {
				throw new RuntimeException("TMDB: invalid JSON response for $endpoint");
			}

			/** @var array<string, mixed> $decoded */
			return $decoded;
		}

		throw new RuntimeException("TMDB request failed after $attempts attempts: $lastError");
	}
}
