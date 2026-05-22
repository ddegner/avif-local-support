<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem-based JPEG scanner.
 *
 * Complements the DB-based Media Library scan by walking wp-content/uploads/
 * directly, so JPEGs that landed there outside the Media Library (page-builder
 * caches, direct FTP uploads, legacy imports) also get AVIF siblings produced.
 *
 * Skips privacy-sensitive directories (form plugins, backups, etc.) by default
 * and honors an `.aviflosu-skip` marker file for per-directory opt-out.
 */
final class FilesystemScanner {

	private const PROGRESS_TRANSIENT  = 'aviflosu_fs_scan_progress';
	private const STOP_TRANSIENT      = 'aviflosu_stop_conversion';
	private const OPT_OUT_MARKER      = '.aviflosu-skip';
	private const PREVIEW_SAMPLE_SIZE = 20;
	private const PROGRESS_TTL        = 3600;

	/**
	 * Directory basenames to skip during the walk.
	 *
	 * Covers: form plugins (private user uploads), backup plugins (potentially
	 * sensitive data), import staging, other image optimizers (avoid double
	 * work / conflicts), and common trash/versions folders.
	 */
	private const SKIP_DIR_BASENAMES = array(
		// Form plugin uploads (private).
		'gravity_forms',
		'wpforms',
		'wpcf7_uploads',
		'nf-subs',
		'forminator_uploads',
		'fluentform',
		// Backup plugin storage.
		'updraft',
		'backwpup',
		'backupbuddy_backups',
		'wpvivid',
		'mainwp',
		'duplicator',
		'ai1wm-backups',
		// Import staging.
		'wpallimport',
		'wp-all-import-pro',
		'imported',
		// Other optimizers' private caches.
		'ewww',
		'shortpixel_backups',
		'smush-webp',
		'webp-express',
		'webp-converter-for-media',
		'litespeed',
		'wp-rocket',
		// Trash / versions.
		'trash',
		'.trash',
		'versions',
	);

	private Converter $converter;

	public function __construct( Converter $converter ) {
		$this->converter = $converter;
	}

	public function init(): void {
		add_action( 'aviflosu_run_filesystem_scan', array( $this, 'run' ) );
	}

	/**
	 * Dry-run: classify candidates under uploads/ without converting.
	 *
	 * @return array{
	 *   found:int,
	 *   already_have_avif:int,
	 *   will_convert:int,
	 *   skipped:array<int,array{dir:string,reason:string,count:int}>,
	 *   sample:array<int,string>
	 * }
	 */
	public function preview(): array {
		$baseDir = $this->uploadsBaseDir();
		$result  = array(
			'found'             => 0,
			'already_have_avif' => 0,
			'will_convert'      => 0,
			'skipped'           => array(),
			'sample'            => array(),
		);

		if ( '' === $baseDir || ! is_dir( $baseDir ) ) {
			return $result;
		}

		$skipped = array();
		try {
			foreach ( $this->iterateJpegs( $baseDir, $skipped ) as $jpegPath ) {
				++$result['found'];
				$avifPath = $this->avifPathFor( $jpegPath );
				if ( '' !== $avifPath && file_exists( $avifPath ) ) {
					++$result['already_have_avif'];
					continue;
				}
				++$result['will_convert'];
				if ( count( $result['sample'] ) < self::PREVIEW_SAMPLE_SIZE ) {
					$result['sample'][] = $this->relativePath( $jpegPath, $baseDir );
				}
			}
		} catch ( \Throwable $e ) {
			// Never leak a fatal from the preview endpoint.
			unset( $e );
		}

		$result['skipped'] = $this->formatSkipped( $skipped, $baseDir );
		return $result;
	}

	/**
	 * Walk uploads/ and convert every missing AVIF found.
	 * Writes progress to a transient so the admin UI can poll.
	 */
	public function run(): void {
		$baseDir = $this->uploadsBaseDir();
		if ( '' === $baseDir || ! is_dir( $baseDir ) ) {
			$this->writeProgress(
				array(
					'total_found' => 0,
					'scanned'     => 0,
					'converted'   => 0,
					'already_had' => 0,
					'failed'      => 0,
					'skipped'     => 0,
					'done'        => true,
					'started_at'  => time(),
					'finished_at' => time(),
				)
			);
			return;
		}

		// Clear any previous stop flag so a fresh scan isn't instantly aborted.
		delete_transient( self::STOP_TRANSIENT );

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$progress = array(
			'total_found' => 0,
			'scanned'     => 0,
			'converted'   => 0,
			'already_had' => 0,
			'failed'      => 0,
			'skipped'     => 0,
			'done'        => false,
			'started_at'  => time(),
			'state'       => 'running',
		);
		$this->writeProgress( $progress );

		$skipped      = array();
		$lastFlush    = microtime( true );
		$checkCounter = 0;

		try {
			foreach ( $this->iterateJpegs( $baseDir, $skipped ) as $jpegPath ) {
				++$checkCounter;
				// Check stop flag every 25 files.
				if ( ( $checkCounter % 25 ) === 0 && get_transient( self::STOP_TRANSIENT ) ) {
					$progress['done'] = true;
					break;
				}

				++$progress['total_found'];
				++$progress['scanned'];

				$avifPath = $this->avifPathFor( $jpegPath );
				if ( '' !== $avifPath && file_exists( $avifPath ) ) {
					++$progress['already_had'];
					++$progress['skipped'];
				} else {
					$result = $this->converter->convertSingleJpegToAvif( $jpegPath );
					if ( $result->success ) {
						++$progress['converted'];
					} else {
						++$progress['failed'];
						++$progress['skipped'];
					}
				}

				// Flush progress transient every ~1.5s so the UI sees movement.
				$now = microtime( true );
				if ( ( $now - $lastFlush ) >= 1.5 ) {
					$this->writeProgress( $progress );
					$lastFlush = $now;
				}
			}
		} catch ( \Throwable $e ) {
			// Record and finish; don't 500 the cron request.
			$progress['error'] = $e->getMessage();
		}

		$progress['done']         = true;
		$progress['finished_at']  = time();
		$progress['state']        = 'complete';
		$progress['skipped_dirs'] = $this->formatSkipped( $skipped, $baseDir );
		$this->writeProgress( $progress );

		// Clear stop flag if it was set, so subsequent runs aren't blocked.
		delete_transient( self::STOP_TRANSIENT );
	}

	/**
	 * Current progress snapshot. Safe to call at any time.
	 */
	public function progress(): array {
		$data = get_transient( self::PROGRESS_TRANSIENT );
		if ( ! is_array( $data ) ) {
			return array(
				'total_found' => 0,
				'scanned'     => 0,
				'converted'   => 0,
				'already_had' => 0,
				'failed'      => 0,
				'skipped'     => 0,
				'done'        => true,
				'started_at'  => 0,
				'state'       => 'idle',
			);
		}
		return $data;
	}

	/**
	 * Mark the scan as queued so UI polling sees movement while waiting for
	 * the scheduled cron tick to start run().
	 */
	public function markQueued(): void {
		$this->writeProgress(
			array(
				'total_found' => 0,
				'scanned'     => 0,
				'converted'   => 0,
				'already_had' => 0,
				'failed'      => 0,
				'skipped'     => 0,
				'done'        => false,
				'started_at'  => time(),
				'state'       => 'queued',
			)
		);
	}

	/**
	 * Yields absolute paths to JPEG files under $baseDir, respecting skip rules.
	 * Populates $skipped with an entry per skipped directory.
	 *
	 * @param array<string,array{reason:string,count:int}> $skipped
	 * @return \Generator<int,string>
	 */
	private function iterateJpegs( string $baseDir, array &$skipped ): \Generator {
		$skipRef = &$skipped;

		$dirIterator = new \RecursiveDirectoryIterator(
			$baseDir,
			\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
		);

		$filtered = new \RecursiveCallbackFilterIterator(
			$dirIterator,
			static function ( \SplFileInfo $current ) use ( &$skipRef ): bool {
				if ( $current->isDir() ) {
					$path     = $current->getPathname();
					$basename = $current->getBasename();

					// Hidden / underscore-prefixed dirs.
					if ( '' !== $basename && ( $basename[0] === '.' || $basename[0] === '_' ) ) {
						self::recordSkip( $skipRef, $path, 'hidden directory' );
						return false;
					}

					// Opt-out marker.
					if ( file_exists( $path . '/' . self::OPT_OUT_MARKER ) ) {
						self::recordSkip( $skipRef, $path, 'opt-out marker' );
						return false;
					}

					// Deny-list by basename (case-insensitive).
					$lower = strtolower( $basename );
					if ( in_array( $lower, self::SKIP_DIR_BASENAMES, true ) ) {
						self::recordSkip( $skipRef, $path, 'deny-list: ' . $lower );
						return false;
					}

					// Unreadable.
					if ( ! is_readable( $path ) ) {
						self::recordSkip( $skipRef, $path, 'unreadable' );
						return false;
					}

					$filterResult = apply_filters( 'aviflosu_filesystem_scan_skip_dir', false, $path, $basename );
					if ( $filterResult ) {
						self::recordSkip( $skipRef, $path, 'filter hook' );
						return false;
					}

					return true;
				}

				// File-level filter for JPEGs.
				if ( ! $current->isFile() ) {
					return false;
				}

				$filename = $current->getFilename();
				if ( ! preg_match( '/\.(jpe?g)$/i', $filename ) ) {
					return false;
				}

				$path = $current->getPathname();
				if ( apply_filters( 'aviflosu_filesystem_scan_skip_file', false, $path ) ) {
					return false;
				}

				return true;
			}
		);

		$iterator = new \RecursiveIteratorIterator(
			$filtered,
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterator as $fileInfo ) {
			/** @var \SplFileInfo $fileInfo */
			if ( $fileInfo->isFile() ) {
				yield $fileInfo->getPathname();
			}
		}
	}

	/**
	 * @param array<string,array{reason:string,count:int}> $skipped
	 */
	private static function recordSkip( array &$skipped, string $path, string $reason ): void {
		if ( isset( $skipped[ $path ] ) ) {
			++$skipped[ $path ]['count'];
			return;
		}
		$skipped[ $path ] = array(
			'reason' => $reason,
			'count'  => 1,
		);
	}

	/**
	 * @param array<string,array{reason:string,count:int}> $skipped
	 * @return array<int,array{dir:string,reason:string,count:int}>
	 */
	private function formatSkipped( array $skipped, string $baseDir ): array {
		$out = array();
		foreach ( $skipped as $path => $info ) {
			$out[] = array(
				'dir'    => $this->relativePath( $path, $baseDir ),
				'reason' => $info['reason'],
				'count'  => $info['count'],
			);
		}
		return $out;
	}

	private function avifPathFor( string $jpegPath ): string {
		return (string) preg_replace( '/\.(jpe?g)$/i', '.avif', $jpegPath );
	}

	private function relativePath( string $absolute, string $baseDir ): string {
		$baseDir = rtrim( $baseDir, '/' );
		if ( str_starts_with( $absolute, $baseDir . '/' ) ) {
			return substr( $absolute, strlen( $baseDir ) + 1 );
		}
		if ( $absolute === $baseDir ) {
			return '';
		}
		return $absolute;
	}

	private function uploadsBaseDir(): string {
		$uploads = wp_get_upload_dir();
		$baseDir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		return rtrim( $baseDir, '/' );
	}

	private function writeProgress( array $progress ): void {
		set_transient( self::PROGRESS_TRANSIENT, $progress, self::PROGRESS_TTL );
	}
}
