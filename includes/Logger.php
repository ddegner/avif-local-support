<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined('ABSPATH') || exit;

/**
 * Handles logging for AVIF Local Support plugin.
 * Stores logs in WordPress transients with automatic expiration.
 */
final class Logger
{
    private const TRANSIENT_KEY = 'aviflosu_logs';
    private const MAX_ENTRIES = 50;

    /**
     * Get all logs from storage.
     *
     * @return array<int, array{timestamp: int, status: string, message: string, details: array}>
     */
    public function getLogs(): array
    {
        $logs = get_transient(self::TRANSIENT_KEY);
        return is_array($logs) ? $logs : [];
    }

    /**
     * Add a log entry.
     *
     * @param string $status Log status (success, error, warning, info).
     * @param string $message Log message.
     * @param array $details Additional details.
     */
    public function addLog(string $status, string $message, array $details = []): void
    {
        $logs = $this->getLogs();

        $logEntry = [
            'timestamp' => time(),
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];

        // Prepend to show newest first
        array_unshift($logs, $logEntry);

        // Keep only last N entries to prevent unlimited growth
        $logs = array_slice($logs, 0, self::MAX_ENTRIES);

        // Store for 24 hours (temporary logs)
        set_transient(self::TRANSIENT_KEY, $logs, DAY_IN_SECONDS);
    }

    /**
     * Clear all logs.
     */
    public function clearLogs(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Render logs content as HTML for the admin interface.
     */
    public function renderLogsContent(): void
    {
        // Ensure we're in a safe context (admin page) before rendering
        if (!is_admin()) {
            return;
        }
        
        $logs = $this->getLogs();

        if (empty($logs)) {
            echo '<p class="description">' . esc_html__('No logs available.', 'avif-local-support') . '</p>';
            return;
        }

        echo '<div class="avif-logs-container">';

        foreach ($logs as $log) {
            $timestamp = isset($log['timestamp']) ? (int) $log['timestamp'] : 0;
            $status = isset($log['status']) ? (string) $log['status'] : 'info';
            $message = isset($log['message']) ? (string) $log['message'] : '';
            $details = isset($log['details']) ? (array) $log['details'] : [];

            $timeDisplay = $timestamp > 0 ? wp_date('Y-m-d H:i:s', $timestamp) : '-';
            $status = strtolower($status);

            if (!in_array($status, ['error', 'warning', 'success', 'info'], true)) {
                $status = 'info';
            }

            echo '<div class="avif-log-entry ' . esc_attr($status) . '" data-status="' . esc_attr($status) . '">';
            echo '  <div class="avif-log-header">';
            echo '    <span class="avif-log-status ' . esc_attr($status) . '">' . esc_html(strtoupper($status)) . '</span>';
            echo '    - ' . esc_html($timeDisplay);
            echo '  </div>';
            echo '  <div class="avif-log-message">' . esc_html($message) . '</div>';

            if (!empty($details)) {
                // Highlight suggestion if present
                if (isset($details['error_suggestion'])) {
                    echo '<div class="avif-log-suggestion">';
                    echo '💡 ' . esc_html((string) $details['error_suggestion']);
                    echo '</div>';
                    unset($details['error_suggestion']);
                }

                echo '<div class="avif-log-details">';
                foreach ($details as $key => $value) {
                    if (is_scalar($value)) {
                        echo '<div><strong>' . esc_html($key) . ':</strong> ' . esc_html((string) $value) . '</div>';
                    }
                }
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
    }
}




