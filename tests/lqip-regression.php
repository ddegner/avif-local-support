<?php
/** Run with PHP + GD: php tests/lqip-regression.php. No WordPress database required. */
declare(strict_types=1);

namespace Ddegner\AvifLocalSupport {

	final class AttachmentQuery {
		public const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif' );
		public static function idsAfter( int $cursor, int $limit, array $mimes ): array {
			return $cursor < 1 ? array( 1 ) : array();
		}
		public static function allIds( array $mimes ): array { return array( 1 ); }
	}
}

namespace {
	if ( ! extension_loaded( 'gd' ) ) { fwrite( STDERR, "GD is required.\n" ); exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	$transients = array(); $options = array(); $events = array(); $meta = array();
	$fixture = sys_get_temp_dir() . '/avif-lqip-test-' . bin2hex( random_bytes( 6 ) );
	mkdir( $fixture );
	$metadataCallback = null;
	$beforeWriteCallback = null;
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; return true; }
	function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }
	function wp_next_scheduled( $hook ) { return $GLOBALS['events'][ $hook ] ?? false; }
	function wp_schedule_single_event( $time, $hook ) { $GLOBALS['events'][ $hook ] = $time; return true; }
	function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['events'][ $hook ] ); return 1; }
	function wp_cache_delete( $key, $group = '' ) { return true; }
	function wp_using_ext_object_cache() { return true; }
	function wp_installing() { return false; }
	function wp_cache_get( $key, $group = '', $force = false ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function clean_post_cache( $id ) {}
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ] ?? ''; }
	function update_post_meta( $id, $key, $value ) {
		if ( $GLOBALS['beforeWriteCallback'] ) { ( $GLOBALS['beforeWriteCallback'] )(); }
		$GLOBALS['meta'][ $id ] = $value; return true;
	}
	function delete_post_meta( $id, $key, $value = null ) {
		if ( null === $value || ( $GLOBALS['meta'][ $id ] ?? null ) === $value ) { unset( $GLOBALS['meta'][ $id ] ); }
		return true;
	}
	function wp_get_attachment_metadata( $id ) {
		if ( $GLOBALS['metadataCallback'] ) { ( $GLOBALS['metadataCallback'] )(); }
		return array( 'file' => 'red-64.gif', 'width' => 64, 'height' => 64, 'sizes' => array() );
	}
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'attachment' ); }
	function get_post_mime_type( $id ) { return 'image/gif'; }
	function wp_upload_dir() { return array( 'basedir' => $GLOBALS['fixture'] ); }
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function maybe_unserialize( $value ) { return unserialize( $value ); }
	class WP_CLI {
		public static array $messages = array();
		public static function line( $text ) { self::$messages[] = $text; }
		public static function warning( $text ) { self::$messages[] = $text; }
		public static function success( $text ) { self::$messages[] = 'SUCCESS: ' . $text; }
		public static function error( $text ) { throw new \RuntimeException( $text ); }
	}
	class LqipTestDatabase {
		public string $postmeta = 'test_postmeta';
		public string $posts = 'test_posts';
		public function prepare( $query, ...$args ) { return $query; }
		public function get_col( $query ) { return array_keys( $GLOBALS['meta'] ); }
		public function get_var( $query ) { return 1; }
		public function get_results( $query ) {
			$out = array();
			foreach ( $GLOBALS['meta'] as $id => $value ) { $out[] = (object) array( 'post_id' => $id, 'meta_value' => serialize( $value ) ); }
			return $out;
		}
		public function query( $query ) { $count = count( $GLOBALS['meta'] ); $GLOBALS['meta'] = array(); return $count; }
	}
	$wpdb = new LqipTestDatabase();
	$root = dirname( __DIR__ );
	require $root . '/lib/Thumbhash/Thumbhash.php';
	require $root . '/includes/AttachmentBatchRunner.php';
	require $root . '/includes/ThumbHash.php';
	require $root . '/includes/CliProgress.php';
	require $root . '/includes/LQIP_CLI.php';
	use Ddegner\AvifLocalSupport\ThumbHash;
	use Ddegner\AvifLocalSupport\LQIP_CLI;
	$checks = 0;
	function check( bool $passed, string $message ): void {
		if ( ! $passed ) { throw new \RuntimeException( $message ); }
		++$GLOBALS['checks'];
	}
	try {
		foreach ( array( 64, 128 ) as $size ) {
			$image = imagecreate( $size, $size );
			imagecolorallocate( $image, 255, 0, 0 );
			imagegif( $image, "$fixture/red-$size.gif" );
			imagepng( $image, "$fixture/red-$size.png" );
		}
		// Force the GD path even on machines where Imagick is also installed.
		$gd = new \ReflectionMethod( ThumbHash::class, 'generateWithGd' );
		foreach ( array( 'gif', 'png' ) as $ext ) {
			foreach ( array( 64, 128 ) as $size ) {
				$hash = $gd->invoke( null, "$fixture/red-$size.$ext" );
				$bytes = unpack( 'C*', base64_decode( $hash ) );
				$header = $bytes[1] | ( $bytes[2] << 8 ) | ( $bytes[3] << 16 );
				$l = ( $header & 63 ) / 63; $p = ( ( $header >> 6 ) & 63 ) / 31.5 - 1; $q = ( ( $header >> 12 ) & 63 ) / 31.5 - 1;
				$blue = $l - 2 / 3 * $p; $red = ( 3 * $l - $blue + $q ) / 2; $green = $red - $q;
				check( $red > .9 && $green < .1 && $blue < .1, "$size px palette $ext must remain red" );
			}
		}
		check( null === ThumbHash::generateForAttachment( 1 ), 'Disabled feature must not generate implicitly' );
		( new LQIP_CLI() )->generate( array( '1' ), array( 'force' => true ) );
		check( ThumbHash::hasValidHash( 1 ), 'CLI --force must generate while feature is disabled' );
		$meta = array();
		( new LQIP_CLI() )->generate( array(), array( 'all' => true, 'force' => true ) );
		check( ThumbHash::hasValidHash( 1 ), 'CLI --all --force must generate while disabled' );
		ThumbHash::queueGenerateAll();
		check( (bool) wp_next_scheduled( ThumbHash::GENERATE_HOOK ), 'Generation should be queued' );
		check( 1 === ThumbHash::deleteAll(), 'Delete all should remove the stored hash' );
		check( ! wp_next_scheduled( ThumbHash::GENERATE_HOOK ), 'Delete all must cancel queued generation' );
		// A worker already dequeued by WP-Cron must also respect cancellation.
		ThumbHash::runGenerationBatch();
		check( ! ThumbHash::hasValidHash( 1 ), 'Late worker must not recreate a deleted hash' );
		check( 'stopped' === ThumbHash::getGenerationProgress()['state'], 'Late worker must report stopped' );
		ThumbHash::queueGenerateAll();
		check( 0 === ThumbHash::deleteAll(), 'Deleting before the first result should be safe' );
		check( ! wp_next_scheduled( ThumbHash::GENERATE_HOOK ), 'An empty library of hashes still needs cancellation' );
		// Model a delete in another request after this encode captured its revision.
		$metadataCallback = static function (): void { $GLOBALS['metadataCallback'] = null; ThumbHash::deleteAll(); };
		check( null === ThumbHash::generateForAttachment( 1, true ), 'A hash begun before bulk delete must not be stored' );
		check( ! ThumbHash::hasValidHash( 1 ), 'Canceled in-flight result must not repopulate metadata' );
		$beforeWriteCallback = static function (): void { $GLOBALS['beforeWriteCallback'] = null; ThumbHash::deleteAll(); };
		check( null === ThumbHash::generateForAttachment( 1, true ), 'A delete during the metadata write must invalidate that result' );
		check( ! ThumbHash::hasValidHash( 1 ), 'A write racing bulk deletion must be cleaned up' );
		ThumbHash::queueGenerateAll();
		ThumbHash::runGenerationBatch();
		check( ThumbHash::hasValidHash( 1 ), 'An explicit new queue request must resume generation' );
		echo "LQIP regression checks passed: $checks\n";
	} finally {
		foreach ( glob( $fixture . '/*' ) as $file ) { unlink( $file ); }
		rmdir( $fixture );
	}
}
