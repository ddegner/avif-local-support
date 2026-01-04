<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

use Thumbhash\Thumbhash as ThumbhashLib;

// Prevent direct access.
\defined('ABSPATH') || exit;

/**
 * ThumbHash LQIP (Low Quality Image Placeholder) service.
 *
 * Generates ultra-compact (~30 bytes) image hashes that can be decoded
 * client-side to smooth placeholders while full images load.
 */
final class ThumbHash
{


	/**
	 * Post meta key for storing ThumbHash data.
	 */
	private const META_KEY = '_aviflosu_thumbhash';

	/**
	 * Default maximum dimension for thumbnail before hashing.
	 * ThumbHash library requires images ≤100×100.
	 */
	private const DEFAULT_MAX_DIMENSION = 100;

	/**
	 * Available size options.
	 */
	public const SIZE_OPTIONS = array(
		16 => 'Extra Small (16px)',
		32 => 'Small (32px)',
		64 => 'Medium (64px)',
		100 => 'Large (100px)',
	);

	/**
	 * Get the configured maximum dimension for ThumbHash generation.
	 */
	public static function getMaxDimension(): int
	{
		$size = (int) \get_option('aviflosu_thumbhash_size', self::DEFAULT_MAX_DIMENSION);
		if (!array_key_exists($size, self::SIZE_OPTIONS)) {
			return self::DEFAULT_MAX_DIMENSION;
		}
		return $size;
	}

	/**
	 * Check if ThumbHash feature is enabled.
	 */
	public static function isEnabled(): bool
	{
		return (bool) \get_option('aviflosu_thumbhash_enabled', false);
	}

	/**
	 * Check if the ThumbHash library is available.
	 *
	 * @return bool True if the library class exists, false otherwise.
	 */
	public static function isLibraryAvailable(): bool
	{
		return class_exists('Thumbhash\Thumbhash');
	}

	/**
	 * Generate ThumbHash string for an image file.
	 *
	 * @param string $imagePath Absolute path to JPEG/PNG image.
	 * @return string|null Base64-encoded ThumbHash or null on failure.
	 */
	public static function generate(string $imagePath): ?string
	{
		// Check if the ThumbHash library is available
		if (!self::isLibraryAvailable()) {
			self::$lastError = 'ThumbHash library not found. Please run "composer install" in the plugin directory to install dependencies.';
			if (class_exists(Logger::class)) {
				(new Logger())->addLog(
					'error',
					'ThumbHash library not available',
					array(
						'path' => $imagePath,
						'error' => 'Thumbhash\Thumbhash class not found. Composer dependencies may not be installed.',
					)
				);
			}
			return null;
		}

		if (!file_exists($imagePath) || !is_readable($imagePath)) {
			self::$lastError = "File not found or unreadable: $imagePath";
			if (class_exists(Logger::class)) {
				(new Logger())->addLog('error', 'ThumbHash failed: File not found', array('path' => $imagePath));
			}
			return null;
		}

		try {
			// Try Imagick first (better alpha support)
			if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
				return self::generateWithImagick($imagePath);
			}

			// Fall back to GD
			if (extension_loaded('gd')) {
				return self::generateWithGd($imagePath);
			}

			self::$lastError = 'No supported image library (Imagick or GD) found.';
			return null;
		} catch (\Throwable $e) {
			// Log error but don't block conversion
			self::$lastError = $e->getMessage();

			if (defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('ThumbHash generation failed for ' . $imagePath . ': ' . $e->getMessage());
			}

			if (class_exists(Logger::class)) {
				(new Logger())->addLog(
					'error',
					'ThumbHash generation exception',
					array(
						'path' => $imagePath,
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
	public static function getLastError(): ?string
	{
		return self::$lastError;
	}

	/**
	 * Generate ThumbHash using Imagick.
	 */
	private static function generateWithImagick(string $imagePath): ?string
	{
		$imagick = new \Imagick();

		try {
			// Optimization: Hint to the decoder to load a smaller version (powers of 2: 1/2, 1/4, 1/8)
			// effectively reducing memory usage for large JPEGs.
			// Requesting 2x the max dimension ensures we have enough data for a good quality downscale.
			$targetSize = self::getMaxDimension() * 2;
			$imagick->setSize($targetSize, $targetSize);
			$imagick->readImage($imagePath);
		} catch (\Throwable $e) {
			$imagick->clear();
			$imagick->destroy();
			throw $e;
		}

		// Get original dimensions
		$width = $imagick->getImageWidth();
		$height = $imagick->getImageHeight();

		// Calculate thumbnail dimensions maintaining aspect ratio
		if ($width > self::getMaxDimension() || $height > self::getMaxDimension()) {
			if ($width >= $height) {
				$newWidth = self::getMaxDimension();
				$newHeight = (int) round($height * (self::getMaxDimension() / $width));
			} else {
				$newHeight = self::getMaxDimension();
				$newWidth = (int) round($width * (self::getMaxDimension() / $height));
			}
			// Ensure minimum of 1px
			$newWidth = max(1, $newWidth);
			$newHeight = max(1, $newHeight);
			$imagick->thumbnailImage($newWidth, $newHeight);
		} else {
			$newWidth = $width;
			$newHeight = $height;
		}

		// Extract RGBA pixels
		$pixels = array();
		$iterator = $imagick->getPixelIterator();

		foreach ($iterator as $row) {
			foreach ($row as $pixel) {
				/** @var \ImagickPixel $pixel */
				// Use getColorValue() for compatibility across Imagick versions
				$pixels[] = (int) round($pixel->getColorValue(\Imagick::COLOR_RED) * 255);
				$pixels[] = (int) round($pixel->getColorValue(\Imagick::COLOR_GREEN) * 255);
				$pixels[] = (int) round($pixel->getColorValue(\Imagick::COLOR_BLUE) * 255);
				$pixels[] = (int) round($pixel->getColorValue(\Imagick::COLOR_ALPHA) * 255);
			}
			$iterator->syncIterator();
		}

		$imagick->destroy();

		// Generate hash
		$hash = ThumbhashLib::RGBAToHash($newWidth, $newHeight, $pixels);

		return ThumbhashLib::convertHashToString($hash);
	}

	/**
	 * Generate ThumbHash using GD.
	 */
	private static function generateWithGd(string $imagePath): ?string
	{
		$imageInfo = @getimagesize($imagePath);
		if (!$imageInfo) {
			return null;
		}

		$mimeType = $imageInfo['mime'] ?? '';
		$image = match ($mimeType) {
			'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($imagePath),
			'image/png' => @imagecreatefrompng($imagePath),
			'image/gif' => @imagecreatefromgif($imagePath),
			'image/webp' => @imagecreatefromwebp($imagePath),
			default => false,
		};

		if (!$image) {
			return null;
		}

		$width = imagesx($image);
		$height = imagesy($image);

		// Calculate thumbnail dimensions maintaining aspect ratio
		if ($width > self::getMaxDimension() || $height > self::getMaxDimension()) {
			if ($width >= $height) {
				$newWidth = self::getMaxDimension();
				$newHeight = (int) round($height * (self::getMaxDimension() / $width));
			} else {
				$newHeight = self::getMaxDimension();
				$newWidth = (int) round($width * (self::getMaxDimension() / $height));
			}
			$newWidth = max(1, $newWidth);
			$newHeight = max(1, $newHeight);

			$resized = imagecreatetruecolor($newWidth, $newHeight);
			if (!$resized) {
				imagedestroy($image);
				return null;
			}

			// Preserve alpha channel
			imagealphablending($resized, false);
			imagesavealpha($resized, true);

			imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
			imagedestroy($image);
			$image = $resized;
		} else {
			$newWidth = $width;
			$newHeight = $height;
		}

		// Extract RGBA pixels
		$pixels = array();
		for ($y = 0; $y < $newHeight; $y++) {
			for ($x = 0; $x < $newWidth; $x++) {
				$rgba = imagecolorat($image, $x, $y);
				$pixels[] = ($rgba >> 16) & 0xFF; // R
				$pixels[] = ($rgba >> 8) & 0xFF;  // G
				$pixels[] = $rgba & 0xFF;          // B
				// GD alpha: 127 = transparent, 0 = opaque (invert for ThumbHash)
				$alpha = ($rgba >> 24) & 0x7F;
				$pixels[] = (int) round((127 - $alpha) * (255 / 127));
			}
		}

		imagedestroy($image);

		// Generate hash
		$hash = ThumbhashLib::RGBAToHash($newWidth, $newHeight, $pixels);

		return ThumbhashLib::convertHashToString($hash);
	}

	/**
	 * Get ThumbHash for a specific attachment size.
	 *
	 * @param int    $attachmentId WordPress attachment ID.
	 * @param string $size         Size name (e.g., 'full', 'medium', 'thumbnail').
	 * @return string|null Base64-encoded ThumbHash or null if not available.
	 */
	public static function getForAttachment(int $attachmentId, string $size = 'full'): ?string
	{
		if (!self::isEnabled()) {
			return null;
		}

		$meta = \get_post_meta($attachmentId, self::META_KEY, true);
		if (!is_array($meta)) {
			return null;
		}

		return $meta[$size] ?? $meta['full'] ?? null;
	}

	/**
	 * Generate and store ThumbHashes for all sizes of an attachment.
	 *
	 * @param int $attachmentId WordPress attachment ID.
	 */
	public static function generateForAttachment(int $attachmentId): void
	{
		if (!self::isEnabled()) {
			return;
		}

		$metadata = \wp_get_attachment_metadata($attachmentId);
		if (!is_array($metadata)) {
			return;
		}

		$uploadDir = \wp_upload_dir();
		$baseDir = $uploadDir['basedir'] ?? '';
		if (!$baseDir) {
			self::$lastError = 'Upload basedir not found.';
			return;
		}

		$hashes = array();

		// Get the directory from metadata
		$file = $metadata['file'] ?? '';
		if (!$file) {
			self::$lastError = "Original file path missing in metadata for attachment $attachmentId";
			return;
		}
		$fileDir = dirname($file);

		// Generate for original/full image
		$fullPath = $baseDir . '/' . $file;
		// Some setups have 'file' as absolute path (rare but possible in offload plugins) -> usually relative
		if (!file_exists($fullPath)) {
			// Check if file exists relative to baseDir directly or if $file is absolute
			if (file_exists($file)) {
				$fullPath = $file;
			} else {
				self::$lastError = "File not found: $fullPath";
				// Log primarily for the main file
				if (class_exists(Logger::class)) {
					(new Logger())->addLog('warning', "ThumbHash skipped: Source file missing for ID $attachmentId", array('path' => $fullPath));
				}
			}
		}

		if (file_exists($fullPath)) {
			$hash = self::generate($fullPath);
			if ($hash) {
				$hashes['full'] = $hash;
			}
		}

		// Generate for each registered size
		$sizes = $metadata['sizes'] ?? array();
		foreach ($sizes as $sizeName => $sizeData) {
			$sizeFile = $sizeData['file'] ?? '';
			if (!$sizeFile) {
				continue;
			}

			$sizePath = $baseDir . '/' . $fileDir . '/' . $sizeFile;
			if (file_exists($sizePath)) {
				$hash = self::generate($sizePath);
				if ($hash) {
					$hashes[$sizeName] = $hash;
				}
			}
		}

		if (!empty($hashes)) {
			\update_post_meta($attachmentId, self::META_KEY, $hashes);
		}
	}

	/**
	 * Delete stored ThumbHashes for an attachment.
	 *
	 * @param int $attachmentId WordPress attachment ID.
	 */
	public static function deleteForAttachment(int $attachmentId): void
	{
		\delete_post_meta($attachmentId, self::META_KEY);
	}

	/**
	 * Get the meta key used for ThumbHash storage.
	 * Used by uninstall.php for cleanup.
	 */
	public static function getMetaKey(): string
	{
		return self::META_KEY;
	}

	/**
	 * Generate ThumbHashes for all image attachments that don't have them yet.
	 *
	 * @param bool $force If true, regenerate even if ThumbHash already exists.
	 * @return array{generated: int, skipped: int, failed: int}
	 */
	public static function generateAll(bool $force = false): array
	{
		$result = array(
			'generated' => 0,
			'skipped' => 0,
			'failed' => 0,
		);

		$query = new \WP_Query(
			array(
				'post_type' => 'attachment',
				'post_mime_type' => array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'),
				'post_status' => 'inherit',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'no_found_rows' => true,
				'update_post_meta_cache' => false, // Avoid potential caching issues
				'update_post_term_cache' => false,
			)
		);

		foreach ($query->posts as $attachmentId) {
			// Skip if already has valid ThumbHash (unless forcing regeneration)
			if (!$force) {
				$existing = \get_post_meta($attachmentId, self::META_KEY, true);
				// Verify it's a valid ThumbHash array with at least a 'full' entry
				if (is_array($existing) && isset($existing['full']) && is_string($existing['full']) && strlen($existing['full']) > 10) {
					++$result['skipped'];
					continue;
				}
			}

			$metadata = \wp_get_attachment_metadata($attachmentId);
			if (!is_array($metadata) || empty($metadata['file'])) {
				++$result['failed'];
				continue;
			}

			$uploadDir = \wp_upload_dir();
			$baseDir = $uploadDir['basedir'] ?? '';
			if (!$baseDir) {
				++$result['failed'];
				continue;
			}

			$hashes = array();
			$file = $metadata['file'];
			$fileDir = dirname($file);

			// Generate for original/full image
			$fullPath = $baseDir . '/' . $file;
			if (file_exists($fullPath)) {
				$hash = self::generate($fullPath);
				if ($hash) {
					$hashes['full'] = $hash;
				}
			}

			// Generate for each registered size
			$sizes = $metadata['sizes'] ?? array();
			foreach ($sizes as $sizeName => $sizeData) {
				$sizeFile = $sizeData['file'] ?? '';
				if (!$sizeFile) {
					continue;
				}

				$sizePath = $baseDir . '/' . $fileDir . '/' . $sizeFile;
				if (file_exists($sizePath)) {
					$hash = self::generate($sizePath);
					if ($hash) {
						$hashes[$sizeName] = $hash;
					}
				}
			}

			if (!empty($hashes)) {
				\update_post_meta($attachmentId, self::META_KEY, $hashes);
				++$result['generated'];
			} else {
				++$result['failed'];
				// Capture the last error for debugging
				if (!isset($result['last_error']) && self::$lastError) {
					$result['last_error'] = self::$lastError;
					$result['last_error_file'] = $fullPath ?? '';
				}
			}
		}

		return $result;
	}

	/**
	 * Delete all ThumbHash metadata from all attachments.
	 *
	 * @return int Number of meta entries deleted.
	 */
	public static function deleteAll(): int
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);
		return (int) $deleted;
	}

	/**
	 * Count attachments with ThumbHash metadata.
	 *
	 * @return array{with_hash: int, without_hash: int, total: int}
	 */
	public static function getStats(): array
	{
		global $wpdb;

		// Count total image attachments
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp')"
		);

		// Count attachments with ThumbHash
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$withHash = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		return array(
			'with_hash' => $withHash,
			'without_hash' => $total - $withHash,
			'total' => $total,
		);
	}
}
