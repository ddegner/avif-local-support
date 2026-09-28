<?php
/**
 * Cursor-based, time-sliced batch scan over media-library attachments.
 *
 * @package Ddegner\AvifLocalSupport
 */

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Cursor-based, time-sliced batch scan over media-library attachments.
 *
 * Bulk jobs (AVIF conversion, LQIP generation) can take hours on large
 * libraries — far longer than a single cron request may safely run. This
 * runner processes attachments in ID order for a bounded time slice, then
 * persists a cursor and schedules a follow-up cron event so the next slice
 * resumes exactly where this one stopped.
 */
final class AttachmentBatchRunner {

	public const STATUS_COMPLETE = 'complete';
	public const STATUS_PAUSED   = 'paused';
	public const STATUS_STOPPED  = 'stopped';

	/**
	 * How many attachment IDs to fetch per query while scanning.
	 */
	private const IDS_PER_QUERY = 100;

	/**
	 * Default wall-clock budget (seconds) for one cron slice. Kept well below
	 * typical max_execution_time; a single slow encode may overshoot since the
	 * budget is only checked between items.
	 */
	private const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Delay (seconds) before a queued scan or continuation slice starts.
	 */
	private const SCHEDULE_DELAY = 5;

	/**
	 * Configure a batch scan.
	 *
	 * @param string   $cursorTransient  Option holding the resume cursor (legacy name).
	 * @param string   $stopTransient    Transient acting as the stop flag.
	 * @param string   $continuationHook Cron hook that runs the next slice.
	 * @param string[] $mimeTypes        Attachment MIME types to scan.
	 * @param \Closure $processItem      Called as fn( int $attachmentId ): void.
	 */
	public function __construct(
		private readonly string $cursorTransient,
		private readonly string $stopTransient,
		private readonly string $continuationHook,
		private readonly array $mimeTypes,
		private readonly \Closure $processItem,
	) {
	}

	/**
	 * Queue a scan via a single cron event unless one is already pending.
	 *
	 * @param string $hook          Cron hook that runs the scan.
	 * @param string $stopTransient Stop flag to clear so the scan can start.
	 * @param int    $delay         Seconds before the event fires.
	 * @return bool Whether a new event was scheduled.
	 */
	public static function queue( string $hook, string $stopTransient, int $delay = self::SCHEDULE_DELAY ): bool {
		if ( wp_next_scheduled( $hook ) ) {
			return false;
		}
		$scheduled = wp_schedule_single_event( time() + $delay, $hook );
		if ( $scheduled ) {
			self::clearStopFlag( $stopTransient );
		}
		return (bool) $scheduled;
	}

	/**
	 * Abandon a scan: drop the pending continuation event, raise the stop flag
	 * for any slice currently running, and forget the resume cursor.
	 *
	 * @param string $hook            Cron hook the scan runs on.
	 * @param string $stopTransient   Stop flag to raise.
	 * @param string $cursorTransient Cursor to forget.
	 */
	public static function stop( string $hook, string $stopTransient, string $cursorTransient ): void {
		// Cancellation lasts until an explicit queue request, even on quiet sites.
		self::refreshStopFlagOptionCache( $stopTransient );
		if ( ! wp_using_ext_object_cache() && ! wp_installing() ) {
			// set_transient(..., 0) does not remove a pre-existing DB timeout.
			delete_option( '_transient_timeout_' . $stopTransient );
		}
		set_transient( $stopTransient, true, 0 );
		wp_clear_scheduled_hook( $hook );
		self::clearCursor( $cursorTransient );
	}

	/**
	 * Forget durable progress and any cursor left by an earlier plugin version.
	 *
	 * @param string $cursorKey Option and legacy transient key for the cursor.
	 */
	public static function clearCursor( string $cursorKey ): void {
		delete_option( $cursorKey );
		delete_transient( $cursorKey );
	}

	/**
	 * Clear cancellation only when explicitly starting a new job.
	 *
	 * @param string $stopTransient Transient key for the cancellation flag.
	 */
	public static function clearStopFlag( string $stopTransient ): void {
		self::refreshStopFlagOptionCache( $stopTransient );
		delete_transient( $stopTransient );
	}

	/**
	 * Read cancellation written by another request without a stale local cache.
	 *
	 * @param string $stopTransient Transient key for the cancellation flag.
	 */
	public static function isStopped( string $stopTransient ): bool {
		if ( wp_using_ext_object_cache() || wp_installing() ) {
			// Force a backend read, without deleting the authoritative stop flag.
			return (bool) wp_cache_get( $stopTransient, 'transient', true );
		}

		global $wpdb;
		$valueName   = '_transient_' . $stopTransient;
		$timeoutName = '_transient_timeout_' . $stopTransient;
		// A running worker can retain a miss in notoptions or an old alloptions
		// snapshot. Query only these two keys instead of flushing all options on
		// every item. Honor timeouts left by earlier plugin versions, too.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				$valueName,
				$timeoutName
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = array_column( $rows, 'option_value', 'option_name' );
		return ! empty( $values[ $valueName ] )
			&& ( ! isset( $values[ $timeoutName ] ) || (int) $values[ $timeoutName ] >= time() );
	}

	/**
	 * Mutations are rare; refresh local option caches before using the WP API.
	 *
	 * @param string $stopTransient Transient key for the cancellation flag.
	 */
	private static function refreshStopFlagOptionCache( string $stopTransient ): void {
		if ( wp_using_ext_object_cache() || wp_installing() ) {
			return;
		}
		wp_cache_delete( '_transient_' . $stopTransient, 'options' );
		wp_cache_delete( '_transient_timeout_' . $stopTransient, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Run one time slice of the scan.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function run(): string {
		$storedCursor = get_option( $this->cursorTransient, false );
		if ( false === $storedCursor ) {
			// Preserve a live continuation when upgrading from transient cursors.
			$storedCursor = get_transient( $this->cursorTransient );
			if ( $storedCursor ) {
				update_option( $this->cursorTransient, (int) $storedCursor, false );
			}
			delete_transient( $this->cursorTransient );
		}
		$cursor = (int) $storedCursor;

		/**
		 * Filters the wall-clock budget (seconds) for one batch-scan slice.
		 *
		 * @param int    $timeBudget Seconds a slice may run before pausing.
		 * @param string $hook       The continuation hook of this scan.
		 */
		$timeBudget = (int) apply_filters( 'aviflosu_batch_time_budget', self::DEFAULT_TIME_BUDGET, $this->continuationHook );
		$deadline   = time() + max( 1, $timeBudget );

		while ( true ) {
			if ( self::isStopped( $this->stopTransient ) ) {
				self::clearCursor( $this->cursorTransient );
				return self::STATUS_STOPPED;
			}
			$ids = AttachmentQuery::idsAfter( $cursor, self::IDS_PER_QUERY, $this->mimeTypes );
			if ( empty( $ids ) ) {
				self::clearCursor( $this->cursorTransient );
				return self::STATUS_COMPLETE;
			}

			foreach ( $ids as $id ) {
				if ( self::isStopped( $this->stopTransient ) ) {
					self::clearCursor( $this->cursorTransient );
					return self::STATUS_STOPPED;
				}

				( $this->processItem )( $id );
				$cursor = $id;
				if ( self::isStopped( $this->stopTransient ) ) {
					self::clearCursor( $this->cursorTransient );
					return self::STATUS_STOPPED;
				}

				if ( time() >= $deadline ) {
					// WP-Cron may not run again for days; progress must not expire.
					update_option( $this->cursorTransient, $cursor, false );
					if ( ! wp_next_scheduled( $this->continuationHook ) ) {
						wp_schedule_single_event( time() + self::SCHEDULE_DELAY, $this->continuationHook );
					}
					return self::STATUS_PAUSED;
				}
			}
		}
	}
}
