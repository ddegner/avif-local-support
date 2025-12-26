<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * ImageMagick CLI discovery + AVIF capability probing.
 *
 * Designed to work on Linux/macOS across ImageMagick 6 and 7 packaging variants.
 */
final class ImageMagickCli {



	/**
	 * @return array<int, array{path:string,version:string,avif:bool,flavor:string}>
	 */
	public static function detectCandidates( ?array $env = null ): array {
		$env      = self::normalizeEnv( $env );
		$cacheKey = 'aviflosu_imc_cand_' . substr( md5( $env['PATH'] ?? '' ), 0, 12 );
		$ttl      = self::cacheTtl();

		$cached = get_transient( $cacheKey );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$paths = array();

		// Prefer PATH discovery if possible.
		foreach ( array( 'magick', 'convert-im7', 'convert-im6', 'convert' ) as $name ) {
			$p = self::commandV( $name, $env );
			if ( '' !== $p ) {
				$paths[ $p ] = true;
			}
		}

		// Common locations (Linux/macOS).
		foreach ( array(
			'/usr/bin/magick',
			'/usr/local/bin/magick',
			'/opt/homebrew/bin/magick',
			'/opt/local/bin/magick',
			'/usr/bin/convert',
			'/usr/local/bin/convert',
			'/opt/homebrew/bin/convert',
			'/opt/local/bin/convert',
			'/usr/bin/convert-im6',
			'/usr/bin/convert-im7',
			'/usr/local/bin/convert-im6',
			'/usr/local/bin/convert-im7',
		) as $p ) {
			if ( @file_exists( $p ) && @is_executable( $p ) ) {
				$paths[ $p ] = true;
			}
		}

		$out = array();
		foreach ( array_keys( $paths ) as $path ) {
			if ( ! @file_exists( $path ) || ! @is_executable( $path ) ) {
				continue;
			}

			$version = self::version( $path, $env );
			if ( '' === $version ) {
				continue;
			}

			$avif  = self::supportsAvifWrite( $path, $env );
			$out[] = array(
				'path'    => $path,
				'version' => $version,
				'avif'    => $avif,
				'flavor'  => self::flavor( $path ),
			);
		}

		set_transient( $cacheKey, $out, $ttl );
		return $out;
	}

	/**
	 * Pick the best ImageMagick CLI path for AVIF encoding.
	 */
	public static function pickBestBinary( array $candidates ): string {
		$order = array( 'magick', 'convert-im7', 'convert-im6', 'convert' );

		$byFlavor = array();
		foreach ( $candidates as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$path   = isset( $c['path'] ) ? (string) $c['path'] : '';
			$flavor = isset( $c['flavor'] ) ? (string) $c['flavor'] : '';
			$avif   = ! empty( $c['avif'] );
			if ( '' === $path || '' === $flavor || ! $avif ) {
				continue;
			}
			$byFlavor[ $flavor ][] = $path;
		}

		foreach ( $order as $flavor ) {
			if ( ! empty( $byFlavor[ $flavor ] ) ) {
				// Stable: pick the first discovered path for that flavor.
				return (string) $byFlavor[ $flavor ][0];
			}
		}

		return '';
	}

	/**
	 * Return a cached best CLI path (empty if none).
	 */
	public static function getAutoDetectedPath( ?array $env = null ): string {
		$env      = self::normalizeEnv( $env );
		$cacheKey = 'aviflosu_imc_sel_' . substr( md5( $env['PATH'] ?? '' ), 0, 12 );
		$ttl      = self::cacheTtl();

		$cached = get_transient( $cacheKey );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$candidates = self::detectCandidates( $env );
		$best       = self::pickBestBinary( $candidates );

		// Cache even if empty so we don't repeatedly scan.
		set_transient( $cacheKey, $best, $ttl );
		return $best;
	}

	/**
	 * Probe which AVIF-related -define namespace is safe for this binary.
	 *
	 * @return array{namespace:string,supports_lossless:bool,supports_chroma:bool,supports_depth:bool,supports_bit_depth_define:bool}
	 */
	public static function getDefineStrategy( string $bin, ?array $env = null ): array {
		$env      = self::normalizeEnv( $env );
		$cacheKey = 'aviflosu_imc_def_' . substr( md5( $bin . '|' . ( $env['PATH'] ?? '' ) ), 0, 16 );
		$ttl      = self::cacheTtl();

		$cached = get_transient( $cacheKey );
		if ( is_array( $cached ) && isset( $cached['namespace'] ) ) {
			return $cached;
		}

		$strategy = self::probeDefineStrategy( $bin, $env );
		set_transient( $cacheKey, $strategy, $ttl );
		return $strategy;
	}

	/**
	 * @return array{namespace:string,supports_lossless:bool,supports_chroma:bool,supports_depth:bool,supports_bit_depth_define:bool}
	 */
	private static function probeDefineStrategy( string $bin, array $env ): array {
		$result = array(
			'namespace'                 => 'none',
			'supports_lossless'         => false,
			'supports_chroma'           => false,
			'supports_depth'            => false,
			'supports_bit_depth_define' => false,
		);

		if ( '' === $bin || ! @file_exists( $bin ) || ! @is_executable( $bin ) ) {
			return $result;
		}

		// Baseline test args for each namespace.
		$tests = array(
			'heic' => array(
				array( '-define', 'heic:speed=6' ),
				array( '-define', 'heic:chroma=420' ),
			),
			'avif' => array(
				array( '-define', 'avif:speed=6' ),
				array( '-define', 'avif:chroma-subsample=4:2:0' ),
			),
			'none' => array(),
		);

		$chosen = 'none';
		foreach ( array( 'heic', 'avif', 'none' ) as $ns ) {
			if ( self::probeConversion( $bin, $env, $tests[ $ns ] ?? array() ) ) {
				$chosen = $ns;
				break;
			}
		}

		$result['namespace'] = $chosen;
		if ( 'none' === $chosen ) {
			return $result;
		}

		// Lossless define probe.
		$losslessArgs                = 'heic' === $chosen
			? array_merge( $tests[ $chosen ], array( array( '-define', 'heic:lossless=true' ) ) )
			: array_merge( $tests[ $chosen ], array( array( '-define', 'avif:lossless=true' ) ) );
		$result['supports_lossless'] = self::probeConversion( $bin, $env, $losslessArgs );

		// Chroma define is already included in baseline tests if chosen != none.
		$result['supports_chroma'] = true;

		// Depth / bit-depth probes (only verify they don't break; some builds may ignore).
		$depthArgs                = array_merge( array( array( '-depth', '10' ) ), $tests[ $chosen ] );
		$result['supports_depth'] = self::probeConversion( $bin, $env, $depthArgs );

		$bitDepthKey                         = 'heic' === $chosen ? 'heic:bit-depth=10' : 'avif:bit-depth=10';
		$bitDepthArgs                        = array_merge( $tests[ $chosen ], array( array( '-define', $bitDepthKey ) ) );
		$result['supports_bit_depth_define'] = self::probeConversion( $bin, $env, $bitDepthArgs );

		return $result;
	}

	/**
	 * Attempt a tiny AVIF conversion; returns true if it produced an output file.
	 *
	 * @param array<int, array{0:string,1:string}|array{0:string,1?:string}> $extraArgsPairs
	 */
	private static function probeConversion( string $bin, array $env, array $extraArgsPairs ): bool {
		$tmp = @tempnam( sys_get_temp_dir(), 'aviflosu_imc_' );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return false;
		}
		// tempnam creates a file; use .avif extension.
		$out = $tmp . '.avif';
		wp_delete_file( $tmp );
		wp_delete_file( $out );

		$args = array(
			'-size',
			'1x1',
			'xc:white',
			'-quality',
			'50',
		);

		foreach ( $extraArgsPairs as $pair ) {
			if ( ! is_array( $pair ) || empty( $pair[0] ) ) {
				continue;
			}
			$args[] = (string) $pair[0];
			if ( isset( $pair[1] ) && '' !== $pair[1] ) {
				$args[] = (string) $pair[1];
			}
		}

		$args[] = $out;

		[$code] = self::run( $bin, $args, $env );

		$ok = ( 0 === $code && @file_exists( $out ) && @filesize( $out ) > 64 );
		wp_delete_file( $out );
		return $ok;
	}

	private static function cacheTtl(): int {
		$ttl = (int) get_option( 'aviflosu_cache_duration', 3600 );
		if ( $ttl < 60 ) {
			$ttl = 60;
		}
		if ( $ttl > DAY_IN_SECONDS ) {
			$ttl = DAY_IN_SECONDS;
		}
		return $ttl;
	}

	/**
	 * Normalize env and ensure PATH/HOME/LC_ALL exist.
	 */
	private static function normalizeEnv( ?array $env ): array {
		return Environment::normalizeEnv( $env );
	}

	private static function flavor( string $path ): string {
		$base = strtolower( basename( $path ) );
		if ( 'magick' === $base ) {
			return 'magick';
		}
		if ( 'convert-im7' === $base ) {
			return 'convert-im7';
		}
		if ( 'convert-im6' === $base ) {
			return 'convert-im6';
		}
		if ( 'convert' === $base ) {
			return 'convert';
		}
		return $base;
	}

	private static function commandV( string $bin, array $env ): string {
		// Prefer proc_open when available.
		if ( function_exists( 'proc_open' ) ) {
			[$code, $output] = self::runShell( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $env );
			if ( 0 === $code ) {
				$p = trim( $output );
				if ( '' !== $p && @file_exists( $p ) && @is_executable( $p ) ) {
					return $p;
				}
			}
			return '';
		}

		// Fallback to shell_exec if allowed.
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'shell_exec', $disabled, true ) ) {
			return '';
		}
		$res = @shell_exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null' );
		$p   = is_string( $res ) ? trim( $res ) : '';
		if ( '' !== $p && @file_exists( $p ) && @is_executable( $p ) ) {
			return $p;
		}
		return '';
	}

	private static function version( string $path, array $env ): string {
		[$code, $output] = self::run( $path, array( '-version' ), $env );
		if ( 0 !== $code || '' === $output ) {
			return '';
		}
		$lines = preg_split( "/\r\n|\r|\n/", $output ) ?: array();
		$first = isset( $lines[0] ) ? trim( (string) $lines[0] ) : '';
		return $first;
	}

	private static function supportsAvifWrite( string $path, array $env ): bool {
		[$code, $output] = self::run( $path, array( '-list', 'format' ), $env );
		if ( $code !== 0 || $output === '' ) {
			return false;
		}
		$lines = preg_split( "/\r\n|\r|\n/", $output ) ?: array();
		foreach ( $lines as $line ) {
			$line = (string) $line;
			if ( preg_match( '/^\s*AVIF\b/i', $line ) === 1 ) {
				return ( stripos( $line, 'w' ) !== false );
			}
		}
		return false;
	}

	/**
	 * Run a command, returning [exitCode, combinedOutput].
	 *
	 * @return array{0:int,1:string}
	 */
	private static function run( string $bin, array $args, array $env ): array {
		$cmdParts = array( escapeshellarg( $bin ) );
		foreach ( $args as $a ) {
			$cmdParts[] = escapeshellarg( (string) $a );
		}
		$command = implode( ' ', $cmdParts ) . ' 2>&1';

		if ( function_exists( 'proc_open' ) ) {
			$descriptor = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Required for non-shell command execution.
			$process = @proc_open( $command, $descriptor, $pipes, null, $env );
			if ( ! is_resource( $process ) ) {
				return array( 1, '' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for proc_open pipes.
			fclose( $pipes[0] );
			$stdout = stream_get_contents( $pipes[1] ) ?: '';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for proc_open pipes.
			fclose( $pipes[1] );
			$stderr = stream_get_contents( $pipes[2] ) ?: '';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for proc_open pipes.
			fclose( $pipes[2] );
			$code = (int) proc_close( $process );
			return array( $code, trim( $stdout . "\n" . $stderr ) );
		}

		// exec fallback
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return array( 1, '' );
		}
		$out  = array();
		$code = 0;
		@exec( $command, $out, $code );
		$output = trim( implode( "\n", array_map( 'strval', $out ) ) );
		return array( (int) $code, $output );
	}

	/**
	 * Run a shell snippet under /bin/sh -lc.
	 *
	 * @return array{0:int,1:string}
	 */
	private static function runShell( string $shellCommand, array $env ): array {
		$sh = '/bin/sh';
		if ( ! @file_exists( $sh ) || ! @is_executable( $sh ) ) {
			return array( 1, '' );
		}
		return self::run( $sh, array( '-lc', $shellCommand ), $env );
	}
}
