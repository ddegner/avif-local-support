<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Admin;

use Ddegner\AvifLocalSupport\Diagnostics;
use Ddegner\AvifLocalSupport\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Handles WordPress Settings API registration for AVIF Local Support plugin.
 */
final class Settings {




	private const OPTION_GROUP      = 'aviflosu_settings';
	private const BETA_OPTION_GROUP = 'aviflosu_beta_settings';
	private const PAGE_SLUG         = 'avif-local-support';

	private Diagnostics $diagnostics;

	public function __construct( Diagnostics $diagnostics ) {
		$this->diagnostics = $diagnostics;
	}

	/**
	 * Register all settings with the WordPress Settings API.
	 */
	public function register(): void {
		$this->registerOptions();
		$this->registerSections();
		$this->registerFields();
	}

	/**
	 * Register setting options.
	 */
	private function registerOptions(): void {
		register_setting(
			self::OPTION_GROUP,
			'aviflosu_enable_support',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_enable_background_images',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_convert_on_upload',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_convert_via_schedule',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_schedule_time',
			array(
				'type'              => 'string',
				'default'           => '01:00',
				'sanitize_callback' => array( $this, 'sanitizeScheduleTime' ),
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_quality',
			array(
				'type'              => 'integer',
				'default'           => 85,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_speed',
			array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => array( $this, 'sanitizeSpeed' ),
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_subsampling',
			array(
				'type'              => 'string',
				'default'           => '420',
				'sanitize_callback' => array( $this, 'sanitizeSubsampling' ),
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_bit_depth',
			array(
				'type'              => 'string',
				'default'           => '8',
				'sanitize_callback' => array( $this, 'sanitizeBitDepth' ),
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cache_duration',
			array(
				'type'              => 'integer',
				'default'           => 3600,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_disable_memory_check',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_engine_mode',
			array(
				'type'              => 'string',
				'default'           => 'auto',
				'sanitize_callback' => array( $this, 'sanitizeEngineMode' ),
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cli_path',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cli_args',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'aviflosu_cli_env',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitizeCliEnv' ),
				'show_in_rest'      => true,
			)
		);

		// Beta features - use separate option group to avoid form conflicts.
		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_thumbhash_enabled',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_generate_on_upload',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_generate_via_schedule',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_lqip_fade',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_setting(
			self::BETA_OPTION_GROUP,
			'aviflosu_thumbhash_size',
			array(
				'type'              => 'integer',
				'default'           => 100,
				'sanitize_callback' => array( $this, 'sanitizeThumbHashSize' ),
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Register settings sections.
	 */
	private function registerSections(): void {
		add_settings_section( 'aviflosu_main', '', fn() => null, self::PAGE_SLUG );
		add_settings_section( 'aviflosu_conversion', '', fn() => null, self::PAGE_SLUG );
		add_settings_section( 'aviflosu_engine', '', fn() => null, self::PAGE_SLUG );
		add_settings_section( 'aviflosu_beta', '', fn() => null, self::PAGE_SLUG );
		add_settings_section( 'aviflosu_lqip_conversion', '', fn() => null, self::PAGE_SLUG );
	}

	/**
	 * Register settings fields.
	 */
	private function registerFields(): void {
		// Main section.
		add_settings_field(
			'avif_local_support_enable_support',
			__( 'Serve AVIF images', 'avif-local-support' ),
			array( $this, 'renderEnableSupportField' ),
			self::PAGE_SLUG,
			'aviflosu_main',
			array( 'label_for' => 'aviflosu_enable_support' )
		);

		add_settings_field(
			'avif_local_support_enable_background_images',
			__( 'Serve AVIF for CSS backgrounds', 'avif-local-support' ),
			array( $this, 'renderEnableBackgroundImagesField' ),
			self::PAGE_SLUG,
			'aviflosu_main',
			array( 'label_for' => 'aviflosu_enable_background_images' )
		);

		// Conversion section.
		add_settings_field(
			'avif_local_support_convert_on_upload',
			__( 'Convert on upload', 'avif-local-support' ),
			array( $this, 'renderConvertOnUploadField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_convert_on_upload' )
		);

		add_settings_field(
			'avif_local_support_convert_via_schedule',
			__( 'Daily conversion', 'avif-local-support' ),
			array( $this, 'renderScheduleField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_convert_via_schedule' )
		);

		add_settings_field(
			'avif_local_support_quality',
			__( 'Quality (0–100)', 'avif-local-support' ),
			array( $this, 'renderQualityField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_quality' )
		);

		add_settings_field(
			'avif_local_support_speed',
			__( 'Speed (0–8)', 'avif-local-support' ),
			array( $this, 'renderSpeedField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_speed' )
		);

		add_settings_field(
			'avif_local_support_subsampling',
			__( 'Chroma subsampling', 'avif-local-support' ),
			array( $this, 'renderSubsamplingField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_subsampling' )
		);

		add_settings_field(
			'avif_local_support_bit_depth',
			__( 'Bit depth', 'avif-local-support' ),
			array( $this, 'renderBitDepthField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_bit_depth' )
		);

		add_settings_field(
			'avif_local_support_disable_memory_check',
			__( 'Disable memory check', 'avif-local-support' ),
			array( $this, 'renderDisableMemoryCheckField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_disable_memory_check' )
		);

		// Engine section.
		add_settings_field(
			'avif_local_support_engine_mode',
			__( 'Engine selection', 'avif-local-support' ),
			array( $this, 'renderEngineModeField' ),
			self::PAGE_SLUG,
			'aviflosu_engine',
			array( 'label_for' => 'aviflosu_engine_mode' )
		);

		add_settings_field(
			'avif_local_support_cli_settings',
			__( 'ImageMagick CLI', 'avif-local-support' ),
			array( $this, 'renderCliSettingsField' ),
			self::PAGE_SLUG,
			'aviflosu_conversion',
			array( 'label_for' => 'aviflosu_cli_path' )
		);

		// Beta section.
		add_settings_field(
			'avif_local_support_thumbhash_enabled',
			__( 'Serve LQIP images', 'avif-local-support' ),
			array( $this, 'renderThumbHashEnabledField' ),
			self::PAGE_SLUG,
			'aviflosu_beta',
			array( 'label_for' => 'aviflosu_thumbhash_enabled' )
		);

		add_settings_field(
			'avif_local_support_lqip_generate_on_upload',
			__( 'Convert on upload', 'avif-local-support' ),
			array( $this, 'renderLqipGenerateOnUploadField' ),
			self::PAGE_SLUG,
			'aviflosu_lqip_conversion',
			array( 'label_for' => 'aviflosu_lqip_generate_on_upload' )
		);

		add_settings_field(
			'avif_local_support_lqip_generate_via_schedule',
			__( 'Daily conversion', 'avif-local-support' ),
			array( $this, 'renderLqipScheduleField' ),
			self::PAGE_SLUG,
			'aviflosu_lqip_conversion',
			array( 'label_for' => 'aviflosu_lqip_generate_via_schedule' )
		);

		add_settings_field(
			'avif_local_support_lqip_fade',
			__( 'Fade in images', 'avif-local-support' ),
			array( $this, 'renderLqipFadeField' ),
			self::PAGE_SLUG,
			'aviflosu_lqip_conversion',
			array( 'label_for' => 'aviflosu_lqip_fade' )
		);

		add_settings_field(
			'avif_local_support_thumbhash_size',
			__( 'ThumbHash size', 'avif-local-support' ),
			array( $this, 'renderThumbHashSizeField' ),
			self::PAGE_SLUG,
			'aviflosu_lqip_conversion',
			array( 'label_for' => 'aviflosu_thumbhash_size' )
		);
	}

	// =========================================================================
	// Sanitizers
	// =========================================================================

	public function sanitizeScheduleTime( ?string $value ): string {
		if ( $value === null || ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return '01:00';
		}
		return $value;
	}

	public function sanitizeSpeed( $value ): int {
		return max( 0, min( 8, (int) ( $value ?? 1 ) ) );
	}

	public function sanitizeSubsampling( $value ): string {
		$v = (string) ( $value ?? '420' );
		return in_array( $v, array( '420', '422', '444' ), true ) ? $v : '420';
	}

	public function sanitizeBitDepth( $value ): string {
		$v = (string) ( $value ?? '8' );
		return in_array( $v, array( '8', '10', '12' ), true ) ? $v : '8';
	}

	public function sanitizeEngineMode( $value ): string {
		$v = (string) ( $value ?? 'auto' );
		return in_array( $v, array( 'auto', 'cli', 'imagick', 'gd' ), true ) ? $v : 'auto';
	}

	public function sanitizeCliEnv( $value ): string {
		return trim( wp_strip_all_tags( (string) ( $value ?? '' ) ) );
	}

	// =========================================================================
	// Field Renderers
	// =========================================================================

	public function renderEnableSupportField(): void {
		$value = (bool) get_option( 'aviflosu_enable_support', true );
		echo '<label for="aviflosu_enable_support">';
		echo '<input id="aviflosu_enable_support" type="checkbox" name="aviflosu_enable_support" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo esc_html__( 'Add AVIF sources to JPEG images on the front end', 'avif-local-support' );
		echo '</label>';
	}

	public function renderEnableBackgroundImagesField(): void {
		$value = (bool) get_option( 'aviflosu_enable_background_images', true );
		echo '<label for="aviflosu_enable_background_images">';
		echo '<input id="aviflosu_enable_background_images" type="checkbox" name="aviflosu_enable_background_images" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo esc_html__( 'Replace JPEG background images with AVIF versions', 'avif-local-support' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Works with page builders like Elementor, Divi, and WPBakery that set background images via CSS.', 'avif-local-support' ) . '</p>';
	}

	public function renderConvertOnUploadField(): void {
		$value = (bool) get_option( 'aviflosu_convert_on_upload', true );
		echo '<label for="aviflosu_convert_on_upload">';
		echo '<input id="aviflosu_convert_on_upload" type="checkbox" name="aviflosu_convert_on_upload" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo esc_html__( 'Recommended; may slow uploads on some servers', 'avif-local-support' );
		echo '</label>';
	}

	public function renderScheduleField(): void {
		$enabled = (bool) get_option( 'aviflosu_convert_via_schedule', true );
		$time    = (string) get_option( 'aviflosu_schedule_time', '01:00' );
		echo '<label for="aviflosu_convert_via_schedule">';
		echo '<input id="aviflosu_convert_via_schedule" type="checkbox" name="aviflosu_convert_via_schedule" value="1" ' . checked( true, $enabled, false ) . ' /> ';
		echo esc_html__( 'Scan daily and convert missing AVIFs', 'avif-local-support' );
		echo '</label> ';
		echo '<input id="aviflosu_schedule_time" type="time" name="aviflosu_schedule_time" value="' . esc_attr( $time ) . '" aria-label="' . esc_attr__( 'Time', 'avif-local-support' ) . '" />';
	}

	public function renderQualityField(): void {
		$value = (int) get_option( 'aviflosu_quality', 85 );
		echo '<input id="aviflosu_quality" type="range" name="aviflosu_quality" min="0" max="100" value="' . esc_attr( (string) $value ) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
		echo '<span>' . esc_html( (string) $value ) . '</span>';
		echo '<p class="description">' . esc_html__( 'Higher = better quality and larger files.', 'avif-local-support' ) . '</p>';
	}

	public function renderSpeedField(): void {
		$value = max( 0, min( 8, (int) get_option( 'aviflosu_speed', 1 ) ) );
		echo '<input id="aviflosu_speed" type="range" name="aviflosu_speed" min="0" max="8" value="' . esc_attr( (string) $value ) . '" oninput="this.nextElementSibling.innerText=this.value" /> ';
		echo '<span>' . esc_html( (string) $value ) . '</span>';
		echo '<p class="description">' . esc_html__( 'Lower = smaller files (slower). Higher = faster (larger files).', 'avif-local-support' ) . '</p>';
	}

	public function renderSubsamplingField(): void {
		$value   = (string) get_option( 'aviflosu_subsampling', '420' );
		$allowed = array(
			'420' => '4:2:0',
			'422' => '4:2:2',
			'444' => '4:4:4',
		);
		echo '<fieldset id="aviflosu_subsampling">';
		foreach ( $allowed as $key => $label ) {
			$id = 'aviflosu_subsampling_' . $key;
			echo '<label for="' . esc_attr( $id ) . '" style="margin-right:12px;">';
			echo '<input type="radio" name="aviflosu_subsampling" id="' . esc_attr( $id ) . '" value="' . esc_attr( $key ) . '" ' . checked( $key, $value, false ) . ' /> ' . esc_html( $label ) . '&nbsp;&nbsp;';
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( '4:2:0 is most compatible and smallest; 4:4:4 preserves more color detail.', 'avif-local-support' ) . '</p>';
	}

	public function renderBitDepthField(): void {
		$value   = (string) get_option( 'aviflosu_bit_depth', '8' );
		$allowed = array(
			'8'  => '8-bit',
			'10' => '10-bit',
			'12' => '12-bit',
		);
		echo '<fieldset id="aviflosu_bit_depth">';
		foreach ( $allowed as $key => $label ) {
			$id = 'aviflosu_bit_depth_' . $key;
			echo '<label for="' . esc_attr( $id ) . '" style="margin-right:12px;">';
			echo '<input type="radio" name="aviflosu_bit_depth" id="' . esc_attr( $id ) . '" value="' . esc_attr( $key ) . '" ' . checked( $key, $value, false ) . ' /> ' . esc_html( $label ) . '&nbsp;&nbsp;';
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( '8-bit is standard; higher bit depths may increase file size and require broader support.', 'avif-local-support' ) . '</p>';
	}

	public function renderDisableMemoryCheckField(): void {
		$value = (bool) get_option( 'aviflosu_disable_memory_check', false );
		echo '<label for="aviflosu_disable_memory_check">';
		echo '<input id="aviflosu_disable_memory_check" type="checkbox" name="aviflosu_disable_memory_check" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo esc_html__( 'Skip pre-conversion memory availability check (not recommended)', 'avif-local-support' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Useful if the memory estimator is too conservative, but may cause fatal errors on large images.', 'avif-local-support' ) . '</p>';
	}

	public function renderEngineModeField(): void {
		$mode = (string) get_option( 'aviflosu_engine_mode', 'auto' );
		echo '<fieldset id="aviflosu_engine_mode">';
		echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="aviflosu_engine_mode" value="auto" ' . checked( 'auto', $mode, false ) . ' /> ' . esc_html__( 'Auto (CLI → Imagick → GD)', 'avif-local-support' ) . '</label>';
		echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="aviflosu_engine_mode" value="cli" ' . checked( 'cli', $mode, false ) . ' /> ' . esc_html__( 'Force ImageMagick CLI', 'avif-local-support' ) . '</label>';
		echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="aviflosu_engine_mode" value="imagick" ' . checked( 'imagick', $mode, false ) . ' /> ' . esc_html__( 'Force ImageMagick (Imagick PHP)', 'avif-local-support' ) . '</label>';
		echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="aviflosu_engine_mode" value="gd" ' . checked( 'gd', $mode, false ) . ' /> ' . esc_html__( 'Force GD', 'avif-local-support' ) . '</label>';
		echo '</fieldset>';
	}

	public function renderCliSettingsField(): void {
		$cliPath = (string) get_option( 'aviflosu_cli_path', '' );
		$cliArgs = (string) get_option( 'aviflosu_cli_args', '' );

		// Suppress any output/errors from diagnostic methods.
		ob_start();
		try {
			$suggestedEnv  = $this->diagnostics->getSuggestedCliEnv();
			$detected      = $this->diagnostics->detectCliBinaries();
			$suggestedArgs = $this->diagnostics->getSuggestedCliArgs();
		} catch ( \Throwable $e ) {
			$suggestedEnv  = '';
			$detected      = array();
			$suggestedArgs = '';
		}
		ob_end_clean();

		$cliEnv = (string) get_option( 'aviflosu_cli_env', $suggestedEnv );

		echo '<div id="aviflosu_cli_options">';

		// CLI Path.
		echo '<label for="aviflosu_cli_path" style="display:block;margin:0 0 4px;">' . esc_html__( 'CLI Path', 'avif-local-support' ) . '</label>';
		echo '<input type="text" id="aviflosu_cli_path" name="aviflosu_cli_path" value="' . esc_attr( $cliPath ) . '" list="aviflosu_cli_path_datalist" placeholder="/usr/local/bin/magick" style="min-width:360px;" />';
		echo '<datalist id="aviflosu_cli_path_datalist">';
		foreach ( $detected as $bin ) {
			$path = isset( $bin['path'] ) ? (string) $bin['path'] : '';
			if ( '' !== $path ) {
				echo '<option value="' . esc_attr( $path ) . '">';
			}
		}
		echo '</datalist>';
		echo '<p class="description">' . esc_html__( 'Select a detected binary or enter a custom path.', 'avif-local-support' ) . '</p>';

		// CLI Args.
		echo '<label for="aviflosu_cli_args" style="display:block;margin-top:12px;">' . esc_html__( 'Extra CLI Arguments', 'avif-local-support' ) . '</label>';
		echo '<input type="text" id="aviflosu_cli_args" name="aviflosu_cli_args" value="' . esc_attr( $cliArgs ) . '" style="min-width:360px;width:100%;max-width:600px;" />';
		if ( $cliArgs !== $suggestedArgs && '' !== $suggestedArgs ) {
			/* translators: %s: Suggested CLI arguments. */
			echo '<br><small>' . sprintf( esc_html__( 'Suggested: %s', 'avif-local-support' ), '<code>' . esc_html( $suggestedArgs ) . '</code>' );
			echo ' <a href="#" class="aviflosu-apply-suggestion" data-target="aviflosu_cli_args" data-value="' . esc_attr( $suggestedArgs ) . '">[' . esc_html__( 'Apply', 'avif-local-support' ) . ']</a></small>';
		}
		echo '<p class="description" style="margin-top:6px;">' . esc_html__( 'Additional flags passed to ImageMagick (e.g. -define avif:my-flag=1).', 'avif-local-support' ) . '</p>';

		// CLI Env.
		echo '<label for="aviflosu_cli_env" style="display:block;margin-top:12px;">' . esc_html__( 'CLI Environment Variables', 'avif-local-support' ) . '</label>';
		echo '<textarea id="aviflosu_cli_env" name="aviflosu_cli_env" rows="4" style="min-width:360px;width:100%;max-width:600px;font-family:monospace;">' . esc_textarea( $cliEnv ) . '</textarea>';
		if ( $cliEnv !== $suggestedEnv ) {
			echo '<br><small>' . esc_html__( 'Suggested environment:', 'avif-local-support' );
			echo ' <a href="#" class="aviflosu-apply-suggestion" data-target="aviflosu_cli_env" data-value="' . esc_attr( $suggestedEnv ) . '">[' . esc_html__( 'Apply', 'avif-local-support' ) . ']</a></small>';
		}
		echo '<p class="description" style="margin-top:6px;">' . esc_html__( 'Environment variables for the CLI process (KEY=VALUE), one per line. This does not change the CLI Path; it only affects the subprocess environment.', 'avif-local-support' ) . '</p>';

		echo '</div>';
	}

	public function renderThumbHashEnabledField(): void {
		$value = (bool) get_option( 'aviflosu_thumbhash_enabled', false );
		echo '<label for="aviflosu_thumbhash_enabled">';
		echo '<input id="aviflosu_thumbhash_enabled" type="checkbox" name="aviflosu_thumbhash_enabled" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Low Quality Image Placeholders (LQIP) using ThumbHash. Generates ultra-compact image representations (~30 bytes) that are decoded client-side to smooth placeholders while full images load.', 'avif-local-support' ) . '</p>';
	}

	public function renderLqipGenerateOnUploadField(): void {
		$value = (bool) get_option( 'aviflosu_lqip_generate_on_upload', true );
		echo '<label for="aviflosu_lqip_generate_on_upload">';
		echo '<input id="aviflosu_lqip_generate_on_upload" type="checkbox" name="aviflosu_lqip_generate_on_upload" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo esc_html__( 'Generate ThumbHash on upload', 'avif-local-support' );
		echo '</label>';
	}

	public function renderLqipScheduleField(): void {
		$enabled = (bool) get_option( 'aviflosu_lqip_generate_via_schedule', true );
		echo '<label for="aviflosu_lqip_generate_via_schedule">';
		echo '<input id="aviflosu_lqip_generate_via_schedule" type="checkbox" name="aviflosu_lqip_generate_via_schedule" value="1" ' . checked( true, $enabled, false ) . ' /> ';
		echo esc_html__( 'Scan daily and generate missing ThumbHashes', 'avif-local-support' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Uses the same schedule time as AVIF conversion.', 'avif-local-support' ) . '</p>';
	}

	public function renderLqipFadeField(): void {
		$value = (bool) get_option( 'aviflosu_lqip_fade', true );
		echo '<label for="aviflosu_lqip_fade">';
		echo '<input id="aviflosu_lqip_fade" type="checkbox" name="aviflosu_lqip_fade" value="1" ' . checked( true, $value, false ) . ' /> ';
		echo esc_html__( 'Fade in loaded images', 'avif-local-support' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Adds a smooth transition effect when the full image loads over the placeholder.', 'avif-local-support' ) . '</p>';
	}

	public function renderThumbHashSizeField(): void {
		$value   = (int) get_option( 'aviflosu_thumbhash_size', 100 );
		$options = \Ddegner\AvifLocalSupport\ThumbHash::SIZE_OPTIONS;
		echo '<select id="aviflosu_thumbhash_size" name="aviflosu_thumbhash_size">';
		foreach ( $options as $size => $label ) {
			echo '<option value="' . esc_attr( (string) $size ) . '" ' . selected( $size, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Larger sizes preserve more detail but generate slightly larger hashes.', 'avif-local-support' ) . '</p>';
	}

	public function sanitizeThumbHashSize( $value ): int {
		$size       = (int) ( $value ?? 100 );
		$validSizes = array_keys( \Ddegner\AvifLocalSupport\ThumbHash::SIZE_OPTIONS );
		return in_array( $size, $validSizes, true ) ? $size : 100;
	}

	/**
	 * Get the option group name for use in settings_fields().
	 */
	public static function getOptionGroup(): string {
		return self::OPTION_GROUP;
	}

	/**
	 * Get the page slug for use in do_settings_fields().
	 */
	public static function getPageSlug(): string {
		return self::PAGE_SLUG;
	}
}
