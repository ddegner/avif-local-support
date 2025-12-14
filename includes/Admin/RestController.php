<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Admin;

use Ddegner\AvifLocalSupport\Converter;
use Ddegner\AvifLocalSupport\Diagnostics;
use Ddegner\AvifLocalSupport\Formatter;
use Ddegner\AvifLocalSupport\ImageMagickCli;
use Ddegner\AvifLocalSupport\Logger;

defined('ABSPATH') || exit;

/**
 * Handles REST API routes for AVIF Local Support plugin.
 */
final class RestController
{
    private const NAMESPACE = 'aviflosu/v1';

    private Converter $converter;
    private Logger $logger;
    private Diagnostics $diagnostics;

    public function __construct(Converter $converter, Logger $logger, Diagnostics $diagnostics)
    {
        $this->converter = $converter;
        $this->logger = $logger;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Register all REST routes.
     */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/scan-missing', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'scanMissing'],
        ]);

        register_rest_route(self::NAMESPACE, '/convert-now', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'convertNow'],
        ]);

        register_rest_route(self::NAMESPACE, '/delete-all-avifs', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'deleteAllAvifs'],
        ]);

        register_rest_route(self::NAMESPACE, '/logs', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'getLogs'],
        ]);

        register_rest_route(self::NAMESPACE, '/logs/clear', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'clearLogs'],
        ]);

        register_rest_route(self::NAMESPACE, '/magick-test', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'runMagickTest'],
        ]);

        register_rest_route(self::NAMESPACE, '/upload-test', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'permissionManageOptions'],
            'callback' => [$this, 'uploadTest'],
        ]);
    }

    public function permissionManageOptions(): bool
    {
        return current_user_can('manage_options');
    }

    public function scanMissing(\WP_REST_Request $request): \WP_REST_Response
    {
        return rest_ensure_response($this->diagnostics->computeMissingCounts());
    }

    public function convertNow(\WP_REST_Request $request): \WP_REST_Response
    {
        $queued = false;
        if (!\wp_next_scheduled('aviflosu_run_on_demand')) {
            \wp_schedule_single_event(time() + 5, 'aviflosu_run_on_demand');
            $queued = true;
        }
        return rest_ensure_response(['queued' => $queued]);
    }

    public function deleteAllAvifs(\WP_REST_Request $request): \WP_REST_Response
    {
        $uploads = \wp_upload_dir();
        $baseDir = (string) ($uploads['basedir'] ?? '');

        if ($baseDir === '' || !is_dir($baseDir)) {
            return new \WP_REST_Response(['message' => 'uploads_not_found'], 400);
        }

        $deleted = 0;
        $failed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }
            $path = $fileInfo->getPathname();
            if (\preg_match('/\.avif$/i', $path)) {
                if ($fileInfo->isLink()) {
                    continue;
                }
                $ok = \wp_delete_file($path);
                if ($ok) {
                    $deleted++;
                } else {
                    $failed++;
                }
            }
        }

        return rest_ensure_response(['deleted' => $deleted, 'failed' => $failed]);
    }

    public function getLogs(\WP_REST_Request $request): \WP_REST_Response
    {
        ob_start();
        $this->logger->renderLogsContent();
        $content = ob_get_clean();
        return rest_ensure_response(['content' => $content]);
    }

    public function clearLogs(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->logger->clearLogs();
        return rest_ensure_response(['message' => 'Logs cleared']);
    }

    public function runMagickTest(\WP_REST_Request $request): \WP_REST_Response
    {
        $path = (string) get_option('aviflosu_cli_path', '');
        $detected = $this->diagnostics->detectCliBinaries();

        if ($path === '' && !empty($detected)) {
            $path = (string) ($detected[0]['path'] ?? '');
        }

        $autoSelected = false;
        if ($path === '') {
            $auto = ImageMagickCli::getAutoDetectedPath(null);
            if ($auto !== '') {
                $path = $auto;
                $autoSelected = true;
            }
        }

        $disableFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $execAvailable = !in_array('exec', $disableFunctions, true);

        if (!$execAvailable) {
            return new \WP_REST_Response(['message' => 'exec disabled by PHP disable_functions.'], 400);
        }

        if ($path === '' || !@file_exists($path)) {
            return new \WP_REST_Response(['message' => 'No ImageMagick CLI path found. Set a custom path under Engine Selection.'], 400);
        }

        $strategy = ImageMagickCli::getDefineStrategy($path, null);

        $cmd = escapeshellarg($path) . ' -version 2>&1';
        $outLines = [];
        $exitCode = 0;
        @exec($cmd, $outLines, $exitCode);
        $output = trim(implode("\n", array_map('strval', $outLines)));

        if ($output === '') {
            return rest_ensure_response([
                'code' => $exitCode,
                'output' => $output,
                'hint' => 'No output. If using ImageMagick 7, ensure the path points to `magick`.',
                'selected_path' => $path,
                'auto_selected' => $autoSelected,
                'define_strategy' => $strategy,
            ]);
        }

        return rest_ensure_response([
            'code' => $exitCode,
            'output' => $output,
            'selected_path' => $path,
            'auto_selected' => $autoSelected,
            'define_strategy' => $strategy,
        ]);
    }

    public function uploadTest(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $files = $request->get_file_params();
        $rawFile = isset($files['avif_local_support_test_file']) && is_array($files['avif_local_support_test_file'])
            ? $files['avif_local_support_test_file']
            : [];

        if (empty($rawFile) || empty($rawFile['tmp_name'])) {
            return new \WP_REST_Response(['message' => __('No file uploaded.', 'avif-local-support')], 400);
        }

        $fileType = wp_check_filetype_and_ext(
            (string) $rawFile['tmp_name'],
            (string) ($rawFile['name'] ?? ''),
            ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg']
        );

        if (empty($fileType['ext']) || !\in_array($fileType['ext'], ['jpg', 'jpeg'], true)) {
            return new \WP_REST_Response(['message' => __('Only JPEG files are allowed.', 'avif-local-support')], 400);
        }

        $attachment_id = media_handle_sideload($rawFile, 0);
        if (is_wp_error($attachment_id)) {
            return new \WP_REST_Response(['message' => $attachment_id->get_error_message()], 400);
        }

        $file = get_attached_file($attachment_id);
        if ($file) {
            $metadata = \wp_generate_attachment_metadata($attachment_id, $file);
            if ($metadata) {
                \wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        $results = $this->converter->convertAttachmentNow((int) $attachment_id);
        $editLink = get_edit_post_link($attachment_id);
        $title = get_the_title($attachment_id) ?: (string) $attachment_id;

        $html = $this->renderTestResultsTable($results, $editLink, $title);

        return rest_ensure_response(['html' => $html, 'attachment_id' => $attachment_id]);
    }

    /**
     * Render the test results table HTML.
     */
    private function renderTestResultsTable(array $results, ?string $editLink, string $title): string
    {
        ob_start();

        echo '<hr />';
        echo '<p><strong>' . esc_html__('Test results for attachment:', 'avif-local-support') . '</strong> ';
        echo sprintf('<a href="%s" target="_blank">%s</a>', esc_url($editLink ?: '#'), esc_html($title));
        echo '</p>';

        echo '<table class="widefat striped" style="max-width:960px">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Size', 'avif-local-support') . '</th>';
        echo '<th>' . esc_html__('Dimensions', 'avif-local-support') . '</th>';
        echo '<th>' . esc_html__('JPEG', 'avif-local-support') . '</th>';
        echo '<th>' . esc_html__('JPEG size', 'avif-local-support') . '</th>';
        echo '<th>' . esc_html__('AVIF', 'avif-local-support') . '</th>';
        echo '<th>' . esc_html__('AVIF size', 'avif-local-support') . '</th>';
        echo '<th>' . esc_html__('Status', 'avif-local-support') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach (($results['sizes'] ?? []) as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $dims = '';
            if (!empty($row['width']) && !empty($row['height'])) {
                $dims = (int) $row['width'] . '×' . (int) $row['height'];
            }
            $jpegUrl = isset($row['jpeg_url']) ? (string) $row['jpeg_url'] : '';
            $jpegSize = isset($row['jpeg_size']) ? (int) $row['jpeg_size'] : 0;
            $avifUrl = isset($row['avif_url']) ? (string) $row['avif_url'] : '';
            $avifSize = isset($row['avif_size']) ? (int) $row['avif_size'] : 0;
            $status = !empty($row['converted'])
                ? __('Converted', 'avif-local-support')
                : __('Not created', 'avif-local-support');

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html($dims) . '</td>';
            echo '<td>' . ($jpegUrl !== '' ? '<a href="' . esc_url($jpegUrl) . '" target="_blank" rel="noopener">' . esc_html__('View', 'avif-local-support') . '</a>' : '-') . '</td>';
            echo '<td>' . esc_html(Formatter::bytes($jpegSize)) . '</td>';
            echo '<td>' . (!empty($row['converted']) && $avifUrl !== '' ? '<a href="' . esc_url($avifUrl) . '" target="_blank" rel="noopener">' . esc_html__('View', 'avif-local-support') . '</a>' : '-') . '</td>';
            echo '<td>' . esc_html(Formatter::bytes($avifSize)) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        return (string) ob_get_clean();
    }
}

