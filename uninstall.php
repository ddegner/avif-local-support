<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$aviflosu_options = array(
	'aviflosu_enable_support',
	'aviflosu_convert_on_upload',
	'aviflosu_convert_via_schedule',
	'aviflosu_schedule_time',
	'aviflosu_quality',
	'aviflosu_speed',
	'aviflosu_subsampling',
	'aviflosu_bit_depth',
	'aviflosu_disable_memory_check',
	'aviflosu_cache_duration',
	'aviflosu_engine_mode',
	'aviflosu_cli_path',
	'aviflosu_cli_args',
	'aviflosu_cli_env',
	'aviflosu_logs_generation',
	// LQIP (ThumbHash) settings
	'aviflosu_thumbhash_enabled',
	'aviflosu_lqip_generate_on_upload',
	'aviflosu_lqip_generate_via_schedule',
	'aviflosu_lqip_fade',
	'aviflosu_lqip_pixelated',
	// CSS Background Images
	'aviflosu_enable_background_images',
	// legacy options left behind in older versions
	'aviflosu_preserve_metadata',
	'aviflosu_preserve_color_profile',
	'aviflosu_wordpress_logic',
);

foreach ($aviflosu_options as $aviflosu_option) {
	if (get_option($aviflosu_option) !== false) {
		delete_option($aviflosu_option);
	}
}

// Delete transients using WordPress functions.
delete_transient('aviflosu_file_cache');
delete_transient('aviflosu_logs');
delete_transient('aviflosu_stop_conversion');
delete_transient('aviflosu_stop_lqip_generation');

// Delete ImageMagick CLI cache transients (with wildcard pattern).
// These use dynamic keys like aviflosu_imc_cand_*, aviflosu_imc_sel_*, aviflosu_imc_def_*.
// Note: Direct DB queries won't clear object cache entries (Redis/Memcached),
// but those will naturally expire based on their TTL.
if (!wp_using_ext_object_cache()) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aviflosu_imc_%' OR option_name LIKE '_transient_timeout_aviflosu_imc_%'");
}

// Delete all ThumbHash post meta entries.
delete_post_meta_by_key('_aviflosu_thumbhash');
