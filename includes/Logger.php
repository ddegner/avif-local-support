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

			$summary  = '<span class="avif-log-status ' . esc_attr( $status ) . '">' . esc_html( strtoupper( $status ) ) . '</span> ';
			$summary .= '<span class="avif-log-time">' . esc_html( $timeDisplay ) . '</span> ';
			$summary .= '<span class="avif-log-message">' . esc_html( $message ) . '</span>';

			$consumed = array();
			$meta     = $this->buildLogMeta( $details, $consumed );
			if ( '' !== $meta ) {
				$summary .= ' <span class="avif-log-meta">' . esc_html( $meta ) . '</span>';
				// Values already shown in the summary line are not repeated in the expanded body.
				foreach ( $consumed as $consumedKey ) {
					unset( $details[ $consumedKey ] );
				}
			}

			if ( empty( $details ) ) {
				echo '<div class="avif-log-entry ' . esc_attr( $status ) . '" data-status="' . esc_attr( $status ) . '">';
				echo '<div class="avif-log-summary">' . $summary . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				echo '</div>';
				continue;
			}

			echo '<details class="avif-log-entry ' . esc_attr( $status ) . '" data-status="' . esc_attr( $status ) . '">';
			echo '<summary class="avif-log-summary">' . $summary . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			echo '<div class="avif-log-body">';

			// Highlight suggestion if present.
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

			echo '</div>';
			echo '</details>';
		}
	}

	/**
	 * Build the compact one-line meta string (engine · duration · size delta) for a log entry.
	 *
	 * @param array    $details  Log entry details (engine_used, duration_ms, source_size, target_size, ...).
	 * @param string[] $consumed Receives the detail keys that were rendered into the meta string.
	 */
	private function buildLogMeta( array $details, array &$consumed ): string {
		$parts    = array();
		$consumed = array();

		$engine = isset( $details['engine_used'] ) ? (string) $details['engine_used'] : '';
		if ( '' !== $engine && 'none' !== $engine ) {
			$parts[]    = $engine;
			$consumed[] = 'engine_used';
		}

		if ( isset( $details['duration_ms'] ) && is_numeric( $details['duration_ms'] ) ) {
			/* translators: %s: Duration in milliseconds. */
			$parts[]    = sprintf( __( '%s ms', 'avif-local-support' ), number_format_i18n( (float) $details['duration_ms'] ) );
			$consumed[] = 'duration_ms';
		}

		$source = isset( $details['source_size'] ) && is_numeric( $details['source_size'] ) ? (int) $details['source_size'] : 0;
		$target = isset( $details['target_size'] ) && is_numeric( $details['target_size'] ) ? (int) $details['target_size'] : 0;
		if ( $source > 0 && $target > 0 ) {
			$parts[]    = size_format( $source ) . ' → ' . size_format( $target );
			$consumed[] = 'source_size';
			$consumed[] = 'target_size';
		}

		return implode( ' · ', $parts );
	}
}
