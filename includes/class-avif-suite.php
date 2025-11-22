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
        $this->converter->set_plugin($this);
    }

    public function init(): void
    {
        // Settings page + Settings API
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(\AVIFLOSU_PLUGIN_FILE), [$this, 'add_settings_link']);
        add_action('admin_post_aviflosu_upload_test', [$this, 'handle_upload_test']);
        add_action('admin_post_aviflosu_reset_defaults', [$this, 'handle_reset_defaults']);
        add_action('wp_ajax_aviflosu_scan_missing', [$this, 'ajax_scan_missing']);
        add_action('wp_ajax_aviflosu_convert_now', [$this, 'ajax_convert_now']);
        add_action('wp_ajax_aviflosu_delete_all_avifs', [$this, 'ajax_delete_all_avifs']);
        add_action('wp_ajax_aviflosu_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_aviflosu_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_aviflosu_run_magick_test', [$this, 'ajax_run_magick_test']);

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
        wp_enqueue_style('avif-local-support-admin', $base . 'assets/admin.css', [], \AVIFLOSU_VERSION);
        wp_enqueue_script('avif-local-support-admin', $base . 'assets/admin.js', [], \AVIFLOSU_VERSION, true);
        wp_localize_script('avif-local-support-admin', 'AVIFLocalSupportData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'scanNonce' => wp_create_nonce('aviflosu_scan_missing'),
            'convertNonce' => wp_create_nonce('aviflosu_convert_now'),
            'deleteNonce' => wp_create_nonce('aviflosu_delete_all_avifs'),
            'logsNonce' => wp_create_nonce('aviflosu_logs'),
            'diagNonce' => wp_create_nonce('aviflosu_diag'),
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
        // New: AVIF chroma subsampling and bit depth
        register_setting('aviflosu_settings', 'aviflosu_subsampling', [
            'type' => 'string',
            'default' => '420',
            'sanitize_callback' => [$this, 'sanitize_subsampling'],
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_bit_depth', [
            'type' => 'string',
            'default' => '8',
            'sanitize_callback' => [$this, 'sanitize_bit_depth'],
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_cache_duration', [
            'type' => 'integer',
            'default' => 3600,
            'sanitize_callback' => 'absint',
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_disable_memory_check', [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'show_in_rest' => true,
        ]);

        // Engine selection
        register_setting('aviflosu_settings', 'aviflosu_engine_mode', [
            'type' => 'string',
            'default' => 'auto',
            'sanitize_callback' => [$this, 'sanitize_engine_mode'],
            'show_in_rest' => true,
        ]);
        register_setting('aviflosu_settings', 'aviflosu_cli_path', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
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

        // New: Speed slider (0-8)
        add_settings_field(
            'avif_local_support_speed',
            __('Speed (0–8)', 'avif-local-support'),
            function (): void {
                $value = (int) get_option('aviflosu_speed', 1);
                $value = max(0, min(8, $value));
                echo '<input id="aviflosu_speed" type="range" name="aviflosu_speed" min="0" max="8" value="' . \esc_attr((string) $value) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
                echo '<span>' . \esc_html((string) $value) . '</span>';
                echo '<p class="description">' . esc_html__('Lower = smaller files (slower). Higher = faster (larger files).', 'avif-local-support') . '</p>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_speed' ]
        );

        // New: Chroma subsampling (radio)
        add_settings_field(
            'avif_local_support_subsampling',
            __('Chroma subsampling', 'avif-local-support'),
            function (): void {
                $value = (string) get_option('aviflosu_subsampling', '420');
                $allowed = ['420' => '4:2:0', '422' => '4:2:2', '444' => '4:4:4'];
                echo '<fieldset id="aviflosu_subsampling">';
                foreach ($allowed as $key => $label) {
                    $id = 'aviflosu_subsampling_' . $key;
                    echo '<label for="' . \esc_attr($id) . '" style="margin-right:12px;">';
                    echo '<input type="radio" name="aviflosu_subsampling" id="' . \esc_attr($id) . '" value="' . \esc_attr($key) . '" ' . checked($key, $value, false) . ' /> ' . \esc_html($label) . '&nbsp;&nbsp;';
                    echo '</label>';
                }
                echo '</fieldset>';
                echo '<p class="description">' . esc_html__('4:2:0 is most compatible and smallest; 4:4:4 preserves more color detail.', 'avif-local-support') . '</p>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_subsampling' ]
        );

        // New: Bit depth (radio)
        add_settings_field(
            'avif_local_support_bit_depth',
            __('Bit depth', 'avif-local-support'),
            function (): void {
                $value = (string) get_option('aviflosu_bit_depth', '8');
                $allowed = ['8' => '8-bit', '10' => '10-bit', '12' => '12-bit'];
                echo '<fieldset id="aviflosu_bit_depth">';
                foreach ($allowed as $key => $label) {
                    $id = 'aviflosu_bit_depth_' . $key;
                    echo '<label for="' . \esc_attr($id) . '" style="margin-right:12px;">';
                    echo '<input type="radio" name="aviflosu_bit_depth" id="' . \esc_attr($id) . '" value="' . \esc_attr($key) . '" ' . checked($key, $value, false) . ' /> ' . \esc_html($label) . '&nbsp;&nbsp;';
                    echo '</label>';
                }
                echo '</fieldset>';
                echo '<p class="description">' . esc_html__('8-bit is standard; higher bit depths may increase file size and require broader support.', 'avif-local-support') . '</p>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_bit_depth' ]
        );

        add_settings_field(
            'avif_local_support_disable_memory_check',
            __('Disable memory check', 'avif-local-support'),
            function (): void {
                $value = (bool) get_option('aviflosu_disable_memory_check', false);
                echo '<label for="aviflosu_disable_memory_check">'
                    . '<input id="aviflosu_disable_memory_check" type="checkbox" name="aviflosu_disable_memory_check" value="1" ' . checked(true, $value, false) . ' /> '
                    . esc_html__('Skip pre-conversion memory availability check (not recommended)', 'avif-local-support')
                    . '</label>';
                echo '<p class="description">' . esc_html__('Useful if the memory estimator is too conservative, but may cause fatal errors on large images.', 'avif-local-support') . '</p>';
            },
            'avif-local-support',
            'aviflosu_conversion',
            [ 'label_for' => 'aviflosu_disable_memory_check' ]
        );

        // Engine selection section
        add_settings_section(
            'aviflosu_engine',
            '',
            function (): void {},
            'avif-local-support'
        );

        add_settings_field(
            'avif_local_support_engine_mode',
            __('Engine selection', 'avif-local-support'),
            function (): void {
                $mode = (string) get_option('aviflosu_engine_mode', 'auto');
                $cliPath = (string) get_option('aviflosu_cli_path', '');
                $detected = $this->detect_cli_binaries();
                echo '<fieldset id="aviflosu_engine_mode">';
                echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="aviflosu_engine_mode" value="auto" ' . checked('auto', $mode, false) . ' /> ' . esc_html__('Auto (use Imagick if available; fallback to GD)', 'avif-local-support') . '</label>';
                echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="aviflosu_engine_mode" value="cli" ' . checked('cli', $mode, false) . ' /> ' . esc_html__('ImageMagick CLI', 'avif-local-support') . '</label>';
                // CLI binary list
                echo '<div style="margin-left:20px;margin-top:6px;">';
                if (!empty($detected)) {
                    echo '<label for="aviflosu_cli_path_select" style="display:block;margin:6px 0 4px;">' . esc_html__('Detected binaries', 'avif-local-support') . '</label>';
                    echo '<select id="aviflosu_cli_path_select" onchange="var v=this.value; if(v){ document.getElementById(\'aviflosu_cli_path\').value=v; }" style="min-width:360px;">';
                    echo '<option value="">' . esc_html__('— Select detected binary —', 'avif-local-support') . '</option>';
                    foreach ($detected as $bin) {
                        $label = $bin['path'] . ' — ' . $bin['version'] . ' — ' . ($bin['avif'] ? 'AVIF: yes' : 'AVIF: no');
                        echo '<option value="' . esc_attr($bin['path']) . '">' . esc_html($label) . '</option>';
                    }
                    echo '</select>';
                } else {
                    echo '<p class="description" style="margin:0 0 6px;">' . esc_html__('No ImageMagick CLI detected. You can still enter a custom path below.', 'avif-local-support') . '</p>';
                }
                echo '<label for="aviflosu_cli_path" style="display:block;margin-top:8px;">' . esc_html__('Custom path', 'avif-local-support') . '</label>';
                echo '<input type="text" id="aviflosu_cli_path" name="aviflosu_cli_path" value="' . esc_attr($cliPath) . '" placeholder="/usr/local/bin/magick" style="min-width:360px;" />';
                echo '<p class="description" style="margin-top:6px;">' . esc_html__('Provide full path to `magick` or `convert`. Must support writing AVIF.', 'avif-local-support') . '</p>';
                echo '</div>';
                echo '</fieldset>';
            },
            'avif-local-support',
            'aviflosu_engine',
            [ 'label_for' => 'aviflosu_engine_mode' ]
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
        if ($n > 8) { $n = 8; }
        return $n;
    }

    public function sanitize_subsampling($value): string
    {
        $v = (string) $value;
        $allowed = ['420', '422', '444'];
        return in_array($v, $allowed, true) ? $v : '420';
    }

    public function sanitize_bit_depth($value): string
    {
        $v = (string) $value;
        $allowed = ['8', '10', '12'];
        return in_array($v, $allowed, true) ? $v : '8';
    }

    public function sanitize_engine_mode($value): string
    {
        $v = (string) $value;
        $allowed = ['auto', 'cli'];
        return in_array($v, $allowed, true) ? $v : 'auto';
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
        echo '        <form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;align-items:flex-start;gap:8px">';
        echo '          <input type="hidden" name="action" value="aviflosu_upload_test" />';
        wp_nonce_field('aviflosu_upload_test', '_wpnonce');
        echo '          <input type="file" name="avif_local_support_test_file" accept="image/jpeg" required />';
        echo '          <button type="submit" class="button button-primary">' . esc_html__('Convert now', 'avif-local-support') . '</button>';
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
        echo '        <p class="description">' . esc_html__('Server capabilities for AVIF conversion.', 'avif-local-support') . '</p>';
        echo '        <table class="widefat striped" style="max-width:720px">';
        echo '          <tbody>';
        echo '            <tr><td><strong>' . esc_html__('PHP Version', 'avif-local-support') . '</strong></td><td>' . esc_html(PHP_VERSION) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('PHP SAPI', 'avif-local-support') . '</strong></td><td>' . esc_html($system_status['php_sapi'] ?? PHP_SAPI) . '</td></tr>';
        $currentUser = (string) ($system_status['current_user'] ?? @get_current_user());
        echo '            <tr><td><strong>' . esc_html__('Current user', 'avif-local-support') . '</strong></td><td>' . esc_html($currentUser !== '' ? $currentUser : '-') . '</td></tr>';
        $ob = (string) ($system_status['open_basedir'] ?? ini_get('open_basedir'));
        echo '            <tr><td><strong>' . esc_html__('open_basedir', 'avif-local-support') . '</strong></td><td>' . esc_html($ob !== '' ? $ob : '-') . '</td></tr>';
        $df = (string) ($system_status['disable_functions'] ?? ini_get('disable_functions'));
        echo '            <tr><td><strong>' . esc_html__('disable_functions', 'avif-local-support') . '</strong></td><td><code style="white-space:pre-wrap;word-break:break-word;display:inline-block;max-width:560px;overflow:auto;">' . esc_html($df !== '' ? $df : '-') . '</code></td></tr>';
        echo '            <tr><td><strong>' . esc_html__('WordPress Version', 'avif-local-support') . '</strong></td><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('GD Extension', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['gd_available']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('GD AVIF Support', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['gd_avif_support']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('ImageMagick (Imagick)', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['imagick_available']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        if (!empty($system_status['imagick_version'])) {
            echo '            <tr><td><strong>' . esc_html__('ImageMagick Version', 'avif-local-support') . '</strong></td><td>' . esc_html($system_status['imagick_version']) . '</td></tr>';
        }
        echo '            <tr><td><strong>' . esc_html__('ImageMagick AVIF Support', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['imagick_avif_support']) ? esc_html__('Available', 'avif-local-support') : esc_html__('Not available', 'avif-local-support')) . '</td></tr>';
        $formats = isset($system_status['imagick_formats']) && is_array($system_status['imagick_formats']) ? $system_status['imagick_formats'] : [];
        if (!empty($formats)) {
            $fmtStr = implode(', ', array_map('strval', $formats));
            echo '            <tr><td><strong>' . esc_html__('Imagick formats (sample)', 'avif-local-support') . '</strong></td><td><code style="white-space:pre-wrap;word-break:break-word;display:inline-block;max-width:560px;overflow:auto;">' . esc_html($fmtStr) . '</code></td></tr>';
        }
        // CLI binaries found
        $cliDetected = isset($system_status['cli_detected']) && is_array($system_status['cli_detected']) ? $system_status['cli_detected'] : [];
        if (!empty($cliDetected)) {
            echo '            <tr><td><strong>' . esc_html__('ImageMagick CLI detected', 'avif-local-support') . '</strong></td><td>';
            echo '<ul style="margin:0;padding-left:18px;">';
            foreach ($cliDetected as $bin) {
                $path = isset($bin['path']) ? (string) $bin['path'] : '';
                $ver = isset($bin['version']) ? (string) $bin['version'] : '';
                $avif = !empty($bin['avif']) ? 'AVIF: yes' : 'AVIF: no';
                echo '<li><code>' . esc_html($path) . '</code> — ' . esc_html($ver) . ' — ' . esc_html($avif) . '</li>';
            }
            echo '</ul>';
            echo '</td></tr>';
        }
        echo '            <tr><td><strong>' . esc_html__('Selected engine', 'avif-local-support') . '</strong></td><td>' . esc_html((string) get_option('aviflosu_engine_mode', 'auto')) . '</td></tr>';
        $selCli = (string) get_option('aviflosu_cli_path', '');
        echo '            <tr><td><strong>' . esc_html__('CLI path', 'avif-local-support') . '</strong></td><td>' . ($selCli !== '' ? esc_html($selCli) : '-') . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('Preferred method', 'avif-local-support') . '</strong></td><td>' . esc_html($system_status['preferred_method']) . '</td></tr>';
        echo '            <tr><td><strong>' . esc_html__('AVIF supported', 'avif-local-support') . '</strong></td><td>' . (!empty($system_status['avif_support']) ? esc_html__('Yes', 'avif-local-support') : esc_html__('No', 'avif-local-support')) . '</td></tr>';
        echo '          </tbody></table>';
        // Run test button
        echo '        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '          <button type="button" class="button" id="avif-local-support-run-magick-test">' . esc_html__('Run ImageMagick test', 'avif-local-support') . '</button>';
        echo '          <span class="spinner" id="avif-local-support-magick-test-spinner" style="float:none;margin:0 6px;"></span>';
        echo '          <span id="avif-local-support-magick-test-status" class="description"></span>';
        echo '        </div>';
        echo '        <pre id="avif-local-support-magick-test-output" style="display:none;max-width:960px;white-space:pre-wrap;background:#f6f7f7;padding:10px;border:1px solid #ccd0d4;margin-top:8px;"></pre>';
        if (!empty($system_status['preferred_method']) && $system_status['preferred_method'] === 'gd') {
            echo '<p class="description" style="margin-top:8px;"><strong>' . esc_html__('Color management note:', 'avif-local-support') . '</strong> ' . esc_html__('GD does not perform color management; non‑sRGB JPEGs (Adobe RGB, Display P3) may appear desaturated. For accurate color and metadata preservation, enable Imagick with AVIF support.', 'avif-local-support') . '</p>';
        }
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
        \wp_safe_redirect(\admin_url('options-general.php?page=avif-local-support#settings'));
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

    public function ajax_delete_all_avifs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('aviflosu_delete_all_avifs', '_wpnonce');

        $uploads = \wp_upload_dir();
        $baseDir = (string) ($uploads['basedir'] ?? '');
        if ($baseDir === '' || !is_dir($baseDir)) {
            wp_send_json_error(['message' => 'uploads_not_found']);
        }

        $deleted = 0;
        $failed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) { continue; }
            $path = $fileInfo->getPathname();
            if (preg_match('/\.avif$/i', $path)) {
                // Do not follow symlinks
                if ($fileInfo->isLink()) { continue; }
                $ok = \wp_delete_file($path);
                if ($ok) { $deleted++; } else { $failed++; }
            }
        }

        wp_send_json_success(['deleted' => $deleted, 'failed' => $failed]);
    }

    private function compute_missing_counts(): array
    {
        $uploadDir = \wp_upload_dir();
        $baseDir = \trailingslashit($uploadDir['basedir'] ?? '');
        $total = 0;
        $existing = 0;
        $missing = 0;
        // Deduplicate by absolute JPEG path so identical size files aren't double-counted
        $seenJpegs = [];

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
                $real = (string) (@realpath($file) ?: $file);
                if (!isset($seenJpegs[$real])) {
                    $seenJpegs[$real] = true;
                    $total++;
                    $avif = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $real);
                    // Validate size > 512 bytes
                    if ($avif && file_exists($avif) && filesize($avif) > 512) { $existing++; } else { $missing++; }
                }
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
                        $realP = (string) (@realpath($p) ?: $p);
                        if (isset($seenJpegs[$realP])) { continue; }
                        $seenJpegs[$realP] = true;
                        $total++;
                        $avif = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $realP);
                        // Validate size > 512 bytes
                        if ($avif && file_exists($avif) && filesize($avif) > 512) { $existing++; } else { $missing++; }
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
            'php_sapi' => PHP_SAPI,
            'current_user' => function_exists('posix_geteuid') ? (string) @get_current_user() . ' (uid ' . (int) @posix_geteuid() . ')' : (string) @get_current_user(),
            'open_basedir' => (string) ini_get('open_basedir'),
            'disable_functions' => (string) ini_get('disable_functions'),
            'gd_available' => extension_loaded('gd'),
            'gd_avif_support' => false,
            'imagick_available' => extension_loaded('imagick'),
            'imagick_avif_support' => false,
            'imagick_version' => '',
            'imagick_formats' => [],
            'cli_detected' => [],
            'preferred_method' => 'none',
            'avif_support' => false,
        ];

        // ImageMagick AVIF support and version detection
        if ($status['imagick_available']) {
            try {
                $imagick = new \Imagick();
                $version = $imagick->getVersion();
                $status['imagick_version'] = $version['versionString'] ?? '';
                
                $formats = $imagick->queryFormats('AVIF');
                $status['imagick_avif_support'] = !empty($formats);
                // Capture a short list of formats to display
                $allFormats = $imagick->queryFormats();
                if (is_array($allFormats)) {
                    // Show a compact comma list limited to common ones if too long
                    $subset = array_slice($allFormats, 0, 20);
                    $status['imagick_formats'] = $subset;
                }
                
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

        // Detect CLI binaries for display
        $status['cli_detected'] = $this->detect_cli_binaries();

        return $status;
    }


    /**
     * Detect ImageMagick CLI binaries and AVIF support.
     * @return array<int, array{path:string,version:string,avif:bool}>
     */
    private function detect_cli_binaries(): array
    {
        $candidates = [];
        
        // Check if shell functions are available
        if (in_array('shell_exec', explode(',', ini_get('disable_functions')), true)) {
            // shell_exec is disabled, only check hardcoded paths
            foreach (['/usr/bin/magick','/usr/local/bin/magick','/opt/homebrew/bin/magick','/opt/local/bin/magick','/usr/bin/convert','/usr/local/bin/convert','/opt/homebrew/bin/convert','/opt/local/bin/convert'] as $p) {
                if (@file_exists($p) && @is_executable($p)) { 
                    $candidates[$p] = true; 
                }
            }
        } else {
            // Common names using which/command -v
            foreach (['magick', 'convert', 'convert-im6', 'convert-im7'] as $name) {
                $p = $this->which($name);
                if ($p) { $candidates[$p] = true; }
            }
            // Common locations
            foreach (['/usr/bin/magick','/usr/local/bin/magick','/opt/homebrew/bin/magick','/opt/local/bin/magick','/usr/bin/convert','/usr/local/bin/convert','/opt/homebrew/bin/convert','/opt/local/bin/convert'] as $p) {
                if (@file_exists($p) && @is_executable($p)) { 
                    $candidates[$p] = true; 
                }
            }
        }
        
        $out = [];
        foreach (array_keys($candidates) as $path) {
            // Ensure the path exists and is executable
            if (!@file_exists($path) || !@is_executable($path)) {
                continue;
            }
            
            $version = $this->im_version($path);
            if ($version === '') { continue; }
            $avif = $this->im_supports_avif($path);
            $out[] = ['path' => $path, 'version' => $version, 'avif' => $avif];
        }
        return $out;
    }

    private function which(string $bin): string
    {
        // Check if shell functions are disabled
        if (in_array('shell_exec', explode(',', ini_get('disable_functions')), true)) {
            return '';
        }
        
        $cmd = 'command -v ' . escapeshellarg($bin) . ' 2>/dev/null';
        $res = @shell_exec($cmd);
        $p = is_string($res) ? trim($res) : '';
        
        // Verify the result exists and is executable
        if ($p !== '' && @file_exists($p) && @is_executable($p)) {
            return $p;
        }
        
        return '';
    }

    private function im_version(string $path): string
    {
        // Check if exec is disabled
        if (in_array('exec', explode(',', ini_get('disable_functions')), true)) {
            return '';
        }
        
        $cmd = escapeshellarg($path) . ' -version 2>&1';
        @exec($cmd, $out, $code);
        if ($code !== 0 || empty($out)) { return ''; }
        // First line like: Version: ImageMagick 7.1.1-34 Q16-HDRI ...
        $line = $out[0] ?? '';
        return is_string($line) ? trim($line) : '';
    }

    private function im_supports_avif(string $path): bool
    {
        // Check if exec is disabled
        if (in_array('exec', explode(',', ini_get('disable_functions')), true)) {
            return false;
        }
        
        $base = strtolower(basename($path));
        // For IM7 'magick' use 'magick identify -list format'; for IM6 'convert' use 'convert -list format'
        $cmd = ($base === 'magick')
            ? (escapeshellarg($path) . ' identify -list format 2>/dev/null')
            : (escapeshellarg($path) . ' -list format 2>/dev/null');
        @exec($cmd, $out, $code);
        if ($code !== 0 || empty($out)) { return false; }
        foreach ($out as $line) {
            if (!preg_match('/^\s*AVIF\b/i', $line)) { continue; }
            // Lines can be like:
            //   AVIF* rw-   ...
            //   AVIF  HEIC  rw+   ...
            if (preg_match('/^\s*AVIF\S*\s+(?:[A-Za-z0-9_-]+\s+)?([rw\+\-]{2,3})\s/i', $line, $m)) {
                $mode = strtolower($m[1]);
                return (strpos($mode, 'w') !== false);
            }
            // Fallback: if AVIF line exists, assume supported (conservative yes)
            return true;
        }
        return false;
    }

    /**
     * Render logs content for the admin interface
     */
    private function render_logs_content(): void
    {
        $logs = $this->get_logs();
        if (empty($logs)) {
            echo '<p class="description">' . esc_html__('No logs available.', 'avif-local-support') . '</p>';
            return;
        }

        echo '<div style="max-height:400px;overflow-y:auto;border:1px solid #ddd;background:#f9f9f9;padding:10px;">';
        foreach ($logs as $log) {
            $timestamp = isset($log['timestamp']) ? (int) $log['timestamp'] : 0;
            $status = isset($log['status']) ? (string) $log['status'] : 'info';
            $message = isset($log['message']) ? (string) $log['message'] : '';
            $details = isset($log['details']) ? (array) $log['details'] : [];

            $time_display = $timestamp > 0 ? wp_date('Y-m-d H:i:s', $timestamp) : '-';
            $status_class = $status === 'error' ? 'color:red;' : ($status === 'warning' ? 'color:orange;' : 'color:green;');

            echo '<div style="margin-bottom:8px;padding:8px;background:white;border-left:3px solid ' . ($status === 'error' ? '#dc3232' : ($status === 'warning' ? '#ffb900' : '#46b450')) . ';">';
            echo '<div style="font-weight:bold;margin-bottom:4px;"><span style="' . esc_attr($status_class) . '">' . esc_html(strtoupper($status)) . '</span> - ' . esc_html($time_display) . '</div>';
            echo '<div style="margin-bottom:4px;">' . esc_html($message) . '</div>';
            
            if (!empty($details)) {
                // Highlight suggestion if present
                if (isset($details['error_suggestion'])) {
                    echo '<div style="margin:4px 0;padding:4px;background:#fff4f4;border-left:3px solid #d63638;color:#d63638;font-weight:bold;font-size:12px;">';
                    echo '💡 ' . esc_html((string)$details['error_suggestion']);
                    echo '</div>';
                    unset($details['error_suggestion']);
                }

                echo '<div style="font-size:11px;color:#666;font-family:monospace;">';
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

    /**
     * Get logs from WordPress transients
     */
    private function get_logs(): array
    {
        $logs = get_transient('aviflosu_logs');
        return is_array($logs) ? $logs : [];
    }

    /**
     * Add a log entry
     */
    public function add_log(string $status, string $message, array $details = []): void
    {
        $logs = $this->get_logs();
        
        // Add new log entry
        $log_entry = [
            'timestamp' => time(),
            'status' => $status,
            'message' => $message,
            'details' => $details
        ];
        
        // Prepend to show newest first
        array_unshift($logs, $log_entry);
        
        // Keep only last 50 entries to prevent unlimited growth
        $logs = array_slice($logs, 0, 50);
        
        // Store for 24 hours (temporary logs)
        set_transient('aviflosu_logs', $logs, DAY_IN_SECONDS);
    }

    /**
     * Clear all logs
     */
    private function clear_logs(): void
    {
        delete_transient('aviflosu_logs');
    }

    /**
     * AJAX handler to get logs
     */
    public function ajax_get_logs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('aviflosu_logs', '_wpnonce');
        
        ob_start();
        $this->render_logs_content();
        $content = ob_get_clean();
        
        wp_send_json_success(['content' => $content]);
    }

    /**
     * AJAX handler to clear logs
     */
    public function ajax_clear_logs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('aviflosu_logs', '_wpnonce');
        
        $this->clear_logs();
        wp_send_json_success(['message' => 'Logs cleared']);
    }

    /**
     * AJAX: Run `magick -version` (or selected CLI) via proc_open and return output
     */
    public function ajax_run_magick_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('aviflosu_diag', '_wpnonce');

        $path = (string) get_option('aviflosu_cli_path', '');
        $detected = $this->detect_cli_binaries();
        if ($path === '' && !empty($detected)) {
            $path = (string) ($detected[0]['path'] ?? '');
        }

        $disableFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $execAvailable = !in_array('exec', $disableFunctions, true);
        if (!$execAvailable) {
            wp_send_json_error(['message' => 'exec disabled by PHP disable_functions.'], 400);
        }
        if ($path === '' || !@file_exists($path)) {
            wp_send_json_error(['message' => 'No ImageMagick CLI path found. Set a custom path under Engine Selection.'], 400);
        }

        $cmd = escapeshellarg($path) . ' -version 2>&1';
        $outLines = [];
        $exitCode = 0;
        @exec($cmd, $outLines, $exitCode);
        $output = trim(implode("\n", array_map('strval', $outLines)));

        if ($output === '') {
            $hint = 'No output. If using ImageMagick 7, ensure the path points to `magick`.';
            wp_send_json_success(['code' => $exitCode, 'output' => $output, 'hint' => $hint]);
        }
        wp_send_json_success(['code' => $exitCode, 'output' => $output]);
    }
}
