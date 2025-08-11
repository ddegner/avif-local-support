<?php

declare(strict_types=1);

/**
 * Plugin Name: AVIF Local Support
 * Plugin URI: https://github.com/daviddegner
 * Description: Unified AVIF support and conversion. Local-first processing with a strong focus on image quality when converting JPEGs.
 * Version: 0.1.1
 * Author: David Degner
 * Author URI: https://www.DavidDegner.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Text Domain: avif-local-support
 * Domain Path: /languages
 */

// Prevent direct access
\defined('ABSPATH') || exit;

// Define constants
\define('AVIF_SUITE_VERSION', '0.1.1');
\define('AVIF_SUITE_PLUGIN_FILE', __FILE__);
\define('AVIF_SUITE_PLUGIN_DIR', __DIR__);
\define('AVIF_SUITE_INC_DIR', __DIR__ . '/includes');

// Includes (simple autoload)
require_once AVIF_SUITE_INC_DIR . '/class-avif-suite.php';
require_once AVIF_SUITE_INC_DIR . '/class-support.php';
require_once AVIF_SUITE_INC_DIR . '/class-converter.php';

use AVIFSuite\Plugin as AVIF_Suite_Plugin;

// Initialize
function avif_local_support_init(): void
{
    static $instance = null;
    if ($instance === null) {
        $instance = new AVIF_Suite_Plugin();
        $instance->init();
    }
}
add_action('init', 'avif_local_support_init');

// i18n: WordPress.org will auto-load translations for plugins hosted there.
// Keeping manual loader disabled to satisfy Plugin Check recommendations.

// Activation / Deactivation / Uninstall
function avif_local_support_activate(): void
{
    // Ensure defaults
    add_option('avif_local_support_enable_support', true);
    add_option('avif_local_support_convert_on_upload', true);
    add_option('avif_local_support_convert_via_schedule', true);
    add_option('avif_local_support_schedule_time', '01:00');
    add_option('avif_local_support_quality', 85);
    add_option('avif_local_support_speed', 1);
    add_option('avif_local_support_preserve_metadata', true);
    add_option('avif_local_support_preserve_color_profile', true);
    add_option('avif_local_support_wordpress_logic', true);
    add_option('avif_local_support_cache_duration', 3600);
}

function avif_local_support_deactivate(): void
{
    // Clear any scheduled events created by this plugin
    if (function_exists('wp_clear_scheduled_hook')) {
        \wp_clear_scheduled_hook('avif_local_support_daily_event');
        \wp_clear_scheduled_hook('avif_local_support_run_on_demand');
    } else {
        $timestamp = \wp_next_scheduled('avif_local_support_daily_event');
        if ($timestamp && \wp_get_schedule('avif_local_support_daily_event')) {
            \wp_unschedule_event($timestamp, 'avif_local_support_daily_event');
        }
        $timestamp = \wp_next_scheduled('avif_local_support_run_on_demand');
        if ($timestamp && \wp_get_schedule('avif_local_support_run_on_demand')) {
            \wp_unschedule_event($timestamp, 'avif_local_support_run_on_demand');
        }
    }
}

register_activation_hook(__FILE__, 'avif_local_support_activate');
register_deactivation_hook(__FILE__, 'avif_local_support_deactivate');
register_uninstall_hook(__FILE__, 'avif_local_support_uninstall');

function avif_local_support_uninstall(): void
{
    // Delete only options created by this plugin
    $options = [
        'avif_local_support_enable_support',
        'avif_local_support_convert_on_upload',
        'avif_local_support_convert_via_schedule',
        'avif_local_support_schedule_time',
        'avif_local_support_quality',
        'avif_local_support_speed',
        'avif_local_support_preserve_metadata',
        'avif_local_support_preserve_color_profile',
        'avif_local_support_wordpress_logic',
        'avif_local_support_cache_duration',
    ];

    foreach ($options as $option) {
        if (\get_option($option) !== false) {
            \delete_option($option);
        }
    }
}
