<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Admin;

use Ddegner\AvifLocalSupport\Diagnostics;
use Ddegner\AvifLocalSupport\Environment;

defined('ABSPATH') || exit;

/**
 * Handles WordPress Settings API registration for AVIF Local Support plugin.
 */
final class Settings
{
	private const OPTION_GROUP = 'aviflosu_settings';
	private const BETA_OPTION_GROUP = 'aviflosu_beta_settings';
	private const PAGE_SLUG = 'avif-local-support';

	private Diagnostics $diagnostics;

	public function __construct(Diagnostics $diagnostics)
	{
		$this->diagnostics = $diagnostics;
	}

	/**
	 * Register all settings with the WordPress Settings API.
	 */
	public function register(): void
	{
		$this->registerOptions();
		$this->registerSections();
		$this->registerFields();
	}

	/**
	 * Register setting options.
	 */
	private function registerOptions(): void
	{
		register_setting(
			self::OPTION_GROUP,
			'aviflosu_enable_support',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_enable_background_images',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_convert_on_upload',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_convert_via_schedule',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_schedule_time',
			array(
				'type' => 'string',
				'default' => '01:00',
				'sanitize_callback' => array($this, 'sanitizeScheduleTime'),
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_quality',
			array(
				'type' => 'integer',
				'default' => 85,
				'sanitize_callback' => 'absint',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_speed',
			array(
				'type' => 'integer',
				'default' => 1,
				'sanitize_callback' => array($this, 'sanitizeSpeed'),
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_subsampling',
			array(
				'type' => 'string',
				'default' => '420',
				'sanitize_callback' => array($this, 'sanitizeSubsampling'),
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_bit_depth',
			array(
				'type' => 'string',
				'default' => '8',
				'sanitize_callback' => array($this, 'sanitizeBitDepth'),
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cache_duration',
			array(
				'type' => 'integer',
				'default' => 3600,
				'sanitize_callback' => 'absint',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_disable_memory_check',
			array(
				'type' => 'boolean',
				'default' => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_engine_mode',
			array(
				'type' => 'string',
				'default' => 'auto',
				'sanitize_callback' => array($this, 'sanitizeEngineMode'),
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cli_path',
			array(
				'type' => 'string',
				'default' => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cli_args',
			array(
				'type' => 'string',
				'default' => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cli_env',
			array(
				'type' => 'string',
				'default' => '',
				'sanitize_callback' => array($this, 'sanitizeCliEnv'),
				'show_in_rest' => true,
			)
		);

		// Beta features - use separate option group to avoid form conflicts.
		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_thumbhash_enabled',
			array(
				'type' => 'boolean',
				'default' => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_generate_on_upload',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_generate_via_schedule',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_fade',
			array(
				'type' => 'boolean',
				'default' => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_pixelated',
			array(
				'type' => 'boolean',
				'default' => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest' => true,
			)
		);

	}

	/**
	 * Register settings sections.
	 */
	private function registerSections(): void
	{
		add_settings_section('aviflosu_main', '', fn() => null, self::PAGE_SLUG);
		add_settings_section('aviflosu_conversion_basic', '', fn() => null, self::PAGE_SLUG);
		add_settings_section('aviflosu_conversion_advanced', '', fn() => null, self::PAGE_SLUG);
		add_settings_section('aviflosu_engine', '', fn() => null, self::PAGE_SLUG);
		add_settings_section('aviflosu_beta', '', fn() => null, self::PAGE_SLUG);
		add_settings_section('aviflosu_lqip_basic', '', fn() => null, self::PAGE_SLUG);
		add_settings_section('aviflosu_lqip_advanced', '', fn() => null, self::PAGE_SLUG);
	}

	/**
	 * Register settings fields.
	 */
	private function registerFields(): void
	{
		// Main section.
		add_settings_field(
			'avif_local_support_enable_support',
			__('Enable AVIF image delivery', 'avif-local-support'),
			array($this, 'renderEnableSupportField'),
			self::PAGE_SLUG,
			'aviflosu_main',
			array('label_for' => 'aviflosu_enable_support')
		);

		add_settings_field(
			'avif_local_support_enable_background_images',
			__('Enable AVIF for CSS background images', 'avif-local-support'),
			array($this, 'renderEnableBackgroundImagesField'),
			self::PAGE_SLUG,
			'aviflosu_main',
			array('label_for' => 'aviflosu_enable_background_images')
		);

		// Conversion section.
		add_settings_field(
			'avif_local_support_convert_on_upload',
			__('Convert uploads to AVIF', 'avif-local-support'),
			array($this, 'renderConvertOnUploadField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_basic',
			array('label_for' => 'aviflosu_convert_on_upload')
		);

		add_settings_field(
			'avif_local_support_convert_via_schedule',
			__('Daily background conversion', 'avif-local-support'),
			array($this, 'renderScheduleField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_basic',
			array('label_for' => 'aviflosu_convert_via_schedule')
		);

		add_settings_field(
			'avif_local_support_quality',
			__('AVIF quality (0-100)', 'avif-local-support'),
			array($this, 'renderQualityField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_basic',
			array('label_for' => 'aviflosu_quality')
		);

		add_settings_field(
			'avif_local_support_speed',
			__('AVIF encoding speed (0-8)', 'avif-local-support'),
			array($this, 'renderSpeedField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_basic',
			array('label_for' => 'aviflosu_speed')
		);

		add_settings_field(
			'avif_local_support_subsampling',
			__('Chroma subsampling', 'avif-local-support'),
			array($this, 'renderSubsamplingField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_advanced',
			array('label_for' => 'aviflosu_subsampling')
		);

		add_settings_field(
			'avif_local_support_bit_depth',
			__('Bit depth', 'avif-local-support'),
			array($this, 'renderBitDepthField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_advanced',
			array('label_for' => 'aviflosu_bit_depth')
		);

		add_settings_field(
			'avif_local_support_disable_memory_check',
			__('Memory safety check', 'avif-local-support'),
			array($this, 'renderDisableMemoryCheckField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_advanced',
			array('label_for' => 'aviflosu_disable_memory_check')
		);

		// Engine section.
		add_settings_field(
			'avif_local_support_engine_mode',
			__('Conversion engine', 'avif-local-support'),
			array($this, 'renderEngineModeField'),
			self::PAGE_SLUG,
			'aviflosu_engine',
			array('label_for' => 'aviflosu_engine_mode')
		);

		add_settings_field(
			'avif_local_support_cli_settings',
			__('ImageMagick command options', 'avif-local-support'),
			array($this, 'renderCliSettingsField'),
			self::PAGE_SLUG,
			'aviflosu_conversion_advanced',
			array('label_for' => 'aviflosu_cli_path')
		);

		// Beta section.
		add_settings_field(
			'avif_local_support_thumbhash_enabled',
			__('Enable LQIP', 'avif-local-support'),
			array($this, 'renderThumbHashEnabledField'),
			self::PAGE_SLUG,
			'aviflosu_beta',
			array('label_for' => 'aviflosu_thumbhash_enabled')
		);

		add_settings_field(
			'avif_local_support_lqip_generate_on_upload',
			__('Generate LQIP on upload', 'avif-local-support'),
			array($this, 'renderLqipGenerateOnUploadField'),
			self::PAGE_SLUG,
			'aviflosu_lqip_basic',
			array('label_for' => 'aviflosu_lqip_generate_on_upload')
		);

		add_settings_field(
			'avif_local_support_lqip_generate_via_schedule',
			__('Daily background generation', 'avif-local-support'),
			array($this, 'renderLqipScheduleField'),
			self::PAGE_SLUG,
			'aviflosu_lqip_basic',
			array('label_for' => 'aviflosu_lqip_generate_via_schedule')
		);

		add_settings_field(
			'avif_local_support_lqip_fade',
			__('Fade in full images', 'avif-local-support'),
			array($this, 'renderLqipFadeField'),
			self::PAGE_SLUG,
			'aviflosu_lqip_advanced',
			array('label_for' => 'aviflosu_lqip_fade')
		);

		add_settings_field(
			'avif_local_support_lqip_pixelated',
			__('Show pixelated LQIP', 'avif-local-support'),
			array($this, 'renderLqipPixelatedField'),
			self::PAGE_SLUG,
			'aviflosu_lqip_advanced',
			array('label_for' => 'aviflosu_lqip_pixelated')
		);
	}

	// =========================================================================
	// Sanitizers
	// =========================================================================

	public function sanitizeScheduleTime(?string $value): string
	{
		if ($value === null || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value)) {
			return '01:00';
		}
		return $value;
	}

	public function sanitizeSpeed($value): int
	{
		return max(0, min(8, (int) ($value ?? 1)));
	}

	public function sanitizeSubsampling($value): string
	{
		$v = (string) ($value ?? '420');
		return in_array($v, array('420', '422', '444'), true) ? $v : '420';
	}

	public function sanitizeBitDepth($value): string
	{
		$v = (string) ($value ?? '8');
		return in_array($v, array('8', '10', '12'), true) ? $v : '8';
	}

	public function sanitizeEngineMode($value): string
	{
		$v = (string) ($value ?? 'auto');
		return in_array($v, array('auto', 'cli', 'imagick', 'gd'), true) ? $v : 'auto';
	}

	public function sanitizeCliEnv($value): string
	{
		return trim(wp_strip_all_tags((string) ($value ?? '')));
	}

	// =========================================================================
	// Field Renderers
	// =========================================================================

	private function renderHelpTip(string $text): void
	{
		echo ' <span class="dashicons dashicons-editor-help avif-help-tip" role="img" aria-label="' . esc_attr($text) . '" title="' . esc_attr($text) . '"></span>';
	}

	public function renderEnableSupportField(): void
	{
		$value = (bool) get_option('aviflosu_enable_support', true);
		echo '<label for="aviflosu_enable_support">';
		echo '<input id="aviflosu_enable_support" type="checkbox" name="aviflosu_enable_support" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Serve AVIF files before JPEG files on the front end', 'avif-local-support');
		echo '</label>';
	}

	public function renderEnableBackgroundImagesField(): void
	{
		$value = (bool) get_option('aviflosu_enable_background_images', true);
		echo '<label for="aviflosu_enable_background_images">';
		echo '<input id="aviflosu_enable_background_images" type="checkbox" name="aviflosu_enable_background_images" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Replace JPEG background images with AVIF when available', 'avif-local-support');
		$this->renderHelpTip(__('Works with page builders that set background images via CSS.', 'avif-local-support'));
		echo '</label>';
	}

	public function renderConvertOnUploadField(): void
	{
		$value = (bool) get_option('aviflosu_convert_on_upload', true);
		echo '<label for="aviflosu_convert_on_upload">';
		echo '<input id="aviflosu_convert_on_upload" type="checkbox" name="aviflosu_convert_on_upload" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Convert uploaded JPEG files to AVIF', 'avif-local-support');
		$this->renderHelpTip(__('Can slow uploads on low-resource servers.', 'avif-local-support'));
		echo '</label>';
	}

	public function renderScheduleField(): void
	{
		$enabled = (bool) get_option('aviflosu_convert_via_schedule', true);
		$time = (string) get_option('aviflosu_schedule_time', '01:00');
		echo '<label for="aviflosu_convert_via_schedule">';
		echo '<input id="aviflosu_convert_via_schedule" type="checkbox" name="aviflosu_convert_via_schedule" value="1" ' . checked(true, $enabled, false) . ' /> ';
		echo esc_html__('Scan daily and convert missing AVIF files', 'avif-local-support');
		$this->renderHelpTip(__('Set the time field to choose when the daily scan runs.', 'avif-local-support'));
		echo '</label> ';
		echo '<input id="aviflosu_schedule_time" type="time" name="aviflosu_schedule_time" value="' . esc_attr($time) . '" aria-label="' . esc_attr__('Daily run time', 'avif-local-support') . '" />';
	}

	public function renderQualityField(): void
	{
		$value = (int) get_option('aviflosu_quality', 85);
		echo '<input id="aviflosu_quality" type="range" name="aviflosu_quality" min="0" max="100" value="' . esc_attr((string) $value) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
		echo '<span>' . esc_html((string) $value) . '</span>';
		$this->renderHelpTip(__('Higher values improve quality and increase file size.', 'avif-local-support'));
	}

	public function renderSpeedField(): void
	{
		$value = max(0, min(8, (int) get_option('aviflosu_speed', 1)));
		echo '<input id="aviflosu_speed" type="range" name="aviflosu_speed" min="0" max="8" value="' . esc_attr((string) $value) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
		echo '<span>' . esc_html((string) $value) . '</span>';
		$this->renderHelpTip(__('Lower values are slower with better compression. Higher values are faster with larger files.', 'avif-local-support'));
	}

	public function renderSubsamplingField(): void
	{
		$value = (string) get_option('aviflosu_subsampling', '420');
		$allowed = array(
			'420' => '4:2:0',
			'422' => '4:2:2',
			'444' => '4:4:4',
		);
		echo '<fieldset id="aviflosu_subsampling">';
		foreach ($allowed as $key => $label) {
			$id = 'aviflosu_subsampling_' . $key;
			echo '<label for="' . esc_attr($id) . '">';
			echo '<input type="radio" name="aviflosu_subsampling" id="' . esc_attr($id) . '" value="' . esc_attr($key) . '" ' . checked($key, $value, false) . ' /> ' . esc_html($label) . '&nbsp;&nbsp;';
			echo '</label>';
		}
		echo '</fieldset>';
		$this->renderHelpTip(__('4:2:0 is usually best for compatibility and size. 4:4:4 preserves more color detail.', 'avif-local-support'));
	}

	public function renderBitDepthField(): void
	{
		$value = (string) get_option('aviflosu_bit_depth', '8');
		$allowed = array(
			'8' => '8-bit',
			'10' => '10-bit',
			'12' => '12-bit',
		);
		echo '<fieldset id="aviflosu_bit_depth">';
		foreach ($allowed as $key => $label) {
			$id = 'aviflosu_bit_depth_' . $key;
			echo '<label for="' . esc_attr($id) . '">';
			echo '<input type="radio" name="aviflosu_bit_depth" id="' . esc_attr($id) . '" value="' . esc_attr($key) . '" ' . checked($key, $value, false) . ' /> ' . esc_html($label) . '&nbsp;&nbsp;';
			echo '</label>';
		}
		echo '</fieldset>';
		$this->renderHelpTip(__('8-bit is standard. Higher bit depth can increase file size and reduce compatibility.', 'avif-local-support'));
	}

	public function renderDisableMemoryCheckField(): void
	{
		$value = (bool) get_option('aviflosu_disable_memory_check', false);
		echo '<label for="aviflosu_disable_memory_check">';
		echo '<input id="aviflosu_disable_memory_check" type="checkbox" name="aviflosu_disable_memory_check" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Disable memory safety check before conversion', 'avif-local-support');
		$this->renderHelpTip(__('Only enable this if you trust available server memory; large images may cause fatal errors.', 'avif-local-support'));
		echo '</label>';
	}

	public function renderEngineModeField(): void
	{
		$mode = (string) get_option('aviflosu_engine_mode', 'auto');
		echo '<fieldset id="aviflosu_engine_mode">';
		echo '<p><label><input type="radio" name="aviflosu_engine_mode" value="auto" ' . checked('auto', $mode, false) . ' /> ' . esc_html__('Automatic (try CLI, then Imagick, then GD)', 'avif-local-support') . '</label></p>';
		echo '<p><label><input type="radio" name="aviflosu_engine_mode" value="cli" ' . checked('cli', $mode, false) . ' /> ' . esc_html__('ImageMagick CLI only', 'avif-local-support') . '</label></p>';
		echo '<p><label><input type="radio" name="aviflosu_engine_mode" value="imagick" ' . checked('imagick', $mode, false) . ' /> ' . esc_html__('Imagick PHP extension only', 'avif-local-support') . '</label></p>';
		echo '<p><label><input type="radio" name="aviflosu_engine_mode" value="gd" ' . checked('gd', $mode, false) . ' /> ' . esc_html__('GD only', 'avif-local-support') . '</label></p>';
		echo '</fieldset>';
	}

	public function renderCliSettingsField(): void
	{
		$cliPath = (string) get_option('aviflosu_cli_path', '');
		$cliArgs = (string) get_option('aviflosu_cli_args', '');

		// Suppress any output/errors from diagnostic methods.
		ob_start();
		try {
			$suggestedEnv = $this->diagnostics->getSuggestedCliEnv();
			$detected = $this->diagnostics->detectCliBinaries();
			$suggestedArgs = $this->diagnostics->getSuggestedCliArgs();
		} catch (\Throwable $e) {
			$suggestedEnv = '';
			$detected = array();
			$suggestedArgs = '';
		}
		ob_end_clean();

		$cliEnv = (string) get_option('aviflosu_cli_env', $suggestedEnv);

		echo '<div id="aviflosu_cli_options">';

		// CLI Path.
		echo '<label for="aviflosu_cli_path">' . esc_html__('ImageMagick binary path', 'avif-local-support');
		$this->renderHelpTip(__('Choose a detected binary or enter a custom path.', 'avif-local-support'));
		echo '</label><br>';
		echo '<input type="text" id="aviflosu_cli_path" name="aviflosu_cli_path" value="' . esc_attr($cliPath) . '" list="aviflosu_cli_path_datalist" placeholder="/usr/local/bin/magick" class="regular-text code" />';
		echo '<datalist id="aviflosu_cli_path_datalist">';
		foreach ($detected as $bin) {
			$path = isset($bin['path']) ? (string) $bin['path'] : '';
			if ('' !== $path) {
				echo '<option value="' . esc_attr($path) . '">';
			}
		}
		echo '</datalist>';

		// CLI Args.
		echo '<p><label for="aviflosu_cli_args">' . esc_html__('Extra ImageMagick flags', 'avif-local-support');
		$this->renderHelpTip(__('Additional flags passed to ImageMagick (example: -define avif:my-flag=1).', 'avif-local-support'));
		echo '</label><br>';
		echo '<input type="text" id="aviflosu_cli_args" name="aviflosu_cli_args" value="' . esc_attr($cliArgs) . '" class="large-text code" /></p>';
		if ($cliArgs !== $suggestedArgs && '' !== $suggestedArgs) {
			/* translators: %s: Suggested CLI arguments. */
			echo '<p class="description"><small>' . sprintf(esc_html__('Suggested: %s', 'avif-local-support'), '<code>' . esc_html($suggestedArgs) . '</code>');
			echo ' <a href="#" class="aviflosu-apply-suggestion" data-target="aviflosu_cli_args" data-value="' . esc_attr($suggestedArgs) . '">[' . esc_html__('Use suggested', 'avif-local-support') . ']</a></small></p>';
		}

		// CLI Env.
		echo '<p><label for="aviflosu_cli_env">' . esc_html__('ImageMagick environment variables', 'avif-local-support');
		$this->renderHelpTip(__('Environment variables for the CLI process (KEY=VALUE), one per line.', 'avif-local-support'));
		echo '</label><br>';
		echo '<textarea id="aviflosu_cli_env" name="aviflosu_cli_env" rows="4" class="large-text code">' . esc_textarea($cliEnv) . '</textarea></p>';
		if ($cliEnv !== $suggestedEnv) {
			echo '<p class="description"><small>' . esc_html__('Suggested values:', 'avif-local-support');
			echo ' <a href="#" class="aviflosu-apply-suggestion" data-target="aviflosu_cli_env" data-value="' . esc_attr($suggestedEnv) . '">[' . esc_html__('Use suggested', 'avif-local-support') . ']</a></small></p>';
		}

		echo '</div>';
	}

	public function renderThumbHashEnabledField(): void
	{
		$value = (bool) get_option('aviflosu_thumbhash_enabled', false);
		echo '<label for="aviflosu_thumbhash_enabled">';
		echo '<input id="aviflosu_thumbhash_enabled" type="checkbox" name="aviflosu_thumbhash_enabled" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Enable LQIP using ThumbHash', 'avif-local-support');
		$this->renderHelpTip(__('Generates ultra-compact LQIP data decoded client-side while full images load.', 'avif-local-support'));
		echo '</label>';
	}

	public function renderLqipGenerateOnUploadField(): void
	{
		$value = (bool) get_option('aviflosu_lqip_generate_on_upload', true);
		echo '<label for="aviflosu_lqip_generate_on_upload">';
		echo '<input id="aviflosu_lqip_generate_on_upload" type="checkbox" name="aviflosu_lqip_generate_on_upload" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Generate LQIP on upload', 'avif-local-support');
		echo '</label>';
	}

	public function renderLqipScheduleField(): void
	{
		$enabled = (bool) get_option('aviflosu_lqip_generate_via_schedule', true);
		echo '<label for="aviflosu_lqip_generate_via_schedule">';
		echo '<input id="aviflosu_lqip_generate_via_schedule" type="checkbox" name="aviflosu_lqip_generate_via_schedule" value="1" ' . checked(true, $enabled, false) . ' /> ';
		echo esc_html__('Scan daily and generate missing LQIP', 'avif-local-support');
		$this->renderHelpTip(__('Uses the same daily schedule time as AVIF conversion.', 'avif-local-support'));
		echo '</label>';
	}

	public function renderLqipFadeField(): void
	{
		$value = (bool) get_option('aviflosu_lqip_fade', true);
		echo '<label for="aviflosu_lqip_fade">';
		echo '<input id="aviflosu_lqip_fade" type="checkbox" name="aviflosu_lqip_fade" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Fade in full images', 'avif-local-support');
		$this->renderHelpTip(__('Adds a smooth transition when the full image replaces the LQIP.', 'avif-local-support'));
		echo '</label>';
	}

	public function renderLqipPixelatedField(): void
	{
		$value = (bool) get_option('aviflosu_lqip_pixelated', false);
		echo '<label for="aviflosu_lqip_pixelated">';
		echo '<input id="aviflosu_lqip_pixelated" type="checkbox" name="aviflosu_lqip_pixelated" value="1" ' . checked(true, $value, false) . ' /> ';
		echo esc_html__('Show pixelated LQIP instead of blur', 'avif-local-support');
		$this->renderHelpTip(__('Displays chunky pixels instead of a blur for the LQIP style.', 'avif-local-support'));
		echo '</label>';
	}

	/**
	 * Get the option group name for use in settings_fields().
	 */
	public static function getOptionGroup(): string
	{
		return self::OPTION_GROUP;
	}

	/**
	 * Get the page slug for use in do_settings_fields().
	 */
	public static function getPageSlug(): string
	{
		return self::PAGE_SLUG;
	}
}
