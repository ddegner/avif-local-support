<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for AVIF Local Support plugin.
 *
 * @package Ddegner\AvifLocalSupport
 */
class CLI
{
    private Diagnostics $diagnostics;
    private Converter $converter;
    private Logger $logger;

    public function __construct()
    {
        $this->diagnostics = new Diagnostics();
        $this->converter = new Converter();
        $this->logger = new Logger();
    }

    /**
     * Show system status and AVIF support diagnostics.
     *
     * ## EXAMPLES
     *
     *     wp avif status
     *     wp avif status --format=json
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json). Default: table
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, string> $assoc_args Associative arguments.
     */
    public function status(array $args, array $assoc_args): void
    {
        $status = $this->diagnostics->getSystemStatus();
        $format = $assoc_args['format'] ?? 'table';

        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT));
            return;
        }

        // Display as formatted table
        \WP_CLI::line('');
        \WP_CLI::line(\WP_CLI::colorize('%G=== AVIF Local Support - System Status ===%n'));
        \WP_CLI::line('');

        // PHP & WordPress
        \WP_CLI::line(\WP_CLI::colorize('%B-- Environment --%n'));
        \WP_CLI::line(sprintf('PHP Version:       %s', $status['php_version']));
        \WP_CLI::line(sprintf('WordPress Version: %s', $status['wordpress_version']));
        \WP_CLI::line(sprintf('PHP SAPI:          %s', $status['php_sapi']));
        \WP_CLI::line(sprintf('Current User:      %s', $status['current_user']));
        \WP_CLI::line('');

        // Engine support
        \WP_CLI::line(\WP_CLI::colorize('%B-- Engine Support --%n'));
        $this->printSupport('GD Extension', $status['gd_available']);
        $this->printSupport('GD AVIF Support', $status['gd_avif_support']);
        $this->printSupport('Imagick Extension', $status['imagick_available']);
        $this->printSupport('Imagick AVIF Support', $status['imagick_avif_support']);
        $this->printSupport('CLI proc_open()', $status['cli_proc_open']);
        $this->printSupport('CLI AVIF Binary', $status['cli_has_avif_binary']);
        \WP_CLI::line('');

        // Current configuration
        \WP_CLI::line(\WP_CLI::colorize('%B-- Configuration --%n'));
        \WP_CLI::line(sprintf('Engine Mode:       %s', $status['engine_mode']));
        \WP_CLI::line(sprintf('First Attempt:     %s', $status['auto_first_attempt']));
        $this->printSupport('Has Fallback', $status['auto_has_fallback']);
        \WP_CLI::line('');

        // Overall support
        $level = $status['avif_support_level'];
        $color = match ($level) {
            'full' => '%G',
            'partial' => '%Y',
            default => '%R',
        };
        \WP_CLI::line(\WP_CLI::colorize('%B-- Overall --%n'));
        \WP_CLI::line(\WP_CLI::colorize(sprintf('AVIF Support:      %s%s%%n', $color, strtoupper($level))));
        \WP_CLI::line('');
    }

    /**
     * Convert JPEG images to AVIF format.
     *
     * ## OPTIONS
     *
     * [<attachment-id>]
     * : Specific attachment ID to convert
     *
     * [--all]
     * : Convert all attachments missing AVIF versions
     *
     * [--dry-run]
     * : Show what would be converted without actually converting
     *
     * ## EXAMPLES
     *
     *     wp avif convert --all
     *     wp avif convert 123
     *     wp avif convert --all --dry-run
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, string> $assoc_args Associative arguments.
     */
    public function convert(array $args, array $assoc_args): void
    {
        $all = isset($assoc_args['all']);
        $dryRun = isset($assoc_args['dry-run']);
        $attachmentId = !empty($args[0]) ? (int) $args[0] : 0;

        if (!$all && $attachmentId === 0) {
            \WP_CLI::error('Please specify an attachment ID or use --all flag.');
            return;
        }

        if ($attachmentId > 0) {
            $this->convertSingle($attachmentId, $dryRun);
            return;
        }

        $this->convertAll($dryRun);
    }

    /**
     * Show AVIF conversion statistics.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv). Default: table
     *
     * ## EXAMPLES
     *
     *     wp avif stats
     *     wp avif stats --format=json
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, string> $assoc_args Associative arguments.
     */
    public function stats(array $args, array $assoc_args): void
    {
        $format = $assoc_args['format'] ?? 'table';
        $counts = $this->diagnostics->computeMissingCounts();

        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($counts, JSON_PRETTY_PRINT));
            return;
        }

        if ($format === 'csv') {
            \WP_CLI::line('total_jpegs,existing_avifs,missing_avifs');
            \WP_CLI::line(sprintf('%d,%d,%d', $counts['total_jpegs'], $counts['existing_avifs'], $counts['missing_avifs']));
            return;
        }

        // Table format
        $percentage = $counts['total_jpegs'] > 0
            ? round(($counts['existing_avifs'] / $counts['total_jpegs']) * 100, 1)
            : 0;

        \WP_CLI::line('');
        \WP_CLI::line(\WP_CLI::colorize('%G=== AVIF Conversion Statistics ===%n'));
        \WP_CLI::line('');
        \WP_CLI::line(sprintf('Total JPEG files:  %d', $counts['total_jpegs']));
        \WP_CLI::line(sprintf('Existing AVIFs:    %d (%s%%)', $counts['existing_avifs'], $percentage));
        \WP_CLI::line(sprintf('Missing AVIFs:     %d', $counts['missing_avifs']));
        \WP_CLI::line('');

        if ($counts['missing_avifs'] > 0) {
            \WP_CLI::line(\WP_CLI::colorize('%YRun `wp avif convert --all` to convert missing files.%n'));
            \WP_CLI::line('');
        }
    }

    /**
     * View or clear conversion logs.
     *
     * ## OPTIONS
     *
     * [--clear]
     * : Clear all logs
     *
     * [--limit=<number>]
     * : Number of logs to show. Default: 20
     *
     * [--format=<format>]
     * : Output format (table, json). Default: table
     *
     * ## EXAMPLES
     *
     *     wp avif logs
     *     wp avif logs --limit=50
     *     wp avif logs --clear
     *     wp avif logs --format=json
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, string> $assoc_args Associative arguments.
     */
    public function logs(array $args, array $assoc_args): void
    {
        if (isset($assoc_args['clear'])) {
            $this->logger->clearLogs();
            \WP_CLI::success('All logs cleared.');
            return;
        }

        $logs = $this->logger->getLogs();
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 20;
        $format = $assoc_args['format'] ?? 'table';
        $logs = array_slice($logs, 0, $limit);

        if (empty($logs)) {
            \WP_CLI::line('No logs available.');
            return;
        }

        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($logs, JSON_PRETTY_PRINT));
            return;
        }

        // Table format
        $tableData = [];
        foreach ($logs as $log) {
            $timestamp = isset($log['timestamp']) ? (int) $log['timestamp'] : 0;
            $tableData[] = [
                'Time' => $timestamp > 0 ? wp_date('Y-m-d H:i:s', $timestamp) : '-',
                'Status' => strtoupper($log['status'] ?? 'info'),
                'Message' => $log['message'] ?? '',
            ];
        }

        \WP_CLI\Utils\format_items('table', $tableData, ['Time', 'Status', 'Message']);
    }

    /**
     * Delete AVIF files for an attachment or all attachments.
     *
     * ## OPTIONS
     *
     * [<attachment-id>]
     * : Attachment ID to delete AVIF files for
     *
     * [--all]
     * : Delete all AVIF files in the media library
     *
     * [--yes]
     * : Skip confirmation prompt when using --all
     *
     * ## EXAMPLES
     *
     *     wp avif delete 123
     *     wp avif delete --all
     *     wp avif delete --all --yes
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, string> $assoc_args Associative arguments.
     */
    public function delete(array $args, array $assoc_args): void
    {
        $all = isset($assoc_args['all']);
        $attachmentId = !empty($args[0]) ? (int) $args[0] : 0;

        if (!$all && $attachmentId === 0) {
            \WP_CLI::error('Please specify an attachment ID or use --all flag.');
            return;
        }

        if ($attachmentId > 0) {
            $this->deleteSingle($attachmentId);
            return;
        }

        $this->deleteAll($assoc_args);
    }

    // =========================================================================
    // Private helper methods
    // =========================================================================

    /**
     * Print a support status line with color.
     */
    private function printSupport(string $label, bool $supported): void
    {
        $status = $supported
            ? \WP_CLI::colorize('%GYes%n')
            : \WP_CLI::colorize('%RNo%n');
        \WP_CLI::line(sprintf('%-20s %s', $label . ':', $status));
    }

    /**
     * Convert a single attachment.
     */
    private function convertSingle(int $attachmentId, bool $dryRun): void
    {
        $post = get_post($attachmentId);
        if (!$post || $post->post_type !== 'attachment') {
            \WP_CLI::error("Attachment ID {$attachmentId} not found.");
            return;
        }

        $mimeType = get_post_mime_type($attachmentId);
        if (!$mimeType || !preg_match('/^image\/jpe?g$/i', $mimeType)) {
            \WP_CLI::error("Attachment ID {$attachmentId} is not a JPEG image.");
            return;
        }

        if ($dryRun) {
            \WP_CLI::line("Would convert attachment ID {$attachmentId}");
            return;
        }

        \WP_CLI::line("Converting attachment ID {$attachmentId}...");
        $results = $this->converter->convertAttachmentNow($attachmentId);

        $converted = 0;
        $skipped = 0;
        foreach ($results['sizes'] as $size) {
            if ($size['converted'] && !$size['existed_before']) {
                $converted++;
                \WP_CLI::line(sprintf('  ✓ %s: %s', $size['name'], basename($size['avif_path'])));
            } else {
                $skipped++;
            }
        }

        \WP_CLI::success("Converted {$converted} files, skipped {$skipped} (already exist).");
    }

    /**
     * Convert all attachments missing AVIF versions.
     */
    private function convertAll(bool $dryRun): void
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results' => false,
        ]);

        $attachmentIds = $query->posts;
        $total = count($attachmentIds);

        if ($total === 0) {
            \WP_CLI::line('No JPEG attachments found.');
            return;
        }

        if ($dryRun) {
            \WP_CLI::line("Would convert {$total} attachments.");
            return;
        }

        \WP_CLI::line("Converting {$total} attachments...");
        $progress = \WP_CLI\Utils\make_progress_bar('Converting', $total);

        $totalConverted = 0;
        $totalSkipped = 0;

        foreach ($attachmentIds as $attachmentId) {
            $results = $this->converter->convertAttachmentNow((int) $attachmentId);

            foreach ($results['sizes'] as $size) {
                if ($size['converted'] && !$size['existed_before']) {
                    $totalConverted++;
                } else {
                    $totalSkipped++;
                }
            }

            $progress->tick();
        }

        $progress->finish();
        \WP_CLI::success("Converted {$totalConverted} files, skipped {$totalSkipped} (already exist).");
    }

    /**
     * Delete AVIF files for a single attachment.
     */
    private function deleteSingle(int $attachmentId): void
    {
        $post = get_post($attachmentId);
        if (!$post || $post->post_type !== 'attachment') {
            \WP_CLI::error("Attachment ID {$attachmentId} not found.");
            return;
        }

        $this->converter->deleteAvifsForAttachment($attachmentId);
        \WP_CLI::success("Deleted AVIF files for attachment ID {$attachmentId}.");
    }

    /**
     * Delete all AVIF files in the media library.
     *
     * @param array<string, string> $assoc_args Associative arguments.
     */
    private function deleteAll(array $assoc_args): void
    {
        $counts = $this->diagnostics->computeMissingCounts();

        if ($counts['existing_avifs'] === 0) {
            \WP_CLI::line('No AVIF files to delete.');
            return;
        }

        if (!isset($assoc_args['yes'])) {
            \WP_CLI::confirm(
                sprintf('This will delete %d AVIF files. Continue?', $counts['existing_avifs']),
                $assoc_args
            );
        }

        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results' => false,
        ]);

        $attachmentIds = $query->posts;
        $total = count($attachmentIds);

        if ($total === 0) {
            \WP_CLI::line('No JPEG attachments found.');
            return;
        }

        \WP_CLI::line("Deleting AVIF files for {$total} attachments...");
        $progress = \WP_CLI\Utils\make_progress_bar('Deleting', $total);

        foreach ($attachmentIds as $attachmentId) {
            $this->converter->deleteAvifsForAttachment((int) $attachmentId);
            $progress->tick();
        }

        $progress->finish();
        \WP_CLI::success("Deleted AVIF files for {$total} attachments.");
    }
}
