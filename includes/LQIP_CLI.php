<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for LQIP (ThumbHash) management.
 *
 * @package Ddegner\AvifLocalSupport
 */
class LQIP_CLI {

	/**
	 * Show LQIP statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp lqip stats
	 *     wp lqip stats --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function stats( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$stats  = ThumbHash::getStats();

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'csv' === $format ) {
			\WP_CLI::line( 'total,with_lqip,without_lqip' );
			\WP_CLI::line( sprintf( '%d,%d,%d', $stats['total'], $stats['with_hash'], $stats['without_hash'] ) );
			return;
		}

		// Table format.
		$percentage = $stats['total'] > 0
			? round( ( $stats['with_hash'] / $stats['total'] ) * 100, 1 )
			: 0;

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%G=== LQIP Statistics ===%n' ) );
		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( 'Total images:      %d', $stats['total'] ) );
		\WP_CLI::line( sprintf( 'With LQIP:         %d (%s%%)', $stats['with_hash'], $percentage ) );
		\WP_CLI::line( sprintf( 'Without LQIP:      %d', $stats['without_hash'] ) );
		\WP_CLI::line( '' );

		if ( $stats['without_hash'] > 0 ) {
			\WP_CLI::line( \WP_CLI::colorize( '%YRun `wp lqip generate --all` to generate missing LQIPs.%n' ) );
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Generate LQIP for images.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>]
	 * : Specific attachment ID to generate LQIP for
	 *
	 * [--all]
	 * : Generate LQIP for all attachments missing them
	 *
	 * [--dry-run]
	 * : Show what would be generated without actually generating
	 *
	 * ## EXAMPLES
	 *
	 *     wp lqip generate --all
	 *     wp lqip generate 123
	 *     wp lqip generate --all --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$all          = isset( $assoc_args['all'] );
		$dryRun       = isset( $assoc_args['dry-run'] );
		$attachmentId = ! empty( $args[0] ) ? (int) $args[0] : 0;

		if ( ! $all && 0 === $attachmentId ) {
			\WP_CLI::error( 'Please specify an attachment ID or use --all flag.' );
			return;
		}

		if ( $attachmentId > 0 ) {
			$this->generateSingle( $attachmentId, $dryRun );
			return;
		}

		$this->generateAll( $dryRun );
	}

	/**
	 * Delete LQIP data for attachments.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>]
	 * : Attachment ID to delete LQIP for
	 *
	 * [--all]
	 * : Delete all LQIP data
	 *
	 * [--yes]
	 * : Skip confirmation prompt when using --all
	 *
	 * ## EXAMPLES
	 *
	 *     wp lqip delete 123
	 *     wp lqip delete --all
	 *     wp lqip delete --all --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$all          = isset( $assoc_args['all'] );
		$attachmentId = ! empty( $args[0] ) ? (int) $args[0] : 0;

		if ( ! $all && 0 === $attachmentId ) {
			\WP_CLI::error( 'Please specify an attachment ID or use --all flag.' );
			return;
		}

		if ( $attachmentId > 0 ) {
			$this->deleteSingle( $attachmentId );
			return;
		}

		$this->deleteAll( $assoc_args );
	}

	// =========================================================================
	// Private helper methods
	// =========================================================================

	/**
	 * Generate LQIP for a single attachment.
	 */
	private function generateSingle( int $attachmentId, bool $dryRun ): void {
		$post = get_post( $attachmentId );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			\WP_CLI::error( "Attachment ID {$attachmentId} not found." );
			return;
		}

		$mimeType = get_post_mime_type( $attachmentId );
		if ( ! $mimeType || ! preg_match( '/^image\/(jpe?g|png|gif|webp)$/i', $mimeType ) ) {
			\WP_CLI::error( "Attachment ID {$attachmentId} is not a supported image type." );
			return;
		}

		// Check if already has LQIP
		$existing = get_post_meta( $attachmentId, ThumbHash::getMetaKey(), true );
		if ( ! empty( $existing ) && is_array( $existing ) ) {
			\WP_CLI::line( "Attachment ID {$attachmentId} already has LQIP data." );
			return;
		}

		if ( $dryRun ) {
			\WP_CLI::line( "Would generate LQIP for attachment ID {$attachmentId}" );
			return;
		}

		\WP_CLI::line( "Generating LQIP for attachment ID {$attachmentId}..." );
		ThumbHash::generateForAttachment( $attachmentId );

		// Verify it was generated
		$hash = get_post_meta( $attachmentId, ThumbHash::getMetaKey(), true );
		if ( ! empty( $hash ) && is_array( $hash ) ) {
			$count = count( $hash );
			\WP_CLI::success( "Generated LQIP for {$count} size(s)." );
		} else {
			$error = ThumbHash::getLastError();
			\WP_CLI::error( 'Failed to generate LQIP.' . ( $error ? " Error: {$error}" : '' ) );
		}
	}

	/**
	 * Generate LQIP for all attachments missing them.
	 */
	private function generateAll( bool $dryRun ): void {
		$stats = ThumbHash::getStats();

		if ( 0 === $stats['total'] ) {
			\WP_CLI::line( 'No images found in media library.' );
			return;
		}

		if ( 0 === $stats['without_hash'] ) {
			\WP_CLI::success( 'All images already have LQIP data.' );
			return;
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( 'Total images:      %d', $stats['total'] ) );
		\WP_CLI::line( sprintf( 'With LQIP:         %d', $stats['with_hash'] ) );
		\WP_CLI::line( sprintf( 'Without LQIP:      %d', $stats['without_hash'] ) );
		\WP_CLI::line( '' );

		if ( $dryRun ) {
			\WP_CLI::line( sprintf( 'Would generate LQIP for %d images.', $stats['without_hash'] ) );
			return;
		}

		\WP_CLI::line( sprintf( 'Generating LQIP for %d images...', $stats['without_hash'] ) );
		\WP_CLI::line( '' );

		$startTime    = microtime( true );
		$totalMissing = $stats['without_hash'];
		$processed    = 0;

		// Get all image attachments without LQIP
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_mime_type'         => array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp' ),
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$generated = 0;
		$skipped   = 0;
		$failed    = 0;

		foreach ( $query->posts as $attachmentId ) {
			// Skip if already has LQIP
			$existing = get_post_meta( $attachmentId, ThumbHash::getMetaKey(), true );
			if ( ! empty( $existing ) && is_array( $existing ) ) {
				++$skipped;
				continue;
			}

			ThumbHash::generateForAttachment( (int) $attachmentId );

			// Verify it was generated
			$hash = get_post_meta( $attachmentId, ThumbHash::getMetaKey(), true );
			if ( ! empty( $hash ) && is_array( $hash ) ) {
				++$generated;
				++$processed;
				$this->printProgress( $processed, $totalMissing, $startTime );
			} else {
				++$failed;
			}
		}

		// Clear the progress line.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI progress output.
		fwrite( STDERR, "\r" . str_repeat( ' ', 80 ) . "\r" );

		\WP_CLI::success( sprintf( 'Generated: %d, Skipped: %d, Failed: %d', $generated, $skipped, $failed ) );
	}

	/**
	 * Delete LQIP for a single attachment.
	 */
	private function deleteSingle( int $attachmentId ): void {
		$post = get_post( $attachmentId );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			\WP_CLI::error( "Attachment ID {$attachmentId} not found." );
			return;
		}

		$existing = get_post_meta( $attachmentId, ThumbHash::getMetaKey(), true );
		if ( empty( $existing ) ) {
			\WP_CLI::line( "No LQIP data found for attachment ID {$attachmentId}." );
			return;
		}

		ThumbHash::deleteForAttachment( $attachmentId );
		\WP_CLI::success( "Deleted LQIP data for attachment ID {$attachmentId}." );
	}

	/**
	 * Delete all LQIP data.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	private function deleteAll( array $assoc_args ): void {
		$stats = ThumbHash::getStats();

		if ( $stats['with_hash'] === 0 ) {
			\WP_CLI::line( 'No LQIP data to delete.' );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				sprintf( 'This will delete LQIP data for %d images. Continue?', $stats['with_hash'] )
			);
		}

		$deleted = ThumbHash::deleteAll();
		\WP_CLI::success( "Deleted {$deleted} LQIP entries." );
	}

	/**
	 * Print progress with elapsed and estimated time in hh:mm:ss format.
	 */
	private function printProgress( int $current, int $total, float $startTime ): void {
		$elapsed    = microtime( true ) - $startTime;
		$percentage = ( $total > 0 ) ? round( ( $current / $total ) * 100, 1 ) : 0;

		// Calculate estimated time remaining
		$eta = 0;
		if ( $current > 0 && $current < $total ) {
			$avgTimePerItem = $elapsed / $current;
			$eta            = $avgTimePerItem * ( $total - $current );
		}

		$elapsedStr = $this->formatSecondsToTime( (int) $elapsed );
		$etaStr     = $this->formatSecondsToTime( (int) $eta );

		// Build progress bar
		$barWidth = 20;
		$filled   = ( $total > 0 ) ? (int) round( ( $current / $total ) * $barWidth ) : 0;
		$empty    = $barWidth - $filled;
		$bar      = str_repeat( '█', $filled ) . str_repeat( '░', $empty );

		// Output progress on same line (using STDERR like WP-CLI progress bar)
		$output = sprintf(
			"\rProgress: [%s] %d/%d (%.1f%%) | Elapsed: %s | ETA: %s",
			$bar,
			$current,
			$total,
			$percentage,
			$elapsedStr,
			$etaStr
		);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI progress output.
		fwrite( STDERR, $output );
	}

	/**
	 * Format seconds as hh:mm:ss.
	 */
	private function formatSecondsToTime( int $seconds ): string {
		if ( $seconds < 0 ) {
			$seconds = 0;
		}

		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		return sprintf( '%02d:%02d:%02d', $hours, $minutes, $secs );
	}
}
