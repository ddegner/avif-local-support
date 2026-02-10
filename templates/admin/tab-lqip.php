<?php
/**
 * LQIP features tab template.
 *
 * @package Ddegner\AvifLocalSupport
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template local variables.
defined( 'ABSPATH' ) || exit;
?>
<div id="avif-local-support-tab-lqip" class="avif-local-support-tab">
	<form action="options.php" method="post" class="avif-settings-form">
		<?php settings_fields( 'aviflosu_beta_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'LQIP', 'avif-local-support' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'LQIP means Low-Quality Image Placeholder. It shows a tiny preview while the full image loads, improving perceived speed.', 'avif-local-support' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php do_settings_fields( 'avif-local-support', 'aviflosu_beta' ); ?>
			<?php do_settings_fields( 'avif-local-support', 'aviflosu_lqip_basic' ); ?>
		</table>

		<details class="avif-support-details">
			<summary><?php esc_html_e( 'Advanced Settings', 'avif-local-support' ); ?></summary>
			<div class="avif-support-details-body">
				<table class="form-table" role="presentation">
					<?php do_settings_fields( 'avif-local-support', 'aviflosu_lqip_advanced' ); ?>
				</table>
			</div>
		</details>

		<div class="avif-actions-row">
			<?php submit_button( __( 'Save LQIP Settings', 'avif-local-support' ), 'primary', 'submit', false ); ?>
		</div>
	</form>
</div>
