<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

// Prevent direct access
\defined('ABSPATH') || exit;

final class Plugin
{
    private Support $support;
    private Converter $converter;

    public function __construct()
    {
        $this->support = new Support();
        $this->converter = new Converter();
    }

    public function init(): void
    {
        // Settings page + Settings API
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(\AVIFLOSU_PLUGIN_FILE), [$this, 'add_settings_link']);
        add_action('admin_post_aviflosu_convert_now', [$this, 'handle_convert_now']);
        add_action('admin_post_aviflosu_upload_test', [$this, 'handle_upload_test']);
        add_action('wp_ajax_aviflosu_scan_missing', [$this, 'ajax_scan_missing']);
        add_action('wp_ajax_aviflosu_convert_now', [$this, 'ajax_convert_now']);

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
        $base = plugins_url('', \AVIFLOSU_PLUGIN_FILE);
        wp_enqueue_style('avif-local-support-admin', $base . '/assets/admin.css', [], \AVIFLOSU_VERSION);
        wp_enqueue_script('avif-local-support-admin', $base . '/assets/admin.js', [], \AVIFLOSU_VERSION, true);
        wp_localize_script('avif-local-support-admin', 'AVIFLocalSupportData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'scanNonce' => wp_create_nonce('aviflosu_scan_missing'),
            'convertNonce' => wp_create_nonce('aviflosu_convert_now'),
        ]);
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

    public function register_settings(): void
    {
        // Group: aviflosu_settings, Page: avif-local-support
        register_setting('aviflosu_settings', 'aviflosu_enable_support', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_convert_on_upload', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_convert_via_schedule', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_schedule_time', [
            'type' => 'string',
            'default' => '01:00',
            'sanitize_callback' => [$this, 'sanitize_schedule_time'],
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_quality', [
            'type' => 'integer',
            'default' => 85,
            'sanitize_callback' => 'absint',
            'show_in_rest' => true,
        ]);
        // New: speed setting (0-10)
        register_setting('aviflosu_settings', 'aviflosu_speed', [
            'type' => 'integer',
            'default' => 1,
            'sanitize_callback' => [$this, 'sanitize_speed'],
            'show_in_rest' => true,
        ]);
        // New: preserve metadata/profile toggles
        register_setting('aviflosu_settings', 'aviflosu_preserve_metadata', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_preserve_color_profile', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);
        // New: WordPress thumbnail intelligence
        register_setting('aviflosu_settings', 'aviflosu_wordpress_logic', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_cache_duration', [
            'type' => 'integer',
            'default' => 3600,
            'sanitize_callback' => 'absint',
            'show_in_rest' => true,
        ]);

        add_settings_section(
            'aviflosu_main',
            '',
            function (): void {},
            'avif-local-support'
        );

        add_settings_field(
            'avif_local_support_enable_support',
            __('Serve AVIF images', 'avif-local-support'),
            function (): void {
                $value = (bool) get_option('aviflosu_enable_support', true);
                echo '<label for="aviflosu_enable_support">'
                    . '<input id="aviflosu_enable_support" type="checkbox" name="aviflosu_enable_support" value="1" ' . checked(true, $value, false) . ' /> '
                    . esc_html__('Add AVIF sources to JPEG images on the front end', 'avif-local-support')
                    . '</label>';
            },
            'avif-local-support',
            'aviflosu_main',
            [ 'label_for' => 'aviflosu_enable_support' ]
        );

        add_settings_section(
            'aviflosu_conversion',
            '',
            function (): void {},
            'avif-local-support'
        );

        add_settings_field(
            'avif_local_support_convert_on_upload',
            __('Convert on upload', 'avif-local-support'),
            function (): void {
                $value = (bool) get_option('aviflosu_convert_on_upload', true);
                echo '<label for="aviflosu_convert_on_upload">'
                    . '<input id="aviflosu_convert_on_upload" type="checkbox" name="aviflosu_convert_on_upload" value="1" ' . checked(true, $value, false) . ' /> '
                    . esc_html__('Recommended; may slow uploads on some servers', 'avif-local-support')
                    . '</label>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_convert_on_upload' ]
        );

        add_settings_field(
            'avif_local_support_convert_via_schedule',
            __('Daily conversion', 'avif-local-support'),
            function (): void {
                $enabled = (bool) get_option('aviflosu_convert_via_schedule', true);
                $time = (string) get_option('aviflosu_schedule_time', '01:00');
                echo '<label for="aviflosu_convert_via_schedule">'
                    . '<input id="aviflosu_convert_via_schedule" type="checkbox" name="aviflosu_convert_via_schedule" value="1" ' . checked(true, $enabled, false) . ' /> '
                    . esc_html__('Scan daily and convert missing AVIFs', 'avif-local-support')
                    . '</label> ';
                echo '<input id="aviflosu_schedule_time" type="time" name="aviflosu_schedule_time" value="' . \esc_attr($time) . '" aria-label="' . esc_attr__('Time', 'avif-local-support') . '" />';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_convert_via_schedule' ]
        );

        add_settings_field(
            'avif_local_support_quality',
            __('Quality (0–100)', 'avif-local-support'),
            function (): void {
                $value = (int) get_option('aviflosu_quality', 85);
                echo '<input id="aviflosu_quality" type="range" name="aviflosu_quality" min="0" max="100" value="' . \esc_attr((string) $value) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
                echo '<span>' . \esc_html((string) $value) . '</span>';
                echo '<p class="description">' . esc_html__('Higher = better quality and larger files.', 'avif-local-support') . '</p>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_quality' ]
        );

        // New: Speed slider (0-10)
        add_settings_field(
            'avif_local_support_speed',
            __('Speed (0–10)', 'avif-local-support'),
            function (): void {
                $value = (int) get_option('aviflosu_speed', 1);
                $value = max(0, min(10, $value));
                echo '<input id="aviflosu_speed" type="range" name="aviflosu_speed" min="0" max="10" value="' . \esc_attr((string) $value) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
                echo '<span>' . \esc_html((string) $value) . '</span>';
                echo '<p class="description">' . esc_html__('Lower = smaller files (slower). Higher = faster (larger files).', 'avif-local-support') . '</p>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_speed' ]
        );

        // New: Preserve metadata
        add_settings_field(
            'avif_local_support_preserve_metadata',
            __('Keep metadata (EXIF/XMP/IPTC)', 'avif-local-support'),
            function (): void {
                $value = (bool) get_option('aviflosu_preserve_metadata', true);
                echo '<label for="aviflosu_preserve_metadata">'
                    . '<input id="aviflosu_preserve_metadata" type="checkbox" name="aviflosu_preserve_metadata" value="1" ' . checked(true, $value, false) . ' /> '
                    . esc_html__('When possible (ImageMagick required).', 'avif-local-support')
                    . '</label>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_preserve_metadata' ]
        );

        // New: Preserve color profile
        add_settings_field(
            'avif_local_support_preserve_color_profile',
            __('Keep color profile (ICC)', 'avif-local-support'),
            function (): void {
                $value = (bool) get_option('aviflosu_preserve_color_profile', true);
                echo '<label for="aviflosu_preserve_color_profile">'
                    . '<input id="aviflosu_preserve_color_profile" type="checkbox" name="aviflosu_preserve_color_profile" value="1" ' . checked(true, $value, false) . ' /> '
                    . esc_html__('When possible (ImageMagick required).', 'avif-local-support')
                    . '</label>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_preserve_color_profile' ]
        );

        // New: WordPress thumbnail intelligence
        add_settings_field(
            'avif_local_support_wordpress_logic',
            __('Avoid double-resizing', 'avif-local-support'),
            function (): void {
                $value = (bool) get_option('aviflosu_wordpress_logic', true);
                echo '<label for="aviflosu_wordpress_logic">'
                    . '<input id="aviflosu_wordpress_logic" type="checkbox" name="aviflosu_wordpress_logic" value="1" ' . checked(true, $value, false) . ' /> '
                    . esc_html__('Use original/-scaled as the source when converting resized JPEGs.', 'avif-local-support')
                    . '</label>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_wordpress_logic' ]
        );
    }

    public function sanitize_schedule_time(string $value): string
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value) ? $value : '01:00';
    }

    public function sanitize_speed($value): int
    {
        $n = (int) $value;
        if ($n < 0) { $n = 0; }
        if ($n > 10) { $n = 10; }
        return $n;
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'avif-local-support'));
        }
        $system_status = $this->get_system_status();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AVIF Local Support', 'avif-local-support') . '</h1>';

        if (empty($system_status['avif_support'])) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('AVIF support not available!', 'avif-local-support') . '</strong></p>';
            echo '<p>' . esc_html__('This plugin requires either GD with AVIF support (imageavif) or ImageMagick with AVIF format support.', 'avif-local-support') . '</p></div>';
        }

        // Tabs
        echo '<h2 class="nav-tab-wrapper">';
        echo '  <a href="#settings" class="nav-tab nav-tab-active" id="avif-local-support-tab-link-settings">' . esc_html__('Settings', 'avif-local-support') . '</a>';
        echo '  <a href="#tools" class="nav-tab" id="avif-local-support-tab-link-tools">' . esc_html__('Tools', 'avif-local-support') . '</a>';
        echo '  <a href="#status" class="nav-tab" id="avif-local-support-tab-link-status">' . esc_html__('Status', 'avif-local-support') . '</a>';
        echo '  <a href="#about" class="nav-tab" id="avif-local-support-tab-link-about">' . esc_html__('About', 'avif-local-support') . '</a>';
        echo '</h2>';

        // Tab: Settings
        echo '<div id="avif-local-support-tab-settings" class="avif-local-support-tab active">';
        echo '  <div class="metabox-holder">';
        echo '    <div class="postbox">';
        echo '      <h2 class="hndle"><span>' . esc_html__('Settings', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <form action="options.php" method="post">';
        settings_fields('aviflosu_settings');
        do_settings_sections('avif-local-support');
        submit_button();
        echo '        </form>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // Tab: Tools
        echo '<div id="avif-local-support-tab-tools" class="avif-local-support-tab">';
        echo '  <div class="metabox-holder">';
        echo '    <div class="postbox">';
        echo '      <h2 class="hndle"><span>' . esc_html__('Convert missing AVIFs', 'avif-local-support') . '</span></h2>';
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
        echo '      <h2 class="hndle"><span>' . esc_html__('Test conversion', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p class="description">' . esc_html__('Upload a JPEG to preview resized images and the AVIFs generated by your current settings. The file is added to the Media Library.', 'avif-local-support') . '</p>';
        echo '        <form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '          <input type="hidden" name="action" value="aviflosu_upload_test" />';
        wp_nonce_field('aviflosu_upload_test', '_wpnonce');
        echo '          <input type="file" name="avif_local_support_test_file" accept="image/jpeg" required />';
        echo '          <button type="submit" class="button button-primary" style="flex-basis:100%;margin-top:8px">' . esc_html__('Convert Now', 'avif-local-support') . '</button>';
        echo '        </form>';

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
                    $fmt = function (int $bytes): string { if ($bytes <= 0) return '-'; $units = ['B','KB','MB','GB']; $i=0; $n=(float)$bytes; while ($n>=1024 && $i<count($units)-1){$n/=1024;$i++;} return sprintf('%.1f %s',$n,$units[$i]); };
                    echo '<tr>'
                        . '<td>' . esc_html($name) . '</td>'
                        . '<td>' . esc_html($dims) . '</td>'
                        . '<td>' . ($jpegUrl !== '' ? '<a href="' . esc_url($jpegUrl) . '" target="_blank" rel="noopener">' . esc_html__('View', 'avif-local-support') . '</a>' : '-') . '</td>'
                        . '<td>' . esc_html($fmt($jpegSize)) . '</td>'
                        . '<td>' . (!empty($row['converted']) && $avifUrl !== '' ? '<a href="' . esc_url($avifUrl) . '" target="_blank" rel="noopener">' . esc_html__('View', 'avif-local-support') . '</a>' : '-') . '</td>'
                        . '<td>' . esc_html($fmt($avifSize)) . '</td>'
                        . '<td>' . esc_html($status) . '</td>'
                        . '</tr>';
                }
                echo '  </tbody>';
                echo '</table>';
            }
        }
        echo '      </div>';
        echo '    </div>';

        echo '  </div>';
        echo '</div>';

        // Tab: Status
        // Stats UI
        $initial_stats = $this->compute_missing_counts();
        echo '<div id="avif-local-support-tab-status" class="avif-local-support-tab">';
        echo '  <div class="metabox-holder">';
        echo '    <div class="postbox">';
        echo '      <h2 class="hndle"><span>' . esc_html__('Library AVIF coverage', 'avif-local-support') . '</span></h2>';
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

        // System Status card
        echo '    <div class="postbox">';
        echo '      <h2 class="hndle"><span>' . esc_html__('Server support', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        echo '        <p class="description">' . esc_html__('Server capabilities for AVIF conversion.', 'avif-local-support') . '</p>';
        echo '        <table class="widefat striped" style="max-width:720px">';
        echo '          <tbody>';
        echo '            <tr><td><strong>' . esc_html__('PHP Version', 'avif-local-support') . '</strong></td><td>' . esc_html(PHP_VERSION) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('WordPress Version', 'avif-local-support') . '</strong></td><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('GD Extension', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['gd_available']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('GD AVIF Support', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['gd_avif_support']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('ImageMagick (Imagick)', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['imagick_available']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('ImageMagick AVIF Support', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['imagick_avif_support']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('Preferred method', 'avif-local-support') . '</strong></td><td>' . esc_html($system_status['preferred_method']) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('AVIF supported', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['avif_support']) ? esc_html__('Yes', 'avif-local-support') : esc_html__('No', 'avif-local-support')) . '</td></tr>';
        echo '          </tbody></table>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>'; // .metabox-holder
        echo '</div>'; // #status tab

        // Tab: About
        echo '<div id="avif-local-support-tab-about" class="avif-local-support-tab">';
        echo '  <div class="metabox-holder">';
        echo '    <div class="postbox">';
        echo '      <h2 class="hndle"><span>' . esc_html__('About', 'avif-local-support') . '</span></h2>';
        echo '      <div class="inside">';
        $readme_path = \AVIFLOSU_PLUGIN_DIR . '/readme.txt';
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

    public function handle_convert_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'avif-local-support'));
        }
        check_admin_referer('aviflosu_convert_now');
        // schedule a single immediate event handled by Converter
        if (!\wp_next_scheduled('aviflosu_run_on_demand')) {
            \wp_schedule_single_event(time() + 5, 'aviflosu_run_on_demand');
        }
        \wp_safe_redirect(\add_query_arg('avif-local-support-convert', 'queued', \admin_url('options-general.php?page=avif-local-support')));
        exit;
    }

    public function ajax_convert_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('aviflosu_convert_now', '_wpnonce');
        $queued = false;
        if (!\wp_next_scheduled('aviflosu_run_on_demand')) {
            \wp_schedule_single_event(time() + 5, 'aviflosu_run_on_demand');
            $queued = true;
        }
        wp_send_json_success(['queued' => $queued]);
    }

    public function handle_upload_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'avif-local-support'));
        }
        check_admin_referer('aviflosu_upload_test');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Accessing the uploaded file array; individual fields are validated/sanitized below
        $fileArray = isset($_FILES['avif_local_support_test_file']) ? $_FILES['avif_local_support_test_file'] : [];
        if (empty($fileArray) || !is_array($fileArray)) {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'nofile', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (!isset($fileArray['tmp_name'], $fileArray['name'])) {
            \wp_safe_redirect(\add_query_arg('avif-local-support-upload-error', 'nofile', \admin_url('options-general.php?page=avif-local-support#tools')));
            exit;
        }
        $tmpName = (string) $fileArray['tmp_name'];
        $originalName = sanitize_file_name((string) $fileArray['name']);

        $errorCode = isset($fileArray['error']) ? (int) $fileArray['error'] : UPLOAD_ERR_OK;
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

    public function add_settings_link(array $links): array
    {
        $settings_link = sprintf('<a href="%s">%s</a>', esc_url(admin_url('options-general.php?page=avif-local-support')), __('Settings', 'avif-local-support'));
        array_unshift($links, $settings_link);
        return $links;
    }

    public function ajax_scan_missing(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('aviflosu_scan_missing', '_wpnonce');
        $stats = $this->compute_missing_counts();
        wp_send_json_success($stats);
    }

    private function compute_missing_counts(): array
    {
        $uploadDir = \wp_upload_dir();
        $baseDir = \trailingslashit($uploadDir['basedir'] ?? '');
        $total = 0;
        $existing = 0;
        $missing = 0;

        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'post_mime_type' => 'image/jpeg',
        ]);
        foreach ($query->posts as $attachmentId) {
            // Original
            $file = get_attached_file($attachmentId);
            if ($file && preg_match('/\.(jpe?g)$/i', $file) && file_exists($file)) {
                $total++;
                $avif = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $file);
                if ($avif && file_exists($avif)) { $existing++; } else { $missing++; }
            }
            // Sizes via metadata
            $meta = wp_get_attachment_metadata($attachmentId);
            if (!empty($meta['file'])) {
                $relative = (string) $meta['file'];
                $dir = pathinfo($relative, PATHINFO_DIRNAME);
                if ($dir === '.' || $dir === DIRECTORY_SEPARATOR) { $dir = ''; }
                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size) {
                        if (empty($size['file'])) { continue; }
                        $p = $baseDir . \trailingslashit($dir) . $size['file'];
                        if (!preg_match('/\.(jpe?g)$/i', $p) || !file_exists($p)) { continue; }
                        $total++;
                        $avif = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $p);
                        if ($avif && file_exists($avif)) { $existing++; } else { $missing++; }
                    }
                }
            }
        }

        return [
            'total_jpegs' => $total,
            'existing_avifs' => $existing,
            'missing_avifs' => $missing,
        ];
    }

    /**
     * Detect server AVIF support similar to AVIF Converter
     */
    private function get_system_status(): array
    {
        $status = [
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'gd_available' => extension_loaded('gd'),
            'gd_avif_support' => false,
            'imagick_available' => extension_loaded('imagick'),
            'imagick_avif_support' => false,
            'preferred_method' => 'none',
            'avif_support' => false,
        ];

        // ImageMagick AVIF support
        if ($status['imagick_available']) {
            try {
                $imagick = new \Imagick();
                $formats = $imagick->queryFormats('AVIF');
                $status['imagick_avif_support'] = !empty($formats);
                $imagick->destroy();
            } catch (\Exception $e) {
                $status['imagick_avif_support'] = false;
            }
        }

        // GD AVIF support
        if ($status['gd_available']) {
            $hasImageAvif = function_exists('imageavif');
            $hasGdInfoFlag = function_exists('gd_info') ? (bool) ((gd_info()['AVIF Support'] ?? false)) : false;
            $status['gd_avif_support'] = $hasImageAvif || $hasGdInfoFlag;
        }

        // Preferred method
        if ($status['imagick_avif_support']) {
            $status['preferred_method'] = 'imagick';
        } elseif ($status['gd_avif_support']) {
            $status['preferred_method'] = 'gd';
        }

        $status['avif_support'] = $status['gd_avif_support'] || $status['imagick_avif_support'];

        return $status;
    }
}
