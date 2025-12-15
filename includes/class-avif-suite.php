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
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this->settings, 'register'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('rest_api_init', array($this->restController, 'register'));
		add_filter('plugin_action_links_' . plugin_basename(\AVIFLOSU_PLUGIN_FILE), array($this, 'add_settings_link'));
		add_action('admin_post_aviflosu_upload_test', array($this, 'handle_upload_test'));
		add_action('admin_post_aviflosu_reset_defaults', array($this, 'handle_reset_defaults'));

		// Features
		if ((bool) get_option('aviflosu_enable_support', true)) {
			$this->support->init();
		}
		// Always init converter so schedule/on-demand are available
		$this->converter->init();

		// Allow AVIF uploads
		add_filter(
			'upload_mimes',
			function (array $mimes): array {
				$mimes['avif'] = 'image/avif';
				return $mimes;
			}
		);
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

		wp_enqueue_style('avif-local-support-admin', $base . 'assets/admin.css', array(), $cssVer);
		wp_enqueue_script('avif-local-support-admin', $base . 'assets/admin.js', array('wp-api-fetch'), $jsVer, true);

		$data = array(
			'restUrl' => esc_url_raw(rest_url()),
			'restNonce' => wp_create_nonce('wp_rest'),
		);
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
			array($this, 'render_admin_page')
		);
	}

	public function render_admin_page(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'avif-local-support'));
		}

		// Gather data for templates
		ob_start();
		try {
			$system_status = $this->diagnostics->getSystemStatus();
		} catch (\Throwable $e) {
			$system_status = array();
		}
		ob_end_clean();

		ob_start();
		try {
			$stats = $this->diagnostics->computeMissingCounts();
		} catch (\Throwable $e) {
			$stats = array(
				'total_jpegs' => 0,
				'existing_avifs' => 0,
				'missing_avifs' => 0,
			);
		}
		ob_end_clean();

		$settings = array(
			'engine_mode' => (string) get_option('aviflosu_engine_mode', 'auto'),
			'convert_on_upload' => (bool) get_option('aviflosu_convert_on_upload', true),
			'schedule_enabled' => (bool) get_option('aviflosu_convert_via_schedule', true),
			'schedule_time' => (string) get_option('aviflosu_schedule_time', '01:00'),
			'frontend_enabled' => (bool) get_option('aviflosu_enable_support', true),
		);

		// Check for test upload results
		$test_id = 0;
		$test_results = null;
		$view_nonce = (string) (filter_input(INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
		$upload_id_raw = (string) (filter_input(INPUT_GET, 'avif-local-support-upload-id', FILTER_SANITIZE_NUMBER_INT) ?? '');
		if ($view_nonce !== '' && wp_verify_nonce($view_nonce, 'aviflosu_view_results')) {
			$test_id = absint($upload_id_raw);
			if ($test_id > 0) {
				$attachment = get_post($test_id);
				if ($attachment && $attachment->post_type === 'attachment') {
					$test_results = $this->converter->convertAttachmentNow($test_id);
				}
			}
		}

		// Render template with data
		$this->render_template(
			'admin/page',
			array(
				'system_status' => $system_status,
				'stats' => $stats,
				'settings' => $settings,
				'logger' => $this->logger,
				'test_id' => $test_id,
				'test_results' => $test_results,
			)
		);
	}

	/**
	 * Render a template file with the given data.
	 *
	 * @param string $template Template path relative to templates/ directory (without .php).
	 * @param array  $data Data to pass to the template.
	 */
	private function render_template(string $template, array $data = array()): void
	{
		$file = \AVIFLOSU_PLUGIN_DIR . 'templates/' . $template . '.php';
		if (file_exists($file)) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Safe: controlled input, template isolation
			extract($data, EXTR_SKIP);
			include $file;
		}
	}



	public function handle_upload_test(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do this.', 'avif-local-support'));
		}
		check_admin_referer('aviflosu_upload_test');

		// Build a sanitized, validated view of the uploaded file entry
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading from $_FILES; individual fields are sanitized below
		$rawFile = isset($_FILES['avif_local_support_test_file']) && is_array($_FILES['avif_local_support_test_file']) ? $_FILES['avif_local_support_test_file'] : array();
		$fileArray = array(
			'name' => isset($rawFile['name']) ? sanitize_file_name((string) $rawFile['name']) : '',
			'tmp_name' => isset($rawFile['tmp_name']) ? (string) $rawFile['tmp_name'] : '',
			'error' => isset($rawFile['error']) ? (int) $rawFile['error'] : UPLOAD_ERR_NO_FILE,
			'size' => isset($rawFile['size']) ? (int) $rawFile['size'] : 0,
		);
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

		$fileType = wp_check_filetype_and_ext(
			$tmpName,
			$originalName,
			array(
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg',
			)
		);
		if (empty($fileType['ext']) || !\in_array($fileType['ext'], array('jpg', 'jpeg'), true)) {
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
		\wp_safe_redirect(
			\add_query_arg(
				array(
					'avif-local-support-upload-id' => (string) $attachment_id,
					'_wpnonce' => $view_nonce,
				),
				\admin_url('options-general.php?page=avif-local-support#tools')
			)
		);
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
	 *
	 * @return array<int, array{path:string,version:string,avif:bool}>
	 */
	private function detect_cli_binaries(): array
	{
		return $this->diagnostics->detectCliBinaries();
	}

	/**
	 * Add a log entry
	 */
	public function add_log(string $status, string $message, array $details = array()): void
	{
		$this->logger->addLog($status, $message, $details);
	}
}
