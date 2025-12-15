<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Admin;

use Ddegner\AvifLocalSupport\Converter;
use Ddegner\AvifLocalSupport\Diagnostics;
use Ddegner\AvifLocalSupport\Formatter;
use Ddegner\AvifLocalSupport\ImageMagickCli;
use Ddegner\AvifLocalSupport\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Handles REST API routes for AVIF Local Support plugin.
 */
final class RestController {

	private const NAMESPACE = 'aviflosu/v1';

	private Converter $converter;
	private Logger $logger;
	private Diagnostics $diagnostics;

	public function __construct( Converter $converter, Logger $logger, Diagnostics $diagnostics ) {
		$this->converter   = $converter;
		$this->logger      = $logger;
		$this->diagnostics = $diagnostics;
	}

	/**
	 * Register all REST routes.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/scan-missing',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'scanMissing' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/convert-now',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'convertNow' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stop-convert',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'stopConvert' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/delete-all-avifs',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'deleteAllAvifs' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'getLogs' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/clear',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'clearLogs' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/magick-test',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'runMagickTest' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/upload-test-status',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'uploadTestStatus' ),
				'args'                => array(
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'target_index'  => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/upload-test',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permissionManageOptions' ),
				'callback'            => array( $this, 'uploadTest' ),
			)
		);
	}

	public function permissionManageOptions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function scanMissing( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->diagnostics->computeMissingCounts() );
	}

	public function convertNow( \WP_REST_Request $request ): \WP_REST_Response {
		$queued = false;
		if ( ! \wp_next_scheduled( 'aviflosu_run_on_demand' ) ) {
			\wp_schedule_single_event( time() + 5, 'aviflosu_run_on_demand' );
			$queued = true;
		}
		return rest_ensure_response( array( 'queued' => $queued ) );
	}

	public function stopConvert( \WP_REST_Request $request ): \WP_REST_Response {
		// Set stop flag that the conversion loop checks
		\set_transient( 'aviflosu_stop_conversion', true, 300 ); // 5 minute expiry

		// Also unschedule any pending cron job
		$timestamp = \wp_next_scheduled( 'aviflosu_run_on_demand' );
		if ( $timestamp ) {
			\wp_unschedule_event( $timestamp, 'aviflosu_run_on_demand' );
		}

		return rest_ensure_response( array( 'stopped' => true ) );
	}

	public function deleteAllAvifs( \WP_REST_Request $request ): \WP_REST_Response {
		$uploads = \wp_upload_dir();
		$baseDir = (string) ( $uploads['basedir'] ?? '' );

		if ( $baseDir === '' || ! is_dir( $baseDir ) ) {
			return new \WP_REST_Response( array( 'message' => 'uploads_not_found' ), 400 );
		}

		$deleted = 0;
		$failed  = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $baseDir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $fileInfo ) {
			if ( ! $fileInfo instanceof \SplFileInfo ) {
				continue;
			}
			$path = $fileInfo->getPathname();
			if ( \preg_match( '/\.avif$/i', $path ) ) {
				if ( $fileInfo->isLink() ) {
					continue;
				}
				$ok = \wp_delete_file( $path );
				if ( $ok ) {
					++$deleted;
				} else {
					++$failed;
				}
			}
		}

		return rest_ensure_response(
			array(
				'deleted' => $deleted,
				'failed'  => $failed,
			)
		);
	}

	public function getLogs( \WP_REST_Request $request ): \WP_REST_Response {
		ob_start();
		$this->logger->renderLogsContent();
		$content = ob_get_clean();
		return rest_ensure_response( array( 'content' => $content ) );
	}

	public function clearLogs( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->clearLogs();
		return rest_ensure_response( array( 'message' => 'Logs cleared' ) );
	}

	public function runMagickTest( \WP_REST_Request $request ): \WP_REST_Response {
		$path     = (string) get_option( 'aviflosu_cli_path', '' );
		$detected = $this->diagnostics->detectCliBinaries();

		if ( $path === '' && ! empty( $detected ) ) {
			$path = (string) ( $detected[0]['path'] ?? '' );
		}

		$autoSelected = false;
		if ( $path === '' ) {
			$auto = ImageMagickCli::getAutoDetectedPath( null );
			if ( $auto !== '' ) {
				$path         = $auto;
				$autoSelected = true;
			}
		}

		$disableFunctions = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		$execAvailable    = ! in_array( 'exec', $disableFunctions, true );

		if ( ! $execAvailable ) {
			return new \WP_REST_Response( array( 'message' => 'exec disabled by PHP disable_functions.' ), 400 );
		}

		if ( $path === '' || ! @file_exists( $path ) ) {
			return new \WP_REST_Response( array( 'message' => 'No ImageMagick CLI path found. Set a custom path under Engine Selection.' ), 400 );
		}

		$strategy = ImageMagickCli::getDefineStrategy( $path, null );

		$cmd      = escapeshellarg( $path ) . ' -version 2>&1';
		$outLines = array();
		$exitCode = 0;
		@exec( $cmd, $outLines, $exitCode );
		$output = trim( implode( "\n", array_map( 'strval', $outLines ) ) );

		if ( $output === '' ) {
			return rest_ensure_response(
				array(
					'code'            => $exitCode,
					'output'          => $output,
					'hint'            => 'No output. If using ImageMagick 7, ensure the path points to `magick`.',
					'selected_path'   => $path,
					'auto_selected'   => $autoSelected,
					'define_strategy' => $strategy,
				)
			);
		}

		return rest_ensure_response(
			array(
				'code'            => $exitCode,
				'output'          => $output,
				'selected_path'   => $path,
				'auto_selected'   => $autoSelected,
				'define_strategy' => $strategy,
			)
		);
	}

	public function uploadTest( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$files   = $request->get_file_params();
		$rawFile = isset( $files['avif_local_support_test_file'] ) && is_array( $files['avif_local_support_test_file'] )
			? $files['avif_local_support_test_file']
			: array();

		if ( empty( $rawFile ) || empty( $rawFile['tmp_name'] ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No file uploaded.', 'avif-local-support' ) ), 400 );
		}

		$fileType = wp_check_filetype_and_ext(
			(string) $rawFile['tmp_name'],
			(string) ( $rawFile['name'] ?? '' ),
			array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
			)
		);

		if ( empty( $fileType['ext'] ) || ! \in_array( $fileType['ext'], array( 'jpg', 'jpeg' ), true ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Only JPEG files are allowed.', 'avif-local-support' ) ), 400 );
		}

		$attachment_id = media_handle_sideload( $rawFile, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_REST_Response( array( 'message' => $attachment_id->get_error_message() ), 400 );
		}

		$file = get_attached_file( $attachment_id );
		if ( $file ) {
			$metadata = \wp_generate_attachment_metadata( $attachment_id, $file );
			if ( $metadata ) {
				\wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		// Get sizes without converting - conversion happens incrementally via status endpoint
		$sizes    = $this->converter->getAttachmentSizes( (int) $attachment_id );
		$editLink = get_edit_post_link( $attachment_id );
		$title    = get_the_title( $attachment_id ) ?: (string) $attachment_id;

		return rest_ensure_response(
			array(
				'attachment_id' => $attachment_id,
				'edit_link'     => $editLink ?: '',
				'title'         => $title,
				'sizes'         => $sizes['sizes'] ?? array(),
				'complete'      => false,
			)
		);
	}

	/**
	 * Poll for upload test status and convert one size at a time by index.
	 * Guaranteed to progress through the list one by one.
	 */
	public function uploadTestStatus( \WP_REST_Request $request ): \WP_REST_Response {
		$attachmentId = (int) $request->get_param( 'attachment_id' );
		$targetIndex  = (int) $request->get_param( 'target_index' );

		if ( $attachmentId <= 0 ) {
			return new \WP_REST_Response( array( 'message' => 'Invalid attachment ID.' ), 400 );
		}

		// Get current sizes
		$data       = $this->converter->getAttachmentSizes( $attachmentId );
		$sizes      = $data['sizes'] ?? array();
		$totalCount = count( $sizes );

		// Fix stateless polling issue:
		// Function getAttachmentSizes() returns 'pending' if file is missing.
		// But if we have already iterated past an index (i < targetIndex),
		// and it is still 'pending' (meaning no file created), it corresponds to a failure.
		for ( $i = 0; $i < $targetIndex && $i < $totalCount; $i++ ) {
			if ( isset( $sizes[ $i ]['status'] ) && $sizes[ $i ]['status'] === 'pending' ) {
				$sizes[ $i ]['status'] = 'failure';
			}
		}

		if ( $targetIndex >= $totalCount ) {
			// Index out of bounds - we are done
			$complete = true;
		} else {
			$complete = false;
			$size     = &$sizes[ $targetIndex ];

			if ( $size['status'] === 'success' ) {
				// Already converted, skip
			} else {
				$jpegPath = $size['jpeg_path'] ?? '';
				if ( $jpegPath !== '' ) {
					$conversionResult  = $this->converter->convertSingleJpegToAvif( $jpegPath );
					$success           = $conversionResult->success;
					$size['converted'] = $success;
					$size['status']    = $success ? 'success' : 'failure';
					if ( ! $success && ! empty( $conversionResult->error ) ) {
						$size['error'] = $conversionResult->error;
					}
					if ( $success ) {
						// Refresh AVIF size
						$avifPath          = $size['avif_path'] ?? '';
						$size['avif_size'] = file_exists( $avifPath ) ? (int) filesize( $avifPath ) : 0;
					}
				} else {
					$size['status'] = 'failure';
				}
			}
		}

		// Mark the next item as 'processing' so frontend can show spinner
		$nextIndex = $targetIndex + 1;
		if ( ! $complete && $nextIndex < $totalCount && $sizes[ $nextIndex ]['status'] === 'pending' ) {
			$sizes[ $nextIndex ]['status'] = 'processing';
		}

		$editLink = get_edit_post_link( $attachmentId );
		$title    = get_the_title( $attachmentId ) ?: (string) $attachmentId;

		return rest_ensure_response(
			array(
				'attachment_id' => $attachmentId,
				'edit_link'     => $editLink ?: '',
				'title'         => $title,
				'sizes'         => $sizes,
				'complete'      => $complete,
				'next_index'    => $nextIndex,
			)
		);
	}
}
