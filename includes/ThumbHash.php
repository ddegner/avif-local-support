<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

use Thumbhash\Thumbhash as ThumbhashLib;

// Prevent direct access.
\defined( 'ABSPATH' ) || exit;

/**
 * ThumbHash LQIP (Low Quality Image Placeholder) service.
 *
 * Generates ultra-compact (~30 bytes) image hashes that can be decoded
 * client-side to smooth placeholders while full images load.
 */
final class ThumbHash {



	/**
	 * Post meta key for storing ThumbHash data.
	 */
	private const META_KEY = '_aviflosu_thumbhash';
	private const STOP_TRANSIENT = 'aviflosu_stop_lqip_generation';
	private const GENERATION_OPTION = 'aviflosu_lqip_generation';

	/**
	 * Cron hook that runs one slice of the bulk LQIP generation scan.
	 */
	public const GENERATE_HOOK = 'aviflosu_run_lqip_generation';

	/**
	 * Resume cursor for the batched generation scan.
	 */
	private const CURSOR_TRANSIENT = 'aviflosu_lqip_cursor';

	/**
	 * Progress snapshot polled by the admin UI during bulk generation.
	 */
	private const PROGRESS_TRANSIENT = 'aviflosu_lqip_progress';
	private const PROGRESS_TTL       = HOUR_IN_SECONDS;

	/**
	 * Maximum dimension for thumbnail before hashing.
	 * Set to 100px (ThumbHash maximum) to capture more detail in the DCT encoding.
	 * The decoder outputs 32px, but larger input = more frequency data = richer placeholders.
	 */
	private const MAX_DIMENSION = 100;

	/**
	 * Get the maximum dimension for ThumbHash generation.
	 * Fixed at 32px to match the decoder output.
	 */
	public static function getMaxDimension(): int {
		return self::MAX_DIMENSION;
	}

	/**
	 * Check if ThumbHash feature is enabled.
	 */
	public static function isEnabled(): bool {
		return (bool) \get_option( 'aviflosu_thumbhash_enabled', false );
	}

	/**
	 * Check if the ThumbHash library is available.
	 *
	 * @return bool True if the library class exists, false otherwise.
	 */
	public static function isLibraryAvailable(): bool {
		return class_exists( 'Thumbhash\Thumbhash' );
	}

	/**
	 * Generate ThumbHash string for an image file.
	 *
	 * @param string $imagePath Absolute path to JPEG/PNG image.
	 * @return string|null Base64-encoded ThumbHash or null on failure.
	 */
	public static function generate( string $imagePath ): ?string {
		// Check if the ThumbHash library is available
		if ( ! self::isLibraryAvailable() ) {
			self::$lastError = 'ThumbHash library not found. Please run "composer install" in the plugin directory to install dependencies.';
			if ( class_exists( Logger::class ) ) {
				( new Logger() )->addLog(
					'error',
					'ThumbHash library not available',
					array(
						'path'  => $imagePath,
						'error' => 'Thumbhash\Thumbhash class not found. Composer dependencies may not be installed.',
					)
				);
			}
			return null;
		}

		if ( ! file_exists( $imagePath ) || ! is_readable( $imagePath ) ) {
			self::$lastError = "File not found or unreadable: $imagePath";
			if ( class_exists( Logger::class ) ) {
				( new Logger() )->addLog( 'error', 'ThumbHash failed: File not found', array( 'path' => $imagePath ) );
			}
			return null;
		}

		try {
			// Try Imagick first (better alpha support)
			if ( extension_loaded( 'imagick' ) && class_exists( \Imagick::class ) ) {
				return self::generateWithImagick( $imagePath );
			}

			// Fall back to GD
			if ( extension_loaded( 'gd' ) ) {
				return self::generateWithGd( $imagePath );
			}

			self::$lastError = 'No supported image library (Imagick or GD) found.';
			return null;
		} catch ( \Throwable $e ) {
			// Log error but don't block conversion
			self::$lastError = $e->getMessage();

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'ThumbHash generation failed for ' . $imagePath . ': ' . $e->getMessage() );
			}

			if ( class_exists( Logger::class ) ) {
				( new Logger() )->addLog(
					'error',
					'ThumbHash generation exception',
					array(
						'path'  => $imagePath,
						'error' => $e->getMessage(),
					)
				);
			}

			return null;
		}
	}

	/**
	 * Last error message for debugging.
	 */
	private static ?string $lastError = null;

	/**
	 * Get the last error that occurred during generation.
	 */
	public static function getLastError(): ?string {
		return self::$lastError;
	}

	/**
	 * Generate ThumbHash using Imagick.
	 */
	private static function generateWithImagick( string $imagePath ): ?string {
		$imagick = new \Imagick();
		// Optimization: Hint to libjpeg to load a smaller version (downscale) to save memory.
		// ThumbHash output is 32px max, but we request 200x200 to give the resampling algorithm
		// sufficient source data for smooth downscaling while still significantly reducing memory for large JPEGs.
		try {
			$imagick->setOption( 'jpeg:size', '200x200' );
		} catch ( \Throwable $e ) {
			// Ignore if setOption fails (e.g. older ImageMagick versions)
		}
		$imagick->readImage( $imagePath );

		// Get original dimensions
		$width  = $imagick->getImageWidth();
		$height = $imagick->getImageHeight();

		// Calculate thumbnail dimensions maintaining aspect ratio
		if ( $width > self::getMaxDimension() || $height > self::getMaxDimension() ) {
			if ( $width >= $height ) {
				$newWidth  = self::getMaxDimension();
				$newHeight = (int) round( $height * ( self::getMaxDimension() / $width ) );
			} else {
				$newHeight = self::getMaxDimension();
				$newWidth  = (int) round( $width * ( self::getMaxDimension() / $height ) );
			}
			// Ensure minimum of 1px
			$newWidth  = max( 1, $newWidth );
			$newHeight = max( 1, $newHeight );
			$imagick->thumbnailImage( $newWidth, $newHeight );
		} else {
			$newWidth  = $width;
			$newHeight = $height;
		}

		// Extract RGBA pixels
		$pixels   = array();
		$iterator = $imagick->getPixelIterator();

		foreach ( $iterator as $row ) {
			foreach ( $row as $pixel ) {
				/** @var \ImagickPixel $pixel */
				// Use getColorValue() for compatibility across Imagick versions
				$pixels[] = (int) round( $pixel->getColorValue( \Imagick::COLOR_RED ) * 255 );
				$pixels[] = (int) round( $pixel->getColorValue( \Imagick::COLOR_GREEN ) * 255 );
				$pixels[] = (int) round( $pixel->getColorValue( \Imagick::COLOR_BLUE ) * 255 );
				$pixels[] = (int) round( $pixel->getColorValue( \Imagick::COLOR_ALPHA ) * 255 );
			}
			$iterator->syncIterator();
		}

		$imagick->destroy();

		// Generate hash
		$hash = ThumbhashLib::RGBAToHash( $newWidth, $newHeight, $pixels );

		return ThumbhashLib::convertHashToString( $hash );
	}

	/**
	 * Generate ThumbHash using GD.
	 */
	private static function generateWithGd( string $imagePath ): ?string {
		$imageInfo = @getimagesize( $imagePath );
		if ( ! $imageInfo ) {
			return null;
		}

		$mimeType = $imageInfo['mime'] ?? '';
		$image    = match ( $mimeType ) {
			'image/jpeg', 'image/jpg' => @imagecreatefromjpeg( $imagePath ),
			'image/png' => @imagecreatefrompng( $imagePath ),
			'image/gif' => @imagecreatefromgif( $imagePath ),
			'image/webp' => @imagecreatefromwebp( $imagePath ),
			default => false,
		};

		if ( ! $image ) {
			return null;
		}

		// imagecolorat() returns a palette index for indexed GIF/PNG images.
		// Normalize even small images that will not pass through the resize path.
		if ( ! imageistruecolor( $image ) && ! imagepalettetotruecolor( $image ) ) {
			return null;
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );

		// Calculate thumbnail dimensions maintaining aspect ratio
		if ( $width > self::getMaxDimension() || $height > self::getMaxDimension() ) {
			if ( $width >= $height ) {
				$newWidth  = self::getMaxDimension();
				$newHeight = (int) round( $height * ( self::getMaxDimension() / $width ) );
			} else {
				$newHeight = self::getMaxDimension();
				$newWidth  = (int) round( $width * ( self::getMaxDimension() / $height ) );
			}
			$newWidth  = max( 1, $newWidth );
			$newHeight = max( 1, $newHeight );

			$resized = imagecreatetruecolor( $newWidth, $newHeight );
			if ( ! $resized ) {
				return null;
			}

			// Preserve alpha channel
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );

			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height );
			$image = $resized;
		} else {
			$newWidth  = $width;
			$newHeight = $height;
		}

		// Extract RGBA pixels
		$pixels = array();
		for ( $y = 0; $y < $newHeight; $y++ ) {
			for ( $x = 0; $x < $newWidth; $x++ ) {
				$rgba     = imagecolorat( $image, $x, $y );
				$pixels[] = ( $rgba >> 16 ) & 0xFF; // R
				$pixels[] = ( $rgba >> 8 ) & 0xFF;  // G
				$pixels[] = $rgba & 0xFF;          // B
				// GD alpha: 127 = transparent, 0 = opaque (invert for ThumbHash)
				$alpha    = ( $rgba >> 24 ) & 0x7F;
				$pixels[] = (int) round( ( 127 - $alpha ) * ( 255 / 127 ) );
			}
		}

		// Generate hash
		$hash = ThumbhashLib::RGBAToHash( $newWidth, $newHeight, $pixels );

		return ThumbhashLib::convertHashToString( $hash );
	}

	/**
	 * Get ThumbHash for a specific attachment size.
	 *
	 * @param int    $attachmentId WordPress attachment ID.
	 * @param string $size         Size name (e.g., 'full', 'medium', 'thumbnail').
	 * @return string|null Base64-encoded ThumbHash or null if not available.
	 */
	public static function getForAttachment( int $attachmentId, string $size = 'full' ): ?string {
		if ( ! self::isEnabled() ) {
			return null;
		}

		$meta = \get_post_meta( $attachmentId, self::META_KEY, true );
		if ( ! is_array( $meta ) ) {
			return null;
		}

		return $meta[ $size ] ?? $meta['full'] ?? null;
	}

	/**
	 * Generate and store ThumbHashes for all sizes of an attachment.
	 *
	 * @param int  $attachmentId WordPress attachment ID.
	 * @param bool $force Generate even when display of placeholders is disabled.
	 * @return array<string, string>|null Hash array keyed by size name, or null on failure.
	 */
	public static function generateForAttachment( int $attachmentId, bool $force = false ): ?array {
		if ( ! $force && ! self::isEnabled() ) {
			return null;
		}

		return self::doGenerateForAttachment( $attachmentId );
	}

	/**
	 * Request cancellation of an in-progress bulk generation run.
	 */
	public static function requestStop(): void {
		AttachmentBatchRunner::stop( self::GENERATE_HOOK, self::STOP_TRANSIENT, self::CURSOR_TRANSIENT );

		// Reflect the stop in the polled progress even when no slice is
		// currently running (e.g. between continuation events).
		$progress = self::getGenerationProgress();
		if ( in_array( $progress['state'], array( 'queued', 'running' ), true ) ) {
			$progress['state'] = 'stopped';
			\set_transient( self::PROGRESS_TRANSIENT, $progress, self::PROGRESS_TTL );
		}
	}

	/**
	 * Queue a bulk LQIP generation scan unless one is already pending.
	 *
	 * @param int $delay Seconds before the first slice starts.
	 */
	public static function queueGenerateAll( int $delay = 5 ): bool {
		$queued = AttachmentBatchRunner::queue( self::GENERATE_HOOK, self::STOP_TRANSIENT, $delay );
		if ( $queued ) {
			// Reset progress so admin polling reflects the new job immediately.
			\set_transient(
				self::PROGRESS_TRANSIENT,
				array(
					'state'     => 'queued',
					'generated' => 0,
					'skipped'   => 0,
					'failed'    => 0,
				),
				self::PROGRESS_TTL
			);
		}
		return $queued;
	}

	/**
	 * Current bulk-generation progress for admin UI polling.
	 *
	 * @return array{state: string, generated: int, skipped: int, failed: int}
	 */
	public static function getGenerationProgress(): array {
		$progress = \get_transient( self::PROGRESS_TRANSIENT );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}

		return array(
			'state'     => (string) ( $progress['state'] ?? 'idle' ),
			'generated' => (int) ( $progress['generated'] ?? 0 ),
			'skipped'   => (int) ( $progress['skipped'] ?? 0 ),
			'failed'    => (int) ( $progress['failed'] ?? 0 ),
		);
	}

	/**
	 * Run one time slice of the bulk generation scan; continuation slices are
	 * self-scheduled by the runner until the library is covered.
	 */
	public static function runGenerationBatch(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			\wp_raise_memory_limit( 'image' );
		}

		$progress = self::getGenerationProgress();
		if ( ! in_array( $progress['state'], array( 'queued', 'running' ), true ) ) {
			// Scan started outside queueGenerateAll() (e.g. a bare cron event).
			$progress = array(
				'generated' => 0,
				'skipped'   => 0,
				'failed'    => 0,
			);
		}
		$progress['state'] = 'running';
		\set_transient( self::PROGRESS_TRANSIENT, $progress, self::PROGRESS_TTL );

		$runner = new AttachmentBatchRunner(
			self::CURSOR_TRANSIENT,
			self::STOP_TRANSIENT,
			self::GENERATE_HOOK,
			AttachmentQuery::IMAGE_MIMES,
			static function ( int $attachmentId ) use ( &$progress ): void {
				++$progress[ self::generateMissingForAttachment( $attachmentId ) ];
			}
		);
		$status = $runner->run();

		$progress['state'] = match ( $status ) {
			AttachmentBatchRunner::STATUS_COMPLETE => 'complete',
			AttachmentBatchRunner::STATUS_STOPPED  => 'stopped',
			default                                => 'running',
		};
		\set_transient( self::PROGRESS_TRANSIENT, $progress, self::PROGRESS_TTL );

		if ( 'running' !== $progress['state'] && class_exists( Logger::class ) ) {
			( new Logger() )->addLog(
				$progress['failed'] > 0 || 'stopped' === $progress['state'] ? 'warning' : 'success',
				sprintf(
					'LQIP bulk generation %s: %d generated, %d skipped, %d failed',
					$progress['state'],
					$progress['generated'],
					$progress['skipped'],
					$progress['failed']
				),
				$progress
			);
		}
	}

	/**
	 * Generate hashes for one attachment unless it already has a valid set.
	 *
	 * @return string Outcome: 'generated', 'skipped', or 'failed'.
	 */
	public static function generateMissingForAttachment( int $attachmentId, bool $force = false ): string {
		// Clear object cache for this post so persistent caches (Redis/
		// Memcached) cannot serve stale meta data.
		\clean_post_cache( $attachmentId );

		if ( ! $force && self::hasValidHash( $attachmentId ) ) {
			return 'skipped';
		}

		$hashes = self::doGenerateForAttachment( $attachmentId );
		return ( is_array( $hashes ) && ! empty( $hashes['full'] ) ) ? 'generated' : 'failed';
	}

	/**
	 * Whether an attachment already has a valid stored ThumbHash set.
	 */
	public static function hasValidHash( int $attachmentId ): bool {
		return self::isValidHashSet( \get_post_meta( $attachmentId, self::META_KEY, true ) );
	}

	/**
	 * Whether a meta value is a valid ThumbHash set (has a proper 'full' hash).
	 *
	 * @param mixed $meta Stored meta value to validate.
	 */
	public static function isValidHashSet( $meta ): bool {
		return is_array( $meta )
			&& isset( $meta['full'] )
			&& is_string( $meta['full'] )
			&& strlen( $meta['full'] ) > 10;
	}

	/**
	 * Delete stored ThumbHashes for an attachment.
	 *
	 * @param int $attachmentId WordPress attachment ID.
	 */
	public static function deleteForAttachment( int $attachmentId ): void {
		\delete_post_meta( $attachmentId, self::META_KEY );
	}

	/**
	 * Get the meta key used for ThumbHash storage.
	 * Used by uninstall.php for cleanup.
	 */
	public static function getMetaKey(): string {
		return self::META_KEY;
	}

	/**
	 * Internal helper to generate ThumbHashes for an attachment without checking isEnabled().
	 * Used by bulk operations where the caller handles the enable check.
	 *
	 * @param int $attachmentId WordPress attachment ID.
	 * @return array<string, string>|null Hash array or null on failure.
	 */
	private static function doGenerateForAttachment( int $attachmentId ): ?array {
		self::$lastError = null;
		$generation = self::getGeneration();
		$metadata = \wp_get_attachment_metadata( $attachmentId );
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			// Fall back to the attached file so attachments with broken or
			// missing metadata still get a 'full' placeholder.
			$attached = (string) \get_attached_file( $attachmentId );
			if ( '' === $attached || ! file_exists( $attached ) ) {
				self::$lastError = "Invalid or missing metadata for attachment $attachmentId";
				return null;
			}
			$hash = self::generate( $attached );
			if ( $hash ) {
				$hashes = array( 'full' => $hash );
				return self::storeHashes( $attachmentId, $hashes, $generation );
			}
			return null;
		}

		$uploadDir = \wp_upload_dir();
		$baseDir   = $uploadDir['basedir'] ?? '';
		if ( ! $baseDir ) {
			self::$lastError = 'Upload basedir not found.';
			return null;
		}

		$hashes  = array();
		$file    = $metadata['file'];
		$fileDir = dirname( $file );

		// Generate for original/full image
		$fullPath = $baseDir . '/' . $file;
		// Some setups have 'file' as absolute path (rare but possible in offload plugins)
		if ( ! file_exists( $fullPath ) ) {
			if ( file_exists( $file ) ) {
				$fullPath = $file;
			} else {
				self::$lastError = "File not found: $fullPath";
				if ( class_exists( Logger::class ) ) {
					( new Logger() )->addLog( 'warning', "ThumbHash skipped: Source file missing for ID $attachmentId", array( 'path' => $fullPath ) );
				}
			}
		}

		if ( file_exists( $fullPath ) ) {
			$hash = self::generate( $fullPath );
			if ( $hash ) {
				$hashes['full'] = $hash;
			}
		}

		// Generate for each registered size
		$sizes = $metadata['sizes'] ?? array();
		foreach ( $sizes as $sizeName => $sizeData ) {
			$sizeFile = $sizeData['file'] ?? '';
			if ( ! $sizeFile ) {
				continue;
			}

			$sizePath = $baseDir . '/' . $fileDir . '/' . $sizeFile;
			if ( file_exists( $sizePath ) ) {
				$hash = self::generate( $sizePath );
				if ( $hash ) {
					$hashes[ $sizeName ] = $hash;
				}
			}
		}

		if ( ! empty( $hashes ) ) {
			return self::storeHashes( $attachmentId, $hashes, $generation );
		}

		return null;
	}

	/**
	 * Read cancellation state freshly, including across requests with object caching.
	 */
	private static function getGeneration(): int {
		\wp_cache_delete( self::GENERATION_OPTION, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		return (int) \get_option( self::GENERATION_OPTION, 0 );
	}

	/**
	 * Do not repopulate hashes if a bulk delete happened while this image encoded.
	 *
	 * @param int                  $attachmentId Attachment ID.
	 * @param array<string,string> $hashes Generated placeholders.
	 * @param int                  $generation Cancellation revision at encode start.
	 * @return array<string,string>|null
	 */
	private static function storeHashes( int $attachmentId, array $hashes, int $generation ): ?array {
		if ( self::getGeneration() !== $generation ) {
			self::$lastError = 'LQIP generation was cancelled by a bulk delete.';
			return null;
		}
		\update_post_meta( $attachmentId, self::META_KEY, $hashes );
		// A delete can also arrive between the check and the metadata write.
		// Remove only this value, leaving a different newer result untouched.
		if ( self::getGeneration() !== $generation ) {
			\delete_post_meta( $attachmentId, self::META_KEY, $hashes );
			self::$lastError = 'LQIP generation was cancelled by a bulk delete.';
			return null;
		}
		return $hashes;
	}

	/**
	 * Delete all ThumbHash metadata from all attachments.
	 *
	 * @return int Number of meta entries deleted.
	 */
	public static function deleteAll(): int {
		global $wpdb;

		// Cancel first, even when the queued scan has not saved any hashes yet.
		self::requestStop();
		\update_option( self::GENERATION_OPTION, self::getGeneration() + 1, false );
		\delete_transient( self::PROGRESS_TRANSIENT );

		// Get all attachment IDs that have ThumbHash data
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$postIds = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		if ( empty( $postIds ) ) {
			return 0;
		}

		// Delete the meta data directly
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		// Clear object cache for affected posts to prevent stale data in get_post_meta
		foreach ( $postIds as $postId ) {
			\clean_post_cache( (int) $postId );
		}

		// Log the deletion
		if ( class_exists( Logger::class ) ) {
			( new Logger() )->addLog(
				'info',
				sprintf( 'LQIP bulk delete: %d entries deleted', $deleted ),
				array( 'deleted' => (int) $deleted )
			);
		}

		return (int) $deleted;
	}

	/**
	 * Count attachments with ThumbHash metadata.
	 *
	 * Validates that entries have a proper 'full' key with hash length > 10,
	 * matching the bulk-generation skip logic to ensure consistent reporting.
	 *
	 * @return array{with_hash: int, without_hash: int, total: int}
	 */
	public static function getStats(): array {
		global $wpdb;

		// Count total image attachments
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp')"
		);

		// Get all ThumbHash metadata entries and validate structure
		// This ensures stats match the skip logic which checks for valid 'full' key
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		$withHash    = 0;
		$seenPostIds = array();

		foreach ( $rows as $row ) {
			// Skip if we've already counted this post
			if ( isset( $seenPostIds[ $row->post_id ] ) ) {
				continue;
			}

			// Validate the structure matches what the generation skip logic expects
			$meta = maybe_unserialize( $row->meta_value );
			if ( self::isValidHashSet( $meta ) ) {
				++$withHash;
				$seenPostIds[ $row->post_id ] = true;
			}
		}

		return array(
			'with_hash'    => $withHash,
			'without_hash' => $total - $withHash,
			'total'        => $total,
		);
	}
}
