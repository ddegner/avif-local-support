<?php
/** Run without a database: php tests/frontend-regression.php [/path/to/wordpress] */
declare(strict_types=1);

$wpRoot = rtrim($argv[1] ?? dirname(__DIR__, 4), '/');
if (!is_file($wpRoot . '/wp-includes/html-api/class-wp-html-tag-processor.php')) {
	fwrite(STDERR, "Supply a WordPress 6.8+ source directory as the first argument.\n");
	exit(1);
}
define('ABSPATH', $wpRoot . '/');
define('WPINC', 'wp-includes');
define('HOUR_IN_SECONDS', 3600);
define('AVIFLOSU_PLUGIN_URL', 'https://example.test/wp-content/plugins/avif-local-support/');
define('AVIFLOSU_VERSION', 'test');
$options = ['blog_charset' => 'UTF-8', 'aviflosu_enable_support' => true];
$fixtures = sys_get_temp_dir() . '/avif-frontend-regression-' . bin2hex(random_bytes(6));
mkdir($fixtures . '/css', 0777, true);
$base = 'https://example.test/wp-content/uploads';
$checks = 0;
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function get_transient($key) { return false; }
function add_filter(...$args) {}
function add_action(...$args) {}
function apply_filters($hook, $value, ...$args) { return $value; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function home_url($path = '') { return 'https://example.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_upload_dir() { return ['baseurl' => $GLOBALS['base'], 'basedir' => $GLOBALS['fixtures']]; }
function get_post_mime_type($id) { return 'image/jpeg'; }
function wp_get_attachment_image_src($id, $size) { return [$GLOBALS['base'] . '/photo.jpg', 1200, 800]; }
function get_post_meta(...$args) { return []; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_unslash($value) { return stripslashes($value); }
function sanitize_url($value) { return $value; }
function _doing_it_wrong($function, $message, $version) { throw new RuntimeException($function . ': ' . $message); }
function __($text, $domain = null) { return $text; }
function check(bool $condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
	++$GLOBALS['checks'];
}

require $wpRoot . '/wp-includes/kses.php';
foreach (['compat-utf8.php', 'utf8.php'] as $file) {
	if (is_file($wpRoot . '/wp-includes/' . $file)) { require $wpRoot . '/wp-includes/' . $file; }
}
if (is_file($wpRoot . '/wp-includes/class-wp-token-map.php')) {
	require $wpRoot . '/wp-includes/class-wp-token-map.php';
}
foreach (['html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php'] as $file) {
	if (is_file($wpRoot . '/wp-includes/html-api/' . $file)) {
		require $wpRoot . '/wp-includes/html-api/' . $file;
	}
}
require dirname(__DIR__) . '/includes/AvifFile.php';
require dirname(__DIR__) . '/includes/ThumbHash.php';
require dirname(__DIR__) . '/includes/class-support.php';
require dirname(__DIR__) . '/includes/BackgroundImages.php';

// A real 294-byte AVIF: no image encoder or external download needed by this test.
$avif = base64_decode('AAAAHGZ0eXBhdmlmAAAAAG1pZjFhdmlmbWlhZgAAANZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAAA+gABAAAAAAAAACwAAAAjaWluZgAAAAAAAQAAABVpbmZlAgAAAAABAABhdjAxAAAAAA5waXRtAAAAAAABAAAAVmlwcnAAAAA4aXBjbwAAAAxhdjFDgQAMAAAAABRpc3BlAAAAAAAAAJYAAACWAAAAEHBpeGkAAAAAAwgICAAAABZpcG1hAAAAAAAAAAEAAQOBAgMAAAA0bWRhdBIACgoYHeVlWyAhoNCAMhwQwe3tmkAAeEAAAACATGJsm3+e3j39ia8KhIXA', true);
foreach (['photo', 'mobile', 'custom'] as $name) { file_put_contents($fixtures . '/' . $name . '.avif', $avif); }

function background(string $html): string {
	$service = new Ddegner\AvifLocalSupport\BackgroundImages();
	$service->init();
	(new ReflectionProperty($service, 'bufferingStarted'))->setValue($service, true);
	return $service->processBuffer($html);
}
function page(string $head, string $body = ''): string { return '<html><head>' . $head . '</head><body>' . $body . '</body></html>'; }

try {
	$support = new Ddegner\AvifLocalSupport\Support();
	$support->init();
	$html = '<img src="' . $base . '/custom.jpg" srcset="' . $base . '/custom.jpg 300w, ' . $base . '/photo.jpg 1200w" sizes="auto, 300px" loading="lazy" alt="A view" data-wp-on--click="actions.showLightbox">';
	$wrapped = $support->wrapAttachment($html, 1, 'full', false, ['sizes' => 'wrong-input']);
	$tags = new WP_HTML_Tag_Processor($wrapped);
	check($tags->next_tag('SOURCE'), 'Valid sub-512-byte AVIF must get a source');
	check($tags->get_attribute('sizes') === 'auto, 300px', 'Source must retain final auto/custom sizes');
	check($tags->get_attribute('srcset') === $base . '/custom.avif 300w, ' . $base . '/photo.avif 1200w', 'Source must retain final candidates');
	check(str_contains($wrapped, $html), 'Original JPEG and accessibility/interactive attributes must remain intact');
	check(str_contains($wrapped, 'class="aviflosu-picture"'), 'Wrapper must be identifiable for scoped core-block layout styles');
	$options['aviflosu_enable_support'] = false;
	$options['aviflosu_thumbhash_enabled'] = true;
	$link = '<a href="' . $base . '/photo.jpg"><img src="' . $base . '/photo.jpg"></a>';
	check(str_contains($support->wrapContentImages($link), 'href="' . $base . '/photo.jpg"'), 'LQIP-only mode must retain JPEG links');
	$options['aviflosu_enable_support'] = true;
	$options['aviflosu_thumbhash_enabled'] = false;

	$css = '.hero{background-image:url(' . $base . '/photo.jpg)}@media(max-width:600px){.hero{background-image:url(' . $base . '/mobile.jpg)}}';
	$result = background(page('<style>' . $css . '</style>'));
	check(str_contains($result, '@media(max-width:600px){.hero{background-image:url(' . $base . '/mobile.jpg);background-image:image-set('), 'Mobile image-set must remain inside its media condition');
	check(!str_contains($result, '!important'), 'Conversion must not escalate cascade priority');
	check(substr_count($result, 'image-set(') === 2, 'Both desktop and mobile backgrounds should convert');

	$layered = 'linear-gradient(black,transparent),url(' . $base . '/photo.jpg?v=2)';
	$result = background(page('<style>.hero{background-image:' . $layered . ' !important;background-size:cover}</style>'));
	check(str_contains($result, 'linear-gradient(black,transparent),image-set('), 'Gradient layer must survive conversion');
	check(str_contains($result, 'photo.avif?v=2'), 'Background URLs must retain query parameters');
	check(str_contains($result, 'type("image/jpeg")) !important;background-size:cover'), 'Authored priority and following longhands must survive');

	$inline = background(page('', '<div style="background-image:url(&quot;' . $base . '/photo.jpg&quot;)"></div>'));
	check(str_contains($inline, 'image-set('), 'Inline core-block backgrounds should convert');

	$linked = '@layer theme{@supports(display:grid){.hero{background:url(../photo.jpg) center no-repeat;background-size:cover}}}.hero{background:none}';
	file_put_contents($fixtures . '/css/page.css', $linked);
	// There is deliberately no JPEG URL in this HTML, and href precedes rel.
	$result = background(page('<link href="' . $base . '/css/page.css" rel="stylesheet" media="screen and (min-width: 600px)"><style>.later{background:none}</style>'));
	check(str_contains($result, 'class="aviflosu-bg-overrides" media="screen and (min-width: 600px)"'), 'Linked sheets must convert and retain link media');
	check(str_contains($result, '@layer theme{@supports(display:grid){'), 'Named layer and supports condition must remain nested');
	check(str_contains($result, 'url("' . $base . '/photo.avif")'), 'Relative URL must resolve against the CSS file directory');
	check(str_contains($result, 'background-size:cover}}}.hero{background:none}</style><style>.later'), 'Later background resets/longhands and subsequent style order must remain intact');
	check(!str_contains($result, 'data-aviflosu-background'), 'Internal insertion markers must be removed');
	file_put_contents($fixtures . '/css/query.css', '.hero{background:url(../photo.jpg?cache=https://cache.test/a/../b#keep)}');
	$result = background(page('<link rel="stylesheet" href="' . $base . '/css/query.css">'));
	check(str_contains($result, 'photo.avif?cache=https://cache.test/a/../b#keep'), 'Relative URL normalization must not modify query or fragment contents');

	foreach ([
		'@layer {.hero{background:url(../photo.jpg)!important}}',
		'@scope {.hero{background:url(../photo.jpg)}}',
		'.hero{background:url(../photo.jpg)}.other{background:url("../escaped\\ image.jpg")}',
		'@import "base.css";.hero{background:url(../photo.jpg)}',
		'.hero{background:url(../photo.jpg);all:unset}',
		'.hero{background:url(../photo.jpg);& .child{background:none}}',
		'.hero/* </style><script>alert(1)</script> */{background:url(../photo.jpg)}',
	] as $unsafeCss) {
		file_put_contents($fixtures . '/css/unsupported.css', $unsafeCss);
		$input = page('<link rel="stylesheet" href="' . $base . '/css/unsupported.css">');
		check(background($input) === $input, 'Unsupported mirror grammar must leave the original link untouched');
	}
	$input = page('<style>.hero{background:url(' . $base . '/photo.jpg);& .child{background:none}}</style>');
	check(background($input) === $input, 'Unsupported inline stylesheet nesting must stay unchanged');
	$input = page('<style>.hero{background:url(https://foreign.test/wp-content/uploads/photo.jpg)}</style>');
	check(background($input) === $input, 'Foreign host URLs must not use matching local companions');
	$input = page('<link rel="stylesheet" disabled href="' . $base . '/css/page.css">');
	check(background($input) === $input, 'Disabled sheets must not be activated by a mirror');

	echo "Frontend regression checks passed: $checks\n";
} finally {
	foreach (glob($fixtures . '/css/*') ?: [] as $file) { unlink($file); }
	foreach (glob($fixtures . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
	rmdir($fixtures . '/css');
	rmdir($fixtures);
}
