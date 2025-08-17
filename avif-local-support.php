<?php

declare(strict_types=1);

/**
 * Plugin Name: AVIF Local Support
 * Plugin URI: https://github.com/daviddegner
 * Description: Unified AVIF support and conversion. Local-first processing with a strong focus on image quality when converting JPEGs.
 * Version: 0.1.2
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
\define('AVIFLOSU_VERSION', '0.1.2');
\define('AVIFLOSU_PLUGIN_FILE', __FILE__);
\define('AVIFLOSU_PLUGIN_DIR', plugin_dir_path(__FILE__));
\define('AVIFLOSU_PLUGIN_URL', plugin_dir_url(__FILE__));
\define('AVIFLOSU_INC_DIR', AVIFLOSU_PLUGIN_DIR . 'includes');

// Includes (simple autoload)
require_once AVIFLOSU_INC_DIR . '/class-avif-suite.php';
require_once AVIFLOSU_INC_DIR . '/class-support.php';
require_once AVIFLOSU_INC_DIR . '/class-converter.php';

use Ddegner\AvifLocalSupport\Plugin as AVIFLOSU_Plugin;

// Initialize
function aviflosu_init(): void
{
	static $instance = null;
	if ($instance === null) {
		$instance = new AVIFLOSU_Plugin();
		$instance->init();
	}
}
add_action('init', 'aviflosu_init');

// i18n: WordPress.org will auto-load translations for plugins hosted there.
// Keeping manual loader disabled to satisfy Plugin Check recommendations.

// Activation / Deactivation
function aviflosu_activate(): void
{
	// Ensure defaults
	add_option('aviflosu_enable_support', true);
	add_option('aviflosu_convert_on_upload', true);
	add_option('aviflosu_convert_via_schedule', true);
	add_option('aviflosu_schedule_time', '01:00');
	add_option('aviflosu_quality', 85);
	add_option('aviflosu_speed', 1);
	add_option('aviflosu_preserve_metadata', true);
	add_option('aviflosu_preserve_color_profile', true);
	add_option('aviflosu_wordpress_logic', true);
	add_option('aviflosu_cache_duration', 3600);
}

function aviflosu_deactivate(): void
{
	// Clear any scheduled events created by this plugin
	if (function_exists('wp_clear_scheduled_hook')) {
		\wp_clear_scheduled_hook('aviflosu_daily_event');
		\wp_clear_scheduled_hook('aviflosu_run_on_demand');
	} else {
		$timestamp = \wp_next_scheduled('aviflosu_daily_event');
		if ($timestamp && \wp_get_schedule('aviflosu_daily_event')) {
			\wp_unschedule_event($timestamp, 'aviflosu_daily_event');
		}
		$timestamp = \wp_next_scheduled('aviflosu_run_on_demand');
		if ($timestamp && \wp_get_schedule('aviflosu_run_on_demand')) {
			\wp_unschedule_event($timestamp, 'aviflosu_run_on_demand');
		}
	}
}

register_activation_hook(__FILE__, 'aviflosu_activate');
register_deactivation_hook(__FILE__, 'aviflosu_deactivate');
// Uninstall is handled by uninstall.php
