<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for CLI environment variable handling.
 * Consolidates PATH building logic used across multiple classes.
 */
final class Environment {

	/**
	 * Build the default PATH string with platform-specific additions.
	 */
	public static function buildDefaultPath(): string {
		$path = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin';

		if ( PHP_OS_FAMILY === 'Darwin' ) {
			if ( @is_dir( '/opt/homebrew/bin' ) ) {
				$path .= ':/opt/homebrew/bin';
			}
			if ( @is_dir( '/opt/local/bin' ) ) {
				$path .= ':/opt/local/bin';
			}
		}

		return $path;
	}

	/**
	 * Build the default CLI environment variables string.
	 */
	public static function buildDefaultEnvString(): string {
		$path = self::buildDefaultPath();
		return "PATH=$path\nHOME=/tmp\nLC_ALL=C";
	}

	/**
	 * Build a normalized environment array suitable for proc_open.
	 *
	 * @param array|null $env Optional existing env to normalize.
	 * @return array<string, string>
	 */
	public static function normalizeEnv( ?array $env = null ): array {
		$env = is_array( $env ) ? $env : array();

		if ( empty( $env['PATH'] ) ) {
			$env['PATH'] = getenv( 'PATH' ) ?: self::buildDefaultPath();
		}

		// Ensure Darwin-specific paths are included
		if ( PHP_OS_FAMILY === 'Darwin' ) {
			$currentPath = (string) $env['PATH'];
			if ( @is_dir( '/opt/homebrew/bin' ) && strpos( $currentPath, '/opt/homebrew/bin' ) === false ) {
				$env['PATH'] .= ':/opt/homebrew/bin';
			}
			if ( @is_dir( '/opt/local/bin' ) && strpos( $currentPath, '/opt/local/bin' ) === false ) {
				$env['PATH'] .= ':/opt/local/bin';
			}
		}

		if ( empty( $env['HOME'] ) ) {
			$env['HOME'] = getenv( 'HOME' ) ?: '/tmp';
		}

		if ( empty( $env['LC_ALL'] ) ) {
			$env['LC_ALL'] = 'C';
		}

		/**
		 * Filters the environment used for CLI operations.
		 *
		 * @param array $env
		 */
		return apply_filters( 'aviflosu_cli_environment', $env );
	}

	/**
	 * Parse CLI environment string into an array.
	 *
	 * @param string $envString Newline-separated KEY=VALUE pairs.
	 * @return array<string, string>
	 */
	public static function parseEnvString( string $envString ): array {
		$env   = array();
		$lines = explode( "\n", $envString );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '=' ) === false ) {
				continue;
			}
			[$key, $val]         = explode( '=', $line, 2 );
			$env[ trim( $key ) ] = trim( $val );
		}

		return $env;
	}

	/**
	 * Best-effort CPU core count detection.
	 * Returns at least 1 when detection is unavailable.
	 */
	public static function detectCpuCoreCount(): int {
		// Prefer explicitly reported CPU count on Windows.
		$winCount = (int) ( getenv( 'NUMBER_OF_PROCESSORS' ) ?: 0 );
		if ( $winCount > 0 ) {
			return $winCount;
		}

		// Try POSIX-style commands when shell execution is available.
		if ( function_exists( 'shell_exec' ) ) {
			$commands = array();
			if ( 'Darwin' === PHP_OS_FAMILY || 'BSD' === PHP_OS_FAMILY ) {
				$commands[] = 'sysctl -n hw.ncpu 2>/dev/null';
			}
			$commands[] = 'nproc 2>/dev/null';
			$commands[] = 'getconf _NPROCESSORS_ONLN 2>/dev/null';

			foreach ( $commands as $command ) {
				$output = @shell_exec( $command );
				if ( ! is_string( $output ) ) {
					continue;
				}
				$count = (int) trim( $output );
				if ( $count > 0 ) {
					return $count;
				}
			}
		}

		return 1;
	}
}
