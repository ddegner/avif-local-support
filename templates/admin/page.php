<?php
/**
 * Admin page main template.
 *
 * @package Ddegner\AvifLocalSupport
 * @var array $system_status System diagnostics data
 * @var array $stats AVIF counts
 * @var array $logs Conversion logs
 * @var array $settings Plugin settings
 */

defined( 'ABSPATH' ) || exit;

$support_level = (string) ( $system_status['avif_support_level'] ?? ( empty( $system_status['avif_support'] ) ? 'no' : 'yes' ) );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'AVIF Local Support', 'avif-local-support' ); ?></h1>

	<?php if ( $support_level === 'no' ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'AVIF support not available!', 'avif-local-support' ); ?></strong></p>
			<p><?php esc_html_e( 'This plugin requires either GD with AVIF support (imageavif) or ImageMagick with AVIF format support.', 'avif-local-support' ); ?>
			</p>
		</div>
	<?php elseif ( $support_level === 'unknown' ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'AVIF support is unconfirmed.', 'avif-local-support' ); ?></strong></p>
			<p><?php esc_html_e( 'The plugin can attempt conversion (usually via CLI), but AVIF capability could not be confirmed. Try the Tools → Upload Test and check Logs for details.', 'avif-local-support' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<a href="#settings" class="nav-tab nav-tab-active"
			id="avif-local-support-tab-link-settings"><?php esc_html_e( 'Settings', 'avif-local-support' ); ?></a>
		<a href="#tools" class="nav-tab"
			id="avif-local-support-tab-link-tools"><?php esc_html_e( 'Tools', 'avif-local-support' ); ?></a>
		<a href="#about" class="nav-tab"
			id="avif-local-support-tab-link-about"><?php esc_html_e( 'About', 'avif-local-support' ); ?></a>
	</h2>

	<?php
	require __DIR__ . '/tab-settings.php';
	require __DIR__ . '/tab-tools.php';
	require __DIR__ . '/tab-about.php';
	?>
</div>