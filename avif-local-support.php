<?php

declare(strict_types=1);

/**
 * Plugin Name: AVIF Local Support
 * Plugin URI: https://github.com/ddegner/avif-local-support
 * Description: Unified AVIF support and conversion. Local-first processing with a strong focus on image quality when converting JPEGs.
 * Version: 0.4.5
 * Author: David Degner
 * Author URI: https://www.DavidDegner.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: avif-local-support
 * Domain Path: /languages
 */

// Prevent direct access
\defined( 'ABSPATH' ) || exit;

// Define constants
\define( 'AVIFLOSU_VERSION', '0.4.5' );
\define( 'AVIFLOSU_PLUGIN_FILE', __FILE__ );
\define( 'AVIFLOSU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
\define( 'AVIFLOSU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
\define( 'AVIFLOSU_INC_DIR', AVIFLOSU_PLUGIN_DIR . 'includes' );

// PSR-4 style autoloader for plugin classes
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Ddegner\\AvifLocalSupport\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );

		// Map class names to file paths
		// Standard PSR-4: Foo\Bar\Baz -> Foo/Bar/Baz.php
		$file = AVIFLOSU_INC_DIR . '/' . str_replace( '\\', '/', $relative ) . '.php';

		// Handle legacy class names (class-*.php pattern)
		if ( ! file_exists( $file ) ) {
			$legacyMappings = array(
				'Plugin'    => '/class-avif-suite.php',
				'Support'   => '/class-support.php',
				'Converter' => '/class-converter.php',
			);
			if ( isset( $legacyMappings[ $relative ] ) ) {
				$file = AVIFLOSU_INC_DIR . $legacyMappings[ $relative ];
			}
		}

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

use Ddegner\AvifLocalSupport\Plugin as AVIFLOSU_Plugin;

// Initialize
function aviflosu_init(): void {
	static $instance = null;
	if ( $instance === null ) {
		$instance = new AVIFLOSU_Plugin();
		$instance->init();
	}
}
add_action( 'init', 'aviflosu_init' );

// Register WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'avif', \Ddegner\AvifLocalSupport\CLI::class );
}

// i18n: WordPress.org will auto-load translations for plugins hosted there.
// Keeping manual loader disabled to satisfy Plugin Check recommendations.

// Activation / Deactivation
function aviflosu_activate(): void {
	// Ensure defaults
	add_option( 'aviflosu_enable_support', true );
	add_option( 'aviflosu_convert_on_upload', true );
	add_option( 'aviflosu_convert_via_schedule', true );
	add_option( 'aviflosu_schedule_time', '01:00' );
	add_option( 'aviflosu_quality', 85 );
	add_option( 'aviflosu_speed', 1 );
	// Defaults for new encoder controls
	add_option( 'aviflosu_subsampling', '420' );
	add_option( 'aviflosu_bit_depth', '8' );
	add_option( 'aviflosu_cache_duration', 3600 );
	// Engine selection defaults
	add_option( 'aviflosu_engine_mode', 'auto' );
	add_option( 'aviflosu_cli_path', '' );
}

function aviflosu_deactivate(): void {
	// Clear any scheduled events created by this plugin
	\wp_clear_scheduled_hook( 'aviflosu_daily_event' );
	\wp_clear_scheduled_hook( 'aviflosu_run_on_demand' );
}

register_activation_hook( __FILE__, 'aviflosu_activate' );
register_deactivation_hook( __FILE__, 'aviflosu_deactivate' );
// Uninstall is handled by uninstall.php
