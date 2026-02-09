<?php
/**
 * Settings tab template.
 *
 * @package Ddegner\AvifLocalSupport
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template local variables.
defined( 'ABSPATH' ) || exit;
?>
<div id="avif-local-support-tab-settings" class="avif-local-support-tab active">
	<form action="options.php" method="post" class="avif-settings-form">
		<?php settings_fields( 'aviflosu_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'AVIF Settings', 'avif-local-support' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php do_settings_fields( 'avif-local-support', 'aviflosu_main' ); ?>
			<?php do_settings_fields( 'avif-local-support', 'aviflosu_conversion_basic' ); ?>
		</table>

		<details class="avif-support-details">
			<summary><?php esc_html_e( 'Advanced Settings', 'avif-local-support' ); ?></summary>
			<div class="avif-support-details-body">
				<table class="form-table" role="presentation">
					<?php do_settings_fields( 'avif-local-support', 'aviflosu_engine' ); ?>
					<?php do_settings_fields( 'avif-local-support', 'aviflosu_conversion_advanced' ); ?>
				</table>
			</div>
		</details>

		<div class="avif-actions-row">
			<?php submit_button( __( 'Save AVIF Settings', 'avif-local-support' ), 'primary', 'submit', false ); ?>
		</div>
	</form>
</div>
