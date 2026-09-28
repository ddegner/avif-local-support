<?php
/**
 * Shared AVIF file identification.
 *
 * @package Ddegner\AvifLocalSupport
 */

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Shared AVIF identification for conversion, diagnostics, and frontend serving.
 */
final class AvifFile {

	/**
	 * Determine whether a readable file contains AVIF image metadata and dimensions.
	 *
	 * @param string $path Absolute path to the image file.
	 * @return bool Whether the file is identified as an AVIF with positive dimensions.
	 */
	public static function isValid( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}

		// Encoders can replace an earlier failed output outside PHP's filesystem API.
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// AVIF can legitimately be smaller than 512 bytes. Identify its format and
		// dimensions instead of treating an arbitrary byte count as validity.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid encoder output is expected and handled by the return value.
		$info = @getimagesize( $path );
		if ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) ) {
			return 'image/avif' === ( $info['mime'] ?? '' );
		}

		// Core's bundled AVIF parser also works on CLI-only installations whose
		// PHP getimagesize() cannot identify AVIF. It does not require GD/Imagick.
		if ( function_exists( 'wp_get_avif_info' ) ) {
			$info = wp_get_avif_info( $path );
			return is_array( $info ) && (int) ( $info['width'] ?? 0 ) > 0 && (int) ( $info['height'] ?? 0 ) > 0;
		}

		return false;
	}
}
