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
	private const GENERATION_OPTION_KEY = 'aviflosu_logs_generation';
	private const MAX_ENTRIES   = 50;

	/**
	 * Get all logs from storage.
	 *
	 * @return array<int, array{timestamp: int, status: string, message: string, details: array, generation?: int}>
	 */
	public function getLogs(): array {
		$logs = get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $logs ) ) {
			return array();
		}

		$generation = $this->getGeneration();
		return array_values(
			array_filter(
				$logs,
				static function ( $log ) use ( $generation ): bool {
					if ( ! is_array( $log ) ) {
						return false;
					}

					$entryGeneration = isset( $log['generation'] ) ? (int) $log['generation'] : 0;
					return $entryGeneration === $generation;
				}
			)
		);
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
		$generation = $this->getGeneration();

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
			'generation' => $generation,
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
		update_option( self::GENERATION_OPTION_KEY, $this->getGeneration() + 1, false );
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Get the active log generation.
	 */
	private function getGeneration(): int {
		$generation = get_option( self::GENERATION_OPTION_KEY, 0 );
		return is_numeric( $generation ) ? max( 0, (int) $generation ) : 0;
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

			echo '<div class="avif-log-entry ' . esc_attr( $status ) . '" data-status="' . esc_attr( $status ) . '">';
			echo '  <div class="avif-log-header">';
			echo '    <span class="avif-log-status ' . esc_attr( $status ) . '">' . esc_html( strtoupper( $status ) ) . '</span>';
			echo '    - ' . esc_html( $timeDisplay );
			echo '  </div>';
			echo '  <div class="avif-log-message">' . esc_html( $message ) . '</div>';

			if ( ! empty( $details ) ) {
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
						echo '<div><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( $displayValue ) . '</div>';
					}
				}
				echo '</div>';
			}

			echo '</div>';
		}
	}
}
