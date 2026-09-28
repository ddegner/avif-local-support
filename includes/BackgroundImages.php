<?php
/**
 * Local AVIF background-image serving.
 *
 * @package Ddegner\AvifLocalSupport
 */

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/** Serves local AVIF backgrounds without changing the authored CSS cascade. */
final class BackgroundImages {
	/**
	 * Upload URL and filesystem roots.
	 *
	 * @var array
	 */
	private array $uploadsInfo = array();
	/**
	 * Whether this instance owns an output buffer.
	 *
	 * @var bool
	 */
	private bool $bufferingStarted = false;

	/** Whether background serving is enabled. */
	public static function isEnabled(): bool {
		return (bool) get_option( 'aviflosu_enable_background_images', true );
	}

	/** Register frontend output processing. */
	public function init(): void {
		if ( ! is_admin() && self::isEnabled() ) {
			$this->uploadsInfo = wp_upload_dir();
			add_action( 'template_redirect', array( $this, 'startBuffering' ), 1 );
		}
	}

	/** Start buffering eligible HTML requests. */
	public function startBuffering(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}
		$this->bufferingStarted = true;
		ob_start( array( $this, 'processBuffer' ) );
	}

	/**
	 * Rewrite eligible backgrounds in a complete HTML document.
	 *
	 * @param string $buffer Buffered page HTML.
	 */
	public function processBuffer( string $buffer ): string {
		if ( '' === $buffer || ! $this->bufferingStarted || false === stripos( $buffer, '</head>' ) ) {
			return $buffer;
		}
		$processor    = new \WP_HTML_Tag_Processor( $buffer );
		$linkedStyles = array();
		$requestPath  = '/' . ltrim( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), '/' );
		$documentUrl  = $this->resolveUrl( $requestPath, home_url( '/' ) );
		while ( $processor->next_tag() ) {
			$inline = $processor->get_attribute( 'style' );
			if ( is_string( $inline ) ) {
				$changed = false;
				$updated = $this->rewriteDeclarations( $inline, $documentUrl, false, $changed );
				if ( $changed && null !== $updated ) {
					$processor->set_attribute( 'style', $updated );
				}
			}
			if ( 'STYLE' === $processor->get_tag() ) {
				$changed = false;
				$updated = $this->rewriteStylesheet( $processor->get_modifiable_text(), $documentUrl, false, $changed );
				if ( $changed && null !== $updated ) {
					$processor->set_modifiable_text( $updated );
				}
			} elseif ( 'LINK' === $processor->get_tag() ) {
				$rel = strtolower( trim( (string) $processor->get_attribute( 'rel' ) ) );
				// Alternate/disabled sheets retain browser-controlled activation.
				if ( 'stylesheet' !== $rel || null !== $processor->get_attribute( 'disabled' ) || null !== $processor->get_attribute( 'title' ) ) {
					continue;
				}
				$url  = $this->resolveUrl( (string) $processor->get_attribute( 'href' ), $documentUrl );
				$path = $this->urlToLocalPath( $url );
				if ( null === $path || ! is_readable( $path ) || 'css' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verified local uploads file, never a remote request.
				$css = file_get_contents( $path );
				if ( ! is_string( $css ) ) {
					continue;
				}
				$changed = false;
				$updated = $this->rewriteStylesheet( $css, $url, true, $changed );
				// External CSS must not be able to close the injected HTML style tag.
				if ( ! $changed || null === $updated || false !== stripos( $updated, '</style' ) ) {
					continue;
				}
				$marker                  = uniqid( 'aviflosu-', false );
				$media                   = $processor->get_attribute( 'media' );
				$linkedStyles[ $marker ] = '<style class="aviflosu-bg-overrides"'
					. ( is_string( $media ) ? ' media="' . esc_attr( $media ) . '"' : '' )
					. '>' . $updated . '</style>';
				$processor->set_attribute( 'data-aviflosu-background', $marker );
			}
		}
		$buffer = $processor->get_updated_html();
		if ( empty( $linkedStyles ) ) {
			return $buffer;
		}
		// Only real LINK tokens received these markers. Keep each mirror next to
		// its source link so later stylesheets retain their cascade precedence.
		return (string) preg_replace_callback(
			'~<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i',
			static function ( array $matches ) use ( $linkedStyles ): string {
				if ( ! preg_match( '/ data-aviflosu-background="([^"]+)"/', $matches[0], $marker ) || ! isset( $linkedStyles[ $marker[1] ] ) ) {
					return $matches[0];
				}
				return str_replace( $marker[0], '', $matches[0] ) . $linkedStyles[ $marker[1] ];
			},
			$buffer
		);
	}

	/**
	 * Mirror all background declarations, including unchanged longhands and
	 * later background:none rules, under their original conditional wrappers.
	 * Unsupported grammar returns null so the original CSS remains untouched.
	 *
	 * @param string $css Stylesheet source.
	 * @param string $baseUrl URL against which relative image URLs resolve.
	 * @param bool   $mirror Whether to keep only background declarations.
	 * @param bool   $changed Set when an AVIF companion is used.
	 */
	private function rewriteStylesheet( string $css, string $baseUrl, bool $mirror, bool &$changed ): ?string {
		$tokens = $this->cssDelimiters( $css );
		if ( null === $tokens ) {
			return null;
		}
		$out   = '';
		$start = 0;
		$count = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			[ $offset, $delimiter ] = $tokens[ $i ];
			if ( ';' === $delimiter ) {
				$statement = substr( $css, $start, $offset - $start + 1 );
				$clean     = trim( (string) preg_replace( '~/\*.*?\*/~s', '', $statement ) );
				if ( $mirror && preg_match( '/^@(import|namespace)\b/i', $clean ) ) {
					return null;
				}
				if ( ! $mirror || preg_match( '/^@layer\b/i', $clean ) ) {
					$out .= $statement;
				}
				$start = $offset + 1;
				continue;
			}
			if ( '{' !== $delimiter ) {
				return null;
			}
			$header = substr( $css, $start, $offset - $start );
			$depth  = 1;
			for ( $j = $i + 1; $j < $count; ++$j ) {
				$depth += '{' === $tokens[ $j ][1] ? 1 : ( '}' === $tokens[ $j ][1] ? -1 : 0 );
				if ( 0 === $depth ) {
					break;
				}
			}
			if ( $j === $count ) {
				return null;
			}
			$end         = $tokens[ $j ][0];
			$body        = substr( $css, $offset + 1, $end - $offset - 1 );
			$cleanHeader = trim( (string) preg_replace( '~/\*.*?\*/~s', '', $header ) );
			if ( $mirror && preg_match( '/^@(layer|scope)\s*$/i', $cleanHeader ) ) {
				// Anonymous layers cannot be reopened, and an implicit scope can
				// acquire a different root when moved into an inline style element.
				return null;
			}
			if ( preg_match( '/^@(media|supports|container|layer|scope|starting-style)\b/i', $cleanHeader ) ) {
				$rewritten = $this->rewriteStylesheet( $body, $baseUrl, $mirror, $changed );
			} elseif ( str_starts_with( $cleanHeader, '@' ) ) {
				$rewritten = $mirror ? '' : $body;
			} else {
				$rewritten = $this->rewriteDeclarations( $body, $baseUrl, $mirror, $changed );
			}
			if ( null === $rewritten ) {
				return null;
			}
			if ( ! $mirror || '' !== $rewritten ) {
				$out .= $header . '{' . $rewritten . '}';
			}
			$start = $end + 1;
			$i     = $j;
		}
		$tail = substr( $css, $start );
		if ( '' !== trim( (string) preg_replace( '~/\*.*?\*/~s', '', $tail ) ) ) {
			return null;
		}
		return $out . ( $mirror ? '' : $tail );
	}

	/**
	 * Find structural delimiters outside strings, comments and functions.
	 *
	 * @param string $css CSS source.
	 */
	private function cssDelimiters( string $css ): ?array {
		$tokens      = array();
		$length      = strlen( $css );
		$parentheses = 0;
		$brackets    = 0;
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $css[ $i ];
			if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$i = $end + 1;
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				for ( $quoteOffset = $i + 1; $quoteOffset < $length; ++$quoteOffset ) {
					if ( '\\' === $css[ $quoteOffset ] ) {
						++$quoteOffset;
					} elseif ( $quote === $css[ $quoteOffset ] ) {
						break;
					}
				}
				$i = $quoteOffset;
				if ( $i >= $length ) {
					return null;
				}
				continue;
			}
			if ( '\\' === $char ) {
				++$i;
				continue;
			}
			$parentheses += '(' === $char ? 1 : ( ')' === $char ? -1 : 0 );
			$brackets    += '[' === $char ? 1 : ( ']' === $char ? -1 : 0 );
			if ( $parentheses < 0 || $brackets < 0 ) {
				return null;
			}
			if ( 0 === $parentheses && 0 === $brackets && str_contains( '{};', $char ) ) {
				$tokens[] = array( $i, $char );
			}
		}
		return 0 === $parentheses && 0 === $brackets ? $tokens : null;
	}

	/**
	 * Replace image URLs within their original declarations and layers.
	 *
	 * @param string $css Declaration list.
	 * @param string $baseUrl Source stylesheet or document URL.
	 * @param bool   $mirror Whether to keep only background declarations.
	 * @param bool   $changed Set when an AVIF companion is used.
	 */
	private function rewriteDeclarations( string $css, string $baseUrl, bool $mirror, bool &$changed ): ?string {
		$tokens = $this->cssDelimiters( $css );
		if ( null === $tokens ) {
			return null;
		}
		$tokens[] = array( strlen( $css ), '' );
		$out      = '';
		$start    = 0;
		foreach ( $tokens as [ $offset, $delimiter ] ) {
			if ( '{' === $delimiter || '}' === $delimiter ) {
				return null; // CSS nesting needs a selector parser; retain original CSS.
			}
			$declaration = substr( $css, $start, $offset - $start );
			$start       = $offset + 1;
			if ( ! preg_match( '~^(\s*(?:/\*.*?\*/\s*)*)([-\w]+)(\s*:\s*)(.*)$~s', $declaration, $parts ) ) {
				$out .= $mirror ? '' : $declaration . $delimiter;
				continue;
			}
			$property = strtolower( $parts[2] );
			if ( $mirror && 'all' === $property ) {
				return null;
			}
			if ( 'background' !== $property && ! str_starts_with( $property, 'background-' ) ) {
				$out .= $mirror ? '' : $declaration . $delimiter;
				continue;
			}
			$prefix = $parts[1] . $parts[2] . $parts[3];
			$value  = $parts[4];
			if ( $mirror && str_contains( $value, '\\' ) ) {
				return null; // Escaped relative URLs cannot be safely relocated to HTML.
			}
			$didConvert = false;
			$fallback   = $mirror ? $this->rewriteUrls( $value, $baseUrl, false, $didConvert ) : $value;
			$converted  = ( 'background' === $property || 'background-image' === $property )
				? $this->rewriteUrls( $value, $baseUrl, true, $didConvert ) : $fallback;
			$out       .= $prefix . $fallback . $delimiter;
			if ( $didConvert ) {
				$changed = true;
				// Retain a JPEG declaration for browsers without image-set().
				$out .= ( '' === $delimiter ? ';' : '' ) . $prefix . $converted . $delimiter;
			}
		}
		return $out;
	}

	/**
	 * Resolve URLs and optionally add format-aware AVIF/JPEG alternatives.
	 *
	 * @param string $value CSS declaration value.
	 * @param string $baseUrl Source stylesheet or document URL.
	 * @param bool   $convert Whether to introduce AVIF alternatives.
	 * @param bool   $didConvert Set when an AVIF companion is used.
	 */
	private function rewriteUrls( string $value, string $baseUrl, bool $convert, bool &$didConvert ): string {
		$canConvert = $convert && ! preg_match( '/(?:-webkit-)?image-set\s*\(/i', $value );
		return (string) preg_replace_callback(
			'~url\(\s*(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|([^\s)]+))\s*\)~i',
			function ( array $matches ) use ( $baseUrl, $canConvert, &$didConvert ): string {
				$url = '' !== $matches[1] ? $matches[1] : ( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
				if ( '' === $url || str_contains( $url, '\\' ) || preg_match( '/^(?:data:|#)/i', $url ) ) {
					return $matches[0];
				}
				$absolute = $this->resolveUrl( $url, $baseUrl );
				$jpeg     = 'url("' . $this->cssString( $absolute ) . '")';
				$avif     = $canConvert ? $this->getAvifUrl( $absolute ) : null;
				if ( null === $avif ) {
					return $jpeg;
				}
				$didConvert = true;
				return 'image-set(url("' . $this->cssString( $avif ) . '") type("image/avif"),' . $jpeg . ' type("image/jpeg"))';
			},
			$value
		);
	}

	/**
	 * Escape a double-quoted CSS string, including HTML's style closing token.
	 *
	 * @param string $value Unescaped URL.
	 */
	private function cssString( string $value ): string {
		return str_replace( array( '\\', '"', "\n", "\r", '<' ), array( '\\\\', '\\"', '\\a ', '\\d ', '\\3c ' ), $value );
	}

	/**
	 * Resolve a CSS URL without normalizing its query string or fragment.
	 *
	 * @param string $url Absolute or relative reference.
	 * @param string $baseUrl Source stylesheet or document URL.
	 */
	private function resolveUrl( string $url, string $baseUrl ): string {
		if ( preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) ) {
			return $url;
		}
		$base = wp_parse_url( $baseUrl );
		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return $url;
		}
		$scheme = $base['scheme'] ?? 'https';
		if ( str_starts_with( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		$origin       = $scheme . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		$suffixOffset = strcspn( $url, '?#' );
		$relativePath = substr( $url, 0, $suffixOffset );
		$suffix       = substr( $url, $suffixOffset );
		$path         = '' === $relativePath ? ( $base['path'] ?? '/' )
			: ( str_starts_with( $relativePath, '/' ) ? $relativePath : dirname( $base['path'] ?? '/' ) . '/' . $relativePath );
		$segments     = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '' !== $segment && '.' !== $segment ) {
				$segments[] = $segment;
			}
		}
		$normalized = '/' . implode( '/', $segments );
		if ( '/' !== $normalized && str_ends_with( $path, '/' ) ) {
			$normalized .= '/';
		}
		return $origin . $normalized . $suffix;
	}

	/**
	 * Resolve an existing same-origin uploads file, rejecting path traversal.
	 *
	 * @param string $url Absolute file URL.
	 */
	private function urlToLocalPath( string $url ): ?string {
		$uploads = wp_parse_url( $this->uploadsInfo['baseurl'] ?? '' );
		$parts   = wp_parse_url( $url );
		if ( ! is_array( $uploads ) || ! is_array( $parts ) || ( $parts['host'] ?? '' ) !== ( $uploads['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $uploads['port'] ?? null ) ) {
			return null;
		}
		$prefix = rtrim( $uploads['path'] ?? '', '/' ) . '/';
		if ( ! str_starts_with( $parts['path'] ?? '', $prefix ) ) {
			return null;
		}
		$root = realpath( $this->uploadsInfo['basedir'] ?? '' );
		$path = $root ? realpath( $root . '/' . rawurldecode( substr( $parts['path'], strlen( $prefix ) ) ) ) : false;
		return $path && str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) ? $path : null;
	}

	/**
	 * Find a valid local AVIF companion while preserving the URL suffix.
	 *
	 * @param string $jpegUrl Absolute JPEG URL.
	 */
	private function getAvifUrl( string $jpegUrl ): ?string {
		$parts = wp_parse_url( $jpegUrl );
		if ( ! is_array( $parts ) || ! preg_match( '/\.jpe?g$/i', $parts['path'] ?? '' ) ) {
			return null;
		}
		$avifUrl = (string) preg_replace( '/\.jpe?g(?=[?#]|$)/i', '.avif', $jpegUrl, 1 );
		$path    = $this->urlToLocalPath( $avifUrl );
		return null !== $path && AvifFile::isValid( $path ) ? $avifUrl : null;
	}
}
