<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * System diagnostics for AVIF Local Support plugin.
 * Handles detection of AVIF support capabilities across different engines.
 */
final class Diagnostics {

	/**
	 * Get comprehensive system status for AVIF support.
	 *
	 * @return array<string, mixed>
	 */
	public function getSystemStatus(): array {
		$status = array(
			'php_version'          => PHP_VERSION,
			'wordpress_version'    => get_bloginfo( 'version' ),
			'php_sapi'             => PHP_SAPI,
			'current_user'         => function_exists( 'posix_geteuid' )
				? (string) @get_current_user() . ' (uid ' . (int) @posix_geteuid() . ')'
				: (string) @get_current_user(),
			'open_basedir'         => (string) ini_get( 'open_basedir' ),
			'disable_functions'    => (string) ini_get( 'disable_functions' ),
			'gd_available'         => extension_loaded( 'gd' ),
			'gd_avif_support'      => false,
			'gd_imageavif'         => false,
			'gd_info_avif'         => false,
			'imagick_available'    => extension_loaded( 'imagick' ),
			'imagick_avif_support' => false,
			'imagick_version'      => '',
			'imagick_formats'      => array(),
			'cli_detected'         => array(),
			'cli_proc_open'        => function_exists( 'proc_open' ),
			'cli_configured_path'  => (string) get_option( 'aviflosu_cli_path', '' ),
			'cli_auto_path'        => '',
			'cli_can_invoke'       => false,
			'cli_has_avif_binary'  => false,
			'engine_mode'          => (string) get_option( 'aviflosu_engine_mode', 'auto' ),
			'auto_first_attempt'   => 'none',
			'auto_has_fallback'    => false,
			'cli_will_attempt'     => false,
			'imagick_will_attempt' => false,
			'gd_will_attempt'      => false,
			'avif_support_level'   => 'no',
			'avif_support'         => false,
		);

		$this->detectImagickSupport( $status );
		$this->detectGdSupport( $status );
		$this->detectCliSupport( $status );
		$this->computeEngineAttempts( $status );
		$this->computeSupportLevel( $status );

		return $status;
	}

	/**
	 * Detect ImageMagick CLI binaries and AVIF support.
	 *
	 * @return array<int, array{path: string, version: string, avif: bool}>
	 */
	public function detectCliBinaries(): array {
		$candidates = ImageMagickCli::detectCandidates( null );
		$out        = array();

		foreach ( $candidates as $c ) {
			$out[] = array(
				'path'    => (string) ( $c['path'] ?? '' ),
				'version' => (string) ( $c['version'] ?? '' ),
				'avif'    => ! empty( $c['avif'] ),
			);
		}

		return $out;
	}

	/**
	 * Compute missing AVIF counts for the media library.
	 *
	 * @return array{total_jpegs: int, existing_avifs: int, missing_avifs: int}
	 */
	public function computeMissingCounts(): array {
		$uploadDir = \wp_upload_dir();
		$baseDir   = \trailingslashit( $uploadDir['basedir'] ?? '' );
		$total     = 0;
		$existing  = 0;
		$missing   = 0;
		$seenJpegs = array();

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'cache_results'          => false,
				'post_mime_type'         => 'image/jpeg',
			)
		);

		foreach ( $query->posts as $attachmentId ) {
			// Original
			$file = get_attached_file( $attachmentId );
			if ( $file && preg_match( '/\.(jpe?g)$/i', $file ) && file_exists( $file ) ) {
				$real = (string) ( @realpath( $file ) ?: $file );
				if ( ! isset( $seenJpegs[ $real ] ) ) {
					$seenJpegs[ $real ] = true;
					++$total;
					$avif = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $real );
					if ( $avif && file_exists( $avif ) && filesize( $avif ) > 512 ) {
						++$existing;
					} else {
						++$missing;
					}
				}
			}

			// Sizes via metadata
			$meta = wp_get_attachment_metadata( $attachmentId );
			if ( ! empty( $meta['file'] ) ) {
				$relative = (string) $meta['file'];
				$dir      = pathinfo( $relative, PATHINFO_DIRNAME );
				if ( $dir === '.' || $dir === DIRECTORY_SEPARATOR ) {
					$dir = '';
				}
				if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $size ) {
						if ( empty( $size['file'] ) ) {
							continue;
						}
						$p = $baseDir . \trailingslashit( $dir ) . $size['file'];
						if ( ! preg_match( '/\.(jpe?g)$/i', $p ) || ! file_exists( $p ) ) {
							continue;
						}
						$realP = (string) ( @realpath( $p ) ?: $p );
						if ( isset( $seenJpegs[ $realP ] ) ) {
							continue;
						}
						$seenJpegs[ $realP ] = true;
						++$total;
						$avif = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $realP );
						if ( $avif && file_exists( $avif ) && filesize( $avif ) > 512 ) {
							++$existing;
						} else {
							++$missing;
						}
					}
				}
			}
		}

		return array(
			'total_jpegs'    => $total,
			'existing_avifs' => $existing,
			'missing_avifs'  => $missing,
		);
	}

	/**
	 * Get suggested CLI environment variables.
	 */
	public function getSuggestedCliEnv(): string {
		return Environment::buildDefaultEnvString();
	}

	/**
	 * Get suggested CLI arguments.
	 */
	public function getSuggestedCliArgs(): string {
		return '';
	}

	/**
	 * Detect ImageMagick (Imagick extension) AVIF support.
	 */
	private function detectImagickSupport( array &$status ): void {
		if ( ! $status['imagick_available'] ) {
			return;
		}

		try {
			$imagick                   = new \Imagick();
			$version                   = $imagick->getVersion();
			$status['imagick_version'] = $version['versionString'] ?? '';

			$formats                        = $imagick->queryFormats( 'AVIF' );
			$status['imagick_avif_support'] = ! empty( $formats );

			$allFormats = $imagick->queryFormats();
			if ( is_array( $allFormats ) ) {
				$status['imagick_formats'] = $allFormats;
			}

			$imagick->destroy();
		} catch ( \Exception $e ) {
			$status['imagick_avif_support'] = false;
		}
	}

	/**
	 * Detect GD AVIF support.
	 */
	private function detectGdSupport( array &$status ): void {
		if ( ! $status['gd_available'] ) {
			return;
		}

		$status['gd_imageavif']    = function_exists( 'imageavif' );
		$status['gd_info_avif']    = function_exists( 'gd_info' )
			? (bool) ( ( gd_info()['AVIF Support'] ?? false ) )
			: false;
		$status['gd_avif_support'] = $status['gd_imageavif'];
	}

	/**
	 * Detect CLI AVIF support.
	 */
	private function detectCliSupport( array &$status ): void {
		$status['cli_detected'] = $this->detectCliBinaries();

		foreach ( $status['cli_detected'] as $bin ) {
			if ( ! empty( $bin['avif'] ) ) {
				$status['cli_has_avif_binary'] = true;
				break;
			}
		}

		$status['cli_auto_path']  = ImageMagickCli::getAutoDetectedPath( null );
		$status['cli_can_invoke'] = (bool) $status['cli_proc_open']
			&& ( ( $status['cli_configured_path'] ?? '' ) !== '' || ( $status['cli_auto_path'] ?? '' ) !== '' );
	}

	/**
	 * Compute what engines will be attempted based on mode and availability.
	 */
	private function computeEngineAttempts( array &$status ): void {
		$cliWillTry     = $status['cli_can_invoke'];
		$imagickWillTry = (bool) $status['imagick_avif_support'];
		$gdWillTry      = (bool) $status['gd_avif_support'];

		if ( $cliWillTry ) {
			$status['auto_first_attempt'] = 'cli';
		} elseif ( $imagickWillTry ) {
			$status['auto_first_attempt'] = 'imagick';
		} elseif ( $gdWillTry ) {
			$status['auto_first_attempt'] = 'gd';
		} else {
			$status['auto_first_attempt'] = 'none';
		}

		$tries                       = array_filter( array( $cliWillTry, $imagickWillTry, $gdWillTry ), static fn( $v ) => (bool) $v );
		$status['auto_has_fallback'] = count( $tries ) > 1;

		$mode = (string) ( $status['engine_mode'] ?? 'auto' );
		if ( $mode === 'cli' ) {
			$status['cli_will_attempt'] = true;
		} elseif ( $mode === 'imagick' ) {
			$status['imagick_will_attempt'] = true;
		} elseif ( $mode === 'gd' ) {
			$status['gd_will_attempt'] = true;
		} else {
			$status['cli_will_attempt']     = (bool) $cliWillTry;
			$status['imagick_will_attempt'] = (bool) $imagickWillTry;
			$status['gd_will_attempt']      = (bool) $gdWillTry;
		}
	}

	/**
	 * Compute overall AVIF support level.
	 */
	private function computeSupportLevel( array &$status ): void {
		if ( ! empty( $status['imagick_avif_support'] )
			|| ! empty( $status['gd_avif_support'] )
			|| ! empty( $status['cli_has_avif_binary'] )
		) {
			$status['avif_support_level'] = 'yes';
		} elseif ( ! empty( $status['cli_can_invoke'] ) ) {
			$status['avif_support_level'] = 'unknown';
		} else {
			$status['avif_support_level'] = 'no';
		}

		$status['avif_support'] = $status['avif_support_level'] === 'yes';
	}
}
