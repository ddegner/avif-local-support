<?php
/** Standalone regression coverage: php tests/conversion-regression.php */
declare(strict_types=1);

namespace Ddegner\AvifLocalSupport {
	// Exercise the WordPress-parser fallback without changing PHP configuration.
	function getimagesize(string $path): array|false {
		if (!empty($GLOBALS['force_avif_fallback']) && str_ends_with($path, '.avif')) {
			return false;
		}
		return @\getimagesize($path);
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('DAY_IN_SECONDS', 86400);
	$GLOBALS['options'] = ['aviflosu_engine_mode' => 'gd', 'aviflosu_memory_check' => false];
	$GLOBALS['transients'] = [];
	$GLOBALS['metadata'] = [];
	$GLOBALS['attachment_ids'] = [];
	function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
	function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
	function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
	function wp_using_ext_object_cache() { return true; }
	function wp_installing() { return false; }
	function wp_cache_get($key, $group, $force = false) { return $group === 'transient' ? get_transient($key) : false; }
	function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
	function apply_filters($tag, $value, ...$args) { return $value; }
	function wp_delete_file($path) { if (is_file($path)) { unlink($path); } }
	function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
	function wp_upload_dir() { return ['basedir' => $GLOBALS['test_directory'], 'baseurl' => 'https://example.test/uploads']; }
	function wp_get_upload_dir() { return wp_upload_dir(); }
	function trailingslashit($path) { return rtrim($path, '/') . '/'; }
	function get_post_mime_type($id) { return 'image/jpeg'; }
	function get_attached_file($id) { return $GLOBALS['test_directory'] . '/' . $GLOBALS['metadata'][$id]['file']; }
	function wp_get_attachment_metadata($id) { return $GLOBALS['metadata'][$id] ?? []; }
	function __($text, $domain = null) { return $text; }
	function size_format($bytes) { return sprintf('%.1f MB', $bytes / 1048576); }
	function wp_get_avif_info($path) {
		$GLOBALS['fallback_calls'] = ($GLOBALS['fallback_calls'] ?? 0) + 1;
		$info = @getimagesize($path);
		return is_array($info) && ($info['mime'] ?? '') === 'image/avif'
			? ['width' => $info[0], 'height' => $info[1]] : ['width' => false, 'height' => false];
	}
	class WP_Query {
		public array $posts;
		public function __construct(array $args) { $this->posts = $GLOBALS['attachment_ids']; }
	}
	spl_autoload_register(static function(string $class): void {
		$prefix = 'Ddegner\\AvifLocalSupport\\';
		if (!str_starts_with($class, $prefix)) { return; }
		$name = substr($class, strlen($prefix));
		$name = $name === 'Converter' ? 'class-converter' : str_replace('\\', '/', $name);
		require_once dirname(__DIR__) . '/includes/' . $name . '.php';
	});

	use Ddegner\AvifLocalSupport\AvifFile;
	use Ddegner\AvifLocalSupport\AttachmentBatchRunner;
	use Ddegner\AvifLocalSupport\Converter;
	use Ddegner\AvifLocalSupport\Diagnostics;
	use Ddegner\AvifLocalSupport\FilesystemScanner;
	use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
	use Ddegner\AvifLocalSupport\DTO\AvifSettings;
	use Ddegner\AvifLocalSupport\DTO\ConversionResult;
	use Ddegner\AvifLocalSupport\Encoders\GdEncoder;

	final class RecordingEncoder implements AvifEncoderInterface {
		public array $calls = [];
		public function __construct(private string $name, private bool $succeeds = true) {}
		public function getName(): string { return $this->name; }
		public function isAvailable(): bool { return true; }
		public function convert(string $source, string $destination, AvifSettings $settings, ?array $dimensions = null): ConversionResult {
			$this->calls[] = [$source, $dimensions];
			if (!empty($GLOBALS['stop_during_encode'])) { set_transient(Converter::STOP_TRANSIENT, true); }
			file_put_contents($destination, $this->succeeds ? $GLOBALS['avif_fixture'] : str_repeat('invalid', 200));
			return $this->succeeds ? ConversionResult::success() : ConversionResult::failure('Simulated encoder failure');
		}
	}
	function converterWith(AvifEncoderInterface ...$encoders): Converter {
		$converter = new Converter();
		(new ReflectionProperty(Converter::class, 'encoders'))->setValue($converter, $encoders);
		return $converter;
	}
	$checks = 0;
	function check(bool $condition, string $message): void {
		if (!$condition) { throw new RuntimeException($message); }
		++$GLOBALS['checks'];
	}
	function fixture(string $name, string $contents): string {
		$path = $GLOBALS['test_directory'] . '/' . $name;
		file_put_contents($path, $contents);
		return $path;
	}
	// Actual 150x150 JPEG and AVIF encoded from the same solid-white image.
	$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCACWAJYBAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKpgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/9k=', true);
	$GLOBALS['avif_fixture'] = base64_decode('AAAAHGZ0eXBhdmlmAAAAAG1pZjFhdmlmbWlhZgAAANZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAAA+gABAAAAAAAAACwAAAAjaWluZgAAAAAAAQAAABVpbmZlAgAAAAABAABhdjAxAAAAAA5waXRtAAAAAAABAAAAVmlwcnAAAAA4aXBjbwAAAAxhdjFDgQAMAAAAABRpc3BlAAAAAAAAAJYAAACWAAAAEHBpeGkAAAAAAwgICAAAABZpcG1hAAAAAAAAAAEAAQOBAgMAAAA0bWRhdBIACgoYHeVlWyAhoNCAMhwQwe3tmkAAeEAAAACATGJsm3+e3j39ia8KhIXA', true);
	$directory = sys_get_temp_dir() . '/avif-conversion-regression-' . bin2hex(random_bytes(6));
	mkdir($directory);
	$GLOBALS['test_directory'] = $directory;
	$oldLimit = ini_get('memory_limit');
	try {
		$tiny = fixture('tiny.avif', $GLOBALS['avif_fixture']);
		check(filesize($tiny) < 512 && AvifFile::isValid($tiny), 'Valid compact AVIF must be accepted');
		check(!AvifFile::isValid(fixture('not-avif.avif', $jpeg)), 'Renamed JPEG must be rejected');
		check(!AvifFile::isValid(fixture('garbage.avif', str_repeat('x', 1024))), 'Large garbage file must be rejected');
		check(!AvifFile::isValid(fixture('empty.avif', '')), 'Empty AVIF must be rejected');
		check(!AvifFile::isValid($directory . '/missing.avif'), 'Missing AVIF must be rejected');
		$GLOBALS['force_avif_fallback'] = true;
		check(AvifFile::isValid($tiny) && $GLOBALS['fallback_calls'] > 0, 'WordPress AVIF metadata parser must provide fallback');
		$GLOBALS['force_avif_fallback'] = false;

		$encoder = new RecordingEncoder('gd');
		$converter = converterWith($encoder);
		$source = fixture('tiny.jpg', $jpeg);
		check($converter->convertSingleJpegToAvif($source)->success && count($encoder->calls) === 0, 'Existing compact AVIF must not be repeatedly converted');
		$GLOBALS['metadata'][1] = ['file' => 'tiny.jpg', 'sizes' => []];
		$GLOBALS['attachment_ids'] = [1];
		check((new Diagnostics())->computeMissingCounts() === ['total_jpegs' => 1, 'existing_avifs' => 1, 'missing_avifs' => 0], 'Diagnostics must count compact AVIF as present');
		check((new FilesystemScanner($converter))->preview()['already_have_avif'] === 1, 'Filesystem scan must recognize compact AVIF');

		$settings = new AvifSettings(engineMode: 'gd', memoryCheck: false);
		fixture('crop.jpg', $jpeg);
		$derivative = fixture('crop-150x150.jpg', $jpeg);
		$result = $converter->convertJpegToAvifWithSettings($derivative, $directory . '/crop.avif', $settings);
		check($result->success && end($encoder->calls) === [$derivative, null], 'WordPress derivative must retain its actual crop instead of selecting original by filename');
		check(AvifFile::isValid($directory . '/crop.avif'), 'Compact output must survive conversion');

		// Model editor-converted JPEG metadata, which still has post_mime_type=image/jpeg.
		foreach (['original.webp', 'thumb.webp', 'native.avif', 'thumb.png'] as $name) { fixture($name, 'preserve this media'); }
		fixture('thumb.jpg', $jpeg);
		fixture('thumb.avif', $GLOBALS['avif_fixture']);
		$GLOBALS['metadata'][2] = ['file' => 'original.webp', 'sizes' => [
			['file' => 'thumb.webp'], ['file' => 'native.avif'], ['file' => 'thumb.png'], ['file' => 'thumb.jpg'],
		]];
		check($converter->deleteAvifsForAttachment(2) === ['attempted' => 1, 'deleted' => 1], 'Delete must remove only JPEG companion AVIF');
		foreach (['original.webp', 'thumb.webp', 'native.avif', 'thumb.png', 'thumb.jpg'] as $name) {
			check(is_file($directory . '/' . $name), 'Delete must preserve actual media: ' . $name);
		}

		// Change only the JPEG SOF dimensions to exercise the memory estimate;
		// the recording encoders do not decode this intentionally synthetic header.
		$sof = strpos($jpeg, "\xff\xc0");
		check($sof !== false, 'JPEG fixture needs a baseline SOF');
		$largeHeader = substr_replace($jpeg, pack('nn', 4000, 4000), $sof + 5, 4);
		$large = fixture('large.jpg', $largeHeader);
		if (function_exists('exif_read_data')) {
			$scaled = fixture('large-scaled.jpg', $jpeg);
			check($converter->convertJpegToAvifWithSettings($scaled, $directory . '/scaled-output.avif', $settings)->success
				&& end($encoder->calls) === [$large, ['width' => 150, 'height' => 150]], 'Proportional WordPress scaled image should preserve original-source encoding');
			fixture('wide.jpg', substr_replace($jpeg, pack('nn', 150, 300), $sof + 5, 4));
			$croppedScaled = fixture('wide-scaled.jpg', $jpeg);
			check($converter->convertJpegToAvifWithSettings($croppedScaled, $directory . '/wide.avif', $settings)->success
				&& end($encoder->calls) === [$croppedScaled, null], 'Scaled filename with different aspect must not trigger a new crop');
		}
		ini_set('memory_limit', '64M');
		$cli = new RecordingEncoder('cli');
		$guarded = new AvifSettings(engineMode: 'cli', memoryCheck: true);
		check(converterWith($cli)->convertJpegToAvifWithSettings($large, $directory . '/large.avif', $guarded)->success && count($cli->calls) === 1, 'External CLI must not be constrained by PHP decoding memory estimate');
		$gd = new RecordingEncoder('gd');
		$guarded->engineMode = 'gd';
		check(!converterWith($gd)->convertJpegToAvifWithSettings($large, $directory . '/large-gd.avif', $guarded)->success && !$gd->calls, 'In-process decoder must retain memory guard');
		$cliFailure = new RecordingEncoder('cli', false);
		$guarded->engineMode = 'auto';
		check(!converterWith($cliFailure, $gd)->convertJpegToAvifWithSettings($large, $directory . '/failed.avif', $guarded)->success && count($cliFailure->calls) === 1 && !$gd->calls, 'Automatic PHP fallback must remain guarded after CLI failure');
		check(!is_file($directory . '/failed.avif'), 'Failed output larger than512 bytes must be cleaned up');
		ini_set('memory_limit', $oldLimit);

		$scanner = new FilesystemScanner($converter);
		$scanner->markQueued();
		set_transient(Converter::STOP_TRANSIENT, true);
		$callsBeforeStop = count($encoder->calls);
		$scanner->run();
		check(count($encoder->calls) === $callsBeforeStop && $scanner->progress()['state'] === 'stopped', 'Already-dequeued filesystem scan must honor cancellation before encoding');
		check(get_transient(Converter::STOP_TRANSIENT) === true, 'Canceled filesystem scan must not clear shared stop flag');
		AttachmentBatchRunner::clearStopFlag(Converter::STOP_TRANSIENT);
		$GLOBALS['stop_during_encode'] = true;
		$scanner->run();
		$GLOBALS['stop_during_encode'] = false;
		check(count($encoder->calls) === $callsBeforeStop + 1 && $scanner->progress()['state'] === 'stopped', 'Filesystem scan must check cancellation after each conversion instead of25 files');
		check(get_transient(Converter::STOP_TRANSIENT) === true, 'Finishing filesystem scan must not resume another canceled worker');

		// Real encoding is optional so the routing/deletion tests run without GD.
		if (function_exists('imageavif')) {
			$result = (new GdEncoder())->convert($source, $directory . '/gd-tiny.avif', $settings);
			check($result->success && AvifFile::isValid($directory . '/gd-tiny.avif'), 'Real GD encoding must accept compact valid AVIF');
		}
		echo 'PASS: ' . $checks . " conversion regression checks\n";
	} finally {
		ini_set('memory_limit', $oldLimit);
		foreach (glob($directory . '/*') as $path) { unlink($path); }
		rmdir($directory);
	}
}
