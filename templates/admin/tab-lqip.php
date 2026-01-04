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
	<div class="metabox-holder">

		<form action="options.php" method="post">
			<?php settings_fields( 'aviflosu_beta_settings' ); ?>

			<div class="postbox">
				<h2 class="avif-header"><span><?php esc_html_e( 'Serve LQIP files', 'avif-local-support' ); ?></span>
				</h2>
				<div class="inside">

					<table class="form-table" role="presentation">
						<?php do_settings_fields( 'avif-local-support', 'aviflosu_beta' ); ?>
					</table>
				</div>
			</div>

			<div class="postbox">
				<h2 class="avif-header"><span><?php esc_html_e( 'Conversion Settings', 'avif-local-support' ); ?></span>
				</h2>
				<div class="inside">
					<table class="form-table" role="presentation">
						<?php do_settings_fields( 'avif-local-support', 'aviflosu_lqip_conversion' ); ?>
					</table>
				</div>
			</div>

			<div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
				<?php submit_button( '', 'primary', 'submit', false ); ?>
			</div>
		</form>
	</div>
</div>