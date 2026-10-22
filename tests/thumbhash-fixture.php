<?php
/** Browser-fixture support only; no WordPress bootstrap, database, or saved settings. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
define('AVIFLOSU_PLUGIN_DIR', rtrim(getenv('THUMBHASH_PLUGIN_DIR') ?: dirname(__DIR__), '/') . '/');
function get_option($name, $default = false) {
	return $name === 'aviflosu_lqip_fade' ? ($GLOBALS['argv'][1] ?? '0') === '1' : $default;
}
if (($argv[1] ?? '') !== 'image') {
	require AVIFLOSU_PLUGIN_DIR . 'includes/class-support.php';
	(new Ddegner\AvifLocalSupport\Support())->inlineThumbHashDecoder();
	exit;
}
$file = getenv('PHOTO_FILE');
if ($file) {
	$bytes = file_get_contents($file);
	$image = imagecreatefromstring($bytes);
} else {
	$image = imagecreatetruecolor(600, 400);
	for ($y = 0; $y < 400; ++$y) {
		$c = imagecolorallocate($image, 35 + (int) ($y / 3), 80 + (int) ($y / 4), 165 - (int) ($y / 4));
		imageline($image, 0, $y, 599, $y, $c);
	}
	imagefilledellipse($image, 440, 100, 90, 90, imagecolorallocate($image, 255, 220, 150));
	imagefilledpolygon($image, [0, 400, 200, 150, 400, 400], imagecolorallocate($image, 40, 70, 65));
	ob_start();
	imagejpeg($image, null, 90);
	$bytes = ob_get_clean();
}
$w = imagesx($image);
$h = imagesy($image);
$tw = max(1, (int) round(32 * $w / max($w, $h)));
$th = max(1, (int) round(32 * $h / max($w, $h)));
$thumb = imagecreatetruecolor($tw, $th);
imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
$rgba = [];
for ($y = 0; $y < $th; ++$y) {
	for ($x = 0; $x < $tw; ++$x) {
		$c = imagecolorat($thumb, $x, $y);
		array_push($rgba, ($c >> 16) & 255, ($c >> 8) & 255, $c & 255, 255);
	}
}
require dirname(__DIR__) . '/lib/Thumbhash/Thumbhash.php';
$hash = Thumbhash\Thumbhash::RGBAToHash($tw, $th, $rgba);
echo json_encode(['bytes' => base64_encode($bytes), 'hash' => base64_encode(pack('C*', ...$hash)), 'width' => $w, 'height' => $h], JSON_THROW_ON_ERROR);
