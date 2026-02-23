<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

// Prevent direct access.
\defined( 'ABSPATH' ) || exit;

final class Support {





	private array $fileCache   = array();
	private array $uploadsInfo = array();

	public function init(): void {

		$this->uploadsInfo = \wp_upload_dir();
		$this->fileCache   = \get_transient( 'aviflosu_file_cache' ) ?: array();
		add_filter( 'wp_get_attachment_image', array( $this, 'wrapAttachment' ), 10, 5 );
		// Priority 15 runs AFTER WordPress adds srcset via wp_filter_content_tags at priority 10.
		add_filter( 'the_content', array( $this, 'wrapContentImages' ), 15 );
		add_filter( 'post_thumbnail_html', array( $this, 'wrapContentImages' ), 15 );
		add_action( 'shutdown', array( $this, 'saveCache' ) );
		add_action( 'wp_head', array( $this, 'printEnviraAvifSrcSwapScript' ), 5 );

		// Enqueue ThumbHash decoder if enabled - inline in head for early execution.
		if ( ThumbHash::isEnabled() && ! \is_admin() ) {
			add_action( 'wp_head', array( $this, 'inlineThumbHashDecoder' ), 1 );
		}
	}

	/**
	 * Envira keeps JPG in img[src] for lazy placeholders.
	 * For AVIF-capable browsers, promote data-envira-src AVIF into img[src]
	 * so thumbnails do not fetch JPG first.
	 */
	public function printEnviraAvifSrcSwapScript(): void {
		if ( \is_admin() || ! (bool) \get_option( 'aviflosu_enable_support', true ) ) {
			return;
		}
		?>
		<script id="aviflosu-envira-src-swap">
		(function () {
			var supportsAvif = false;
			try {
				var canvas = document.createElement('canvas');
				if (canvas.getContext && canvas.toDataURL) {
					supportsAvif = canvas.toDataURL('image/avif').indexOf('data:image/avif') === 0;
				}
			} catch (e) {}
			if (!supportsAvif) {
				return;
			}

			function swap(root) {
				var scope = root || document;
				var imgs = scope.querySelectorAll('img.envira-gallery-image[data-envira-src$=".avif"]');
				for (var i = 0; i < imgs.length; i++) {
					var img = imgs[i];
					var avif = img.getAttribute('data-envira-src');
					if (!avif) {
						continue;
					}
					if (img.getAttribute('src') !== avif) {
						img.setAttribute('src', avif);
					}
				}
			}

			swap(document);
			document.addEventListener('DOMContentLoaded', function () { swap(document); });
			var mo = new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					for (var j = 0; j < mutations[i].addedNodes.length; j++) {
						var node = mutations[i].addedNodes[j];
						if (node && node.nodeType === 1) {
							swap(node);
						}
					}
				}
			});
			mo.observe(document.documentElement, { childList: true, subtree: true });
		}());
		</script>
		<?php
	}

	/**
	 * Inline the ThumbHash decoder script in the head for early execution.
	 * This ensures placeholders appear before images start loading.
	 */
	public function inlineThumbHashDecoder(): void {
		$scriptPath = AVIFLOSU_PLUGIN_DIR . 'assets/thumbhash-decoder.min.js';
		if ( ! file_exists( $scriptPath ) ) {
			return;
		}
		$script = file_get_contents( $scriptPath );
		if ( $script ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline JS from trusted local file.
			echo '<script id="aviflosu-thumbhash-decoder">' . $script . '</script>' . "\n";
		}

		// Inject CSS for fading if enabled.
		if ( (bool) get_option( 'aviflosu_lqip_fade', true ) ) {
			// CSS explanation:
			// 1. img[data-thumbhash] starts visible (opacity 1) with a transition.
			// 2. When .thumbhash-loading is applied, the image is hidden (opacity 0).
			// 3. When JS removes .thumbhash-loading, the image fades in over the LQIP background.
			// 4. The LQIP background is cleared after the fade completes to avoid a white flash.
			$css = 'img[data-thumbhash]{opacity:1;transition:opacity 400ms ease-out;}'
				. '.thumbhash-loading img[data-thumbhash],'
				. 'img.thumbhash-loading[data-thumbhash]{opacity:0;}';
			// Optionally render placeholders as sharp pixels instead of smooth blur.
			if ( (bool) get_option( 'aviflosu_lqip_pixelated', false ) ) {
				$css .= '.thumbhash-loading,img.thumbhash-loading{image-rendering:pixelated;}';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS string, no user input.
			echo '<style id="aviflosu-thumbhash-fade">' . $css . '</style>' . "\n";
		}
	}

	public function wrapAttachment( string $html, int $attachmentId, $size, bool $icon, array $attr ): string {
		if ( str_contains( $html, '<picture' ) ) {
			return $html;
		}

		$mime = \get_post_mime_type( $attachmentId );
		if ( ! \is_string( $mime ) || ! \in_array( $mime, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			return $html;
		}

		$imageSrc = \wp_get_attachment_image_src( $attachmentId, $size );
		if ( ! $imageSrc || ! \is_array( $imageSrc ) || empty( $imageSrc[0] ) ) {
			return $html;
		}

		$avifSrc = $this->avifUrlFor( $imageSrc[0] );

		$srcset     = \wp_get_attachment_image_srcset( $attachmentId, $size );
		$avifSrcset = $srcset ? $this->convertSrcsetToAvif( $srcset ) : '';

		// Ensure single AVIF candidates have width descriptors for responsive selection.
		if ( '' === $avifSrcset && $avifSrc ) {
			$w          = (int) ( $imageSrc[1] ?? 0 );
			$avifSrcset = $w > 0 ? ( $avifSrc . ' ' . $w . 'w' ) : $avifSrc;
		}

		$sizes = \wp_get_attachment_image_sizes( $attachmentId, $size ) ?: '';

		// Get ThumbHash for LQIP if enabled.
		$sizeName  = is_array( $size ) ? 'full' : (string) $size;
		$thumbhash = ThumbHash::getForAttachment( $attachmentId, $sizeName );

		return $this->pictureMarkup( $html, $avifSrc, $avifSrcset, $sizes, $thumbhash );
	}

	public function wrapContentImages( string $content ): string {
		if ( \is_admin() || \wp_doing_ajax() || ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $content;
		}
		if ( ! str_contains( $content, '<img' ) ) {
			return $content;
		}
		return $this->wrapHtmlImages( $content );
	}

	private function avifUrlFor( string $jpegUrl ): ?string {
		if ( ! $this->isUploadsImage( $jpegUrl ) ) {
			return null;
		}
		$parts = \wp_parse_url( $jpegUrl );
		if ( false === $parts || empty( $parts['path'] ) ) {
			return null;
		}
		$path = $parts['path'];
		if ( ! \preg_match( '/\.(jpe?g)$/i', $path ) ) {
			return null;
		}
		$avifPath = (string) \preg_replace( '/\.(jpe?g)$/i', '.avif', $path );

		// Build local path from path-only (no query/fragment) for file_exists check.
		$uploadsBasePath = wp_parse_url( $this->uploadsInfo['baseurl'] ?? '', PHP_URL_PATH ) ?: '';
		if ( ! str_starts_with( $avifPath, $uploadsBasePath ) ) {
			return null;
		}
		$avifRelative = substr( $avifPath, strlen( $uploadsBasePath ) );
		$avifLocal    = trailingslashit( $this->uploadsInfo['basedir'] ?? '' ) . ltrim( $avifRelative, '/' );

		if ( ! $this->avifExists( $avifLocal ) ) {
			return null;
		}

		// Reconstruct AVIF URL (preserve query string for cache-busting if present).
		$avifUrl  = ( $parts['scheme'] ?? '' ) !== '' ? ( $parts['scheme'] . '://' ) : '';
		$avifUrl .= $parts['host'] ?? '';
		$avifUrl .= ! empty( $parts['port'] ) ? ( ':' . $parts['port'] ) : '';
		$avifUrl .= $avifPath;
		if ( ! empty( $parts['query'] ) ) {
			$avifUrl .= '?' . $parts['query'];
		}
		return $avifUrl;
	}

	private function isUploadsImage( string $src ): bool {
		$uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
		return '' !== $uploadsUrl && str_starts_with( $src, $uploadsUrl );
	}

	private function avifExists( string $filePath ): bool {
		// Only cache positive (true) results. Negative results should always be re-checked
		// because files may be converted after the cache was populated.
		if ( isset( $this->fileCache[ $filePath ] ) && true === $this->fileCache[ $filePath ] ) {
			return true;
		}
		$exists = file_exists( $filePath );
		if ( $exists ) {
			$this->fileCache[ $filePath ] = true;
		}
		return $exists;
	}

	private function convertSrcsetToAvif( string $srcset ): string {
		$parts = array_map( 'trim', explode( ',', $srcset ) );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$pieces     = preg_split( '/\s+/', trim( $part ), 2 );
			$url        = $pieces[0];
			$descriptor = $pieces[1] ?? '';
			$avifUrl    = $this->avifUrlFor( $url );
			if ( $avifUrl ) {
				$out[] = trim( $avifUrl . ' ' . $descriptor );
			}
		}
		return implode( ', ', $out );
	}

	private function pictureMarkup( string $originalHtml, ?string $avifSrc, string $avifSrcset = '', string $sizes = '', ?string $thumbhash = null ): string {
		if ( ( ! $avifSrc || '' === $avifSrc ) && ( ! $thumbhash || '' === $thumbhash ) ) {
			return $originalHtml;
		}
		$srcset    = '' !== $avifSrcset ? $avifSrcset : $avifSrc;
		$sizesAttr = '' !== $sizes ? sprintf( ' sizes="%s"', \esc_attr( $sizes ) ) : '';

		// Add ThumbHash data attribute to img tag if available.
		$imgHtml = $originalHtml;
		if ( $thumbhash !== null && $thumbhash !== '' ) {
			$imgHtml = preg_replace(
				'/<img\s/',
				'<img data-thumbhash="' . \esc_attr( $thumbhash ) . '" ',
				$originalHtml,
				1
			);
			if ( $imgHtml === null ) {
				$imgHtml = $originalHtml;
			}
		}

		if ( ! $avifSrc || '' === $avifSrc ) {
			return $imgHtml;
		}

		// Only wrap in <picture> if AVIF serving is enabled.
		$avifEnabled = (bool) \get_option( 'aviflosu_enable_support', true );
		if ( ! $avifEnabled ) {
			return $imgHtml;
		}

		return sprintf( '<picture><source type="image/avif" srcset="%s"%s>%s</picture>', \esc_attr( $srcset ), $sizesAttr, $imgHtml );
	}

	private function isInsidePicture( \DOMNode $node ): bool {
		$parent = $node->parentNode;
		while ( $parent ) {
			if ( $parent instanceof \DOMElement && 'picture' === strtolower( $parent->nodeName ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	private function wrapImgNodeToPicture( \DOMDocument $dom, \DOMElement $img, string $avifSrcset, string $sizes, ?string $thumbhash = null ): void {
		// Add ThumbHash data attribute if available
		if ( $thumbhash !== null && $thumbhash !== '' ) {
			$img->setAttribute( 'data-thumbhash', $thumbhash );
		}

		if ( '' === $avifSrcset ) {
			return;
		}

		// Only wrap in <picture> if AVIF serving is enabled.
		$avifEnabled = (bool) \get_option( 'aviflosu_enable_support', true );
		if ( ! $avifEnabled ) {
			return;
		}

		$picture = $dom->createElement( 'picture' );
		$source  = $dom->createElement( 'source' );
		$source->setAttribute( 'type', 'image/avif' );
		$source->setAttribute( 'srcset', $avifSrcset );
		if ( '' !== $sizes ) {
			$source->setAttribute( 'sizes', $sizes );
		}
		$picture->appendChild( $source );
		$img->parentNode?->replaceChild( $picture, $img );
		$picture->appendChild( $img );
	}

	/**
	 * Find the nearest ancestor anchor for a node.
	 */
	private function nearestAnchor( \DOMNode $node ): ?\DOMElement {
		$current = $node->parentNode;
		while ( $current ) {
			if ( $current instanceof \DOMElement && 'a' === strtolower( $current->nodeName ) ) {
				return $current;
			}
			$current = $current->parentNode;
		}
		return null;
	}

	/**
	 * Update common lightbox data attributes to point at AVIF when available.
	 */
	private function rewriteLightboxImageData( \DOMElement $img ): void {
		$attrs = array( 'data-full-image', 'data-light-image', 'data-envira-src' );
		foreach ( $attrs as $attr ) {
			$val = (string) $img->getAttribute( $attr );
			if ( '' === $val ) {
				continue;
			}
			$avif = $this->avifUrlFor( $val );
			if ( $avif ) {
				$img->setAttribute( $attr, $avif );
			}
		}

		$enviraSrcset = (string) $img->getAttribute( 'data-envira-srcset' );
		if ( '' !== $enviraSrcset ) {
			$avifSrcset = $this->convertSrcsetToAvif( $enviraSrcset );
			if ( '' !== $avifSrcset ) {
				$img->setAttribute( 'data-envira-srcset', $avifSrcset );
			}
		}
	}

	private function wrapHtmlImages( string $htmlInput ): string {
		$html = '<?xml encoding="utf-8" ?>' . $htmlInput;
		$dom  = new \DOMDocument();
		\libxml_use_internal_errors( true );
		if ( ! $dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
			\libxml_clear_errors();
			return $htmlInput;
		}
		\libxml_clear_errors();

		$imgs      = $dom->getElementsByTagName( 'img' );
		$toProcess = array();
		foreach ( $imgs as $img ) {
			$toProcess[] = $img;
		}
		foreach ( $toProcess as $img ) {
			if ( ! ( $img instanceof \DOMElement ) ) {
				continue;
			}
			$src     = (string) $img->getAttribute( 'src' );
			$avifUrl = $this->avifUrlFor( $src );
			$this->rewriteLightboxImageData( $img );

			// Extract attachment ID from wp-image-{ID} class for ThumbHash lookup
			$thumbhash = null;
			if ( ThumbHash::isEnabled() ) {
				$class = (string) $img->getAttribute( 'class' );
				if ( preg_match( '/wp-image-(\d+)/', $class, $matches ) ) {
					$attachmentId = (int) $matches[1];
					$thumbhash    = ThumbHash::getForAttachment( $attachmentId, 'full' );
				}
			}

			if ( ! $avifUrl && ! $thumbhash ) {
				continue;
			}

			// Check if the image is wrapped in a link to a JPEG that also has an AVIF version.
			$anchor = $this->nearestAnchor( $img );
			if ( $anchor ) {
				$href = (string) $anchor->getAttribute( 'href' );
				$class = (string) $anchor->getAttribute( 'class' );
				$isEnviraLink = str_contains( $class, 'envira-gallery-link' ) || '' !== (string) $anchor->getAttribute( 'data-envira-caption' );

				// Envira lightbox can break when href/data attrs are rewritten to AVIF.
				// Keep Envira anchor href untouched for compatibility.
				if ( $isEnviraLink ) {
					$href = '';
				}

				// Only process if href looks like a JPEG
				if ( preg_match( '/\.(jpe?g)$/i', $href ) ) {
					$avifHref = $this->avifUrlFor( $href );
					if ( $avifHref ) {
						$anchor->setAttribute( 'href', $avifHref );
					}
				}
			}

			// If image is already in <picture>, still keep lightbox data/href in sync, but skip re-wrapping.
			if ( $this->isInsidePicture( $img ) ) {
				if ( $thumbhash !== null && $thumbhash !== '' ) {
					$img->setAttribute( 'data-thumbhash', $thumbhash );
				}
				continue;
			}

			$srcset     = (string) $img->getAttribute( 'srcset' );
			$sizes      = (string) $img->getAttribute( 'sizes' );
			$avifSrcset = ( '' !== $srcset ) ? $this->convertSrcsetToAvif( $srcset ) : ( $avifUrl ?: '' );

			// Ensure single AVIF candidates have width descriptors for responsive selection.
			if ( '' === $avifSrcset && $avifUrl ) {
				$w          = (int) $img->getAttribute( 'width' );
				$avifSrcset = $w > 0 ? ( $avifUrl . ' ' . $w . 'w' ) : $avifUrl;
			}

			$this->wrapImgNodeToPicture( $dom, $img, $avifSrcset, $sizes, $thumbhash );
		}

		// Cleanup: Remove the XML declaration node we added to force UTF-8
		while ( $dom->firstChild instanceof \DOMProcessingInstruction && $dom->firstChild->nodeName === 'xml' ) {
			$dom->removeChild( $dom->firstChild );
		}

		$out = $dom->saveHTML();
		return \is_string( $out ) && $out !== '' ? $out : $htmlInput;
	}

	public function saveCache(): void {
		// Only save positive (true) entries - filter out any false entries that might have snuck in.
		$positiveOnly = array_filter( $this->fileCache, fn( $v ) => true === $v );
		set_transient( 'aviflosu_file_cache', $positiveOnly, (int) get_option( 'aviflosu_cache_duration', 3600 ) );
	}
}
