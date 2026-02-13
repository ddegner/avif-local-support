<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Handles logging for AVIF Local Support plugin.
 * Stores logs in WordPress transients with automatic expiration.
 */
final class Logger {


	private const TRANSIENT_KEY = 'aviflosu_logs';
	private const MAX_ENTRIES   = 50;

	/**
	 * Get all logs from storage.
	 *
	 * @return array<int, array{timestamp: int, status: string, message: string, details: array}>
	 */
	public function getLogs(): array {
		$logs = get_transient( self::TRANSIENT_KEY );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Add a log entry.
	 *
	 * @param string $status Log status (success, error, warning, info).
	 * @param string $message Log message.
	 * @param array  $details Additional details.
	 */
	public function addLog( string $status, string $message, array $details = array() ): void {
		$logs = $this->getLogs();

		// Validate status to ensure consistent data
		$status = strtolower( $status );
		if ( ! in_array( $status, array( 'error', 'warning', 'success', 'info' ), true ) ) {
			$status = 'info';
		}

		$logEntry = array(
			'timestamp' => time(),
			'status'    => $status,
			'message'   => $message,
			'details'   => $details,
		);

		// Prepend to show newest first
		array_unshift( $logs, $logEntry );

		// Keep only last N entries to prevent unlimited growth
		$logs = array_slice( $logs, 0, self::MAX_ENTRIES );

		// Store for 24 hours (temporary logs)
		set_transient( self::TRANSIENT_KEY, $logs, DAY_IN_SECONDS );
	}

	/**
	 * Clear all logs.
	 */
	public function clearLogs(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Render logs content as HTML for the admin interface.
	 * Note: Permission checks are handled by the REST endpoint or admin page context.
	 */
	public function renderLogsContent(): void {
		$logs = $this->getLogs();

		if ( empty( $logs ) ) {
			echo '<p class="description">' . esc_html__( 'No logs available.', 'avif-local-support' ) . '</p>';
			return;
		}

		foreach ( $logs as $log ) {
			$timestamp = isset( $log['timestamp'] ) ? (int) $log['timestamp'] : 0;
			$status    = isset( $log['status'] ) ? (string) $log['status'] : 'info';
			$message   = isset( $log['message'] ) ? (string) $log['message'] : '';
			$details   = isset( $log['details'] ) ? (array) $log['details'] : array();

			$timeDisplay = $timestamp > 0 ? wp_date( 'Y-m-d H:i:s', $timestamp ) : '-';

			$sourceSize = isset( $details['source_size'] ) ? (int) $details['source_size'] : 0;
			$targetSize = isset( $details['target_size'] ) ? (int) $details['target_size'] : 0;
			$sourceFile = isset( $details['source_file'] ) ? (string) $details['source_file'] : '';
			$targetFile = isset( $details['target_file'] ) ? (string) $details['target_file'] : '';
			$engineUsed = isset( $details['engine_used'] ) ? (string) $details['engine_used'] : '';
			$sizeDeltaPct = null;
			if ( 'success' === $status && $sourceSize > 0 && $targetSize >= 0 ) {
				$sizeDeltaPct = round( ( ( $targetSize - $sourceSize ) / $sourceSize ) * 100, 1 );
			}

			$searchText = strtolower( trim( implode( ' ', array_filter( array( $message, $sourceFile, $targetFile, $engineUsed, (string) ( $details['error'] ?? '' ) ) ) ) ) );

			$sourceUrl = ( isset( $details['source_url'] ) && is_string( $details['source_url'] ) ) ? $details['source_url'] : '';
			$targetUrl = ( isset( $details['target_url'] ) && is_string( $details['target_url'] ) ) ? $details['target_url'] : '';

			$hasAvifContext = isset( $details['quality'] )
				|| isset( $details['speed'] )
				|| isset( $details['engine_used'] )
				|| isset( $details['source_file'] )
				|| isset( $details['target_file'] );
			$qualityUsed = isset( $details['quality'] ) ? (int) $details['quality'] : (int) get_option( 'aviflosu_quality', 85 );
			$speedUsed   = isset( $details['speed'] ) ? (int) $details['speed'] : (int) get_option( 'aviflosu_speed', 1 );

			echo '<div class="avif-log-entry ' . esc_attr( $status ) . '" data-status="' . esc_attr( $status ) . '" data-filename="' . esc_attr( strtolower( $sourceFile ) ) . '" data-search="' . esc_attr( $searchText ) . '">';
			echo '  <div class="avif-log-header">';
			$statusLabel = strtoupper( $status );
			echo '    <span class="avif-log-status ' . esc_attr( $status ) . '">' . esc_html( $statusLabel ) . '</span>';
			if ( '' !== $sourceFile ) {
				echo '    <span class="avif-log-file">' . esc_html( $sourceFile ) . '</span>';
			}
			$metaBits = array();
			$metaClass = 'avif-log-meta';
			if ( null !== $sizeDeltaPct ) {
				$deltaPrefix = $sizeDeltaPct > 0 ? '+' : '';
				$metaBits[] = $deltaPrefix . number_format( $sizeDeltaPct, 1, '.', '' ) . '%';
				if ( $sizeDeltaPct > 0 ) {
					$metaClass .= ' is-larger';
				} elseif ( $sizeDeltaPct < 0 ) {
					$metaClass .= ' is-smaller';
				}
			}
			if ( $hasAvifContext ) {
				$metaBits[] = 'q=' . $qualityUsed;
				$metaBits[] = 's=' . $speedUsed;
			}
			if ( ! empty( $metaBits ) ) {
				echo '    <span class="' . esc_attr( $metaClass ) . '">' . esc_html( implode( ' ', $metaBits ) ) . '</span>';
			}
			echo '    <span class="avif-log-time">' . esc_html( $timeDisplay ) . '</span>';
			echo '  </div>';
			echo '  <div class="avif-log-message">' . esc_html( $message ) . '</div>';

			if ( ! empty( $details ) ) {
				unset( $details['source_url'], $details['target_url'] );

				// Highlight suggestion if present
				if ( isset( $details['error_suggestion'] ) ) {
					echo '<div class="avif-log-suggestion">';
					echo '💡 ' . esc_html( (string) $details['error_suggestion'] );
					echo '</div>';
					unset( $details['error_suggestion'] );
				}

				echo '<div class="avif-log-details">';
				foreach ( $details as $key => $value ) {
					if ( is_scalar( $value ) ) {
						$displayValue = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
						echo '<div><strong>' . esc_html( $key ) . ':</strong> ';
						if ( 'source_file' === $key && '' !== $sourceUrl ) {
							echo '<a href="' . esc_url( $sourceUrl ) . '" target="_blank" rel="noopener">' . esc_html( $displayValue ) . '</a>';
						} elseif ( 'target_file' === $key && '' !== $targetUrl ) {
							echo '<a href="' . esc_url( $targetUrl ) . '" target="_blank" rel="noopener">' . esc_html( $displayValue ) . '</a>';
						} else {
							echo esc_html( $displayValue );
						}
						echo '</div>';
					}
				}
				echo '</div>';
			}

			echo '</div>';
		}
	}
}
