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
	 * Cursors only need to outlive the gap between slices.
	 */
	private const CURSOR_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Delay (seconds) before a queued scan or continuation slice starts.
	 */
	private const SCHEDULE_DELAY = 5;

	/**
	 * Configure a batch scan.
	 *
	 * @param string   $cursorTransient  Transient holding the resume cursor.
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
		delete_transient( $stopTransient );
		wp_schedule_single_event( time() + $delay, $hook );
		return true;
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
		set_transient( $stopTransient, true, 5 * MINUTE_IN_SECONDS );
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
		delete_transient( $cursorTransient );
	}

	/**
	 * Run one time slice of the scan.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function run(): string {
		$cursor = (int) get_transient( $this->cursorTransient );
		if ( 0 === $cursor ) {
			// Fresh scan: a stale stop flag must not abort it before it starts.
			delete_transient( $this->stopTransient );
		}

		/**
		 * Filters the wall-clock budget (seconds) for one batch-scan slice.
		 *
		 * @param int    $timeBudget Seconds a slice may run before pausing.
		 * @param string $hook       The continuation hook of this scan.
		 */
		$timeBudget = (int) apply_filters( 'aviflosu_batch_time_budget', self::DEFAULT_TIME_BUDGET, $this->continuationHook );
		$deadline   = time() + max( 1, $timeBudget );

		while ( true ) {
			$ids = AttachmentQuery::idsAfter( $cursor, self::IDS_PER_QUERY, $this->mimeTypes );
			if ( empty( $ids ) ) {
				delete_transient( $this->cursorTransient );
				return self::STATUS_COMPLETE;
			}

			foreach ( $ids as $id ) {
				if ( get_transient( $this->stopTransient ) ) {
					delete_transient( $this->cursorTransient );
					delete_transient( $this->stopTransient );
					return self::STATUS_STOPPED;
				}

				( $this->processItem )( $id );
				$cursor = $id;

				if ( time() >= $deadline ) {
					set_transient( $this->cursorTransient, $cursor, self::CURSOR_TTL );
					if ( ! wp_next_scheduled( $this->continuationHook ) ) {
						wp_schedule_single_event( time() + self::SCHEDULE_DELAY, $this->continuationHook );
					}
					return self::STATUS_PAUSED;
				}
			}
		}
	}
}
