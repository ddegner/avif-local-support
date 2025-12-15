<?php
/**
 * Engine details partial template.
 *
 * @package Ddegner\AvifLocalSupport
 * @var array $system_status System diagnostics data
 * @var callable $badge Badge helper function
 */

defined( 'ABSPATH' ) || exit;

// CLI details
$cli_detected     = isset( $system_status['cli_detected'] ) && is_array( $system_status['cli_detected'] ) ? $system_status['cli_detected'] : array();
$cli_proc_open    = ! empty( $system_status['cli_proc_open'] );
$cli_configured   = (string) ( $system_status['cli_configured_path'] ?? '' );
$cli_auto         = (string) ( $system_status['cli_auto_path'] ?? '' );
$cli_can_invoke   = ! empty( $system_status['cli_can_invoke'] );
$cli_has_avif_bin = ! empty( $system_status['cli_has_avif_binary'] );
$cli_will_attempt = ! empty( $system_status['cli_will_attempt'] );
$cli_effective    = $cli_configured !== '' ? $cli_configured : $cli_auto;
$cli_exists       = $cli_effective !== '' ? @file_exists( $cli_effective ) : false;
$cli_exec         = $cli_effective !== '' ? @is_executable( $cli_effective ) : false;

$engine_mode = $settings['engine_mode'] ?? 'auto';

$df            = (string) ( $system_status['disable_functions'] ?? ini_get( 'disable_functions' ) );
$df_list       = array_filter( array_map( 'trim', explode( ',', $df ) ) );
$exec_disabled = in_array( 'exec', $df_list, true );

$cli_summary = $cli_will_attempt ? esc_html__( 'Attempting', 'avif-local-support' ) : esc_html__( 'Skipped', 'avif-local-support' );
if ( $engine_mode === 'cli' ) {
	$cli_summary .= ' <span class="description">(' . esc_html__( 'forced', 'avif-local-support' ) . ')</span>';
}
?>

<!-- CLI Details -->
<details class="avif-support-details">
	<summary><strong><?php esc_html_e( 'CLI (ImageMagick command-line)', 'avif-local-support' ); ?></strong> —
		<?php echo $cli_summary; ?></summary>
	<div class="avif-support-details-body">
		<p class="description" style="margin-top:0;max-width:960px;">
			<?php esc_html_e( 'Used for conversion via proc_open() (no shell). This is usually fastest and most reliable if ImageMagick is installed with AVIF support.', 'avif-local-support' ); ?>
		</p>
		<table class="widefat striped" style="max-width:960px;">
			<tbody>
				<tr>
					<td style="width:260px;">
						<strong><?php esc_html_e( 'proc_open available', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( $cli_proc_open ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Usable in Auto mode', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( $cli_can_invoke, 'Yes', 'No' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Configured CLI path', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $cli_configured !== '' ? '<code>' . esc_html( $cli_configured ) . '</code>' : '-'; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Auto-detected CLI path', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $cli_auto !== '' ? '<code>' . esc_html( $cli_auto ) . '</code>' : '-'; ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Effective CLI path', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $cli_effective !== '' ? '<code>' . esc_html( $cli_effective ) . '</code>' : '-'; ?>
					</td>
				</tr>
				<?php if ( $cli_effective !== '' ) : ?>
					<tr>
						<td><strong><?php esc_html_e( 'Binary exists', 'avif-local-support' ); ?></strong></td>
						<td><?php echo $badge( (bool) $cli_exists ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Binary executable', 'avif-local-support' ); ?></strong></td>
						<td><?php echo $badge( (bool) $cli_exec ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<td><strong><?php esc_html_e( 'AVIF-capable CLI detected', 'avif-local-support' ); ?></strong></td>
					<td>
						<?php echo $badge( $cli_has_avif_bin, 'Yes (probe)', 'Unknown / No' ); ?>
						<div class="description" style="margin-top:4px;">
							<?php esc_html_e( 'This is a best-effort probe. A custom path might still work.', 'avif-local-support' ); ?>
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $cli_detected ) ) : ?>
			<div style="margin-top:8px;">
				<strong><?php esc_html_e( 'Detected ImageMagick binaries', 'avif-local-support' ); ?></strong>
				<ul style="margin:6px 0 0;padding-left:18px;">
					<?php
					foreach ( $cli_detected as $bin ) :
						$path = isset( $bin['path'] ) ? (string) $bin['path'] : '';
						$ver  = isset( $bin['version'] ) ? (string) $bin['version'] : '';
						$avif = ! empty( $bin['avif'] ) ? esc_html__( 'AVIF: yes', 'avif-local-support' ) : esc_html__( 'AVIF: no', 'avif-local-support' );
						?>
						<li><code><?php echo esc_html( $path ); ?></code><?php echo $ver !== '' ? ' — ' . esc_html( $ver ) : ''; ?>
							— <?php echo esc_html( $avif ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
			<button type="button" class="button"
				id="avif-local-support-run-magick-test"><?php esc_html_e( 'Run ImageMagick test', 'avif-local-support' ); ?></button>
			<span class="spinner" id="avif-local-support-magick-test-spinner" style="float:none;margin:0 6px;"></span>
			<span id="avif-local-support-magick-test-status" class="description"></span>
		</div>
		<pre id="avif-local-support-magick-test-output"
			style="display:none;max-width:960px;white-space:pre-wrap;background:#f6f7f7;padding:10px;border:1px solid #ccd0d4;margin-top:8px;"></pre>

		<?php if ( $exec_disabled ) : ?>
			<p class="description" style="margin-top:8px;">
				<strong><?php esc_html_e( 'Note:', 'avif-local-support' ); ?></strong>
				<?php esc_html_e( 'Your PHP has exec disabled. The test button uses exec, but conversions use proc_open—so conversion may still work.', 'avif-local-support' ); ?>
			</p>
		<?php endif; ?>
	</div>
</details>

<!-- Imagick Details -->
<?php
$imagick_will_attempt = ! empty( $system_status['imagick_will_attempt'] );
$im_summary           = $imagick_will_attempt ? esc_html__( 'Attempting', 'avif-local-support' ) : esc_html__( 'Skipped', 'avif-local-support' );
if ( $engine_mode === 'imagick' ) {
	$im_summary .= ' <span class="description">(' . esc_html__( 'forced', 'avif-local-support' ) . ')</span>';
}
?>
<details class="avif-support-details">
	<summary><strong><?php esc_html_e( 'Imagick (PHP extension)', 'avif-local-support' ); ?></strong> —
		<?php echo $im_summary; ?></summary>
	<div class="avif-support-details-body">
		<p class="description" style="margin-top:0;max-width:960px;">
			<?php esc_html_e( 'Used for conversion inside PHP. Great quality and better profile/metadata handling when ImageMagick has AVIF support.', 'avif-local-support' ); ?>
		</p>
		<table class="widefat striped" style="max-width:960px;">
			<tbody>
				<tr>
					<td style="width:260px;">
						<strong><?php esc_html_e( 'Imagick extension loaded', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['imagick_available'] ), 'Yes', 'No' ); ?></td>
				</tr>
				<?php if ( ! empty( $system_status['imagick_version'] ) ) : ?>
					<tr>
						<td><strong><?php esc_html_e( 'ImageMagick library version', 'avif-local-support' ); ?></strong></td>
						<td><code><?php echo esc_html( (string) $system_status['imagick_version'] ); ?></code></td>
					</tr>
				<?php endif; ?>
				<tr>
					<td><strong><?php esc_html_e( 'AVIF support (queryFormats)', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['imagick_avif_support'] ), 'Yes', 'No' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Usable in Auto mode', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['imagick_avif_support'] ), 'Yes', 'No' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</details>

<!-- GD Details -->
<?php
$gd_will_attempt = ! empty( $system_status['gd_will_attempt'] );
$gd_summary      = $gd_will_attempt ? esc_html__( 'Attempting', 'avif-local-support' ) : esc_html__( 'Skipped', 'avif-local-support' );
if ( $engine_mode === 'gd' ) {
	$gd_summary .= ' <span class="description">(' . esc_html__( 'forced', 'avif-local-support' ) . ')</span>';
}
?>
<details class="avif-support-details">
	<summary><strong><?php esc_html_e( 'GD (imageavif)', 'avif-local-support' ); ?></strong> — <?php echo $gd_summary; ?>
	</summary>
	<div class="avif-support-details-body">
		<p class="description" style="margin-top:0;max-width:960px;">
			<?php esc_html_e( 'Used as a fallback when imageavif() is available. Fast, but does not perform color management and may not preserve metadata.', 'avif-local-support' ); ?>
		</p>
		<table class="widefat striped" style="max-width:960px;">
			<tbody>
				<tr>
					<td style="width:260px;">
						<strong><?php esc_html_e( 'GD extension loaded', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['gd_available'] ), 'Yes', 'No' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'imageavif() available', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['gd_imageavif'] ), 'Yes', 'No' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'gd_info(): AVIF Support', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['gd_info_avif'] ), 'Yes', 'No' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Usable in Auto mode', 'avif-local-support' ); ?></strong></td>
					<td><?php echo $badge( ! empty( $system_status['gd_avif_support'] ), 'Yes', 'No' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description" style="margin-top:8px;">
			<strong><?php esc_html_e( 'Color management note:', 'avif-local-support' ); ?></strong>
			<?php esc_html_e( 'GD does not perform color management; non‑sRGB JPEGs may appear desaturated. For accurate color, enable Imagick.', 'avif-local-support' ); ?>
		</p>
	</div>
</details>