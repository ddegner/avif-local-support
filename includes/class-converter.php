<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;
use Ddegner\AvifLocalSupport\Encoders\CliEncoder;
use Ddegner\AvifLocalSupport\Encoders\GdEncoder;
use Ddegner\AvifLocalSupport\Encoders\ImagickEncoder;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

final class Converter {




	private const JPEG_MIMES = array( 'image/jpeg', 'image/jpg' );
	private const JOB_LOCK_TRANSIENT = 'aviflosu_conversion_lock';
	private const JOB_STATE_OPTION   = 'aviflosu_conversion_job_state';
	private const LAST_RUN_OPTION    = 'aviflosu_last_run_summary';
	private const JOB_STALE_SECONDS  = 1800; // 30 minutes without heartbeat/progress.

	private ?Plugin $plugin = null;
	private ?Logger $logger = null;

	/** @var AvifEncoderInterface[] */
	private array $encoders = array();

	public function set_plugin( Plugin $plugin ): void {
		$this->plugin = $plugin;
	}

	/**
	 * Set a logger instance for CLI usage (when Plugin is not available).
	 */
	public function set_logger( Logger $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Check if a MIME type is a JPEG type.
	 */
	private function isJpegMime( ?string $mime ): bool {
		return is_string( $mime ) && in_array( $mime, self::JPEG_MIMES, true );
	}

	/**
	 * Get the relative directory from attachment metadata.
	 */
	private function getMetadataDir( array $metadata ): string {
		$relativeDir = pathinfo( (string) ( $metadata['file'] ?? '' ), PATHINFO_DIRNAME );
		if ( '.' === $relativeDir || DIRECTORY_SEPARATOR === $relativeDir ) {
			return '';
		}
		return $relativeDir;
	}

	/**
	 * Update the file existence cache for a newly converted AVIF.
	 * Sets the entry to true so the Support class immediately recognizes the file exists.
	 * This also prevents race conditions where Support::saveCache() could overwrite with stale data.
	 *
	 * @param string $avifPath The absolute path to the AVIF file.
	 */
	private function invalidateFileCache( string $avifPath ): void {
		$cache = \get_transient( 'aviflosu_file_cache' );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		// Set to true so it's immediately recognized as existing.
		$cache[ $avifPath ] = true;
		\set_transient( 'aviflosu_file_cache', $cache, (int) \get_option( 'aviflosu_cache_duration', 3600 ) );
	}

	public function init(): void {
		// Initialize encoders.
		// Order matters for "Auto" mode: CLI -> Imagick -> GD.
		$this->encoders = array(
			new CliEncoder(),
			new ImagickEncoder(),
			new GdEncoder(),
		);

		// Convert on upload and metadata updates (single hook path).
		add_filter( 'wp_update_attachment_metadata', array( $this, 'convertGeneratedSizes' ), 20, 2 );
		add_filter( 'wp_handle_upload', array( $this, 'convertOriginalOnUpload' ), 20 );

		// Scheduling.
		// Run immediately because plugin bootstraps on `init`; registering another init callback here is too late.
		$this->maybe_schedule_daily();
		add_action( 'aviflosu_daily_event', array( $this, 'run_daily_scan' ) );
		add_action( 'aviflosu_run_on_demand', array( $this, 'run_on_demand_scan' ) );

		// Deletion: keep .avif companions in sync when media is removed.
		add_action( 'delete_attachment', array( $this, 'deleteAvifsForAttachment' ) );
		add_filter( 'wp_delete_file', array( $this, 'deleteCompanionAvif' ) );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'avif-local-support convert', array( $this, 'cliConvertAll' ) );
		}
	}

	public function maybe_schedule_daily(): void {
		$avifEnabled = (bool) get_option( 'aviflosu_convert_via_schedule', true );
		$lqipEnabled = (bool) get_option( 'aviflosu_lqip_generate_via_schedule', true ) && ThumbHash::isEnabled();

		if ( ! $avifEnabled && ! $lqipEnabled ) {
			// Clear if exists.
			wp_clear_scheduled_hook( 'aviflosu_daily_event' );
			return;
		}

		// Calculate next run time based on schedule time option.
		$time = (string) get_option( 'aviflosu_schedule_time', '01:00' );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = '01:00';
		}
		[$hour, $minute] = array_map( 'intval', explode( ':', $time ) );
		$now             = (int) time();
		$tz              = wp_timezone();
		$dt              = new \DateTimeImmutable( '@' . $now );
		$dt              = $dt->setTimezone( $tz );
		$targetToday     = $dt->setTime( $hour, $minute, 0 );
		$nextDt          = ( $targetToday->getTimestamp() <= $now )
			? $targetToday->modify( '+1 day' )
			: $targetToday;
		$next            = $nextDt->getTimestamp();

		// Schedule or reschedule if needed (tolerance: 60 seconds).
		$existing = wp_next_scheduled( 'aviflosu_daily_event' );
		if ( false === $existing ) {
			wp_schedule_event( $next, 'daily', 'aviflosu_daily_event' );
			return;
		}

		// If an event is already due/overdue, let WP-Cron run it instead of skipping to tomorrow.
		if ( (int) $existing <= $now ) {
			return;
		}

		if ( abs( (int) $existing - (int) $next ) > 60 ) {
			wp_clear_scheduled_hook( 'aviflosu_daily_event' );
			wp_schedule_event( $next, 'daily', 'aviflosu_daily_event' );
		}
	}

	public function run_daily_scan(): void {
		if ( (bool) get_option( 'aviflosu_convert_via_schedule', true ) ) {
			$this->convertAllJpegsIfMissingAvif();
		}
		if ( (bool) get_option( 'aviflosu_lqip_generate_via_schedule', true ) && ThumbHash::isEnabled() ) {
			ThumbHash::generateAll();
		}
	}

	public function run_on_demand_scan(): void {
		// Explicit manual AVIF generation should not depend on daily schedule toggles.
		$this->convertAllJpegsIfMissingAvif();
	}

	public function convertGeneratedSizes( array $metadata, int $attachmentId ): array {
		if ( ! $this->isJpegMime( get_post_mime_type( $attachmentId ) ) ) {
			return $metadata;
		}

		$convertOnUpload      = (bool) get_option( 'aviflosu_convert_on_upload', true );
		$lqipGenerateOnUpload = (bool) get_option( 'aviflosu_lqip_generate_on_upload', true );

		// AVIF Conversion
		if ( $convertOnUpload ) {
			$uploadDir = wp_upload_dir();
			$baseDir   = trailingslashit( $uploadDir['basedir'] ?? '' );
			// De-duped: convert original and sizes via shared helper.
			$this->convertFromMetadata( $metadata, $baseDir );
		}

		// Generate ThumbHash placeholders if enabled globally AND triggered on upload.
		if ( ThumbHash::isEnabled() && $lqipGenerateOnUpload ) {
			ThumbHash::generateForAttachment( $attachmentId );
		}

		return $metadata;
	}

	public function convertOriginalOnUpload( array $file ): array {
		$convertOnUpload = (bool) get_option( 'aviflosu_convert_on_upload', true );
		if ( ! $convertOnUpload ) {
			return $file;
		}
		$type           = isset( $file['type'] ) && is_string( $file['type'] ) ? strtolower( $file['type'] ) : '';
		$path           = isset( $file['file'] ) && is_string( $file['file'] ) ? $file['file'] : '';
		$allowedMimes   = array( 'image/jpeg', 'image/jpg', 'image/pjpeg' );
		$hasAllowedMime = in_array( $type, $allowedMimes, true );
		$hasJpegExt     = '' !== $path && preg_match( '/\.(jpe?g)$/i', $path ) === 1;
		if ( ! $hasAllowedMime && ! $hasJpegExt ) {
			return $file;
		}
		$this->checkMissingAvif( $path );
		return $file;
	}

	private function checkMissingAvif( string $path ): ?ConversionResult {
		if ( '' === $path || ! file_exists( $path ) ) {
			return null;
		}
		if ( ! preg_match( '/\.(jpe?g)$/i', $path ) ) {
			return null;
		}
		$avifPath = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $path );
		if ( '' !== $avifPath && file_exists( $avifPath ) ) {
			return null; // Already converted.
		}

		// Determine better source based on WordPress logic (if enabled).
		[$sourcePath, $targetDimensions] = $this->getConversionData( $path );
		return $this->convertToAvif( $sourcePath, $avifPath, $targetDimensions, null, $path );
	}

	private function convertToAvif( string $sourcePath, string $avifPath, ?array $targetDimensions, ?AvifSettings $settings = null, ?string $referenceJpegPath = null ): ConversionResult {
		$start_time = microtime( true );
		$settings   = $settings ?? AvifSettings::fromOptions();

		// Ensure directory exists.
		$dir = dirname( $avifPath );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Memory Check.
		if ( ! $settings->disableMemoryCheck ) {
			$memoryWarning = $this->check_memory_safe( $sourcePath );
			if ( $memoryWarning ) {
				$this->log_conversion( 'error', $sourcePath, $avifPath, 'none', $start_time, $memoryWarning, $settings->toArray(), $referenceJpegPath );
				return ConversionResult::failure( $memoryWarning );
			}
		}

		// Select Encoders.
		$encodersToTry = array();
		if ( 'cli' === $settings->engineMode ) {
			// Force CLI: only try CLI.
			foreach ( $this->encoders as $encoder ) {
				if ( 'cli' === $encoder->getName() ) {
					$encodersToTry[] = $encoder;
					break;
				}
			}
		} elseif ( 'imagick' === $settings->engineMode ) {
			// Force Imagick.
			foreach ( $this->encoders as $encoder ) {
				if ( 'imagick' === $encoder->getName() ) {
					$encodersToTry[] = $encoder;
					break;
				}
			}
		} elseif ( 'gd' === $settings->engineMode ) {
			// Force GD.
			foreach ( $this->encoders as $encoder ) {
				if ( 'gd' === $encoder->getName() ) {
					$encodersToTry[] = $encoder;
					break;
				}
			}
		} else {
			// Auto: Try all available.
			foreach ( $this->encoders as $encoder ) {
				if ( $encoder->isAvailable() ) {
					$encodersToTry[] = $encoder;
				}
			}
		}

		if ( empty( $encodersToTry ) ) {
			$msg = 'No available encoders found.';
			$this->log_conversion( 'error', $sourcePath, $avifPath, 'none', $start_time, $msg, $settings->toArray(), $referenceJpegPath );
			return ConversionResult::failure( $msg );
		}

		$lastResult = null;
		$engineUsed = 'none';

		$maxAttemptsPerEncoder = 3;
		foreach ( $encodersToTry as $encoder ) {
			$engineUsed = $encoder->getName();

			for ( $attempt = 1; $attempt <= $maxAttemptsPerEncoder; $attempt++ ) {
				$result = $encoder->convert( $sourcePath, $avifPath, $settings, $targetDimensions );

					if ( $result->success ) {
						$validationError = '';
						if ( ! $this->validateGeneratedAvif( $avifPath, $validationError ) ) {
							$this->deleteInvalidAvifFile( $avifPath );
						$lastResult = ConversionResult::failure(
							'Generated AVIF failed validation: ' . $validationError,
							'The encoder produced a corrupted AVIF. Try a different engine in settings.'
						);
							continue;
						}

						$comparisonJpegPath = ( is_string( $referenceJpegPath ) && '' !== $referenceJpegPath && file_exists( $referenceJpegPath ) )
							? $referenceJpegPath
							: $sourcePath;
						$sizePolicy = $this->applyLargerOutputPolicy(
							$encoder,
							$sourcePath,
							$avifPath,
							$comparisonJpegPath,
							$targetDimensions,
							$settings
						);
						if ( empty( $sizePolicy['ok'] ) ) {
							$lastResult = ConversionResult::failure(
								(string) ( $sizePolicy['error'] ?? 'AVIF output rejected by size policy.' ),
								(string) ( $sizePolicy['suggestion'] ?? '' )
							);
							continue;
						}

						$logDetails = array_merge(
							$settings->toArray(),
							is_array( $sizePolicy['details'] ?? null ) ? $sizePolicy['details'] : array()
						);

						// Invalidate file existence cache for this path so Support class sees the new file.
						$this->invalidateFileCache( $avifPath );
						$this->log_conversion( 'success', $sourcePath, $avifPath, $engineUsed, $start_time, null, $logDetails, $referenceJpegPath );
						return ConversionResult::success( $logDetails );
					}

				$lastResult = $result;
			}
			// If user forced CLI, do not fallback (loop will end since only CLI is in list).
		}

		// If we reached here, all attempts failed.
		$errorMsg   = $lastResult ? $lastResult->error : 'Unknown error';
		$suggestion = $lastResult ? $lastResult->suggestion : null;

		$details = $settings->toArray();
		if ( $suggestion ) {
			$details['error_suggestion'] = $suggestion;
		}

		$this->log_conversion( 'error', $sourcePath, $avifPath, $engineUsed, $start_time, $errorMsg, $details, $referenceJpegPath );
		return ConversionResult::failure( $errorMsg ?? 'Unknown error', $suggestion );
	}

	/**
	 * Apply "larger than source" policy:
	 * - Retry conversion at lower quality when AVIF is larger than JPEG.
	 * - Keep smallest valid AVIF variant.
	 * - Optionally reject larger AVIF outputs if configured.
	 *
	 * @return array{ok:bool,details:array<string,mixed>,error?:string,suggestion?:string}
	 */
	private function applyLargerOutputPolicy(
		AvifEncoderInterface $encoder,
		string $sourcePath,
		string $avifPath,
		string $comparisonJpegPath,
		?array $targetDimensions,
		AvifSettings $settings
	): array {
		$jpegSize = $this->getPositiveFileSize( $comparisonJpegPath );
		$bestSize = $this->getPositiveFileSize( $avifPath );
		$details  = array(
			'comparison_jpeg_path'          => $comparisonJpegPath,
			'comparison_jpeg_size_bytes'    => $jpegSize,
			'avif_size_bytes'               => $bestSize,
			'larger_file_retries_attempted' => 0,
			'larger_file_retries_succeeded' => 0,
			'larger_file_final_quality'     => $settings->quality,
			'larger_than_source'            => ( $jpegSize > 0 && $bestSize > $jpegSize ),
		);

		// If size comparison isn't possible, keep the valid AVIF we already produced.
		if ( $jpegSize <= 0 || $bestSize <= 0 || $bestSize <= $jpegSize ) {
			return array(
				'ok'      => true,
				'details' => $details,
			);
		}

		$maxRetries = max( 0, (int) $settings->largerRetryCount );
		$step       = max( 1, (int) $settings->largerRetryQualityStep );

		$bestTemp = function_exists( 'wp_tempnam' ) ? (string) \wp_tempnam( $avifPath ) : '';
		if ( '' === $bestTemp ) {
			$tmp = @tempnam( sys_get_temp_dir(), 'aviflosu_best_' );
			if ( is_string( $tmp ) ) {
				$bestTemp = $tmp;
			}
		}
		if ( '' !== $bestTemp ) {
			@copy( $avifPath, $bestTemp );
		}

		$bestQuality       = (int) $settings->quality;
		$attemptedQualities = array( $bestQuality => true );

		for ( $retry = 1; $retry <= $maxRetries; $retry++ ) {
			$nextQuality = max( 0, (int) $settings->quality - ( $retry * $step ) );
			if ( isset( $attemptedQualities[ $nextQuality ] ) ) {
				continue;
			}
			$attemptedQualities[ $nextQuality ] = true;
			$details['larger_file_retries_attempted'] = (int) $details['larger_file_retries_attempted'] + 1;

			$retrySettings = $this->withQuality( $settings, $nextQuality );
			$retryResult   = $encoder->convert( $sourcePath, $avifPath, $retrySettings, $targetDimensions );
			if ( ! $retryResult->success ) {
				if ( 0 === $nextQuality ) {
					break;
				}
				continue;
			}

			$retryValidationError = '';
			if ( ! $this->validateGeneratedAvif( $avifPath, $retryValidationError ) ) {
				$this->deleteInvalidAvifFile( $avifPath );
				if ( 0 === $nextQuality ) {
					break;
				}
				continue;
			}

			$details['larger_file_retries_succeeded'] = (int) $details['larger_file_retries_succeeded'] + 1;
			$candidateSize = $this->getPositiveFileSize( $avifPath );
			if ( $candidateSize > 0 && $candidateSize < $bestSize ) {
				$bestSize                  = $candidateSize;
				$bestQuality               = $nextQuality;
				$details['avif_size_bytes'] = $bestSize;
				if ( '' !== $bestTemp ) {
					@copy( $avifPath, $bestTemp );
				}
				if ( $bestSize <= $jpegSize ) {
					break;
				}
			}

			if ( 0 === $nextQuality ) {
				break;
			}
		}

		if ( '' !== $bestTemp && file_exists( $bestTemp ) ) {
			@copy( $bestTemp, $avifPath );
			@unlink( $bestTemp );
		}

		$finalSize = $this->getPositiveFileSize( $avifPath );
		if ( $finalSize > 0 ) {
			$bestSize = $finalSize;
		}
		$details['avif_size_bytes']           = $bestSize;
		$details['larger_file_final_quality'] = $bestQuality;
		$details['larger_than_source']        = ( $bestSize > $jpegSize );

		if ( $bestSize > $jpegSize && ! $settings->keepLargerAvif ) {
			$this->deleteInvalidAvifFile( $avifPath );
			return array(
				'ok'         => false,
				'details'    => $details,
				'error'      => 'AVIF output is larger than source JPEG and keep-larger policy is disabled.',
				'suggestion' => 'Enable "Keep AVIF when larger" or increase retries/quality step to reduce output size.',
			);
		}

		return array(
			'ok'      => true,
			'details' => $details,
		);
	}

	private function getPositiveFileSize( string $path ): int {
		$size = @filesize( $path );
		return ( is_int( $size ) && $size > 0 ) ? $size : 0;
	}

	private function withQuality( AvifSettings $settings, int $quality ): AvifSettings {
		return new AvifSettings(
			quality: max( 0, min( 100, $quality ) ),
			speed: $settings->speed,
			subsampling: $settings->subsampling,
			bitDepth: $settings->bitDepth,
			engineMode: $settings->engineMode,
			cliPath: $settings->cliPath,
			disableMemoryCheck: $settings->disableMemoryCheck,
			lossless: $quality >= 100,
			convertOnUpload: $settings->convertOnUpload,
			convertViaSchedule: $settings->convertViaSchedule,
			cliArgs: $settings->cliArgs,
			cliEnv: $settings->cliEnv,
			cliThreads: $settings->cliThreads,
			keepLargerAvif: $settings->keepLargerAvif,
			largerRetryCount: $settings->largerRetryCount,
			largerRetryQualityStep: $settings->largerRetryQualityStep,
			maxDimension: $settings->maxDimension
		);
	}

	/**
	 * Process a single JPEG candidate and return normalized job counters.
	 *
	 * @return array<string,int|string>
	 */
	private function processJpegCandidate( string $jpegPath ): array {
		$stats = array(
			'processed'             => 0,
			'existing'              => 0,
			'created'               => 0,
			'errors'                => 0,
			'failed_validation'     => 0,
			'larger_than_source'    => 0,
			'bytes_jpeg_compared'   => 0,
			'bytes_avif_generated'  => 0,
			'retry_quality_sum'     => 0,
			'retry_quality_count'   => 0,
			'last_error'            => '',
		);

		if ( '' === $jpegPath || ! file_exists( $jpegPath ) || ! preg_match( '/\.(jpe?g)$/i', $jpegPath ) ) {
			return $stats;
		}

		$stats['processed'] = 1;
		$jpegSize           = $this->getPositiveFileSize( $jpegPath );
		$avifPath           = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegPath );
		$hadAvif            = ( '' !== $avifPath && file_exists( $avifPath ) && $this->getPositiveFileSize( $avifPath ) > 0 );
		if ( $hadAvif ) {
			$stats['existing'] = 1;
			return $stats;
		}

		$result = $this->checkMissingAvif( $jpegPath );
		if ( $result && $result->success ) {
			$stats['created'] = 1;
			$details          = is_array( $result->details ) ? $result->details : array();
			$avifSize         = $this->getPositiveFileSize( $avifPath );
			if ( $jpegSize > 0 ) {
				$stats['bytes_jpeg_compared'] = $jpegSize;
			}
			if ( $avifSize > 0 ) {
				$stats['bytes_avif_generated'] = $avifSize;
			}
			if ( ! empty( $details['larger_than_source'] ) ) {
				$stats['larger_than_source'] = 1;
			}
			if ( isset( $details['larger_file_final_quality'] ) ) {
				$stats['retry_quality_sum']   = (int) $details['larger_file_final_quality'];
				$stats['retry_quality_count'] = 1;
			}
			return $stats;
		}

		if ( $result && ! $result->success ) {
			$stats['errors'] = 1;
			$errorMessage    = (string) ( $result->error ?? '' );
			$stats['last_error'] = $errorMessage;
			if ( false !== stripos( $errorMessage, 'failed validation' ) ) {
				$stats['failed_validation'] = 1;
			}
		}

		return $stats;
	}

	/**
	 * Merge per-file stats into running job stats.
	 *
	 * @param array<string,int|string> $jobStats
	 * @param array<string,int|string> $fileStats
	 */
	private function mergeJobStats( array &$jobStats, array $fileStats ): void {
		foreach ( array( 'processed', 'existing', 'created', 'errors', 'failed_validation', 'larger_than_source', 'bytes_jpeg_compared', 'bytes_avif_generated', 'retry_quality_sum', 'retry_quality_count' ) as $key ) {
			$jobStats[ $key ] = (int) ( $jobStats[ $key ] ?? 0 ) + (int) ( $fileStats[ $key ] ?? 0 );
		}

		$lastError = (string) ( $fileStats['last_error'] ?? '' );
		if ( '' !== $lastError ) {
			$jobStats['last_error'] = $lastError;
		}
	}

	/**
	 * Validate a generated AVIF output to prevent serving broken files.
	 * Validation order:
	 * 1) file existence/size
	 * 2) decoder-based probe (getimagesize/Imagick/imagecreatefromavif)
	 * 3) container signature fallback
	 */
	private function validateGeneratedAvif( string $path, string &$reason = '' ): bool {
		$reason = '';
		if ( '' === $path || ! file_exists( $path ) ) {
			$reason = 'File missing after conversion.';
			return false;
		}

		$size = @filesize( $path );
		if ( ! is_int( $size ) || $size <= 0 ) {
			$reason = 'File is empty.';
			return false;
		}

		// Prefer decoder-based validation when available.
		$info = @getimagesize( $path );
		if ( is_array( $info ) ) {
			$width  = isset( $info[0] ) ? (int) $info[0] : 0;
			$height = isset( $info[1] ) ? (int) $info[1] : 0;
			$mime   = isset( $info['mime'] ) ? strtolower( (string) $info['mime'] ) : '';
			if ( $width > 0 && $height > 0 && in_array( $mime, array( 'image/avif', 'image/heif', 'image/heic' ), true ) ) {
				return true;
			}
		}

		// Fallback to Imagick decode probe.
		if ( class_exists( '\Imagick' ) ) {
			try {
				$im = new \Imagick( $path );
				$w  = (int) $im->getImageWidth();
				$h  = (int) $im->getImageHeight();
				$im->clear();
				$im->destroy();
				if ( $w > 0 && $h > 0 ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// Fall through to signature check.
			}
		}

		// GD AVIF decoder probe if available.
		if ( function_exists( 'imagecreatefromavif' ) ) {
			$gd = @imagecreatefromavif( $path );
			if ( false !== $gd ) {
				@imagedestroy( $gd );
				return true;
			}
		}

		if ( $this->hasLikelyAvifSignature( $path ) ) {
			// Signature looks right, but we still failed decoder probes.
			// Treat as invalid to avoid serving potentially broken output.
			$reason = 'Container signature present but decoding failed.';
			return false;
		}

		$reason = 'Invalid AVIF/HEIF container signature.';
		return false;
	}

	/**
	 * Quick AVIF/HEIF container signature check for ISO BMFF files.
	 */
	private function hasLikelyAvifSignature( string $path ): bool {
		$fh = @fopen( $path, 'rb' );
		if ( false === $fh ) {
			return false;
		}
		$header = (string) @fread( $fh, 64 );
		@fclose( $fh );

		// ISO BMFF: 4 bytes size + "ftyp" + major brand.
		if ( strlen( $header ) < 16 || substr( $header, 4, 4 ) !== 'ftyp' ) {
			return false;
		}

		$majorBrand = strtolower( substr( $header, 8, 4 ) );
		if ( in_array( $majorBrand, array( 'avif', 'avis' ), true ) ) {
			return true;
		}

		// Also inspect compatible brands region for avif/avis.
		$brandsRegion = strtolower( substr( $header, 16 ) );
		return false !== strpos( $brandsRegion, 'avif' ) || false !== strpos( $brandsRegion, 'avis' );
	}

	/**
	 * Delete invalid output while being resilient across environments.
	 */
	private function deleteInvalidAvifFile( string $path ): void {
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			@wp_delete_file( $path );
		}
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
	}

	/**
	 * Convert a JPEG path to a specific AVIF destination using provided settings.
	 * This bypasses option persistence and is intended for preview workflows.
	 */
	public function convertJpegToAvifWithSettings( string $jpegPath, string $avifPath, AvifSettings $settings ): ConversionResult {
		if ( '' === $jpegPath || ! file_exists( $jpegPath ) ) {
			return ConversionResult::failure( __( 'Source file not found', 'avif-local-support' ) );
		}
		if ( ! preg_match( '/\.(jpe?g)$/i', $jpegPath ) ) {
			return ConversionResult::failure( __( 'Only JPEG source files are supported.', 'avif-local-support' ) );
		}

		[$sourcePath, $targetDimensions] = $this->getConversionData( $jpegPath );
		return $this->convertToAvif( $sourcePath, $avifPath, $targetDimensions, $settings, $jpegPath );
	}

	/**
	 * Safely check if we have enough memory to process this image via PHP (GD/Imagick).
	 * Returns a warning string if memory is dangerously low, null if it seems okay.
	 */
	private function check_memory_safe( string $path ): ?string {
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return null; // No limit.
		}

		$limitBytes = $this->parse_memory_limit( (string) $limit );
		if ( $limitBytes <= 0 ) {
			return null; // Could not parse or zero, assume fine
		}

		// Get current usage
		$currentUsage = memory_get_usage( true );

		// Estimate image memory usage:
		// (width * height * channels * bits_per_channel) + overhead
		// Approximating RGBA (4 channels) at 1 byte per channel (8-bit) * 1.8 overhead for GD/Imagick structures
		$info = @getimagesize( $path );
		if ( ! $info ) {
			// Can't read info, so can't estimate. Proceed cautiously.
			return null;
		}

		$width    = isset( $info[0] ) ? (int) $info[0] : 0;
		$height   = isset( $info[1] ) ? (int) $info[1] : 0;
		$channels = isset( $info['channels'] ) ? (int) $info['channels'] : 4;
		if ( $channels <= 0 ) {
			$channels = 4;
		}

		// Rough estimation: width * height * 4 (RGBA) * 1.7 (overhead factor)
		$estimatedNeed = (int) ( $width * $height * 4 * 1.7 );

		// Add buffer (10MB) for other script overhead
		$buffer = 10 * 1024 * 1024;

		if ( ( $currentUsage + $estimatedNeed + $buffer ) > $limitBytes ) {
			$fmtLimit = size_format( $limitBytes );
			$fmtNeed  = size_format( $estimatedNeed );
			return "High risk of memory exhaustion. Memory limit: $fmtLimit. Estimated need: $fmtNeed. Current usage: " . size_format( $currentUsage ) . '.';
		}

		return null;
	}

	private function parse_memory_limit( string $val ): int {
		$val  = trim( $val );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$n    = (int) $val;
		switch ( $last ) {
			case 'g':
				$n *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$n *= 1024 * 1024;
				break;
			case 'k':
				$n *= 1024;
				break;
		}
		return $n;
	}

	private function getConversionData( string $jpegPath ): array {
		// Always use WordPress logic to avoid double-resizing
		$useWpLogic = true;
		$sourcePath = $jpegPath;
		$target     = null;
		if ( $useWpLogic ) {
			$filename  = basename( $jpegPath );
			$directory = dirname( $jpegPath );
			if ( preg_match( '/^(.+)-(\d+)x(\d+)\.(jpe?g)$/i', $filename, $m ) ) {
				$base       = $m[1];
				$w          = (int) $m[2];
				$h          = (int) $m[3];
				$ext        = $m[4];
				$candidates = array(
					$directory . '/' . $base . '.' . $ext,
					$directory . '/' . $base . '-scaled.' . $ext,
				);
				foreach ( $candidates as $candidate ) {
					if ( file_exists( $candidate ) ) {
						$srcReal = @realpath( $candidate );
						$tgtReal = @realpath( $jpegPath );
						if ( $srcReal && $tgtReal && $srcReal !== $tgtReal ) {
							$sourcePath = $candidate;
							$target     = array(
								'width'  => $w,
								'height' => $h,
							);
							break;
						}
					}
				}
			} elseif ( preg_match( '/^(.+)-scaled\.(jpe?g)$/i', $filename, $m ) ) {
				// Handle -scaled images: try to find the non-scaled original
				$base      = $m[1];
				$ext       = $m[2];
				$candidate = $directory . '/' . $base . '.' . $ext;
				if ( file_exists( $candidate ) ) {
					$srcReal = @realpath( $candidate );
					$tgtReal = @realpath( $jpegPath );
					if ( $srcReal && $tgtReal && $srcReal !== $tgtReal ) {
						$sourcePath = $candidate;
						// Use dimensions of the scaled file as target to ensure we don't produce a huge AVIF
						$info = @getimagesize( $jpegPath );
						if ( $info ) {
							$target = array(
								'width'  => $info[0],
								'height' => $info[1],
							);
						}
					}
				}
			}
		}
		return array( $sourcePath, $target );
	}

	/**
	 * Shared: Convert original and generated size JPEGs found in attachment metadata.
	 */
	private function convertFromMetadata( array $metadata, string $baseDir, ?array &$jobStats = null ): void {
		if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
			$originalPath = $baseDir . $metadata['file'];
			if ( is_array( $jobStats ) ) {
				$this->mergeJobStats( $jobStats, $this->processJpegCandidate( $originalPath ) );
			} else {
				$this->checkMissingAvif( $originalPath );
			}
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$relativeDir = $this->getMetadataDir( $metadata );
			foreach ( $metadata['sizes'] as $sizeData ) {
				if ( ! empty( $sizeData['file'] ) ) {
					$sizePath = $baseDir . trailingslashit( $relativeDir ) . $sizeData['file'];
					if ( is_array( $jobStats ) ) {
						$this->mergeJobStats( $jobStats, $this->processJpegCandidate( $sizePath ) );
					} else {
						$this->checkMissingAvif( $sizePath );
					}
				}
			}
		}
	}

	// Optional: WP-CLI bulk conversion
	public function cliConvertAll(): void {
		$this->convertAllJpegsIfMissingAvif( true );
	}

	/**
	 * Check if a conversion job is already active.
	 */
	public function isConversionJobActive(): bool {
		$active = (bool) \get_transient( self::JOB_LOCK_TRANSIENT );
		if ( ! $active ) {
			return false;
		}

		$state       = $this->getConversionJobState();
		$heartbeatAt = (int) ( $state['heartbeat_at'] ?? $state['started_at'] ?? 0 );
		if ( $heartbeatAt > 0 && ( time() - $heartbeatAt ) > self::JOB_STALE_SECONDS ) {
			// Recover from stale locks so users can start a fresh run.
			\delete_transient( self::JOB_LOCK_TRANSIENT );
			\update_option(
				self::JOB_STATE_OPTION,
				array_merge(
					$state,
					array(
						'status'   => 'stale',
						'ended_at' => time(),
					)
				),
				false
			);
			return false;
		}

		return true;
	}

	/**
	 * Get current conversion job state.
	 *
	 * @return array<string, mixed>
	 */
	public function getConversionJobState(): array {
		$state = \get_option( self::JOB_STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Get last run summary.
	 *
	 * @return array<string, mixed>
	 */
	public function getLastRunSummary(): array {
		$summary = \get_option( self::LAST_RUN_OPTION, array() );
		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Update conversion job state and lock.
	 */
	private function beginConversionJob( bool $cli ): bool {
		if ( $this->isConversionJobActive() ) {
			return false;
		}

		$now = time();
		\set_transient( self::JOB_LOCK_TRANSIENT, 1, HOUR_IN_SECONDS );
		\update_option(
			self::JOB_STATE_OPTION,
			array(
				'status'      => 'running',
				'started_at'  => $now,
				'heartbeat_at'=> $now,
				'mode'        => $cli ? 'cli' : 'web',
				'processed'   => 0,
				'attachments' => 0,
				'uploads'     => 0,
				'created'     => 0,
				'existing'    => 0,
				'errors'      => 0,
				'failed_validation' => 0,
				'larger_than_source' => 0,
				'bytes_jpeg_compared' => 0,
				'bytes_avif_generated' => 0,
				'retry_quality_sum' => 0,
				'retry_quality_count' => 0,
				'last_error'  => '',
			),
			false
		);

		return true;
	}

	private function updateConversionJobState( array $patch ): void {
		$state = $this->getConversionJobState();
		$state = array_merge(
			$state,
			$patch,
			array(
				'heartbeat_at' => time(),
			)
		);
		\update_option( self::JOB_STATE_OPTION, $state, false );
	}

	private function endConversionJob( string $status, array $summary ): void {
		$endedAt = time();
		$this->updateConversionJobState(
			array_merge(
				array(
					'status'   => $status,
					'ended_at' => $endedAt,
				),
				$summary
			)
		);
		\update_option(
			self::LAST_RUN_OPTION,
			array_merge(
				$summary,
				array(
					'status'    => $status,
					'ended_at'  => $endedAt,
					'started_at' => (int) ( $this->getConversionJobState()['started_at'] ?? $endedAt ),
				)
			),
			false
		);
		\delete_transient( self::JOB_LOCK_TRANSIENT );
	}

	private function convertAllJpegsIfMissingAvif( bool $cli = false ): void {
		if ( ! $this->beginConversionJob( $cli ) ) {
			return;
		}

		// Clear any previous stop flag when starting
		\delete_transient( 'aviflosu_stop_conversion' );
		$status = 'completed';
		$count = 0;
		$uploadsCount = 0;
		$jobStats = array(
			'processed'             => 0,
			'existing'              => 0,
			'created'               => 0,
			'errors'                => 0,
			'failed_validation'     => 0,
			'larger_than_source'    => 0,
			'bytes_jpeg_compared'   => 0,
			'bytes_avif_generated'  => 0,
			'retry_quality_sum'     => 0,
			'retry_quality_count'   => 0,
			'last_error'            => '',
		);

		try {
			$query = new \WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => array( 'image/jpeg', 'image/jpg' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					// Prime metadata once to avoid per-attachment lookups inside the loop.
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'cache_results'          => false,
				)
			);
			foreach ( $query->posts as $attachmentId ) {
				// Check for stop flag
				if ( \get_transient( 'aviflosu_stop_conversion' ) ) {
					$status = 'stopped';
					if ( $cli && defined( 'WP_CLI' ) && \WP_CLI ) {
						\WP_CLI::warning( "Conversion stopped by user after {$count} attachments." );
					}
					\delete_transient( 'aviflosu_stop_conversion' );
					break;
				}

				if ( ! $this->isJpegMime( get_post_mime_type( $attachmentId ) ) ) {
					continue;
				}
					$path = get_attached_file( $attachmentId );
					if ( $path ) {
						$this->mergeJobStats( $jobStats, $this->processJpegCandidate( (string) $path ) );
					}
					$meta = wp_get_attachment_metadata( $attachmentId );
					if ( $meta ) {
						$this->convertGeneratedSizesForce( $meta, $attachmentId, $jobStats );
					}
					++$count;
					$this->updateConversionJobState(
						array(
							'attachments' => $count,
							'processed'   => $count + $uploadsCount,
							'created'     => (int) $jobStats['created'],
							'existing'    => (int) $jobStats['existing'],
							'errors'      => (int) $jobStats['errors'],
							'failed_validation' => (int) $jobStats['failed_validation'],
							'larger_than_source' => (int) $jobStats['larger_than_source'],
							'bytes_jpeg_compared' => (int) $jobStats['bytes_jpeg_compared'],
							'bytes_avif_generated' => (int) $jobStats['bytes_avif_generated'],
							'retry_quality_sum' => (int) $jobStats['retry_quality_sum'],
							'retry_quality_count' => (int) $jobStats['retry_quality_count'],
							'last_error'  => (string) $jobStats['last_error'],
						)
					);
				}

				if ( 'stopped' !== $status ) {
					$uploadsCount = $this->scanUploadsJpegsIfMissingAvif( $count, $cli, $jobStats );
					$currentState = $this->getConversionJobState();
					if ( 'stopped' === (string) ( $currentState['status'] ?? '' ) ) {
						$status = 'stopped';
				}
			}

			if ( $cli && defined( 'WP_CLI' ) && \WP_CLI ) {
				\WP_CLI::success( "Scanned attachments: {$count}; scanned uploads JPEGs: {$uploadsCount}" );
			}
		} catch ( \Throwable $e ) {
			$status = 'error';
			$this->log_conversion(
				'error',
				'',
				'',
				'none',
				microtime( true ),
				'Batch conversion failed: ' . $e->getMessage(),
				array(
					'result' => 'batch_error',
				)
			);
		} finally {
			$this->endConversionJob(
				$status,
					array(
						'attachments' => $count,
						'uploads'     => $uploadsCount,
						'processed'   => $count + $uploadsCount,
						'created'     => (int) $jobStats['created'],
						'existing'    => (int) $jobStats['existing'],
						'errors'      => (int) $jobStats['errors'],
						'failed_validation' => (int) $jobStats['failed_validation'],
						'larger_than_source' => (int) $jobStats['larger_than_source'],
						'bytes_jpeg_compared' => (int) $jobStats['bytes_jpeg_compared'],
						'bytes_avif_generated' => (int) $jobStats['bytes_avif_generated'],
						'retry_quality_sum' => (int) $jobStats['retry_quality_sum'],
						'retry_quality_count' => (int) $jobStats['retry_quality_count'],
						'last_error'  => (string) $jobStats['last_error'],
					)
				);
			}
		}

	/**
	 * Scan wp-content/uploads recursively and convert any JPEG without a matching AVIF.
	 * This catches files created outside attachment metadata (for example theme/plugin-generated sizes).
	 *
	 * @param int  $startingCount Number of attachments already processed, for stop/log messaging.
	 * @param bool $cli Whether the scan is running in WP-CLI context.
	 * @return int Number of JPEG files scanned in uploads.
	 */
	private function scanUploadsJpegsIfMissingAvif( int $startingCount, bool $cli, array &$jobStats ): int {
		$uploadDir = wp_upload_dir();
		$baseDir   = trailingslashit( (string) ( $uploadDir['basedir'] ?? '' ) );
		if ( '' === $baseDir || ! is_dir( $baseDir ) ) {
			return 0;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					$baseDir,
					\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( \UnexpectedValueException $e ) {
			return 0;
		}

		$count = 0;
		$seen  = array();
		foreach ( $iterator as $entry ) {
			if ( ! ( $entry instanceof \SplFileInfo ) || ! $entry->isFile() ) {
				continue;
			}

			// Respect stop requests during long recursive scans.
			if ( \get_transient( 'aviflosu_stop_conversion' ) ) {
				if ( $cli && defined( 'WP_CLI' ) && \WP_CLI ) {
					$processed = $startingCount + $count;
					\WP_CLI::warning( "Conversion stopped by user after {$processed} files." );
				}
				$this->updateConversionJobState( array( 'status' => 'stopped' ) );
				\delete_transient( 'aviflosu_stop_conversion' );
				return $count;
			}

			$path = (string) $entry->getPathname();
			if ( ! preg_match( '/\.(jpe?g)$/i', $path ) ) {
				continue;
			}
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$realPath = (string) ( @realpath( $path ) ?: $path );
			if ( isset( $seen[ $realPath ] ) ) {
				continue;
			}
			$seen[ $realPath ] = true;

				$this->mergeJobStats( $jobStats, $this->processJpegCandidate( $realPath ) );
				++$count;
				$this->updateConversionJobState(
					array(
						'uploads'   => $count,
						'processed' => $startingCount + $count,
						'created'     => (int) $jobStats['created'],
						'existing'    => (int) $jobStats['existing'],
						'errors'      => (int) $jobStats['errors'],
						'failed_validation' => (int) $jobStats['failed_validation'],
						'larger_than_source' => (int) $jobStats['larger_than_source'],
						'bytes_jpeg_compared' => (int) $jobStats['bytes_jpeg_compared'],
						'bytes_avif_generated' => (int) $jobStats['bytes_avif_generated'],
						'retry_quality_sum' => (int) $jobStats['retry_quality_sum'],
						'retry_quality_count' => (int) $jobStats['retry_quality_count'],
						'last_error' => (string) $jobStats['last_error'],
					)
				);
			}

		return $count;
	}

	private function convertGeneratedSizesForce( array $metadata, int $attachmentId, ?array &$jobStats = null ): void {
		if ( ! $this->isJpegMime( get_post_mime_type( $attachmentId ) ) ) {
			return;
		}
		$uploadDir = wp_upload_dir();
		$baseDir   = trailingslashit( $uploadDir['basedir'] ?? '' );

		// De-duped: convert original and sizes via shared helper
		$this->convertFromMetadata( $metadata, $baseDir, $jobStats );
	}

	/**
	 * Convert the original and all generated JPEG sizes for a specific attachment, using current settings.
	 * Returns a structured array with URLs and paths for each size and whether conversion occurred.
	 *
	 * This is used by the Tools → Upload Test section.
	 */
	public function convertAttachmentNow( int $attachmentId ): array {
		$results = array(
			'attachment_id' => $attachmentId,
			'sizes'         => array(),
		);

		if ( ! $this->isJpegMime( get_post_mime_type( $attachmentId ) ) ) {
			return $results;
		}

		$uploads = wp_upload_dir();
		$baseDir = trailingslashit( $uploads['basedir'] ?? '' );
		$baseUrl = trailingslashit( $uploads['baseurl'] ?? '' );

		$meta        = wp_get_attachment_metadata( $attachmentId ) ?: array();
		$originalAbs = get_attached_file( $attachmentId ) ?: '';

		// Derive relative paths for URLs
		$originalRel = '';
		if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
			$originalRel = (string) $meta['file'];
		} elseif ( $originalAbs !== '' && str_starts_with( $originalAbs, $baseDir ) ) {
			$originalRel = ltrim( substr( $originalAbs, strlen( $baseDir ) ), '/' );
		}
		$dirRel = $this->getMetadataDir( $meta );

		$addRow = function ( string $label, string $jpegAbs, string $jpegRel, ?int $width, ?int $height ) use ( &$results, $baseUrl ): void {
			$avifAbs            = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegAbs );
			$avifRel            = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegRel );
			$jpegUrl            = $jpegRel !== '' ? $baseUrl . $jpegRel : '';
			$avifUrl            = $avifRel !== '' ? $baseUrl . $avifRel : '';
			$results['sizes'][] = array(
				'name'           => $label,
				'jpeg_path'      => $jpegAbs,
				'jpeg_url'       => $jpegUrl,
				'avif_path'      => $avifAbs,
				'avif_url'       => $avifUrl,
				'width'          => $width,
				'height'         => $height,
				'jpeg_size'      => file_exists( $jpegAbs ) ? (int) filesize( $jpegAbs ) : 0,
				'avif_size'      => file_exists( $avifAbs ) ? (int) filesize( $avifAbs ) : 0,
				'existed_before' => file_exists( $avifAbs ),
				'converted'      => false,
			);
		};

		if ( $originalAbs !== '' && file_exists( $originalAbs ) ) {
			$w = isset( $meta['width'] ) ? (int) $meta['width'] : null;
			$h = isset( $meta['height'] ) ? (int) $meta['height'] : null;
			$addRow( 'original', $originalAbs, $originalRel, $w, $h );
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $sizeName => $sizeData ) {
				if ( empty( $sizeData['file'] ) || ! is_string( $sizeData['file'] ) ) {
					continue;
				}
				$jpegRel = ( $dirRel !== '' ? trailingslashit( $dirRel ) : '' ) . $sizeData['file'];
				$jpegAbs = $baseDir . $jpegRel;
				if ( ! file_exists( $jpegAbs ) ) {
					continue;
				}
				$width  = isset( $sizeData['width'] ) ? (int) $sizeData['width'] : null;
				$height = isset( $sizeData['height'] ) ? (int) $sizeData['height'] : null;
				$addRow( (string) $sizeName, $jpegAbs, $jpegRel, $width, $height );
			}
		}

		// Perform conversion for each row using the same pipeline
		foreach ( $results['sizes'] as &$row ) {
			$this->checkMissingAvif( $row['jpeg_path'] );
			$row['converted'] = file_exists( $row['avif_path'] );
			// Refresh sizes after conversion
			$row['jpeg_size'] = file_exists( $row['jpeg_path'] ) ? (int) filesize( $row['jpeg_path'] ) : 0;
			$row['avif_size'] = file_exists( $row['avif_path'] ) ? (int) filesize( $row['avif_path'] ) : 0;
		}
		unset( $row );

		return $results;
	}

	/**
	 * When an attachment is permanently deleted, remove any companion .avif files
	 * for the original and its generated sizes.
	 *
	 * @return array{attempted: int, deleted: int} Count of AVIF files found and successfully deleted.
	 */
	public function deleteAvifsForAttachment( int $attachmentId ): array {
		$result = array(
			'attempted' => 0,
			'deleted'   => 0,
		);

		if ( ! $this->isJpegMime( get_post_mime_type( $attachmentId ) ) ) {
			return $result;
		}

		$uploads = wp_upload_dir();
		$baseDir = trailingslashit( $uploads['basedir'] ?? '' );

		$meta = wp_get_attachment_metadata( $attachmentId ) ?: array();

		$paths = array();
		if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
			$paths[] = $baseDir . $meta['file'];
		} else {
			$attached = get_attached_file( $attachmentId );
			if ( $attached ) {
				$paths[] = (string) $attached;
			}
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dirRel = $this->getMetadataDir( $meta );
			foreach ( $meta['sizes'] as $sizeData ) {
				if ( ! empty( $sizeData['file'] ) && is_string( $sizeData['file'] ) ) {
					$paths[] = $baseDir . trailingslashit( $dirRel ) . $sizeData['file'];
				}
			}
		}

		foreach ( $paths as $jpegPath ) {
			$avifPath = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegPath );
			if ( file_exists( $avifPath ) ) {
				++$result['attempted'];
				if ( wp_delete_file( $avifPath ) || ! file_exists( $avifPath ) ) {
					++$result['deleted'];
				}
			}
		}

		// Also delete ThumbHash metadata for this attachment.
		ThumbHash::deleteForAttachment( $attachmentId );

		return $result;
	}

	/**
	 * Helper for Async Upload Test: Get all sizes from attachment metadata without converting.
	 */
	public function getAttachmentSizes( int $attachmentId ): array {
		$results = array(
			'attachment_id' => $attachmentId,
			'sizes'         => array(),
		);

		if ( ! $this->isJpegMime( get_post_mime_type( $attachmentId ) ) ) {
			return $results;
		}

		$uploads = wp_upload_dir();
		$baseDir = trailingslashit( $uploads['basedir'] ?? '' );
		$baseUrl = trailingslashit( $uploads['baseurl'] ?? '' );

		$meta        = wp_get_attachment_metadata( $attachmentId ) ?: array();
		$originalAbs = get_attached_file( $attachmentId ) ?: '';

		// Derive relative paths for URLs
		$originalRel = '';
		if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
			$originalRel = (string) $meta['file'];
		} elseif ( $originalAbs !== '' && str_starts_with( $originalAbs, $baseDir ) ) {
			$originalRel = ltrim( substr( $originalAbs, strlen( $baseDir ) ), '/' );
		}
		$dirRel = $this->getMetadataDir( $meta );

		$addRow = function ( string $label, string $jpegAbs, string $jpegRel, ?int $width, ?int $height ) use ( &$results, $baseUrl ): void {
			$avifAbs            = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegAbs );
			$avifRel            = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegRel );
			$jpegUrl            = $jpegRel !== '' ? $baseUrl . $jpegRel : '';
			$avifUrl            = $avifRel !== '' ? $baseUrl . $avifRel : '';
			$avifExists         = file_exists( $avifAbs );
			$results['sizes'][] = array(
				'name'      => $label,
				'jpeg_path' => $jpegAbs,
				'jpeg_url'  => $jpegUrl,
				'avif_path' => $avifAbs,
				'avif_url'  => $avifUrl,
				'width'     => $width,
				'height'    => $height,
				'jpeg_size' => file_exists( $jpegAbs ) ? (int) filesize( $jpegAbs ) : 0,
				'avif_size' => $avifExists ? (int) filesize( $avifAbs ) : 0,
				'converted' => $avifExists,
				'status'    => $avifExists ? 'success' : 'pending',
			);
		};

		if ( $originalAbs !== '' && file_exists( $originalAbs ) ) {
			$w = isset( $meta['width'] ) ? (int) $meta['width'] : null;
			$h = isset( $meta['height'] ) ? (int) $meta['height'] : null;
			$addRow( 'original', $originalAbs, $originalRel, $w, $h );
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $sizeName => $sizeData ) {
				if ( empty( $sizeData['file'] ) || ! is_string( $sizeData['file'] ) ) {
					continue;
				}
				$jpegRel = ( $dirRel !== '' ? trailingslashit( $dirRel ) : '' ) . $sizeData['file'];
				$jpegAbs = $baseDir . $jpegRel;
				if ( ! file_exists( $jpegAbs ) ) {
					continue;
				}

				$width  = isset( $sizeData['width'] ) ? (int) $sizeData['width'] : null;
				$height = isset( $sizeData['height'] ) ? (int) $sizeData['height'] : null;
				$addRow( (string) $sizeName, $jpegAbs, $jpegRel, $width, $height );
			}
		}
		return $results;
	}

	/**
	 * Helper for Async Upload Test: Convert a single JPEG file path to AVIF.
	 * Returns true if AVIF exists after attempt.
	 */
	public function convertSingleJpegToAvif( string $jpegPath ): ConversionResult {
		if ( empty( $jpegPath ) || ! file_exists( $jpegPath ) ) {
			return ConversionResult::failure( __( 'Source file not found', 'avif-local-support' ) );
		}

		// This will create the AVIF if it doesn't exist. Returns ConversionResult if attempted, null if skipped.
		$result = $this->checkMissingAvif( $jpegPath );

		if ( $result !== null ) {
			return $result;
		}

		$avifPath = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegPath );
		if ( file_exists( $avifPath ) ) {
			return ConversionResult::success();
		}

		return ConversionResult::failure( __( 'Unknown error (conversion skipped)', 'avif-local-support' ) );
	}



	/**
	 * When WordPress deletes a specific file (e.g., a resized JPEG), also delete its
	 * .avif companion if present. Must return the original path so core proceeds.
	 */
	public function deleteCompanionAvif( string $path ): string {
		if ( is_string( $path ) && $path !== '' && preg_match( '/\.(jpe?g)$/i', $path ) ) {
			$avifPath = (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $path );
			if ( $avifPath !== '' && @file_exists( $avifPath ) && ! @is_link( $avifPath ) ) {
				wp_delete_file( $avifPath );
			}
		}
		return $path;
	}

	/**
	 * Log conversion attempt with all relevant details
	 */
	private function log_conversion( string $status, string $sourcePath, string $avifPath, string $engine_used, float $start_time, ?string $error_message = null, ?array $details = null, ?string $referenceJpegPath = null ): void {
		// Skip logging if neither plugin nor logger is available
		if ( ! $this->plugin && ! $this->logger ) {
			return;
		}

		$end_time = microtime( true );
		$duration = round( ( $end_time - $start_time ) * 1000, 2 ); // Convert to milliseconds

		$comparisonPath = ( is_string( $referenceJpegPath ) && '' !== $referenceJpegPath && file_exists( $referenceJpegPath ) )
			? $referenceJpegPath
			: $sourcePath;
		$sourceSize = file_exists( $comparisonPath ) ? (int) filesize( $comparisonPath ) : 0;
		$targetSize = file_exists( $avifPath ) ? (int) filesize( $avifPath ) : 0;

		$logDetails = array(
			'result'      => $status,
			'source_file' => basename( $comparisonPath ),
			'target_file' => basename( $avifPath ),
			'engine_used' => $engine_used,
			'duration_ms' => $duration,
			'source_size' => $sourceSize,
			'target_size' => $targetSize,
		);
		if ( '' !== $comparisonPath ) {
			$attachmentId = (int) \attachment_url_to_postid( $this->pathToUploadsUrl( $comparisonPath ) );
			if ( $attachmentId > 0 ) {
				$logDetails['attachment_id'] = $attachmentId;
			}
		}
		if ( basename( $comparisonPath ) !== basename( $sourcePath ) ) {
			$logDetails['encoded_from'] = basename( $sourcePath );
		}

		$sourceUrl = $this->pathToUploadsUrl( $comparisonPath );
		if ( '' !== $sourceUrl ) {
			$logDetails['source_url'] = $sourceUrl;
		}
		$targetUrl = $this->pathToUploadsUrl( $avifPath );
		if ( '' !== $targetUrl ) {
			$logDetails['target_url'] = $targetUrl;
		}

		if ( $details !== null ) {
			// Avoid logging overly noisy or sensitive details (env vars / secrets).
			$logDetails = array_merge( $logDetails, $this->sanitize_log_details( $details ) );
		}

		// Add error message if provided
		if ( $error_message ) {
			$logDetails['error'] = $error_message;
			if ( ! isset( $logDetails['error_suggestion'] ) ) {
				$logDetails['error_suggestion'] = $this->getErrorSuggestion( $error_message );
			}
		}

		$message = $status === 'success'
			? 'Successfully converted ' . basename( $comparisonPath ) . " to AVIF using $engine_used"
			: 'Failed to convert ' . basename( $comparisonPath ) . " to AVIF using $engine_used";

		if ( $error_message ) {
			$message .= ": $error_message";
		}

		// Use direct logger if available (CLI), otherwise use plugin's logger
		if ( $this->logger ) {
			$this->logger->addLog( $status, $message, $logDetails );
		} elseif ( $this->plugin ) {
			$this->plugin->add_log( $status, $message, $logDetails );
		}
	}

	/**
	 * Convert an absolute uploads path into a public uploads URL.
	 */
	private function pathToUploadsUrl( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$baseDir = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );
		$baseUrl = trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) );
		if ( '' === $baseDir || '' === $baseUrl ) {
			return '';
		}

		$normalizedPath = str_replace( '\\', '/', $path );
		$normalizedBase = str_replace( '\\', '/', $baseDir );
		if ( ! str_starts_with( $normalizedPath, $normalizedBase ) ) {
			return '';
		}

		$relative = ltrim( substr( $normalizedPath, strlen( $normalizedBase ) ), '/' );
		return $baseUrl . str_replace( ' ', '%20', $relative );
	}

	/**
	 * Sanitize details before persisting them to logs.
	 */
	private function sanitize_log_details( array $details ): array {
		// If the CLI env is provided as a multiline string, only keep PATH (and cap length).
		if ( isset( $details['cli_env'] ) && is_string( $details['cli_env'] ) ) {
			$envLines = preg_split( "/\r\n|\r|\n/", $details['cli_env'] ) ?: array();
			$path     = '';
			foreach ( $envLines as $line ) {
				$line = trim( (string) $line );
				if ( stripos( $line, 'PATH=' ) === 0 ) {
					$path = substr( $line, 5 );
					break;
				}
			}
			if ( $path !== '' ) {
				if ( strlen( $path ) > 500 ) {
					$path = substr( $path, 0, 500 ) . '…';
				}
				$details['cli_env'] = 'PATH=' . $path;
			} else {
				// Still indicate it was set, but don't store the full blob.
				$details['cli_env'] = '(set)';
			}
		}

		// Light redaction for common secret-like patterns in CLI args/env.
		foreach ( array( 'cli_args', 'cli_env' ) as $k ) {
			if ( ! isset( $details[ $k ] ) || ! is_string( $details[ $k ] ) ) {
				continue;
			}
			$details[ $k ] = preg_replace(
				'/\b(password|passwd|secret|token|api[_-]?key)\s*=\s*([^\s]+)/i',
				'$1=REDACTED',
				$details[ $k ]
			) ?? $details[ $k ];
		}

		return $details;
	}

	/**
	 * Provide a concise next action for common conversion failures.
	 */
	private function getErrorSuggestion( string $error ): string {
		$err = strtolower( $error );
		if ( str_contains( $err, 'memory' ) ) {
			return 'Reduce image dimensions, increase PHP memory_limit, or keep memory safety enabled.';
		}
		if ( str_contains( $err, 'imagick' ) || str_contains( $err, 'magick' ) || str_contains( $err, 'cli' ) ) {
			return 'Verify engine settings, CLI path, and PHP extension availability in Server Support.';
		}
		if ( str_contains( $err, 'permission' ) || str_contains( $err, 'write' ) ) {
			return 'Check write permissions for wp-content/uploads.';
		}
		return 'Check Server Support diagnostics and plugin logs for engine/path details.';
	}
}
