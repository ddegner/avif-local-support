<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

// Prevent direct access.
\defined('ABSPATH') || exit;

/**
 * Handles AVIF replacement for CSS background images.
 *
 * Uses output buffering to scan page HTML for background-image CSS rules
 * containing JPEG URLs, then injects style overrides with AVIF equivalents.
 */
final class BackgroundImages
{

	/**
	 * Upload directory information.
	 *
	 * @var array{baseurl: string, basedir: string}
	 */
	private array $uploadsInfo = array();

	/**
	 * Whether buffering has been started.
	 */
	private bool $bufferingStarted = false;

	/**
	 * Collected JPEG to AVIF URL mappings.
	 *
	 * @var array<string, string>
	 */
	private array $urlMappings = array();

	/**
	 * CSS selector to AVIF URL mappings for external stylesheets.
	 *
	 * @var array<string, string>
	 */
	private array $selectorOverrides = array();

	/**
	 * Check if background image AVIF replacement is enabled.
	 */
	public static function isEnabled(): bool
	{
		return (bool) \get_option('aviflosu_enable_background_images', true);
	}

	/**
	 * Initialize the background images handler.
	 */
	public function init(): void
	{
		if (!\is_admin() && self::isEnabled()) {
			$this->uploadsInfo = \wp_upload_dir();
			// Start output buffering early in template loading
			add_action('template_redirect', array($this, 'startBuffering'), 1);
		}
	}

	/**
	 * Start output buffering to capture the page HTML.
	 */
	public function startBuffering(): void
	{
		// Don't buffer admin, AJAX, REST, or feed requests
		if (\is_admin() || \wp_doing_ajax() || (\defined('REST_REQUEST') && REST_REQUEST) || \is_feed()) {
			return;
		}

		$this->bufferingStarted = true;
		ob_start(array($this, 'processBuffer'));
	}

	/**
	 * Process the output buffer and inject AVIF overrides.
	 *
	 * @param string $buffer The complete page HTML.
	 * @return string Modified HTML with AVIF CSS overrides injected.
	 */
	public function processBuffer(string $buffer): string
	{
		if ('' === $buffer || !$this->bufferingStarted) {
			return $buffer;
		}

		// Skip if no </head> tag (not a full HTML document)
		if (false === stripos($buffer, '</head>')) {
			return $buffer;
		}

		// Early exit: skip if no JPEG background images in any CSS
		if (false === stripos($buffer, '.jpg') && false === stripos($buffer, '.jpeg')) {
			return $buffer;
		}

		// Process inline styles and style blocks
		$this->processInlineStyles($buffer);
		$this->processStyleBlocks($buffer);

		// Process external stylesheets (linked CSS files)
		$this->processLinkedStylesheets($buffer);

		// Generate and inject the override CSS
		$overrideCss = $this->generateOverrideCss();
		if ('' === $overrideCss) {
			return $buffer;
		}

		// Inject before </head>
		$styleTag = '<style id="aviflosu-bg-overrides">' . $overrideCss . '</style>';
		$buffer = str_ireplace('</head>', $styleTag . '</head>', $buffer);

		return $buffer;
	}

	/**
	 * Process inline style attributes for background-image URLs.
	 *
	 * @param string $html Page HTML.
	 */
	private function processInlineStyles(string $html): void
	{
		// Match style attributes containing background-image
		$pattern = '/style\s*=\s*["\']([^"\']*background[^"\']*)["\']/i';
		if (!preg_match_all($pattern, $html, $matches)) {
			return;
		}

		foreach ($matches[1] as $styleValue) {
			$this->extractBackgroundUrls($styleValue);
		}
	}

	/**
	 * Process <style> blocks for background-image URLs.
	 *
	 * @param string $html Page HTML.
	 */
	private function processStyleBlocks(string $html): void
	{
		// Match <style> tag contents
		$pattern = '/<style[^>]*>(.*?)<\/style>/is';
		if (!preg_match_all($pattern, $html, $matches)) {
			return;
		}

		foreach ($matches[1] as $cssContent) {
			$this->processCssContent($cssContent);
		}
	}

	/**
	 * Process linked external stylesheets.
	 *
	 * @param string $html Page HTML.
	 */
	private function processLinkedStylesheets(string $html): void
	{
		// Match <link rel="stylesheet" href="..."> tags
		$pattern = '/<link[^>]+rel\s*=\s*["\']stylesheet["\'][^>]+href\s*=\s*["\']([^"\']+)["\'][^>]*>/i';
		if (!preg_match_all($pattern, $html, $matches)) {
			// Also try href before rel
			$pattern2 = '/<link[^>]+href\s*=\s*["\']([^"\']+)["\'][^>]+rel\s*=\s*["\']stylesheet["\'][^>]*>/i';
			if (!preg_match_all($pattern2, $html, $matches)) {
				return;
			}
		}

		$uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
		$uploadsDir = $this->uploadsInfo['basedir'] ?? '';

		foreach ($matches[1] as $cssUrl) {
			// Only process CSS files from our uploads directory (page builder CSS)
			if ('' === $uploadsUrl || !str_contains($cssUrl, $uploadsUrl)) {
				// Also check for /wp-content/uploads/ path pattern
				if (!str_contains($cssUrl, '/wp-content/uploads/')) {
					continue;
				}
			}

			// Convert URL to local path
			$cssPath = $this->urlToLocalPath($cssUrl);
			if (null === $cssPath || !file_exists($cssPath)) {
				continue;
			}

			// Read and process the CSS file
			$cssContent = file_get_contents($cssPath);
			if (false !== $cssContent && '' !== $cssContent) {
				$this->processCssContent($cssContent);
			}
		}
	}

	/**
	 * Convert a URL to a local file path.
	 *
	 * @param string $url The URL to convert.
	 * @return string|null The local file path, or null if not in uploads.
	 */
	private function urlToLocalPath(string $url): ?string
	{
		$uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
		$uploadsDir = $this->uploadsInfo['basedir'] ?? '';

		if ('' === $uploadsUrl || '' === $uploadsDir) {
			return null;
		}

		// Strip query string from URL
		$url = strtok($url, '?');
		if (false === $url || '' === $url) {
			return null;
		}

		// Handle absolute URLs
		if (str_starts_with($url, $uploadsUrl)) {
			$relativePath = substr($url, strlen($uploadsUrl));
			return $uploadsDir . $relativePath;
		}

		// Handle relative URLs - try to find /wp-content/uploads/ segment
		$uploadPathSegment = '/wp-content/uploads/';
		$pos = strpos($url, $uploadPathSegment);
		if (false !== $pos) {
			$relativePath = substr($url, $pos + strlen($uploadPathSegment));
			return $uploadsDir . '/' . ltrim($relativePath, '/');
		}

		return null;
	}

	/**
	 * Process CSS content and extract background images with their selectors.
	 *
	 * @param string $css CSS content to process.
	 */
	private function processCssContent(string $css): void
	{
		// Pattern to match CSS rules with background-image containing JPEG URLs
		// Captures: selector { ... background(-image): url(*.jpg) ... }
		$rulePattern = '/([^{}]+)\{([^{}]*background(?:-image)?\s*:\s*[^;}]*url\s*\([^)]+\.jpe?g[^)]*\)[^;}]*)[;}]/i';

		if (!preg_match_all($rulePattern, $css, $ruleMatches, PREG_SET_ORDER)) {
			return;
		}

		foreach ($ruleMatches as $match) {
			$selector = trim($match[1]);
			$declarationBlock = $match[2];

			// Extract the background-image URL from this declaration
			$urlPattern = '/background(?:-image)?\s*:\s*[^;]*url\s*\(\s*["\']?([^"\')\s?#]+\.jpe?g)(?:[?#][^"\')\s]*)?["\']?\s*\)/i';
			if (preg_match($urlPattern, $declarationBlock, $urlMatch)) {
				$jpegUrl = $this->resolveUrl($urlMatch[1]);
				$avifUrl = $this->getAvifUrl($jpegUrl);

				if (null !== $avifUrl) {
					// Store selector -> AVIF URL mapping
					$this->selectorOverrides[$selector] = $avifUrl;
				}
			}
		}

		// Also extract standalone URLs for inline style processing
		$this->extractBackgroundUrls($css);
	}

	/**
	 * Extract background-image URLs from CSS text.
	 *
	 * @param string $css CSS text to scan.
	 */
	private function extractBackgroundUrls(string $css): void
	{
		// Match background-image: url(...) or background: ... url(...)
		$pattern = '/background(?:-image)?\s*:\s*[^;]*url\s*\(\s*["\']?([^"\')\s?#]+\.jpe?g)(?:[?#][^"\')\s]*)?["\']?\s*\)/i';

		if (!preg_match_all($pattern, $css, $matches)) {
			return;
		}

		foreach ($matches[1] as $jpegUrl) {
			$resolvedUrl = $this->resolveUrl($jpegUrl);
			$avifUrl = $this->getAvifUrl($resolvedUrl);
			if (null !== $avifUrl) {
				$this->urlMappings[$resolvedUrl] = $avifUrl;
			}
		}
	}

	/**
	 * Resolve a potentially relative URL to an absolute URL.
	 *
	 * @param string $url The URL to resolve.
	 * @return string The resolved absolute URL.
	 */
	private function resolveUrl(string $url): string
	{
		// Already absolute
		if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
			return $url;
		}

		// Relative to site
		if (str_starts_with($url, '/')) {
			return \home_url($url);
		}

		// Relative path - assume relative to uploads
		$uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
		return $uploadsUrl . '/' . ltrim($url, '/');
	}

	/**
	 * Get the AVIF URL for a JPEG URL if the AVIF exists.
	 *
	 * @param string $jpegUrl The JPEG URL.
	 * @return string|null The AVIF URL, or null if AVIF doesn't exist.
	 */
	private function getAvifUrl(string $jpegUrl): ?string
	{
		$uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
		$uploadsDir = $this->uploadsInfo['basedir'] ?? '';

		if ('' === $uploadsUrl || '' === $uploadsDir) {
			return null;
		}

		// Strip query string and fragment from URL
		$jpegUrlClean = preg_replace('/[?#].*$/', '', $jpegUrl);
		if ('' === $jpegUrlClean) {
			return null;
		}

		// Check if URL is in uploads directory
		if (!str_contains($jpegUrlClean, $uploadsUrl) && !str_contains($jpegUrlClean, '/wp-content/uploads/')) {
			return null;
		}

		// Build AVIF URL
		$avifUrl = (string) preg_replace('/\.jpe?g$/i', '.avif', $jpegUrlClean);

		// Build local path to check existence
		$localPath = $this->urlToLocalPath($avifUrl);
		if (null === $localPath) {
			// Try alternative path resolution
			$relativePath = str_replace($uploadsUrl, '', $avifUrl);
			$localPath = $uploadsDir . $relativePath;
		}

		// Check if AVIF file exists
		if (!file_exists($localPath)) {
			return null;
		}

		return $avifUrl;
	}

	/**
	 * Generate the CSS override rules.
	 *
	 * @return string CSS rules to inject.
	 */
	private function generateOverrideCss(): string
	{
		$rules = array();

		// Add selector-based overrides (from external stylesheets)
		foreach ($this->selectorOverrides as $selector => $avifUrl) {
			// Sanitize selector to prevent XSS (strip any HTML tags)
			$safeSelector = wp_strip_all_tags(trim($selector));
			if ('' === $safeSelector) {
				continue;
			}
			// Add !important to ensure override
			$rules[] = $safeSelector . '{background-image:url("' . \esc_url($avifUrl) . '") !important}';
		}

		return implode('', $rules);
	}
}
