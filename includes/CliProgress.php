<?php
/**
 * Shared WP-CLI progress bar with elapsed/ETA display.
 *
 * @package Ddegner\AvifLocalSupport
 */

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Shared WP-CLI progress bar with elapsed/ETA display.
 *
 * Used by the `wp avif` and `wp lqip` commands so the rendering logic
 * lives in one place.
 */
final class CliProgress {

	/**
	 * Width of the progress bar in characters.
	 */
	private const BAR_WIDTH = 20;

	/**
	 * Render progress on the current line (STDERR, like WP-CLI progress bars).
	 *
	 * @param int   $current   Items processed so far.
	 * @param int   $total     Total items to process.
	 * @param float $startTime microtime(true) when processing began.
	 */
	public static function render( int $current, int $total, float $startTime ): void {
		$total      = max( 1, $total );
		$elapsed    = microtime( true ) - $startTime;
		$percentage = round( ( $current / $total ) * 100, 1 );

		$eta = 0.0;
		if ( $current > 0 && $current < $total ) {
			$eta = ( $elapsed / $current ) * ( $total - $current );
		}

		$filled = (int) round( ( $current / $total ) * self::BAR_WIDTH );
		$filled = max( 0, min( self::BAR_WIDTH, $filled ) );
		$bar    = str_repeat( '█', $filled ) . str_repeat( '░', self::BAR_WIDTH - $filled );

		$output = sprintf(
			"\rProgress: [%s] %d/%d (%.1f%%) | Elapsed: %s | ETA: %s",
			$bar,
			$current,
			$total,
			$percentage,
			self::formatSeconds( (int) $elapsed ),
			self::formatSeconds( (int) $eta )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI progress output.
		fwrite( STDERR, $output );
	}

	/**
	 * Clear the progress line so subsequent output starts clean.
	 */
	public static function clear(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI progress output.
		fwrite( STDERR, "\r" . str_repeat( ' ', 80 ) . "\r" );
	}

	/**
	 * Format seconds as hh:mm:ss.
	 *
	 * @param int $seconds Seconds to format.
	 */
	private static function formatSeconds( int $seconds ): string {
		$seconds = max( 0, $seconds );

		return sprintf(
			'%02d:%02d:%02d',
			(int) floor( $seconds / 3600 ),
			(int) floor( ( $seconds % 3600 ) / 60 ),
			$seconds % 60
		);
	}
}
