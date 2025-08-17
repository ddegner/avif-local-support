<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$options = [
	'aviflosu_enable_support',
	'aviflosu_convert_on_upload',
	'aviflosu_convert_via_schedule',
	'aviflosu_schedule_time',
	'aviflosu_quality',
	'aviflosu_speed',
	'aviflosu_preserve_metadata',
	'aviflosu_preserve_color_profile',
	'aviflosu_wordpress_logic',
	'aviflosu_cache_duration',
];

foreach ($options as $option) {
	if (get_option($option) !== false) {
		delete_option($option);
	}
}

delete_transient('aviflosu_file_cache');