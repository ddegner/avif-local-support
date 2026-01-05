<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

// Prevent direct access.
\defined('ABSPATH') || exit;

final class Support
{




	private array $fileCache = array();
	private array $uploadsInfo = array();

	public function init(): void
	{
		$this->uploadsInfo = \wp_upload_dir();
		$this->fileCache = \get_transient('aviflosu_file_cache') ?: array();
		add_filter('wp_get_attachment_image', array($this, 'wrapAttachment'), 10, 5);
		add_filter('the_content', array($this, 'wrapContentImages'));
		add_filter('post_thumbnail_html', array($this, 'wrapContentImages'));
		add_filter('render_block', array($this, 'renderBlock'), 10, 2);
		add_action('shutdown', array($this, 'saveCache'));

		// Enqueue ThumbHash decoder if enabled - inline in head for early execution.
		if (ThumbHash::isEnabled() && !\is_admin()) {
			add_action('wp_head', array($this, 'inlineThumbHashDecoder'), 1);
		}
	}

	/**
	 * Inline the ThumbHash decoder script in the head for early execution.
	 * This ensures placeholders appear before images start loading.
	 */
	public function inlineThumbHashDecoder(): void
	{
		$scriptPath = AVIFLOSU_PLUGIN_DIR . 'assets/thumbhash-decoder.min.js';
		if (!file_exists($scriptPath)) {
			return;
		}
		$script = file_get_contents($scriptPath);
		if ($script) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline JS from trusted local file.
			echo '<script id="aviflosu-thumbhash-decoder">' . $script . '</script>' . "\n";
		}

		// Inject CSS for fading if enabled.
		if ((bool) get_option('aviflosu_lqip_fade', true)) {
			// CSS explanation:
			// 1. img[data-thumbhash] starts with opacity 1, scale 1 and transitions.
			// 2. When inside .thumbhash-loading parent OR img itself has the class, opacity is 0 and scale is 1.05.
			// 3. When JS removes .thumbhash-loading, image fades in (opacity 0 -> 1) and scales down (1.05 -> 1).
			// 4. The .thumbhash-loading element has blur(5px) applied, making the ThumbHash background
			//    (which is a data URL set via inline style) appear blurred for a smooth blur-up effect.
			$css = 'img[data-thumbhash]{opacity:1;transform:scale(1);transition:opacity 400ms ease-out,transform 400ms ease-out;}'
				. '.thumbhash-loading img[data-thumbhash],'
				. 'img.thumbhash-loading[data-thumbhash]{opacity:0;transform:scale(1.05);}'
				. '.thumbhash-loading,img.thumbhash-loading{filter:blur(5px);}';
			// Optionally render placeholders as sharp pixels instead of smooth blur.
			if ((bool) get_option('aviflosu_lqip_pixelated', false)) {
				$css .= '.thumbhash-loading,img.thumbhash-loading{image-rendering:pixelated;}';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS string, no user input.
			echo '<style id="aviflosu-thumbhash-fade">' . $css . '</style>' . "\n";
		}
	}

	public function wrapAttachment(string $html, int $attachmentId, $size, bool $icon, array $attr): string
	{
		if (str_contains($html, '<picture')) {
			return $html;
		}

		$mime = \get_post_mime_type($attachmentId);
		if (!\is_string($mime) || !\in_array($mime, array('image/jpeg', 'image/jpg'), true)) {
			return $html;
		}

		$imageSrc = \wp_get_attachment_image_src($attachmentId, $size);
		if (!$imageSrc || !\is_array($imageSrc) || empty($imageSrc[0])) {
			return $html;
		}

		$avifSrc = $this->avifUrlFor($imageSrc[0]);

		$srcset = \wp_get_attachment_image_srcset($attachmentId, $size);
		$avifSrcset = ($srcset && $avifSrc) ? $this->convertSrcsetToAvif($srcset) : '';
		$sizes = \wp_get_attachment_image_sizes($attachmentId, $size) ?: '';

		// Get ThumbHash for LQIP if enabled.
		$sizeName = is_array($size) ? 'full' : (string) $size;
		$thumbhash = ThumbHash::getForAttachment($attachmentId, $sizeName);

		return $this->pictureMarkup($html, $avifSrc, $avifSrcset, $sizes, $thumbhash);
	}

	public function wrapContentImages(string $content): string
	{
		if (\is_admin() || \wp_doing_ajax() || (\defined('REST_REQUEST') && REST_REQUEST)) {
			return $content;
		}
		if (!str_contains($content, '<img')) {
			return $content;
		}
		return $this->wrapHtmlImages($content);
	}

	public function renderBlock(string $block_content, array $block): string
	{
		$name = $block['blockName'] ?? '';
		if ('core/image' !== $name && 'core/gallery' !== $name) {
			return $block_content;
		}
		if ('' === $block_content || false === strpos($block_content, '<img')) {
			return $block_content;
		}
		return $this->wrapHtmlImages($block_content);
	}

	private function avifUrlFor(string $jpegUrl): ?string
	{
		if (!$this->isUploadsImage($jpegUrl)) {
			return null;
		}
		$parts = \wp_parse_url($jpegUrl);
		if (false === $parts || empty($parts['path'])) {
			return null;
		}
		$path = $parts['path'];
		if (!\preg_match('/\.(jpe?g)$/i', $path)) {
			return null;
		}
		$avifPath = (string) \preg_replace('/\.(jpe?g)$/i', '.avif', $path);
		$reconstructed = ($parts['scheme'] ?? '') !== ''
			? ($parts['scheme'] . '://')
			: '';
		if (!empty($parts['host'])) {
			$reconstructed .= $parts['host'];
		}
		if (!empty($parts['port'])) {
			$reconstructed .= ':' . $parts['port'];
		}
		$reconstructed .= $avifPath;
		if (!empty($parts['query'])) {
			$reconstructed .= '?' . $parts['query'];
		}
		if (!empty($parts['fragment'])) {
			$reconstructed .= '#' . $parts['fragment'];
		}

		$relative = str_replace($this->uploadsInfo['baseurl'] ?? '', '', (string) $reconstructed);
		$avifLocal = ($this->uploadsInfo['basedir'] ?? '') . $relative;
		return $this->avifExists($avifLocal) ? $reconstructed : null;
	}

	private function isUploadsImage(string $src): bool
	{
		$uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
		return '' !== $uploadsUrl && str_starts_with($src, $uploadsUrl);
	}

	private function avifExists(string $filePath): bool
	{
		// Only cache positive (true) results. Negative results should always be re-checked
		// because files may be converted after the cache was populated.
		if (isset($this->fileCache[$filePath]) && true === $this->fileCache[$filePath]) {
			return true;
		}
		$exists = file_exists($filePath);
		if ($exists) {
			$this->fileCache[$filePath] = true;
		}
		return $exists;
	}

	private function convertSrcsetToAvif(string $srcset): string
	{
		$parts = array_map('trim', explode(',', $srcset));
		$out = array();
		foreach ($parts as $part) {
			if ('' === $part) {
				continue;
			}
			$pieces = preg_split('/\s+/', trim($part), 2);
			$url = $pieces[0];
			$descriptor = $pieces[1] ?? '';
			$avifUrl = $this->avifUrlFor($url);
			if ($avifUrl) {
				$out[] = trim($avifUrl . ' ' . $descriptor);
			}
		}
		return implode(', ', $out);
	}

	private function pictureMarkup(string $originalHtml, ?string $avifSrc, string $avifSrcset = '', string $sizes = '', ?string $thumbhash = null): string
	{
		if ((!$avifSrc || '' === $avifSrc) && (!$thumbhash || '' === $thumbhash)) {
			return $originalHtml;
		}
		$srcset = '' !== $avifSrcset ? $avifSrcset : $avifSrc;
		$sizesAttr = '' !== $sizes ? sprintf(' sizes="%s"', \esc_attr($sizes)) : '';

		// Add ThumbHash data attribute to img tag if available.
		$imgHtml = $originalHtml;
		if ($thumbhash !== null && $thumbhash !== '') {
			$imgHtml = preg_replace(
				'/<img\s/',
				'<img data-thumbhash="' . \esc_attr($thumbhash) . '" ',
				$originalHtml,
				1
			);
			if ($imgHtml === null) {
				$imgHtml = $originalHtml;
			}
		}

		if (!$avifSrc || '' === $avifSrc) {
			return $imgHtml;
		}

		// Only wrap in <picture> if AVIF serving is enabled.
		$avifEnabled = (bool) \get_option('aviflosu_enable_support', true);
		if (!$avifEnabled) {
			return $imgHtml;
		}

		return sprintf('<picture><source type="image/avif" srcset="%s"%s>%s</picture>', \esc_attr($srcset), $sizesAttr, $imgHtml);
	}

	private function isInsidePicture(\DOMNode $node): bool
	{
		$parent = $node->parentNode;
		while ($parent) {
			if ($parent instanceof \DOMElement && 'picture' === strtolower($parent->nodeName)) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	private function wrapImgNodeToPicture(\DOMDocument $dom, \DOMElement $img, string $avifSrcset, string $sizes, ?string $thumbhash = null): void
	{
		// Add ThumbHash data attribute if available
		if ($thumbhash !== null && $thumbhash !== '') {
			$img->setAttribute('data-thumbhash', $thumbhash);
		}

		if ('' === $avifSrcset) {
			return;
		}

		// Only wrap in <picture> if AVIF serving is enabled.
		$avifEnabled = (bool) \get_option('aviflosu_enable_support', true);
		if (!$avifEnabled) {
			return;
		}

		$picture = $dom->createElement('picture');
		$source = $dom->createElement('source');
		$source->setAttribute('type', 'image/avif');
		$source->setAttribute('srcset', $avifSrcset);
		if ('' !== $sizes) {
			$source->setAttribute('sizes', $sizes);
		}
		$picture->appendChild($source);
		$img->parentNode?->replaceChild($picture, $img);
		$picture->appendChild($img);
	}

	private function wrapHtmlImages(string $htmlInput): string
	{
		$html = '<?xml encoding="utf-8" ?>' . $htmlInput;
		$dom = new \DOMDocument();
		\libxml_use_internal_errors(true);
		if (!$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
			\libxml_clear_errors();
			return $htmlInput;
		}
		\libxml_clear_errors();

		$imgs = $dom->getElementsByTagName('img');
		$toProcess = array();
		foreach ($imgs as $img) {
			$toProcess[] = $img;
		}
		foreach ($toProcess as $img) {
			if (!($img instanceof \DOMElement)) {
				continue;
			}
			if ($this->isInsidePicture($img)) {
				continue;
			}
			$src = (string) $img->getAttribute('src');
			$avifUrl = $this->avifUrlFor($src);

			// Extract attachment ID from wp-image-{ID} class for ThumbHash lookup
			$thumbhash = null;
			if (ThumbHash::isEnabled()) {
				$class = (string) $img->getAttribute('class');
				if (preg_match('/wp-image-(\d+)/', $class, $matches)) {
					$attachmentId = (int) $matches[1];
					$thumbhash = ThumbHash::getForAttachment($attachmentId, 'full');
				}
			}

			if (!$avifUrl && !$thumbhash) {
				continue;
			}

			// Check if the image is wrapped in a link to a JPEG that also has an AVIF version.
			$parent = $img->parentNode;
			if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'a') {
				$href = (string) $parent->getAttribute('href');
				// Only process if href looks like a JPEG
				if (preg_match('/\.(jpe?g)$/i', $href)) {
					$avifHref = $this->avifUrlFor($href);
					if ($avifHref) {
						$parent->setAttribute('href', $avifHref);
					}
				}
			}

			$srcset = (string) $img->getAttribute('srcset');
			$sizes = (string) $img->getAttribute('sizes');
			$avifSrcset = ('' !== $srcset && $avifUrl) ? $this->convertSrcsetToAvif($srcset) : ($avifUrl ?: '');

			$this->wrapImgNodeToPicture($dom, $img, $avifSrcset, $sizes, $thumbhash);
		}

		// Cleanup: Remove the XML declaration node we added to force UTF-8
		while ($dom->firstChild instanceof \DOMProcessingInstruction && $dom->firstChild->nodeName === 'xml') {
			$dom->removeChild($dom->firstChild);
		}

		$out = $dom->saveHTML();
		return \is_string($out) && $out !== '' ? $out : $htmlInput;
	}

	public function saveCache(): void
	{
		// Only save positive (true) entries - filter out any false entries that might have snuck in.
		$positiveOnly = array_filter($this->fileCache, fn($v) => true === $v);
		set_transient('aviflosu_file_cache', $positiveOnly, (int) get_option('aviflosu_cache_duration', 3600));
	}
}
