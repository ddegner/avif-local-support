<?php
/**
 * Tools tab template.
 *
 * @package Ddegner\AvifLocalSupport
 * @var array $system_status System diagnostics data
 * @var array $stats AVIF counts
 * @var array $logs Conversion logs
 * @var array $settings Plugin settings
 * @var \Ddegner\AvifLocalSupport\Logger $logger Logger instance
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template local variables.
defined( 'ABSPATH' ) || exit;

// Simple status helper using native text labels.
$badge = static function ( $state, string $yes = 'Yes', string $no = 'No', string $unknown = 'Unknown' ): string {
	$s = \is_string( $state ) ? strtolower( $state ) : ( $state ? 'yes' : 'no' );
	if ( 'unknown' === $s ) {
		return esc_html( $unknown );
	}
	$ok = in_array( $s, array( 'yes', 'full', 'partial' ), true );
	return esc_html( $ok ? $yes : $no );
};

// Extract values from system status.
$engine_mode       = $settings['engine_mode'] ?? 'auto';
$convert_on_upload = $settings['convert_on_upload'] ?? true;
$schedule_enabled  = $settings['schedule_enabled'] ?? true;
$schedule_time     = $settings['schedule_time'] ?? '01:00';
$frontend_enabled  = $settings['frontend_enabled'] ?? true;
$playground_quality = max( 0, min( 100, (int) get_option( 'aviflosu_quality', 83 ) ) );
$playground_speed = max( 0, min( 8, (int) get_option( 'aviflosu_speed', 0 ) ) );
$playground_subsampling = (string) get_option( 'aviflosu_subsampling', '420' );
if ( ! in_array( $playground_subsampling, array( '420', '422', '444' ), true ) ) {
	$playground_subsampling = '420';
}
$playground_bit_depth = (string) get_option( 'aviflosu_bit_depth', '8' );
if ( ! in_array( $playground_bit_depth, array( '8', '10', '12' ), true ) ) {
	$playground_bit_depth = '8';
}
$playground_engine_mode = (string) get_option( 'aviflosu_engine_mode', 'auto' );
if ( ! in_array( $playground_engine_mode, array( 'auto', 'cli', 'imagick', 'gd' ), true ) ) {
	$playground_engine_mode = 'auto';
}
$playground_size_labels = array(
	'thumbnail'    => __( 'Thumbnail', 'avif-local-support' ),
	'medium'       => __( 'Medium', 'avif-local-support' ),
	'medium_large' => __( 'Medium Large', 'avif-local-support' ),
	'large'        => __( 'Large', 'avif-local-support' ),
);
$playground_sizes = array();
$playground_additional_sizes = function_exists( 'wp_get_additional_image_sizes' ) ? wp_get_additional_image_sizes() : array();
foreach ( (array) get_intermediate_image_sizes() as $playground_size_name ) {
	$playground_width  = 0;
	$playground_height = 0;
	if ( isset( $playground_additional_sizes[ $playground_size_name ] ) ) {
		$playground_size_data = $playground_additional_sizes[ $playground_size_name ];
		$playground_width     = (int) ( $playground_size_data['width'] ?? 0 );
		$playground_height    = (int) ( $playground_size_data['height'] ?? 0 );
	} else {
		$playground_width  = (int) get_option( "{$playground_size_name}_size_w", 0 );
		$playground_height = (int) get_option( "{$playground_size_name}_size_h", 0 );
	}
	if ( $playground_width <= 0 && $playground_height <= 0 ) {
		continue;
	}
	$playground_label = $playground_size_labels[ $playground_size_name ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $playground_size_name ) );
	if ( $playground_width > 0 && $playground_height > 0 ) {
		$playground_dimensions = sprintf( '%d×%d', $playground_width, $playground_height );
	} elseif ( $playground_width > 0 ) {
		/* translators: %d: pixel width */
		$playground_dimensions = sprintf( __( '%dpx wide', 'avif-local-support' ), $playground_width );
	} else {
		/* translators: %d: pixel height */
		$playground_dimensions = sprintf( __( '%dpx tall', 'avif-local-support' ), $playground_height );
	}
	$playground_sizes[ $playground_size_name ] = array(
		'label'      => $playground_label,
		'dimensions' => $playground_dimensions,
	);
}
if ( empty( $playground_sizes ) ) {
	$playground_sizes['large'] = array(
		'label'      => __( 'Large', 'avif-local-support' ),
		'dimensions' => '1024px wide',
	);
}
$playground_default_size = '';
foreach ( array( 'large', 'medium_large', 'medium', 'thumbnail' ) as $playground_preferred_size ) {
	if ( isset( $playground_sizes[ $playground_preferred_size ] ) ) {
		$playground_default_size = $playground_preferred_size;
		break;
	}
}
if ( '' === $playground_default_size ) {
	$playground_default_size = (string) array_key_first( $playground_sizes );
}

$auto_first_attempt = (string) ( $system_status['auto_first_attempt'] ?? 'none' );
$auto_has_fallback  = ! empty( $system_status['auto_has_fallback'] );
$avif_support_level = (string) ( $system_status['avif_support_level'] ?? ( ! empty( $system_status['avif_support'] ) ? 'yes' : 'no' ) );
$auto_first_label   = match ( $auto_first_attempt ) {
	'cli' => esc_html__( 'CLI (ImageMagick command-line)', 'avif-local-support' ),
	'imagick' => esc_html__( 'Imagick (PHP extension)', 'avif-local-support' ),
	'gd' => esc_html__( 'GD (imageavif)', 'avif-local-support' ),
	default => esc_html__( 'None', 'avif-local-support' ),
};
$format_avif_stat = static function ( $value ): string {
	return is_numeric( $value ) ? (string) (int) $value : '...';
};
$last_run = is_array( $settings['last_run'] ?? null ) ? (array) $settings['last_run'] : array();
$last_run_status = (string) ( $last_run['status'] ?? 'none' );
$last_run_started = isset( $last_run['started_at'] ) ? (int) $last_run['started_at'] : 0;
$last_run_ended = isset( $last_run['ended_at'] ) ? (int) $last_run['ended_at'] : 0;
$last_run_processed = isset( $last_run['processed'] ) ? (int) $last_run['processed'] : 0;
?>
<div id="avif-local-support-tab-tools" class="avif-local-support-tab">
	<div class="avif-settings-form avif-tools-layout">
		<section class="avif-tools-section">
			<h3><?php esc_html_e( 'AVIF Tools', 'avif-local-support' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Generate or remove AVIF files for existing JPEG media.', 'avif-local-support' ); ?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: engine mode 2: quality 3: speed 4: schedule status 5: upload conversion status */
						__( 'Current mode: Engine %1$s, Quality %2$d, Speed %3$d, Schedule %4$s, Upload conversion %5$s', 'avif-local-support' ),
						$engine_mode,
						(int) ( $settings['quality'] ?? 83 ),
						(int) ( $settings['speed'] ?? 0 ),
						$schedule_enabled ? __( 'On', 'avif-local-support' ) : __( 'Off', 'avif-local-support' ),
						$convert_on_upload ? __( 'On', 'avif-local-support' ) : __( 'Off', 'avif-local-support' )
					)
				);
				?>
			</p>
			<table id="avif-local-support-stats" class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'JPEG Files', 'avif-local-support' ); ?></th>
						<td><span id="avif-local-support-total-jpegs"><?php echo esc_html( $format_avif_stat( $stats['total_jpegs'] ?? null ) ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'With AVIF', 'avif-local-support' ); ?></th>
						<td><span id="avif-local-support-existing-avifs"><?php echo esc_html( $format_avif_stat( $stats['existing_avifs'] ?? null ) ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Without AVIF', 'avif-local-support' ); ?></th>
						<td><span id="avif-local-support-missing-avifs"><?php echo esc_html( $format_avif_stat( $stats['missing_avifs'] ?? null ) ); ?></span></td>
					</tr>
				</tbody>
			</table>
			<p id="avif-local-support-stats-loading" class="description">
				<span class="spinner is-active avif-spinner-inline"></span>
				<?php esc_html_e( 'Loading AVIF stats...', 'avif-local-support' ); ?>
			</p>

			<div class="avif-actions-row">
				<button type="button" class="button button-primary" id="avif-local-support-convert-now"><?php esc_html_e( 'Generate Missing AVIF', 'avif-local-support' ); ?></button>
				<button type="button" class="button hidden" id="avif-local-support-stop-convert"><?php esc_html_e( 'Stop', 'avif-local-support' ); ?></button>
				<button type="button" class="button button-secondary" id="avif-local-support-delete-avifs"><?php esc_html_e( 'Delete All AVIF', 'avif-local-support' ); ?></button>
				<button type="button" class="button" id="avif-local-support-open-missing-files"><?php esc_html_e( 'Show Files Without AVIF', 'avif-local-support' ); ?></button>
			</div>

			<div id="avif-local-support-result" class="avif-result-row hidden">
				<span class="spinner" id="avif-local-support-spinner"></span>
				<span id="avif-local-support-status" class="description"></span>
				<span id="avif-local-support-convert-progress" class="description hidden">
					<strong><?php esc_html_e( 'Progress:', 'avif-local-support' ); ?></strong>
					<span id="avif-local-support-progress-avifs">0</span> / <span id="avif-local-support-progress-jpegs">0</span>
					<?php esc_html_e( 'AVIF files created', 'avif-local-support' ); ?>
				</span>
			</div>

		</section>

			<section class="avif-tools-section avif-tools-section-playground">
				<h3><?php esc_html_e( 'AVIF Settings Playground', 'avif-local-support' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Upload one JPEG, pick a WordPress image size for preview generation, then compare JPEG and AVIF while tuning settings.', 'avif-local-support' ); ?>
				</p>
				<form id="avif-local-support-playground-upload-form" action="#" method="post" enctype="multipart/form-data" class="avif-test-form">
					<input type="file" id="avif-local-support-playground-file" name="avif_local_support_test_file" accept="image/jpeg" required />
					<div class="avif-playground-upload-row">
						<label for="avif-local-support-playground-size"><?php esc_html_e( 'Preview Size', 'avif-local-support' ); ?></label>
						<select id="avif-local-support-playground-size" name="avif_local_support_playground_size">
							<?php foreach ( $playground_sizes as $playground_size_name => $playground_size ) : ?>
								<?php
								$playground_option_label = (string) $playground_size['label'];
								$playground_option_dims  = (string) ( $playground_size['dimensions'] ?? '' );
								if ( '' !== $playground_option_dims ) {
									$playground_option_label .= ' (' . $playground_option_dims . ')';
								}
								?>
								<option value="<?php echo esc_attr( $playground_size_name ); ?>" <?php selected( $playground_size_name, $playground_default_size ); ?>>
									<?php echo esc_html( $playground_option_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="avif-actions-row">
						<button type="submit" class="button button-primary" id="avif-local-support-playground-upload-submit"><?php esc_html_e( 'Load Playground Image', 'avif-local-support' ); ?></button>
						<span class="spinner avif-spinner-inline" id="avif-local-support-playground-upload-spinner"></span>
						<span id="avif-local-support-playground-upload-status" class="description"></span>
					</div>
				</form>

			<div id="avif-local-support-playground-panel" class="hidden">
				<div class="avif-playground-controls">
					<div class="avif-playground-controls-grid">
						<label for="avif-local-support-playground-quality"><?php esc_html_e( 'Quality', 'avif-local-support' ); ?></label>
						<div class="avif-playground-range-wrap">
							<input type="range" id="avif-local-support-playground-quality" min="0" max="100" value="<?php echo esc_attr( (string) $playground_quality ); ?>" />
							<span id="avif-local-support-playground-quality-value"><?php echo esc_html( (string) $playground_quality ); ?></span>
						</div>

						<label for="avif-local-support-playground-speed"><?php esc_html_e( 'Speed', 'avif-local-support' ); ?></label>
						<div class="avif-playground-range-wrap">
							<input type="range" id="avif-local-support-playground-speed" min="0" max="8" value="<?php echo esc_attr( (string) $playground_speed ); ?>" />
							<span id="avif-local-support-playground-speed-value"><?php echo esc_html( (string) $playground_speed ); ?></span>
						</div>

						<label for="avif-local-support-playground-subsampling"><?php esc_html_e( 'Chroma', 'avif-local-support' ); ?></label>
						<select id="avif-local-support-playground-subsampling">
							<option value="420" <?php selected( '420', $playground_subsampling ); ?>>4:2:0</option>
							<option value="422" <?php selected( '422', $playground_subsampling ); ?>>4:2:2</option>
							<option value="444" <?php selected( '444', $playground_subsampling ); ?>>4:4:4</option>
						</select>

						<label for="avif-local-support-playground-bit-depth"><?php esc_html_e( 'Bit depth', 'avif-local-support' ); ?></label>
						<select id="avif-local-support-playground-bit-depth">
							<option value="8" <?php selected( '8', $playground_bit_depth ); ?>>8-bit</option>
							<option value="10" <?php selected( '10', $playground_bit_depth ); ?>>10-bit</option>
							<option value="12" <?php selected( '12', $playground_bit_depth ); ?>>12-bit</option>
						</select>

						<label for="avif-local-support-playground-engine-mode"><?php esc_html_e( 'Engine', 'avif-local-support' ); ?></label>
						<select id="avif-local-support-playground-engine-mode">
							<option value="auto" <?php selected( 'auto', $playground_engine_mode ); ?>><?php esc_html_e( 'Auto', 'avif-local-support' ); ?></option>
							<option value="cli" <?php selected( 'cli', $playground_engine_mode ); ?>><?php esc_html_e( 'CLI', 'avif-local-support' ); ?></option>
							<option value="imagick" <?php selected( 'imagick', $playground_engine_mode ); ?>><?php esc_html_e( 'Imagick', 'avif-local-support' ); ?></option>
							<option value="gd" <?php selected( 'gd', $playground_engine_mode ); ?>><?php esc_html_e( 'GD', 'avif-local-support' ); ?></option>
						</select>
					</div>

					<div class="avif-actions-row">
						<button type="button" class="button button-primary" id="avif-local-support-playground-refresh"><?php esc_html_e( 'Update AVIF Preview', 'avif-local-support' ); ?></button>
						<button type="button" class="button button-secondary" id="avif-local-support-playground-apply-settings"><?php esc_html_e( 'Use These Settings Plugin-Wide', 'avif-local-support' ); ?></button>
						<span class="spinner avif-spinner-inline" id="avif-local-support-playground-preview-spinner"></span>
						<span id="avif-local-support-playground-preview-status" class="description"></span>
					</div>
				</div>

				<div class="avif-playground-preview-card">
					<h4 id="avif-local-support-playground-preview-title"><?php esc_html_e( 'JPEG', 'avif-local-support' ); ?></h4>
					<div class="avif-playground-view-switch" role="group" aria-label="<?php esc_attr_e( 'Preview format', 'avif-local-support' ); ?>">
						<button type="button" class="button button-small is-primary" id="avif-local-support-playground-view-jpg"><?php esc_html_e( 'Show JPG', 'avif-local-support' ); ?></button>
						<button type="button" class="button button-small" id="avif-local-support-playground-view-avif"><?php esc_html_e( 'Show AVIF', 'avif-local-support' ); ?></button>
					</div>
					<p id="avif-local-support-playground-size-summary" class="description"></p>
					<div class="avif-playground-preview-frame">
						<img id="avif-local-support-playground-preview-image" src="" alt="<?php esc_attr_e( 'Playground preview image', 'avif-local-support' ); ?>" />
					</div>
				</div>

				<div class="avif-actions-row">
					<a id="avif-local-support-playground-download-jpeg" class="button" href="#" target="_blank" rel="noopener" download><?php esc_html_e( 'Download JPG', 'avif-local-support' ); ?></a>
					<a id="avif-local-support-playground-download-avif" class="button" href="#" target="_blank" rel="noopener" download><?php esc_html_e( 'Download AVIF', 'avif-local-support' ); ?></a>
				</div>
			</div>
		</section>

		<section class="avif-tools-section">
			<h3><?php esc_html_e( 'LQIP Tools', 'avif-local-support' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Manage LQIP for existing media items.', 'avif-local-support' ); ?>
			</p>

			<table id="aviflosu-thumbhash-stats" class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Media Items', 'avif-local-support' ); ?></th>
						<td><span id="aviflosu-thumbhash-total">-</span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'With LQIP', 'avif-local-support' ); ?></th>
						<td><span id="aviflosu-thumbhash-with">-</span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Without LQIP', 'avif-local-support' ); ?></th>
						<td><span id="aviflosu-thumbhash-without">-</span></td>
					</tr>
				</tbody>
			</table>

			<div class="avif-actions-row">
				<button type="button" id="aviflosu-thumbhash-generate" class="button button-primary"><?php esc_html_e( 'Generate Missing LQIPs', 'avif-local-support' ); ?></button>
				<button type="button" id="aviflosu-thumbhash-stop" class="button hidden"><?php esc_html_e( 'Stop LQIP Generation', 'avif-local-support' ); ?></button>
				<button type="button" id="aviflosu-thumbhash-delete" class="button button-secondary"><?php esc_html_e( 'Delete All LQIPs', 'avif-local-support' ); ?></button>
			</div>

			<div id="aviflosu-thumbhash-result" class="avif-result-row hidden">
				<span class="spinner" id="aviflosu-thumbhash-spinner"></span>
				<span id="aviflosu-thumbhash-status" class="description"></span>
				<span id="aviflosu-thumbhash-progress" class="description hidden">
					<strong><?php esc_html_e( 'Progress:', 'avif-local-support' ); ?></strong>
					<span id="aviflosu-thumbhash-progress-with">0</span> / <span id="aviflosu-thumbhash-progress-total">0</span>
					<?php esc_html_e( 'LQIP created', 'avif-local-support' ); ?>
				</span>
			</div>
		</section>

			<section class="avif-tools-section">
				<h3><?php esc_html_e( 'Logs', 'avif-local-support' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'View recent conversion logs including errors, settings used, and performance metrics.', 'avif-local-support' ); ?>
			</p>
			<div class="avif-actions-row">
				<button type="button" class="button" id="avif-local-support-refresh-logs"><?php esc_html_e( 'Refresh Logs', 'avif-local-support' ); ?></button>
				<button type="button" class="button" id="avif-local-support-copy-logs"><?php esc_html_e( 'Copy Logs', 'avif-local-support' ); ?></button>
				<button type="button" class="button" id="avif-local-support-clear-logs"><?php esc_html_e( 'Clear Logs', 'avif-local-support' ); ?></button>
				<label class="avif-logs-filter"><input type="checkbox" id="avif-local-support-logs-only-errors" />
					<?php esc_html_e( 'Show only errors', 'avif-local-support' ); ?></label>
				<label class="avif-logs-filter"><input type="checkbox" id="avif-local-support-logs-compact" />
					<?php esc_html_e( 'Compact mode', 'avif-local-support' ); ?></label>
				<input
					type="search"
					id="avif-local-support-logs-search"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Search filename, engine, or error…', 'avif-local-support' ); ?>"
					aria-label="<?php esc_attr_e( 'Search logs', 'avif-local-support' ); ?>"
				/>
				<span class="spinner avif-spinner-inline" id="avif-local-support-logs-spinner"></span>
				<span id="avif-local-support-copy-status" class="description avif-status-success hidden"><?php esc_html_e( 'Copied!', 'avif-local-support' ); ?></span>
			</div>
			<div class="avif-log-chips" id="avif-local-support-logs-chips">
				<button type="button" class="button button-small avif-log-chip is-active" data-status="all" data-label="<?php esc_attr_e( 'All', 'avif-local-support' ); ?>"><?php esc_html_e( 'All', 'avif-local-support' ); ?> (0)</button>
				<button type="button" class="button button-small avif-log-chip" data-status="error" data-label="<?php esc_attr_e( 'Errors', 'avif-local-support' ); ?>"><?php esc_html_e( 'Errors', 'avif-local-support' ); ?> (0)</button>
				<button type="button" class="button button-small avif-log-chip" data-status="warning" data-label="<?php esc_attr_e( 'Warnings', 'avif-local-support' ); ?>"><?php esc_html_e( 'Warnings', 'avif-local-support' ); ?> (0)</button>
				<button type="button" class="button button-small avif-log-chip" data-status="success" data-label="<?php esc_attr_e( 'Success', 'avif-local-support' ); ?>"><?php esc_html_e( 'Success', 'avif-local-support' ); ?> (0)</button>
				<button type="button" class="button button-small avif-log-chip" data-status="info" data-label="<?php esc_attr_e( 'Info', 'avif-local-support' ); ?>"><?php esc_html_e( 'Info', 'avif-local-support' ); ?> (0)</button>
			</div>
			<div id="avif-local-support-logs-content" class="avif-logs-container">
				<?php
				if ( isset( $logger ) && method_exists( $logger, 'renderLogsContent' ) ) {
					$logger->renderLogsContent();
				}
				?>
			</div>
		</section>

		<section class="avif-tools-section">
			<h3><?php esc_html_e( 'Conversion Insights', 'avif-local-support' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Live run health and queue details for AVIF conversion.', 'avif-local-support' ); ?></p>
			<table class="widefat striped">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Current status', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-status">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Run started', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-started">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Last heartbeat', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-heartbeat">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Progress', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-progress">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Conversion throughput', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-throughput">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'ETA', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-eta">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Output quality summary', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-quality">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Result counters', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-results">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Size impact', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-size">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Job health', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-health">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Queue state', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-queue">-</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Last error', 'avif-local-support' ); ?></th><td id="avif-local-support-insight-error">-</td></tr>
				</tbody>
			</table>
		</section>

		<section class="avif-tools-section">
			<h3><?php esc_html_e( 'Server Support', 'avif-local-support' ); ?></h3>
			<div class="avif-actions-row">
				<button type="button" class="button" id="avif-local-support-copy-support"><?php esc_html_e( 'Copy Server Diagnostics', 'avif-local-support' ); ?></button>
				<span id="avif-local-support-copy-support-status" class="description avif-status-success hidden"><?php esc_html_e( 'Copied!', 'avif-local-support' ); ?></span>
			</div>

			<p class="description">
				<?php esc_html_e( 'This panel explains what your server supports, what AVIF Local Support will do, and what to check when something is unexpected.', 'avif-local-support' ); ?>
			</p>

			<div class="avif-support-panel">
				<h3><?php esc_html_e( 'Summary', 'avif-local-support' ); ?></h3>
				<?php
				$mode_explain = 'auto' === $engine_mode
					? esc_html__( 'Auto: the plugin will try engines in order (CLI -> Imagick -> GD) until one succeeds.', 'avif-local-support' )
					: esc_html__( 'Forced: the plugin will use only the selected engine (no fallback).', 'avif-local-support' );
				?>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'AVIF conversion available', 'avif-local-support' ); ?></strong></td>
							<td><?php echo wp_kses_post( $badge( $avif_support_level, 'Yes', 'No', 'Unconfirmed' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Conversion engine mode', 'avif-local-support' ); ?></strong></td>
							<td>
								<code><?php echo esc_html( $engine_mode ); ?></code>
								<div class="description"><?php echo esc_html( $mode_explain ); ?></div>
							</td>
						</tr>
						<?php if ( 'auto' === $engine_mode ) : ?>
							<tr>
								<td><strong><?php esc_html_e( 'First engine in Auto mode', 'avif-local-support' ); ?></strong></td>
								<td>
									<?php echo esc_html( $auto_first_label ); ?>
									<?php if ( $auto_has_fallback ) : ?>
										<span class="description">(<?php esc_html_e( 'fallbacks available', 'avif-local-support' ); ?>)</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php else : ?>
							<tr>
								<td><strong><?php esc_html_e( 'Fallback behavior', 'avif-local-support' ); ?></strong></td>
								<td><?php esc_html_e( 'No fallback in forced mode.', 'avif-local-support' ); ?></td>
							</tr>
						<?php endif; ?>
						<tr>
							<td><strong><?php esc_html_e( 'Convert uploads to AVIF', 'avif-local-support' ); ?></strong></td>
							<td><?php echo wp_kses_post( $badge( $convert_on_upload, 'Enabled', 'Disabled' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Daily background conversion', 'avif-local-support' ); ?></strong></td>
							<td>
								<?php echo wp_kses_post( $badge( $schedule_enabled, 'Enabled', 'Disabled' ) ); ?>
								<?php if ( $schedule_enabled ) : ?>
									<span class="description">(
									<?php
									/* translators: %s: Schedule time (e.g., "01:00") */
									echo esc_html( sprintf( __( 'scheduled around %s', 'avif-local-support' ), $schedule_time ) );
									?>
									)</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Front-end AVIF delivery', 'avif-local-support' ); ?></strong></td>
							<td>
								<?php echo wp_kses_post( $badge( $frontend_enabled, 'Enabled', 'Disabled' ) ); ?>
								<div class="description">
									<?php esc_html_e( 'When enabled, the plugin wraps JPEG outputs in a <picture> tag with an AVIF <source> first.', 'avif-local-support' ); ?>
								</div>
							</td>
						</tr>
					</tbody>
				</table>

					<h3 class="avif-engine-details-heading"><?php esc_html_e( 'Engine Details', 'avif-local-support' ); ?></h3>
				<?php require __DIR__ . '/partials/engine-details.php'; ?>

				<details class="avif-support-details">
					<summary><strong><?php esc_html_e( 'Environment', 'avif-local-support' ); ?></strong></summary>
					<div class="avif-support-details-body">
						<?php
						$php_user = (string) ( $system_status['current_user'] ?? @get_current_user() );
						$ob       = (string) ( $system_status['open_basedir'] ?? ini_get( 'open_basedir' ) );
						$df       = (string) ( $system_status['disable_functions'] ?? ini_get( 'disable_functions' ) );
						?>
						<table class="widefat striped">
							<tbody>
								<tr>
									<td><strong><?php esc_html_e( 'PHP Version', 'avif-local-support' ); ?></strong></td>
									<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'WordPress Version', 'avif-local-support' ); ?></strong></td>
									<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'PHP SAPI', 'avif-local-support' ); ?></strong></td>
									<td><code><?php echo esc_html( $system_status['php_sapi'] ?? PHP_SAPI ); ?></code></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Current user', 'avif-local-support' ); ?></strong></td>
									<td>
										<code><?php echo esc_html( '' !== $php_user ? $php_user : '-' ); ?></code>
										<div class="description">
											<?php esc_html_e( 'This is the OS user PHP runs as; it must have write access to wp-content/uploads.', 'avif-local-support' ); ?>
										</div>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'open_basedir', 'avif-local-support' ); ?></strong></td>
									<td><?php echo '' !== $ob ? '<code class="avif-code-overflow">' . esc_html( $ob ) . '</code>' : '-'; ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'disable_functions', 'avif-local-support' ); ?></strong></td>
									<td><?php echo '' !== $df ? '<code class="avif-code-overflow">' . esc_html( $df ) . '</code>' : '-'; ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</details>
			</div>
		</section>

		<section class="avif-tools-section">
			<h3><?php esc_html_e( 'Reset Plugin Settings', 'avif-local-support' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Use this only if you want to return all AVIF and LQIP settings to defaults.', 'avif-local-support' ); ?>
			</p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('<?php esc_attr_e( 'Reset all plugin settings to default values?', 'avif-local-support' ); ?>');">
				<input type="hidden" name="action" value="aviflosu_reset_defaults" />
				<?php wp_nonce_field( 'aviflosu_reset_defaults', '_wpnonce', false, true ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Reset All Plugin Settings', 'avif-local-support' ); ?></button>
			</form>
		</section>
	</div>
	<div id="avif-local-support-missing-files-modal" class="avif-modal hidden" role="dialog" aria-modal="true" aria-labelledby="avif-local-support-missing-files-modal-title">
		<div class="avif-modal__backdrop" data-close-missing-files-modal></div>
		<div class="avif-modal__dialog" id="avif-local-support-missing-files-panel">
			<div class="avif-modal__header">
				<h3 id="avif-local-support-missing-files-modal-title"><?php esc_html_e( 'Files Without AVIF', 'avif-local-support' ); ?></h3>
				<button type="button" class="button" data-close-missing-files-modal><?php esc_html_e( 'Close', 'avif-local-support' ); ?></button>
			</div>
			<p class="description">
				<?php esc_html_e( 'Review JPEG files that do not currently have a generated AVIF companion.', 'avif-local-support' ); ?>
			</p>
			<div class="avif-actions-row">
				<button type="button" class="button button-secondary" id="avif-local-support-refresh-missing-files">
					<?php esc_html_e( 'Refresh List', 'avif-local-support' ); ?>
				</button>
			</div>
			<p id="avif-local-support-missing-files-status" class="description"></p>
			<div id="avif-local-support-missing-files-wrap" class="avif-logs-container hidden">
				<ul id="avif-local-support-missing-files-list" class="avif-binary-list"></ul>
			</div>
		</div>
	</div>
</div>
