<?php

declare(strict_types=1);

namespace Catenvis;

use RuntimeException;

/**
 * Renders lean PHP templates (*.tpl.php) within a layout.
 */
final class View {
	private string $templateDir;
	private string $baseUrl;
	private Session $session;
	private string $webrootDir;

	/** @var array<string, mixed> Values available to every template. */
	private array $shared = [];

	public function __construct(string $templateDir, string $baseUrl, Session $session, string $webrootDir = '') {
		$this->templateDir = rtrim($templateDir, '/');
		$this->baseUrl     = $baseUrl;
		$this->session     = $session;
		$this->webrootDir  = rtrim($webrootDir, '/');
	}

	/**
	 * Builds an asset URL with a cache-busting version parameter (?v=mtime).
	 */
	public function asset(string $path): string {
		$full = $this->webrootDir . $path;
		$version = ($this->webrootDir !== '' && is_file($full)) ? '?v=' . filemtime($full) : '';

		return $this->baseUrl . $path . $version;
	}

	/**
	 * Sets a value available to all templates (e.g. the current user).
	 */
	public function share(string $key, mixed $value): void {
		$this->shared[$key] = $value;
	}

	/**
	 * Renders a template within the layout and outputs it.
	 *
	 * @param array<string, mixed> $data
	 */
	public function render(string $template, array $data = []): void {
		$content = $this->capture($template, $data);
		$layoutData = array_merge($data, [
			'content'  => $content,
			'flashes'  => $this->session->takeFlashes(),
			'pageTitle' => $data['pageTitle'] ?? 'Catenvis',
		]);
		echo $this->capture('layout', $layoutData);
	}

	/**
	 * Renders a template and returns the result as a string.
	 *
	 * @param array<string, mixed> $data
	 */
	public function capture(string $template, array $data = []): string {
		$file = "{$this->templateDir}/{$template}.tpl.php";
		if (!is_file($file)) {
			throw new RuntimeException("Template not found: $file");
		}

		// Helper functions for templates ($e escapes, $url builds a URL, $asset with version).
		$scope = array_merge($this->shared, $data, [
			'e'     => static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
			'url'   => fn(string $path): string => $this->baseUrl . $path,
			'asset' => fn(string $path): string => $this->asset($path),
		]);

		ob_start();
		(static function (string $file, array $scope): void {
			extract($scope, EXTR_SKIP);
			require $file;
		})($file, $scope);

		return (string) ob_get_clean();
	}

	/**
	 * Builds an absolute URL within the application.
	 */
	public function url(string $path): string {
		return $this->baseUrl . $path;
	}
}
