<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

use Ddegner\AvifLocalSupport\Admin\RestController;
use Ddegner\AvifLocalSupport\Admin\Settings;

// Prevent direct access
\defined('ABSPATH') || exit;

final class Plugin
{
    private Support $support;
    private Converter $converter;
    private Logger $logger;
    private Diagnostics $diagnostics;
    private Settings $settings;
    private RestController $restController;

    public function __construct()
    {
        $this->support = new Support();
        $this->converter = new Converter();
        $this->logger = new Logger();
        $this->diagnostics = new Diagnostics();
        $this->settings = new Settings($this->diagnostics);
        $this->restController = new RestController($this->converter, $this->logger, $this->diagnostics);
        $this->converter->set_plugin($this);
    }

    public function init(): void
    {
        // Settings page + Settings API
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this->settings, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('rest_api_init', [$this->restController, 'register']);
        add_filter('plugin_action_links_' . plugin_basename(\AVIFLOSU_PLUGIN_FILE), [$this, 'add_settings_link']);
        add_action('admin_post_aviflosu_upload_test', [$this, 'handle_upload_test']);
        add_action('admin_post_aviflosu_reset_defaults', [$this, 'handle_reset_defaults']);

        // Features
        if ((bool) get_option('aviflosu_enable_support', true)) {
            $this->support->init();
        }
        // Always init converter so schedule/on-demand are available
        $this->converter->init();

        // Allow AVIF uploads
        add_filter('upload_mimes', function (array $mimes): array {
            $mimes['avif'] = 'image/avif';
            return $mimes;
        });
    }

    public function enqueue_admin_assets(string $hook): void
    {
        // Only on our settings page
        if ($hook !== 'settings_page_avif-local-support') {
            return;
        }
        $base = \AVIFLOSU_PLUGIN_URL;

        $cssFile = \AVIFLOSU_PLUGIN_DIR . 'assets/admin.css';
        $jsFile = \AVIFLOSU_PLUGIN_DIR . 'assets/admin.js';
        $cssVer = file_exists($cssFile) ? (string) filemtime($cssFile) : \AVIFLOSU_VERSION;
        $jsVer = file_exists($jsFile) ? (string) filemtime($jsFile) : \AVIFLOSU_VERSION;

        wp_enqueue_style('avif-local-support-admin', $base . 'assets/admin.css', [], $cssVer);
        wp_enqueue_script('avif-local-support-admin', $base . 'assets/admin.js', ['wp-api-fetch'], $jsVer, true);

        $data = [
            'restUrl' => esc_url_raw(rest_url()),
            'restNonce' => wp_create_nonce('wp_rest'),
        ];
        wp_add_inline_script(
            'avif-local-support-admin',
            'window.AVIFLocalSupportData = ' . wp_json_encode($data) . ';',
            'before'
        );
    }

    public function add_admin_menu(): void
    {
        add_options_page(
            __('AVIF Local Support', 'avif-local-support'),
            __('AVIF Local Support', 'avif-local-support'),
            'manage_options',
            'avif-local-support',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'avif-local-support'));
        }
        $system_status = $this->get_system_status();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AVIF Local Support', 'avif-local-support') . '</h1>';

        $supportLevel = (string) ($system_status['avif_support_level'] ?? (empty($system_status['avif_support']) ? 'no' : 'yes'));
        if ($supportLevel === 'no') {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('AVIF support not available!', 'avif-local-support') . '</strong></p>';
            echo '<p>' . esc_html__('This plugin requires either GD with AVIF support (imageavif) or ImageMagick with AVIF format support.', 'avif-local-support') . '</p></div>';
        } elseif ($supportLevel === 'unknown') {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('AVIF support is unconfirmed.', 'avif-local-support') . '</strong></p>';
            echo '<p>' . esc_html__('The plugin can attempt conversion (usually via CLI), but AVIF capability could not be confirmed. Try the Tools → Upload Test and check Logs for details.', 'avif-local-support') . '</p></div>';
        }

        // Tabs
        echo '<h2 class="nav-tab-wrapper">';
        echo '  <a href="#settings" class="nav-tab nav-tab-active" id="avif-local-support-tab-link-settings">' . esc_html__('Settings', 'avif-local-support') . '</a>';
        echo '  <a href="#tools" class="nav-tab" id="avif-local-support-tab-link-tools">' . esc_html__('Tools', 'avif-local-support') . '</a>';
        echo '  <a href="#about" class="nav-tab" id="avif-local-support-tab-link-about">' . esc_html__('About', 'avif-local-support') . '</a>';
        echo '</h2>';

        // Tab: Settings
        echo '<div id="avif-local-support-tab-settings" class="avif-local-support-tab active">';
        echo '  <div class="metabox-holder">';
        echo '    <form action="options.php" method="post">';
        settings_fields('aviflosu_settings');
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Serve AVIF files', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <table class="form-table" role="presentation">';
        do_settings_fields('avif-local-support', 'aviflosu_main');
        echo '        </table>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Engine Selection', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <table class="form-table" role="presentation">';
        do_settings_fields('avif-local-support', 'aviflosu_engine');
        echo '        </table>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Conversion Settings', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <table class="form-table" role="presentation">';
        do_settings_fields('avif-local-support', 'aviflosu_conversion');
        echo '        </table>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        submit_button('', 'primary', 'submit', false);
        echo '    </div>';
        echo '    </form>';
        echo '    <div style="margin-top:10px;">';
        echo '      <form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" style="display:inline">';
        echo '        <input type="hidden" name="action" value="aviflosu_reset_defaults" />';
        wp_nonce_field('aviflosu_reset_defaults', '_wpnonce', false, true);
        echo '        <button type="submit" class="button">' . esc_html__('Restore defaults', 'avif-local-support') . '</button>';
        echo '      </form>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // Tab: Tools
        echo '<div id="avif-local-support-tab-tools" class="avif-local-support-tab">';
        echo '  <div class="metabox-holder">';
        $initial_stats = $this->compute_missing_counts();
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Library AVIF coverage', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p>' . esc_html__('Overview of JPEGs with and without AVIFs.', 'avif-local-support') . '</p>';
        echo '        <div id="avif-local-support-stats" style="display:flex;gap:24px;align-items:center;">';
        echo '          <div><strong>' . esc_html__('JPEG files', 'avif-local-support') . ':</strong> <span id="avif-local-support-total-jpegs">' . (int) ($initial_stats['total_jpegs'] ?? 0) . '</span></div>';
        echo '          <div><strong>' . esc_html__('AVIF files', 'avif-local-support') . ':</strong> <span id="avif-local-support-existing-avifs">' . (int) ($initial_stats['existing_avifs'] ?? 0) . '</span></div>';
        echo '          <div><strong>' . esc_html__('Missing AVIFs', 'avif-local-support') . ':</strong> <span id="avif-local-support-missing-avifs">' . (int) ($initial_stats['missing_avifs'] ?? 0) . '</span></div>';
        echo '          <div><button type="button" class="button" id="avif-local-support-rescan">' . esc_html__('Rescan', 'avif-local-support') . '</button></div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Convert missing AVIFs', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p class="description">' . esc_html__('Scan the Media Library and convert JPEGs that are missing AVIFs.', 'avif-local-support') . '</p>';
        echo '        <div style="display:flex;align-items:center;gap:8px;">';
        echo '          <button type="button" class="button button-primary" id="avif-local-support-convert-now">' . esc_html__('Convert now', 'avif-local-support') . '</button>';
        echo '          <span class="spinner" id="avif-local-support-convert-spinner" style="float:none;margin:0 6px;"></span>';
        echo '          <span id="avif-local-support-convert-status" class="description"></span>';
        echo '        </div>';
        echo '        <div id="avif-local-support-tools-progress" style="display:none;margin-top:10px;">';
        echo '          <div id="avif-local-support-tools-stats" style="display:flex;gap:16px;align-items:center;margin-bottom:6px;">';
        echo '            <div><strong>' . esc_html__('JPEG files', 'avif-local-support') . ':</strong> <span id="avif-local-support-tools-total">-</span></div>';
        echo '            <div><strong>' . esc_html__('AVIF files', 'avif-local-support') . ':</strong> <span id="avif-local-support-tools-avifs">-</span></div>';
        echo '            <div><strong>' . esc_html__('Missing AVIFs', 'avif-local-support') . ':</strong> <span id="avif-local-support-tools-missing">-</span></div>';
        echo '          </div>';
        echo '          <div class="avif-local-support-progress" id="avif-local-support-tools-progress-bar"><div class="avif-local-support-progress-fill" id="avif-local-support-tools-progress-fill" style="width:0%"></div></div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Test conversion', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p class="description">' . esc_html__('Upload a JPEG to preview resized images and the AVIFs generated by your current settings. The file is added to the Media Library.', 'avif-local-support') . '</p>';
        echo '        <form id="avif-local-support-test-form" action="' . esc_url(admin_url('admin-post.php')) . '" method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;align-items:flex-start;gap:8px">';
        echo '          <input type="hidden" name="action" value="aviflosu_upload_test" />';
        wp_nonce_field('aviflosu_upload_test', '_wpnonce');
        echo '          <input type="file" id="avif-local-support-test-file" name="avif_local_support_test_file" accept="image/jpeg" required />';
        echo '          <div style="display:flex;align-items:center;gap:8px;">';
        echo '            <button type="submit" class="button button-primary" id="avif-local-support-test-submit">' . esc_html__('Convert now', 'avif-local-support') . '</button>';
        echo '            <span class="spinner" id="avif-local-support-test-spinner" style="float:none;margin:0;"></span>';
        echo '            <span id="avif-local-support-test-status" class="description"></span>';
        echo '          </div>';
        echo '        </form>';
        echo '        <div id="avif-local-support-test-results"></div>';

        $testId = 0;
        $viewNonce = (string) (filter_input(INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
        $uploadIdRaw = (string) (filter_input(INPUT_GET, 'avif-local-support-upload-id', FILTER_SANITIZE_NUMBER_INT) ?? '');
        if ($viewNonce !== '' && wp_verify_nonce($viewNonce, 'aviflosu_view_results')) {
            $testId = absint($uploadIdRaw);
        }
        if ($testId > 0) {
            $attachment = get_post($testId);
            if ($attachment && $attachment->post_type === 'attachment') {
                $results = $this->converter->convertAttachmentNow($testId);
                $editLink = get_edit_post_link($testId);
                echo '<hr />';
                echo '<p><strong>' . esc_html__('Test results for attachment:', 'avif-local-support') . '</strong> ' . sprintf('<a href="%s">%s</a>', esc_url($editLink ?: '#'), esc_html(get_the_title($testId) ?: (string) $testId)) . '</p>';
                echo '<table class="widefat striped" style="max-width:960px">';
                echo '  <thead><tr>'
                    . '<th>' . esc_html__('Size', 'avif-local-support') . '</th>'
                    . '<th>' . esc_html__('Dimensions', 'avif-local-support') . '</th>'
                    . '<th>' . esc_html__('JPEG', 'avif-local-support') . '</th>'
                    . '<th>' . esc_html__('JPEG size', 'avif-local-support') . '</th>'
                    . '<th>' . esc_html__('AVIF', 'avif-local-support') . '</th>'
                    . '<th>' . esc_html__('AVIF size', 'avif-local-support') . '</th>'
                    . '<th>' . esc_html__('Status', 'avif-local-support') . '</th>'
                    . '</tr></thead>';
                echo '  <tbody>';
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
                    $status = !empty($row['converted']) ? __('Converted', 'avif-local-support') : __('Not created', 'avif-local-support');
                    echo '<tr>'
                        . '<td>' . esc_html($name) . '</td>'
                        . '<td>' . esc_html($dims) . '</td>'
                        . '<td>' . ($jpegUrl !== '' ? '<a href="' . esc_url($jpegUrl) . '" target="_blank" rel="noopener">' . esc_html__('View', 'avif-local-support') . '</a>' : '-') . '</td>'
                        . '<td>' . esc_html(Formatter::bytes($jpegSize)) . '</td>'
                        . '<td>' . (!empty($row['converted']) && $avifUrl !== '' ? '<a href="' . esc_url($avifUrl) . '" target="_blank" rel="noopener">' . esc_html__('View', 'avif-local-support') . '</a>' : '-') . '</td>'
                        . '<td>' . esc_html(Formatter::bytes($avifSize)) . '</td>'
                        . '<td>' . esc_html($status) . '</td>'
                        . '</tr>';
                }
                echo '  </tbody>';
                echo '</table>';
            }
        }
        echo '      </div>';
        echo '    </div>';

        // Delete all AVIFs
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Delete all AVIF files', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p class="description">' . esc_html__('Remove all .avif files from the uploads directory. Originals (JPEG/PNG) are not touched.', 'avif-local-support') . '</p>';
        echo '        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
        echo '          <button type="button" class="button" id="avif-local-support-delete-avifs">' . esc_html__('Delete AVIFs', 'avif-local-support') . '</button>';
        echo '          <span class="spinner" id="avif-local-support-delete-spinner" style="float:none;margin:0 6px;"></span>';
        echo '          <span id="avif-local-support-delete-status" class="description"></span>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // Logs section
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Logs', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p class="description">' . esc_html__('View recent conversion logs including errors, settings used, and performance metrics.', 'avif-local-support') . '</p>';
        echo '        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">';
        echo '          <button type="button" class="button" id="avif-local-support-refresh-logs">' . esc_html__('Refresh logs', 'avif-local-support') . '</button>';
        echo '          <button type="button" class="button" id="avif-local-support-copy-logs">' . esc_html__('Copy logs', 'avif-local-support') . '</button>';
        echo '          <button type="button" class="button" id="avif-local-support-clear-logs">' . esc_html__('Clear logs', 'avif-local-support') . '</button>';
        echo '          <label class="avif-logs-filter"><input type="checkbox" id="avif-local-support-logs-only-errors" /> ' . esc_html__('Only failed/errored', 'avif-local-support') . '</label>';
        echo '          <span class="spinner" id="avif-local-support-logs-spinner" style="float:none;margin:0 6px;"></span>';
        echo '          <span id="avif-local-support-copy-status" class="description" style="color:#00a32a;display:none;">' . esc_html__('Copied!', 'avif-local-support') . '</span>';
        echo '        </div>';
        echo '        <div id="avif-local-support-logs-container">';
        echo '          <div id="avif-local-support-logs-content">';
        $this->render_logs_content();
        echo '          </div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // Append server support beneath coverage
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('Server support', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';

        // Copy diagnostics button
        echo '        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;">';
        echo '          <button type="button" class="button" id="avif-local-support-copy-support">' . esc_html__('Copy diagnostics as text', 'avif-local-support') . '</button>';
        echo '          <span id="avif-local-support-copy-support-status" class="description" style="color:#00a32a;display:none;">' . esc_html__('Copied!', 'avif-local-support') . '</span>';
        echo '        </div>';

        // Guided diagnostics view (what the server can do, and what the plugin will do).
        $engineMode = (string) ($system_status['engine_mode'] ?? get_option('aviflosu_engine_mode', 'auto'));
        $badge = static function ($state, string $yes = 'Yes', string $no = 'No', string $unknown = 'Unknown'): string {
            // $state may be bool or 'yes'|'no'|'unknown'
            $s = \is_string($state) ? strtolower($state) : ($state ? 'yes' : 'no');
            if ($s === 'unknown') {
                return '<span class="avif-badge avif-badge-neutral">' . esc_html($unknown) . '</span>';
            }
            $ok = $s === 'yes';
            $cls = $ok ? 'avif-badge avif-badge-ok' : 'avif-badge avif-badge-bad';
            $txt = $ok ? $yes : $no;
            return '<span class="' . esc_attr($cls) . '">' . esc_html($txt) . '</span>';
        };

        $convertOnUpload = (bool) get_option('aviflosu_convert_on_upload', true);
        $scheduleEnabled = (bool) get_option('aviflosu_convert_via_schedule', true);
        $scheduleTime = (string) get_option('aviflosu_schedule_time', '01:00');
        $frontEndEnabled = (bool) get_option('aviflosu_enable_support', true);

        $autoFirstAttempt = (string) ($system_status['auto_first_attempt'] ?? 'none');
        $autoHasFallback = !empty($system_status['auto_has_fallback']);
        $avifSupportLevel = (string) ($system_status['avif_support_level'] ?? (!empty($system_status['avif_support']) ? 'yes' : 'no'));

        $modeExplain = $engineMode === 'auto'
            ? esc_html__('Auto: the plugin will try engines in order (CLI → Imagick → GD) until one succeeds.', 'avif-local-support')
            : esc_html__('Forced: the plugin will use only the selected engine (no fallback).', 'avif-local-support');

        echo '        <p class="description" style="max-width:960px;margin-top:0;">'
            . esc_html__('This panel explains what your server supports, what AVIF Local Support will do, and what to check when something is unexpected.', 'avif-local-support')
            . '</p>';

        echo '        <div class="avif-support-panel">';
        echo '          <h3 style="margin:8px 0 6px;">' . esc_html__('Summary (what will happen)', 'avif-local-support') . '</h3>';
        echo '          <table class="widefat striped" style="max-width:960px;margin-bottom:10px;">';
        echo '            <tbody>';
        echo '              <tr><td style="width:260px;"><strong>' . esc_html__('AVIF conversion available', 'avif-local-support') . '</strong></td><td>' . $badge($avifSupportLevel, 'Yes', 'No', 'Unconfirmed') . '</td></tr>';
        echo '              <tr><td><strong>' . esc_html__('Engine setting', 'avif-local-support') . '</strong></td><td><code>' . esc_html($engineMode) . '</code><div class="description" style="margin-top:4px;">' . $modeExplain . '</div></td></tr>';
        if ($engineMode === 'auto') {
            $firstLabel = $autoFirstAttempt === 'cli'
                ? esc_html__('CLI (ImageMagick command-line)', 'avif-local-support')
                : ($autoFirstAttempt === 'imagick'
                    ? esc_html__('Imagick (PHP extension)', 'avif-local-support')
                    : ($autoFirstAttempt === 'gd'
                        ? esc_html__('GD (imageavif)', 'avif-local-support')
                        : esc_html__('None', 'avif-local-support')));
            echo '          <tr><td><strong>' . esc_html__('Auto mode: first attempt', 'avif-local-support') . '</strong></td><td>' . esc_html($firstLabel) . ($autoHasFallback ? ' <span class="description">(' . esc_html__('fallbacks available', 'avif-local-support') . ')</span>' : '') . '</td></tr>';
        } else {
            echo '          <tr><td><strong>' . esc_html__('Fallback behavior', 'avif-local-support') . '</strong></td><td>' . esc_html__('No fallback in forced mode.', 'avif-local-support') . '</td></tr>';
        }
        echo '              <tr><td><strong>' . esc_html__('Convert on upload', 'avif-local-support') . '</strong></td><td>' . $badge($convertOnUpload, 'Enabled', 'Disabled') . '</td></tr>';
        echo '              <tr><td><strong>' . esc_html__('Daily scan for missing AVIFs', 'avif-local-support') . '</strong></td><td>' . $badge($scheduleEnabled, 'Enabled', 'Disabled') . ($scheduleEnabled ? ' <span class="description">(' . esc_html(sprintf(__('scheduled around %s', 'avif-local-support'), $scheduleTime)) . ')</span>' : '') . '</td></tr>';
        echo '              <tr><td><strong>' . esc_html__('Front-end AVIF serving', 'avif-local-support') . '</strong></td><td>' . $badge($frontEndEnabled, 'Enabled', 'Disabled') . '<div class="description" style="margin-top:4px;">' . esc_html__('When enabled, the plugin wraps JPEG outputs in a <picture> tag with an AVIF <source> first.', 'avif-local-support') . '</div></td></tr>';
        echo '            </tbody>';
        echo '          </table>';

        // Recent logs (quick pointer to surprises)
        $logs = $this->get_logs();
        if (!empty($logs) && is_array($logs)) {
            $latest = $logs[0] ?? null;
            if (is_array($latest)) {
                $ts = isset($latest['timestamp']) ? (int) $latest['timestamp'] : 0;
                $status = isset($latest['status']) ? (string) $latest['status'] : '';
                $msg = isset($latest['message']) ? (string) $latest['message'] : '';
                $when = $ts > 0 ? wp_date('Y-m-d H:i:s', $ts) : '';
                echo '      <div class="avif-support-callout">';
                echo '        <strong>' . esc_html__('Most recent conversion log', 'avif-local-support') . '</strong>';
                if ($when !== '') {
                    echo ' <span class="description">(' . esc_html($when) . ')</span>';
                }
                echo '<div style="margin-top:6px;">' . esc_html(($status !== '' ? strtoupper($status) . ': ' : '') . $msg) . '</div>';
                echo '        <div class="description" style="margin-top:6px;">' . esc_html__('For details and error suggestions, see the Logs panel above.', 'avif-local-support') . '</div>';
                echo '      </div>';
            }
        }

        echo '          <h3 style="margin:14px 0 6px;">' . esc_html__('Engine details (why it will / won’t work)', 'avif-local-support') . '</h3>';

        // CLI details
        $cliDetected = isset($system_status['cli_detected']) && is_array($system_status['cli_detected']) ? $system_status['cli_detected'] : [];
        $cliProcOpen = !empty($system_status['cli_proc_open']);
        $cliConfigured = (string) ($system_status['cli_configured_path'] ?? '');
        $cliAuto = (string) ($system_status['cli_auto_path'] ?? '');
        $cliCanInvoke = !empty($system_status['cli_can_invoke']);
        $cliHasAvifBin = !empty($system_status['cli_has_avif_binary']);
        $cliWillAttempt = !empty($system_status['cli_will_attempt']);
        $cliEffective = $cliConfigured !== '' ? $cliConfigured : $cliAuto;
        $cliExists = $cliEffective !== '' ? @file_exists($cliEffective) : false;
        $cliExec = $cliEffective !== '' ? @is_executable($cliEffective) : false;

        $df = (string) ($system_status['disable_functions'] ?? ini_get('disable_functions'));
        $dfList = array_filter(array_map('trim', explode(',', $df)));
        $execDisabled = in_array('exec', $dfList, true);

        $cliSummary = $cliWillAttempt ? esc_html__('Attempting', 'avif-local-support') : esc_html__('Skipped', 'avif-local-support');
        if ($engineMode === 'cli') {
            $cliSummary .= ' <span class="description">(' . esc_html__('forced', 'avif-local-support') . ')</span>';
        }
        echo '          <details open class="avif-support-details">';
        echo '            <summary><strong>' . esc_html__('CLI (ImageMagick command-line)', 'avif-local-support') . '</strong> — ' . $cliSummary . '</summary>';
        echo '            <div class="avif-support-details-body">';
        echo '              <p class="description" style="margin-top:0;max-width:960px;">' . esc_html__('Used for conversion via proc_open() (no shell). This is usually fastest and most reliable if ImageMagick is installed with AVIF support.', 'avif-local-support') . '</p>';
        echo '              <table class="widefat striped" style="max-width:960px;">';
        echo '                <tbody>';
        echo '                  <tr><td style="width:260px;"><strong>' . esc_html__('proc_open available', 'avif-local-support') . '</strong></td><td>' . $badge($cliProcOpen) . '</td></tr>';
        echo '                  <tr><td style="width:260px;"><strong>' . esc_html__('Usable in Auto mode', 'avif-local-support') . '</strong></td><td>' . $badge($cliCanInvoke, 'Yes', 'No') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('Configured CLI path', 'avif-local-support') . '</strong></td><td>' . ($cliConfigured !== '' ? '<code>' . esc_html($cliConfigured) . '</code>' : '-') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('Auto-detected CLI path', 'avif-local-support') . '</strong></td><td>' . ($cliAuto !== '' ? '<code>' . esc_html($cliAuto) . '</code>' : '-') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('Effective CLI path used (configured → auto)', 'avif-local-support') . '</strong></td><td>' . ($cliEffective !== '' ? '<code>' . esc_html($cliEffective) . '</code>' : '-') . '</td></tr>';
        if ($cliEffective !== '') {
            echo '              <tr><td><strong>' . esc_html__('Binary exists', 'avif-local-support') . '</strong></td><td>' . $badge((bool) $cliExists) . '</td></tr>';
            echo '              <tr><td><strong>' . esc_html__('Binary executable', 'avif-local-support') . '</strong></td><td>' . $badge((bool) $cliExec) . '</td></tr>';
        }
        echo '                  <tr><td><strong>' . esc_html__('AVIF-capable CLI detected', 'avif-local-support') . '</strong></td><td>' . $badge($cliHasAvifBin, 'Yes (probe)', 'Unknown / No') . '<div class="description" style="margin-top:4px;">' . esc_html__('This is a best-effort probe of known binaries. Even if this says “No”, a custom path might still work (check Logs).', 'avif-local-support') . '</div></td></tr>';
        echo '                </tbody>';
        echo '              </table>';

        if (!empty($cliDetected)) {
            echo '          <div style="margin-top:8px;">';
            echo '            <strong>' . esc_html__('Detected ImageMagick binaries', 'avif-local-support') . '</strong>';
            echo '            <ul style="margin:6px 0 0;padding-left:18px;">';
            foreach ($cliDetected as $bin) {
                $path = isset($bin['path']) ? (string) $bin['path'] : '';
                $ver = isset($bin['version']) ? (string) $bin['version'] : '';
                $avif = !empty($bin['avif']) ? esc_html__('AVIF: yes', 'avif-local-support') : esc_html__('AVIF: no', 'avif-local-support');
                echo '<li><code>' . esc_html($path) . '</code>' . ($ver !== '' ? ' — ' . esc_html($ver) : '') . ' — ' . esc_html($avif) . '</li>';
            }
            echo '            </ul>';
            echo '          </div>';
        }

        echo '              <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '                <button type="button" class="button" id="avif-local-support-run-magick-test">' . esc_html__('Run ImageMagick test', 'avif-local-support') . '</button>';
        echo '                <span class="spinner" id="avif-local-support-magick-test-spinner" style="float:none;margin:0 6px;"></span>';
        echo '                <span id="avif-local-support-magick-test-status" class="description"></span>';
        echo '              </div>';
        echo '              <pre id="avif-local-support-magick-test-output" style="display:none;max-width:960px;white-space:pre-wrap;background:#f6f7f7;padding:10px;border:1px solid #ccd0d4;margin-top:8px;"></pre>';

        if ($execDisabled) {
            echo '          <p class="description" style="margin-top:8px;"><strong>' . esc_html__('Note:', 'avif-local-support') . '</strong> ' . esc_html__('Your PHP has exec disabled. The “Run ImageMagick test” button uses exec, but conversions use proc_open—so conversion may still work even if the test fails.', 'avif-local-support') . '</p>';
        }
        echo '            </div>';
        echo '          </details>';

        // Imagick details
        $imagickWillAttempt = !empty($system_status['imagick_will_attempt']);
        echo '          <details class="avif-support-details">';
        $imSummary = $imagickWillAttempt ? esc_html__('Attempting', 'avif-local-support') : esc_html__('Skipped', 'avif-local-support');
        if ($engineMode === 'imagick') {
            $imSummary .= ' <span class="description">(' . esc_html__('forced', 'avif-local-support') . ')</span>';
        }
        echo '            <summary><strong>' . esc_html__('Imagick (PHP extension)', 'avif-local-support') . '</strong> — ' . $imSummary . '</summary>';
        echo '            <div class="avif-support-details-body">';
        echo '              <p class="description" style="margin-top:0;max-width:960px;">' . esc_html__('Used for conversion inside PHP. Great quality and better profile/metadata handling when ImageMagick has AVIF support.', 'avif-local-support') . '</p>';
        echo '              <table class="widefat striped" style="max-width:960px;">';
        echo '                <tbody>';
        echo '                  <tr><td style="width:260px;"><strong>' . esc_html__('Imagick extension loaded', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['imagick_available']), 'Yes', 'No') . '</td></tr>';
        if (!empty($system_status['imagick_version'])) {
            echo '              <tr><td><strong>' . esc_html__('ImageMagick library version', 'avif-local-support') . '</strong></td><td><code>' . esc_html((string) $system_status['imagick_version']) . '</code></td></tr>';
        }
        echo '                  <tr><td><strong>' . esc_html__('AVIF support (queryFormats)', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['imagick_avif_support']), 'Yes', 'No') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('Usable in Auto mode', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['imagick_avif_support']), 'Yes', 'No') . '</td></tr>';
        echo '                </tbody>';
        echo '              </table>';
        echo '            </div>';
        echo '          </details>';

        // GD details
        $gdWillAttempt = !empty($system_status['gd_will_attempt']);
        echo '          <details class="avif-support-details">';
        $gdSummary = $gdWillAttempt ? esc_html__('Attempting', 'avif-local-support') : esc_html__('Skipped', 'avif-local-support');
        if ($engineMode === 'gd') {
            $gdSummary .= ' <span class="description">(' . esc_html__('forced', 'avif-local-support') . ')</span>';
        }
        echo '            <summary><strong>' . esc_html__('GD (imageavif)', 'avif-local-support') . '</strong> — ' . $gdSummary . '</summary>';
        echo '            <div class="avif-support-details-body">';
        echo '              <p class="description" style="margin-top:0;max-width:960px;">' . esc_html__('Used as a fallback when imageavif() is available. Fast, but does not perform color management and may not preserve metadata.', 'avif-local-support') . '</p>';
        echo '              <table class="widefat striped" style="max-width:960px;">';
        echo '                <tbody>';
        echo '                  <tr><td style="width:260px;"><strong>' . esc_html__('GD extension loaded', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['gd_available']), 'Yes', 'No') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('imageavif() available', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['gd_imageavif']), 'Yes', 'No') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('gd_info(): AVIF Support', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['gd_info_avif']), 'Yes', 'No') . '</td></tr>';
        echo '                  <tr><td><strong>' . esc_html__('Usable in Auto mode', 'avif-local-support') . '</strong></td><td>' . $badge(!empty($system_status['gd_avif_support']), 'Yes', 'No') . '</td></tr>';
        echo '                </tbody>';
        echo '              </table>';
        echo '              <p class="description" style="margin-top:8px;"><strong>' . esc_html__('Color management note:', 'avif-local-support') . '</strong> ' . esc_html__('GD does not perform color management; non‑sRGB JPEGs (Adobe RGB, Display P3) may appear desaturated. For accurate color and metadata preservation, enable Imagick with AVIF support.', 'avif-local-support') . '</p>';
        echo '            </div>';
        echo '          </details>';

        // Environment
        echo '          <h3 style="margin:14px 0 6px;">' . esc_html__('Environment (useful when debugging permissions / restrictions)', 'avif-local-support') . '</h3>';
        $currentUser = (string) ($system_status['current_user'] ?? @get_current_user());
        $ob = (string) ($system_status['open_basedir'] ?? ini_get('open_basedir'));
        echo '          <table class="widefat striped" style="max-width:960px;">';
        echo '            <tbody>';
        echo '              <tr><td style="width:260px;"><strong>' . esc_html__('PHP Version', 'avif-local-support') . '</strong></td><td><code>' . esc_html(PHP_VERSION) . '</code></td></tr>';
        echo '              <tr><td><strong>' . esc_html__('WordPress Version', 'avif-local-support') . '</strong></td><td><code>' . esc_html(get_bloginfo('version')) . '</code></td></tr>';
        echo '              <tr><td><strong>' . esc_html__('PHP SAPI', 'avif-local-support') . '</strong></td><td><code>' . esc_html($system_status['php_sapi'] ?? PHP_SAPI) . '</code></td></tr>';
        echo '              <tr><td><strong>' . esc_html__('Current user', 'avif-local-support') . '</strong></td><td><code>' . esc_html($currentUser !== '' ? $currentUser : '-') . '</code><div class="description" style="margin-top:4px;">' . esc_html__('This is the OS user PHP runs as; it must have write access to wp-content/uploads.', 'avif-local-support') . '</div></td></tr>';
        echo '              <tr><td><strong>' . esc_html__('open_basedir', 'avif-local-support') . '</strong></td><td>' . ($ob !== '' ? '<code style="white-space:pre-wrap;word-break:break-word;display:inline-block;max-width:680px;overflow:auto;">' . esc_html($ob) . '</code>' : '-') . '</td></tr>';
        echo '              <tr><td><strong>' . esc_html__('disable_functions', 'avif-local-support') . '</strong></td><td>' . ($df !== '' ? '<code style="white-space:pre-wrap;word-break:break-word;display:inline-block;max-width:680px;overflow:auto;">' . esc_html($df) . '</code>' : '-') . '</td></tr>';
        echo '            </tbody>';
        echo '          </table>';

        echo '        </div>'; // .avif-support-panel
        echo '      </div>';
        echo '    </div>';

        echo '  </div>';
        echo '</div>';

        // Status content is combined into the Tools tab above

        // Tab: About
        echo '<div id="avif-local-support-tab-about" class="avif-local-support-tab">';
        echo '  <div class="metabox-holder">';
        echo '    <div class="postbox">';
        echo '      <h2 class="avif-header"><span>' . esc_html__('About', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        $readme_path = \AVIFLOSU_PLUGIN_DIR . 'readme.txt';
        if (file_exists($readme_path) && is_readable($readme_path)) {
            $readme_contents = @file_get_contents($readme_path);
            if ($readme_contents !== false) {
                echo '<pre class="avif-local-support-readme" style="max-width:960px;white-space:pre-wrap;">' . esc_html($readme_contents) . '</pre>';
            } else {
                echo '<p class="description">' . esc_html__('Unable to read readme.txt.', 'avif-local-support') . '</p>';
            }
        } else {
            echo '<p class="description">' . esc_html__('README not found.', 'avif-local-support') . '</p>';
        }
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // Scripts and styles now enqueued via admin_enqueue_scripts

        echo '</div>';
    }



    public function handle_upload_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'avif-local-support'));
        }
        check_admin_referer('aviflosu_upload_test');

        // Build a sanitized, validated view of the uploaded file entry
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading from $_FILES; individual fields are sanitized below
        $rawFile = isset($_FILES['avif_local_support_test_file']) && is_array($_FILES['avif_local_support_test_file']) ? $_FILES['avif_local_support_test_file'] : [];
        $fileArray = [
            'name' => isset($rawFile['name']) ? sanitize_file_name((string) $rawFile['name']) : '',
            'tmp_name' => isset($rawFile['tmp_name']) ? (string) $rawFile['tmp_name'] : '',
            'error' => isset($rawFile['error']) ? (int) $rawFile['error'] : UPLOAD_ERR_NO_FILE,
            'size' => isset($rawFile['size']) ? (int) $rawFile['size'] : 0,
        ];
        if (empty($rawFile) || !is_array($rawFile)) {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'nofile', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if ($fileArray['tmp_name'] === '' || $fileArray['name'] === '') {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'nofile', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }
        $tmpName = $fileArray['tmp_name'];
        $originalName = $fileArray['name'];

        $errorCode = $fileArray['error'];
        if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'upload', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }

        $fileType = wp_check_filetype_and_ext($tmpName, $originalName, ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg']);
        if (empty($fileType['ext']) || !\in_array($fileType['ext'], ['jpg', 'jpeg'], true)) {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'notjpeg', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }

        $attachment_id = media_handle_upload('avif_local_support_test_file', 0);
        if (is_wp_error($attachment_id)) {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'upload', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }

        $file = get_attached_file($attachment_id);
        if ($file) {
            $metadata = \wp_generate_attachment_metadata($attachment_id, $file);
            if ($metadata) {
                \wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        $this->converter->convertAttachmentNow((int) $attachment_id);

        $view_nonce = wp_create_nonce('aviflosu_view_results');
        \wp_safe_redirect(\add_query_arg([
            'avif-local-support-upload-id' => (string) $attachment_id,
            '_wpnonce' => $view_nonce,
        ], \admin_url('options-general.php?page=avif-local-support#tools')));
        exit;
    }

    public function handle_reset_defaults(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'avif-local-support'));
        }
        check_admin_referer('aviflosu_reset_defaults');
        // Reset options to defaults
        update_option('aviflosu_enable_support', true);
        update_option('aviflosu_convert_on_upload', true);
        update_option('aviflosu_convert_via_schedule', true);
        update_option('aviflosu_schedule_time', '01:00');
        update_option('aviflosu_quality', 85);
        update_option('aviflosu_speed', 1);
        update_option('aviflosu_subsampling', '420');
        update_option('aviflosu_bit_depth', '8');
        update_option('aviflosu_cache_duration', 3600);
        update_option('aviflosu_disable_memory_check', false);
        update_option('aviflosu_engine_mode', 'auto');
        update_option('aviflosu_cli_path', '');
        update_option('aviflosu_cli_args', $this->get_suggested_cli_args());
        update_option('aviflosu_cli_env', $this->get_suggested_cli_env());
        \wp_safe_redirect(\admin_url('options-general.php?page=avif-local-support#settings'));
        exit;
    }

    public function add_settings_link(array $links): array
    {
        $settings_link = sprintf('<a href="%s">%s</a>', esc_url(admin_url('options-general.php?page=avif-local-support')), __('Settings', 'avif-local-support'));
        array_unshift($links, $settings_link);
        return $links;
    }

    private function compute_missing_counts(): array
    {
        return $this->diagnostics->computeMissingCounts();
    }

    /**
     * Get suggested CLI environment variables based on the system.
     */
    private function get_suggested_cli_env(): string
    {
        return $this->diagnostics->getSuggestedCliEnv();
    }

    /**
     * Get suggested CLI arguments.
     */
    private function get_suggested_cli_args(): string
    {
        return $this->diagnostics->getSuggestedCliArgs();
    }

    /**
     * Detect server AVIF support
     */
    private function get_system_status(): array
    {
        return $this->diagnostics->getSystemStatus();
    }

    /**
     * Detect ImageMagick CLI binaries and AVIF support.
     * @return array<int, array{path:string,version:string,avif:bool}>
     */
    private function detect_cli_binaries(): array
    {
        return $this->diagnostics->detectCliBinaries();
    }

    /**
     * Render logs content for the admin interface
     */
    private function render_logs_content(): void
    {
        $this->logger->renderLogsContent();
    }

    /**
     * Get logs from storage
     */
    private function get_logs(): array
    {
        return $this->logger->getLogs();
    }

    /**
     * Add a log entry
     */
    public function add_log(string $status, string $message, array $details = []): void
    {
        $this->logger->addLog($status, $message, $details);
    }

    /**
     * Clear all logs
     */
    private function clear_logs(): void
    {
        $this->logger->clearLogs();
    }
}
