<?php

declare(strict_types=1);

namespace Catenvis;

/**
 * Minimal UI translator: flat JSON catalogs (lang/{lang}.json) mapping the
 * English source text to its translation. English is the source language and
 * needs no catalog file. Lookups are fault tolerant: a missing entry, missing
 * file or broken JSON falls back to the English source text.
 */
final class Translator {
	private string $projectDir;
	private string $lang;

	/** @var array<string, string>|null Lazily loaded catalog (null = not loaded yet). */
	private ?array $catalog = null;

	public function __construct(string $projectDir, string $lang) {
		$this->projectDir = $projectDir;
		$this->lang       = $lang;
	}

	/**
	 * Translates an English source text; optional arguments are applied via
	 * vsprintf (positional placeholders like %1$s allow word-order changes).
	 */
	public function translate(string $text, mixed ...$args): string {
		$translated = $this->catalog()[$text] ?? $text;

		return $args === [] ? $translated : vsprintf($translated, $args);
	}

	/**
	 * Loads the catalog on first use.
	 *
	 * @return array<string, string>
	 */
	private function catalog(): array {
		if ($this->catalog !== null) {
			return $this->catalog;
		}

		$this->catalog = [];
		if ($this->lang === 'en') {
			return $this->catalog;
		}

		$file = $this->projectDir . '/lang/' . $this->lang . '.json';
		if (!is_file($file)) {
			return $this->catalog;
		}

		$decoded = json_decode((string) file_get_contents($file), true);
		if (!is_array($decoded)) {
			error_log("Translator: invalid JSON in $file - falling back to English.");
			return $this->catalog;
		}

		foreach ($decoded as $key => $value) {
			// Keys like "__language__" are catalog metadata, not translations.
			if (is_string($key) && str_starts_with($key, '__')) {
				continue;
			}
			if (is_string($key) && is_string($value) && $value !== '') {
				$this->catalog[$key] = $value;
			}
		}

		return $this->catalog;
	}
}
