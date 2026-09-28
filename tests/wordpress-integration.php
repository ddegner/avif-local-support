<?php
/**
 * Destructive regression cases for a disposable WordPress installation only.
 * AVIFLOSU_TEST_DATABASE=avif_review_example wp --path=/tmp/wordpress eval-file tests/wordpress-integration.php --use-include
 */
declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' )
	|| getenv( 'AVIFLOSU_TEST_DATABASE' ) !== DB_NAME || ! str_starts_with( DB_NAME, 'avif_review_' ) ) {
	throw new RuntimeException( 'Use a disposable avif_review_* database and set AVIFLOSU_TEST_DATABASE to its exact name.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
use Ddegner\AvifLocalSupport\AvifFile;
use Ddegner\AvifLocalSupport\AttachmentBatchRunner;
use Ddegner\AvifLocalSupport\Converter;
use Ddegner\AvifLocalSupport\ThumbHash;

$GLOBALS['avifReviewChecks'] = 0;
function avif_review_check( bool $passed, string $message ): void {
	if ( ! $passed ) { throw new RuntimeException( $message ); }
	++$GLOBALS['avifReviewChecks'];
	echo "PASS: $message\n";
}
function avif_review_jpeg( string $path, int $width, int $height, int $offset = 0 ): void {
	$image = imagecreatetruecolor( $width, $height );
	for ( $y = 0; $y < $height; ++$y ) {
		$color = imagecolorallocate( $image, ( $y + $offset ) % 256, ( 2 * $y + 60 ) % 256, ( 3 * $y + 80 ) % 256 );
		imageline( $image, 0, $y, $width - 1, $y, $color );
	}
	imagejpeg( $image, $path, 90 );
}
function avif_review_playground( string $source, string $name, string $size = 'thumbnail' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/aviflosu/v1/playground/create' );
	$request->set_file_params( array( 'avif_local_support_test_file' => array(
		'name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $source, 'error' => 0, 'size' => filesize( $source ),
	) ) );
	$request->set_param( 'playground_size', $size );
	return rest_do_request( $request );
}

wp_set_current_user( 1 );
update_option( 'aviflosu_convert_on_upload', false );
update_option( 'aviflosu_convert_via_schedule', false );
update_option( 'aviflosu_thumbhash_enabled', true );
update_option( 'aviflosu_speed', 8 );
update_option( 'aviflosu_quality', 70 );
add_filter( 'wp_image_editors', static fn() => array( 'WP_Image_Editor_GD' ) );
$uploads = wp_upload_dir();
$suffix = bin2hex( random_bytes( 5 ) );
$jpeg = $uploads['path'] . '/format-' . $suffix . '.jpg';
avif_review_jpeg( $jpeg, 640, 480 );
$id = wp_insert_attachment( array( 'post_title' => 'Format regression fixture', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $jpeg );
$formatFilter = static fn( $formats ) => array_merge( $formats, array( 'image/jpeg' => 'image/webp' ) );
add_filter( 'image_editor_output_format', $formatFilter );
$metadata = wp_generate_attachment_metadata( $id, $jpeg );
wp_update_attachment_metadata( $id, $metadata );
remove_filter( 'image_editor_output_format', $formatFilter );
$webpPaths = array( $uploads['basedir'] . '/' . $metadata['file'] );
foreach ( $metadata['sizes'] as $size ) { $webpPaths[] = dirname( $webpPaths[0] ) . '/' . $size['file']; }
$hashesBefore = array_map( static fn( $path ) => hash_file( 'sha256', $path ), $webpPaths );
avif_review_check( 'image/jpeg' === get_post_mime_type( $id ) && str_ends_with( $metadata['file'], '.webp' ), 'Core generated WebP files for a JPEG attachment' );
$response = rest_do_request( new WP_REST_Request( 'POST', '/aviflosu/v1/delete-all-avifs' ) );
clearstatcache();
avif_review_check( 200 === $response->get_status(), 'Delete-all route succeeds' );
avif_review_check( ! in_array( false, array_map( 'file_exists', $webpPaths ), true ), 'Delete-all preserves every real WebP file' );
avif_review_check( $hashesBefore === array_map( static fn( $path ) => hash_file( 'sha256', $path ), $webpPaths ), 'WebP bytes remain unchanged' );

$base = 'collision-' . $suffix;
$existing = $uploads['path'] . '/' . $base . '-playground.jpg';
avif_review_jpeg( $existing, 180, 160, 90 );
$existingId = wp_insert_attachment( array( 'post_title' => 'Existing JPEG fixture', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $existing );
wp_update_attachment_metadata( $existingId, wp_generate_attachment_metadata( $existingId, $existing ) );
$existingHash = hash_file( 'sha256', $existing );
$temp = wp_tempnam( $base . '.jpg' );
avif_review_jpeg( $temp, 640, 480 );
$dimensionCalls = array();
$qualityFilter = static function ( int $quality, string $mime, array $dimensions ) use ( &$dimensionCalls ): int {
	$dimensionCalls[] = $dimensions;
	return 75;
};
add_filter( 'wp_editor_set_quality', $qualityFilter, 10, 3 );
$response = avif_review_playground( $temp, $base . '.jpg' );
remove_filter( 'wp_editor_set_quality', $qualityFilter );
clearstatcache();
avif_review_check( 200 === $response->get_status(), 'Playground works with a required three-argument quality callback' );
avif_review_check( ! empty( $dimensionCalls ), 'Playground quality callback receives dimensions' );
avif_review_check( $existingHash === hash_file( 'sha256', $existing ), 'Playground preserves the existing JPEG bytes' );
avif_review_check( array( 180, 160 ) === array_slice( getimagesize( $existing ), 0, 2 ), 'Existing JPEG dimensions remain unchanged' );
$firstData = $response->get_data();
$firstToken = $firstData['token'] ?? '';
$temp = wp_tempnam( $base . '.jpg' );
avif_review_jpeg( $temp, 640, 480, 90 );
$secondData = avif_review_playground( $temp, $base . '.jpg' )->get_data();
avif_review_check( '' !== $firstToken && $firstToken !== ( $secondData['token'] ?? '' ), 'Repeated filenames get independent sessions' );

// A real observer after the normal upload converter sees no discarded full AVIF.
update_option( 'aviflosu_convert_on_upload', true );
$fullAvifSeen = null;
$observer = static function ( $upload ) use ( &$fullAvifSeen ) {
	$fullAvifSeen = file_exists( preg_replace( '/\.(jpe?g)$/i', '.avif', $upload['file'] ) );
	return $upload;
};
add_filter( 'wp_handle_upload', $observer, 999 );
$temp = wp_tempnam( 'preview.jpg' ); avif_review_jpeg( $temp, 640, 480 );
$response = avif_review_playground( $temp, 'preview-' . $suffix . '.jpg' );
remove_filter( 'wp_handle_upload', $observer, 999 );
avif_review_check( 200 === $response->get_status() && false === $fullAvifSeen, 'Playground does not encode a discarded full-resolution AVIF' );

// Locate the real initialized converter without registering duplicate hooks.
$converter = null;
foreach ( $GLOBALS['wp_filter']['wp_handle_upload']->callbacks as $group ) {
	foreach ( $group as $entry ) {
		if ( is_array( $entry['function'] ) && $entry['function'][0] instanceof Converter ) { $converter = $entry['function'][0]; break 2; }
	}
}
avif_review_check( $converter instanceof Converter, 'Playground restores the normal upload conversion hook' );
$avif = preg_replace( '/\.jpg$/', '.avif', $existing );
$converter->convertJpegToAvifWithSettings( $existing, $avif, Ddegner\AvifLocalSupport\DTO\AvifSettings::fromOptions() );
avif_review_check( AvifFile::isValid( $avif ), 'A real companion is valid after conversion' );
$coreInfo = wp_get_avif_info( $avif );
avif_review_check( ( $coreInfo['width'] ?? 0 ) === 180 && ( $coreInfo['height'] ?? 0 ) === 160, 'Core fallback parser identifies the generated AVIF' );
$html = wp_get_attachment_image( $existingId, 'full', false, array( 'sizes' => '123px', 'loading' => 'lazy' ) );
$processor = new WP_HTML_Tag_Processor( $html ); $sizes = array();
while ( $processor->next_tag() ) {
	if ( in_array( $processor->get_tag(), array( 'SOURCE', 'IMG' ), true ) ) { $sizes[ $processor->get_tag() ] = $processor->get_attribute( 'sizes' ); }
}
avif_review_check( isset( $sizes['SOURCE'], $sizes['IMG'] ) && $sizes['SOURCE'] === $sizes['IMG'] && str_contains( $sizes['SOURCE'], '123px' ), 'AVIF source preserves core auto and caller sizes' );

ThumbHash::generateForAttachment( $existingId );
ThumbHash::queueGenerateAll();
$response = rest_do_request( new WP_REST_Request( 'POST', '/aviflosu/v1/thumbhash/delete-all' ) );
avif_review_check( 200 === $response->get_status(), 'Bulk LQIP deletion route succeeds' );
avif_review_check( ! wp_next_scheduled( ThumbHash::GENERATE_HOOK ), 'Bulk LQIP deletion cancels the real scheduled event' );
ThumbHash::runGenerationBatch();
avif_review_check( ! ThumbHash::hasValidHash( $existingId ), 'A late cron callback does not restore deleted LQIPs' );
update_option( 'aviflosu_thumbhash_enabled', false );
$hashes = ThumbHash::generateForAttachment( $existingId, true );
avif_review_check( ThumbHash::isValidHashSet( $hashes ), 'Forced generation works while LQIP display is disabled' );

update_option( 'aviflosu_enable_support', false );
update_option( 'aviflosu_thumbhash_enabled', true );
$href = wp_get_attachment_url( $existingId );
$content = apply_filters( 'the_content', '<a href="' . esc_url( $href ) . '"><img class="wp-image-' . $existingId . '" src="' . esc_url( $href ) . '"></a>' );
avif_review_check( str_contains( $content, 'href="' . esc_url( $href ) . '"' ), 'Disabled AVIF support preserves JPEG links while LQIP is enabled' );
update_option( 'aviflosu_enable_support', true );

// Simulate a different PHP request writing cancellation after this worker cached a miss.
global $wpdb;
$stopKey = 'aviflosu_test_stop_' . $suffix;
get_transient( $stopKey );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_transient_' . $stopKey, 'option_value' => '1', 'autoload' => 'on' ) );
avif_review_check( AttachmentBatchRunner::isStopped( $stopKey ), 'Workers observe another request cancellation despite a cached miss' );
wp_cache_delete( '_transient_' . $stopKey, 'options' );
wp_cache_delete( 'notoptions', 'options' );
wp_cache_delete( 'alloptions', 'options' );
delete_transient( $stopKey );

wp_set_current_user( 0 );
$response = rest_do_request( new WP_REST_Request( 'POST', '/aviflosu/v1/delete-all-avifs' ) );
avif_review_check( 401 === $response->get_status(), 'Unauthenticated deletion remains forbidden' );
echo 'WordPress ' . $GLOBALS['wp_version'] . ' integration checks passed: ' . $GLOBALS['avifReviewChecks'] . "\n";
