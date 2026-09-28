<?php
/**
 * Portable admin and batch regression tests: php tests/admin-regression.php
 *
 * Executes the production classes with in-memory WordPress boundaries. No
 * database, installed WordPress, network access, or GD extension is required.
 */
declare(strict_types=1);

namespace Ddegner\AvifLocalSupport {
	function time(): int {
		return $GLOBALS['testClock'];
	}

	final class AttachmentQuery {
		public static function idsAfter(int $cursor, int $limit, array $mimeTypes): array {
			return array_slice(array_values(array_filter(array(1, 2, 3), static fn(int $id): bool => $id > $cursor)), 0, $limit);
		}
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('MINUTE_IN_SECONDS', 60);
	define('ARRAY_A', 'ARRAY_A');
	$GLOBALS['testClock'] = 10000;
	$GLOBALS['testOptions'] = array();
	$GLOBALS['testTransients'] = array();
	$GLOBALS['testEvents'] = array();
	$GLOBALS['testFilters'] = array();
	$GLOBALS['testToken'] = 0;
	$GLOBALS['testSideloadThrows'] = false;
	$GLOBALS['testEditorFailure'] = false;
	$GLOBALS['testAssertions'] = 0;
	$GLOBALS['testExternalCache'] = false;
	$GLOBALS['testTransientReadCache'] = array();
	$GLOBALS['testCacheDeletes'] = array();

	class TestWpdb {
		public string $options = 'wp_options';
		public function prepare(string $query, string ...$names): array { return $names; }
		public function get_results(array $names, string $format): array {
			$rows = array();
			foreach ($names as $name) {
				$timeout = str_starts_with($name, '_transient_timeout_');
				$key = substr($name, strlen($timeout ? '_transient_timeout_' : '_transient_'));
				$entry = $GLOBALS['testTransients'][$key] ?? null;
				if ($entry && (!$timeout || $entry[1])) {
					$rows[] = array('option_name' => $name, 'option_value' => $timeout ? (string) $entry[1] : (string) $entry[0]);
				}
			}
			return $rows;
		}
	}
	$GLOBALS['wpdb'] = new TestWpdb();
	function wp_using_ext_object_cache(): bool { return $GLOBALS['testExternalCache']; }
	function wp_installing(): bool { return false; }
	function wp_cache_get(string $key, string $group, bool $force = false): mixed {
		if ('transient' === $group) {
			if (!$force && array_key_exists($key, $GLOBALS['testTransientReadCache'])) { return $GLOBALS['testTransientReadCache'][$key]; }
			return $GLOBALS['testTransients'][$key][0] ?? false;
		}
		return false;
	}
	function wp_cache_delete(string $key, string $group): bool {
		$GLOBALS['testCacheDeletes'][] = array($key, $group);
		if ('options' === $group && ('notoptions' === $key || 'alloptions' === $key)) {
			$GLOBALS['testTransientReadCache'] = array();
		}
		return true;
	}

	function check(bool $condition, string $message): void {
		if (!$condition) {
			throw new RuntimeException($message);
		}
		++$GLOBALS['testAssertions'];
	}
	function __(string $message, string $domain = ''): string { return $message; }
	function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['testOptions'][$key] ?? $default; }
	function update_option(string $key, mixed $value, mixed $autoload = null): bool {
		$GLOBALS['testOptions'][$key] = $value;
		$GLOBALS['testAutoload'][$key] = $autoload;
		return true;
	}
	function delete_option(string $key): bool { unset($GLOBALS['testOptions'][$key]); return true; }
	function get_transient(string $key): mixed {
		if (array_key_exists($key, $GLOBALS['testTransientReadCache'])) { return $GLOBALS['testTransientReadCache'][$key]; }
		$entry = $GLOBALS['testTransients'][$key] ?? null;
		return $entry && (!$entry[1] || $entry[1] > $GLOBALS['testClock']) ? $entry[0] : false;
	}
	function set_transient(string $key, mixed $value, int $ttl = 0): bool {
		unset($GLOBALS['testTransientReadCache'][$key]);
		$GLOBALS['testTransients'][$key] = array($value, $ttl ? $GLOBALS['testClock'] + $ttl : 0);
		return true;
	}
	function delete_transient(string $key): bool {
		if (!$GLOBALS['testExternalCache'] && array_key_exists($key, $GLOBALS['testTransientReadCache']) && false === $GLOBALS['testTransientReadCache'][$key]) { return false; }
		unset($GLOBALS['testTransients'][$key], $GLOBALS['testTransientReadCache'][$key]);
		return true;
	}
	function wp_next_scheduled(string $hook): int|false {
		return empty($GLOBALS['testEvents'][$hook]) ? false : min($GLOBALS['testEvents'][$hook]);
	}
	function wp_schedule_single_event(int $when, string $hook): bool { $GLOBALS['testEvents'][$hook][] = $when; return true; }
	function wp_clear_scheduled_hook(string $hook): int {
		$count = count($GLOBALS['testEvents'][$hook] ?? array());
		unset($GLOBALS['testEvents'][$hook]);
		return $count;
	}
	function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool {
		$GLOBALS['testFilters'][$hook][] = array($callback, $priority, $acceptedArgs);
		return true;
	}
	function has_filter(string $hook, mixed $callback): int|false {
		foreach ($GLOBALS['testFilters'][$hook] ?? array() as $filter) {
			if ($filter[0] === $callback) { return $filter[1]; }
		}
		return false;
	}
	function remove_filter(string $hook, mixed $callback, int $priority = 10): bool {
		foreach ($GLOBALS['testFilters'][$hook] ?? array() as $index => $filter) {
			if ($filter[0] === $callback && $filter[1] === $priority) {
				unset($GLOBALS['testFilters'][$hook][$index]);
				return true;
			}
		}
		return false;
	}
	function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
		$filters = array_values($GLOBALS['testFilters'][$hook] ?? array());
		usort($filters, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
		foreach ($filters as $filter) {
			$value = ($filter[0])(...array_slice(array_merge(array($value), $args), 0, $filter[2]));
		}
		return $value;
	}
	function register_setting(string $group, string $name, array $args): void {
		$GLOBALS['testSettings'][$group][$name] = $args;
		if (!empty($args['sanitize_callback'])) { add_filter('sanitize_option_' . $name, $args['sanitize_callback']); }
	}
	function wp_generate_password(int $length, bool $special, bool $extra): string { return 'test-session-' . ++$GLOBALS['testToken']; }
	class WP_Error {}
	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function wp_get_image_editor(string $path): WP_Error {
		if ($GLOBALS['testEditorFailure']) { return new WP_Error(); }
		throw new RuntimeException('Small fixture should be copied without decoding');
	}
	function image_resize_dimensions(int $width, int $height, int $targetWidth, int $targetHeight, mixed $crop): array {
		return array(0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
	}
	function image_make_intermediate_size(mixed ...$args): never { throw new RuntimeException('Small fixture should not be resized'); }
	function wp_handle_sideload(array &$file, array $overrides): array {
		check(false === has_filter('wp_handle_upload', $GLOBALS['testUploadCallback']), 'Full original conversion must be suspended during sideload');
		if ($GLOBALS['testSideloadThrows']) { throw new RuntimeException('Simulated upload exception'); }
		return apply_filters('wp_handle_upload', array('file' => '/tmp/test-no-image.jpg', 'type' => 'image/jpeg'));
	}

	$root = dirname(__DIR__);
	require $root . '/includes/class-converter.php';
	require $root . '/includes/Admin/RestController.php';
	require $root . '/includes/Admin/Settings.php';
	require $root . '/includes/AttachmentBatchRunner.php';

	$controller = (new ReflectionClass(Ddegner\AvifLocalSupport\Admin\RestController::class))->newInstanceWithoutConstructor();
	$qualityMethod = new ReflectionMethod($controller, 'getWordPressJpegQuality');
	$qualityDimensions = null;
	add_filter('wp_editor_set_quality', static function(int $quality, string $mime, array $size) use (&$qualityDimensions): int {
		$qualityDimensions = $size;
		return $size['width'] > 1000 ? 75 : 80;
	}, 10, 3);
	add_filter('jpeg_quality', static fn(int $quality, string $context): int => $quality - 1, 10, 2);
	check(74 === $qualityMethod->invoke($controller, array('width' => 1200, 'height' => 800)), 'Honor modern dimension-aware and legacy quality filters');
	check(array('width' => 1200, 'height' => 800) === $qualityDimensions, 'Quality callbacks receive image dimensions');

	$settings = (new ReflectionClass(Ddegner\AvifLocalSupport\Admin\Settings::class))->newInstanceWithoutConstructor();
	(new ReflectionMethod($settings, 'registerOptions'))->invoke($settings);
	update_option('aviflosu_cache_duration', 7200);
	check(7200 === apply_filters('sanitize_option_aviflosu_cache_duration', null), 'Missing form field preserves configured cache duration');
	update_option('aviflosu_cache_duration', 0);
	check(3600 === apply_filters('sanitize_option_aviflosu_cache_duration', null), 'Repair the previously saved zero duration');
	check(1800 === apply_filters('sanitize_option_aviflosu_cache_duration', '1800'), 'Explicit positive cache lifetime remains configurable');

	$fixture = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAGAAgAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgM RBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4uiiivmT9xCiiigAooooAKKKKAP/2Q==');
	$directory = sys_get_temp_dir() . '/avif-admin-regression-' . bin2hex(random_bytes(8));
	mkdir($directory);
	try {
		$source = $directory . '/holiday.jpg';
		file_put_contents($source, $fixture);
		file_put_contents($directory . '/holiday-playground.jpg', 'existing JPEG attachment');
		file_put_contents($directory . '/holiday-playground.avif', 'existing AVIF attachment');
		mkdir($directory . '/aviflosu-playground-test-session-1');
		file_put_contents($directory . '/aviflosu-playground-test-session-1/preview.avif', 'existing session');
		$previewMethod = new ReflectionMethod($controller, 'createPlaygroundPreviewJpeg');
		$first = $previewMethod->invoke($controller, $source, array('width' => 1024, 'height' => 0, 'crop' => false));
		$second = $previewMethod->invoke($controller, $source, array('width' => 1024, 'height' => 0, 'crop' => false));
		check(is_array($first) && is_array($second), 'Both previews are created');
		check($first['path'] !== $second['path'], 'Repeated previews must have distinct destinations');
		check($fixture === file_get_contents($first['path']), 'Small preview preserves the original bytes');
		check('existing JPEG attachment' === file_get_contents($directory . '/holiday-playground.jpg'), 'Existing JPEG remains intact');
		check('existing AVIF attachment' === file_get_contents($directory . '/holiday-playground.avif'), 'Existing native AVIF remains intact');
		check('existing session' === file_get_contents($directory . '/aviflosu-playground-test-session-1/preview.avif'), 'An existing session directory is not reused');
		file_put_contents($directory . '/holiday-16x12.jpg', 'existing media derivative');
		file_put_contents($directory . '/holiday-16x12.avif', 'existing AVIF derivative');
		$GLOBALS['testEditorFailure'] = true;
		$fallback = $previewMethod->invoke($controller, $source, array('width' => 16, 'height' => 12, 'crop' => false));
		check('original' === $fallback['jpeg_quality_source'] && $fixture === file_get_contents($fallback['path']), 'A resize failure safely preserves original bytes in its session');
		check('existing media derivative' === file_get_contents($directory . '/holiday-16x12.jpg'), 'Resize fallback cannot overwrite or delete another JPEG derivative');
		check('existing AVIF derivative' === file_get_contents($directory . '/holiday-16x12.avif'), 'Resize fallback cannot overwrite or delete another AVIF derivative');
	} finally {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
		rmdir($directory);
	}

	$converter = new Ddegner\AvifLocalSupport\Converter();
	(new ReflectionProperty($controller, 'converter'))->setValue($controller, $converter);
	$GLOBALS['testUploadCallback'] = array($converter, 'convertOriginalOnUpload');
	$uploadMethod = new ReflectionMethod($controller, 'sideloadPlaygroundJpeg');
	add_filter('wp_handle_upload', $GLOBALS['testUploadCallback'], 20);
	$uploadMethod->invoke($controller, array());
	check(20 === has_filter('wp_handle_upload', $GLOBALS['testUploadCallback']), 'Restore the upload hook after success');
	$GLOBALS['testSideloadThrows'] = true;
	try { $uploadMethod->invoke($controller, array()); } catch (RuntimeException $error) {
		check('Simulated upload exception' === $error->getMessage(), 'Propagate the original upload exception');
	}
	check(20 === has_filter('wp_handle_upload', $GLOBALS['testUploadCallback']), 'Restore the upload hook after an exception');
	$GLOBALS['testSideloadThrows'] = false;
	remove_filter('wp_handle_upload', $GLOBALS['testUploadCallback'], 20);
	$uploadMethod->invoke($controller, array());
	check(false === has_filter('wp_handle_upload', $GLOBALS['testUploadCallback']), 'Do not install a hook that was absent beforehand');

	$processed = array();
	$runner = new Ddegner\AvifLocalSupport\AttachmentBatchRunner('cursor', 'stop', 'continue', array('image/jpeg'), static function(int $id) use (&$processed): void {
		$processed[] = $id;
		$GLOBALS['testClock'] += 21;
	});
	check('paused' === $runner->run(), 'First slow item pauses the batch');
	check(1 === get_option('cursor') && false === $GLOBALS['testAutoload']['cursor'], 'Progress is durable and not autoloaded');
	$GLOBALS['testClock'] += 7 * 24 * 3600;
	$GLOBALS['testEvents'] = array();
	check('paused' === $runner->run(), 'Resume after a seven-day traffic gap');
	check(array(1, 2) === $processed, 'Resume at the next attachment instead of repeating the prefix');
	$runner->run();
	check('complete' === $runner->run() && false === get_option('cursor'), 'Completed batches remove durable progress');
	set_transient('cursor', 2, 900);
	$runner->run();
	check(3 === get_option('cursor') && false === get_transient('cursor'), 'Migrate an existing transient cursor on upgrade');
	$GLOBALS['testEvents']['continue'] = array($GLOBALS['testClock'] + 5, $GLOBALS['testClock'] + 15);
	Ddegner\AvifLocalSupport\AttachmentBatchRunner::stop('continue', 'stop', 'cursor');
	check(false === wp_next_scheduled('continue') && false === get_option('cursor'), 'Stopping removes all queued events and progress');
	$GLOBALS['testClock'] += 7 * 24 * 3600;
	check('stopped' === $runner->run(), 'A late dequeued request with no cursor still honors cancellation');
	check(Ddegner\AvifLocalSupport\AttachmentBatchRunner::queue('continue', 'stop'), 'An explicit queue request restarts the job');
	check(false === get_transient('stop'), 'Explicit restart clears cancellation');
	$stoppingRunner = new Ddegner\AvifLocalSupport\AttachmentBatchRunner('cursor', 'stop', 'continue', array('image/jpeg'), static function(int $id): void {
		Ddegner\AvifLocalSupport\AttachmentBatchRunner::stop('continue', 'stop', 'cursor');
		$GLOBALS['testClock'] += 21;
	});
	check('stopped' === $stoppingRunner->run(), 'Cancellation during the final item prevents continuation');
	check(false === wp_next_scheduled('continue') && false === get_option('cursor'), 'Canceled slices cannot restore the cursor or queue another event');

	// Another request's stop must override a worker's cached "not found" result.
	$GLOBALS['testTransientReadCache']['fresh-stop'] = false;
	$GLOBALS['testTransients']['fresh-stop'] = array(true, 0);
	check(false === get_transient('fresh-stop'), 'Fixture retains the worker-local cached miss after an external DB write');
	check(Ddegner\AvifLocalSupport\AttachmentBatchRunner::isStopped('fresh-stop'), 'Fresh DB read observes cancellation despite the cached miss');
	Ddegner\AvifLocalSupport\AttachmentBatchRunner::clearStopFlag('fresh-stop');
	check(!Ddegner\AvifLocalSupport\AttachmentBatchRunner::isStopped('fresh-stop'), 'Explicit restart clears an externally written flag despite an earlier cached miss');
	$GLOBALS['testTransients']['expired-stop'] = array(true, $GLOBALS['testClock'] - 1);
	check(!Ddegner\AvifLocalSupport\AttachmentBatchRunner::isStopped('expired-stop'), 'Ignore expired flags from older plugin versions');
	$externalProcessed = array();
	$externalRunner = new Ddegner\AvifLocalSupport\AttachmentBatchRunner('external-cursor', 'external-stop', 'external-continue', array('image/jpeg'), static function(int $id) use (&$externalProcessed): void {
		$externalProcessed[] = $id;
		$GLOBALS['testTransients']['external-stop'] = array(true, 0);
	});
	$GLOBALS['testTransientReadCache']['external-stop'] = false;
	check('stopped' === $externalRunner->run() && array(1) === $externalProcessed, 'External cancellation during an item prevents processing the next item');
	$GLOBALS['testExternalCache'] = true;
	$GLOBALS['testTransientReadCache']['object-stop'] = false;
	$GLOBALS['testTransients']['object-stop'] = array(true, 0);
	$deleteCount = count($GLOBALS['testCacheDeletes']);
	check(Ddegner\AvifLocalSupport\AttachmentBatchRunner::isStopped('object-stop'), 'External object-cache reads are forced past the local stale miss');
	check(count($GLOBALS['testCacheDeletes']) === $deleteCount && true === $GLOBALS['testTransients']['object-stop'][0], 'Polling never deletes the authoritative persistent stop flag');
	echo 'PASS: ' . $GLOBALS['testAssertions'] . " admin and batch assertions\n";
}
