<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

// Prevent direct access
\defined('ABSPATH') || exit;

final class Converter
{
    public function init(): void
    {
        // Convert on upload (toggle)
        add_filter('wp_generate_attachment_metadata', [$this, 'convertGeneratedSizes'], 20, 2);
        add_filter('wp_handle_upload', [$this, 'convertOriginalOnUpload'], 20);

        // Scheduling
        add_action('init', [$this, 'maybe_schedule_daily']);
        add_action('aviflosu_daily_event', [$this, 'run_daily_scan']);
        add_action('aviflosu_run_on_demand', [$this, 'run_daily_scan']);

        if (\defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('avif-local-support convert', [$this, 'cliConvertAll']);
        }
    }

    public function maybe_schedule_daily(): void
    {
        $enabled = (bool) get_option('aviflosu_convert_via_schedule', true);
        if (!$enabled) {
            // clear if exists
            $timestamp = \wp_next_scheduled('aviflosu_daily_event');
            if ($timestamp) {
                \wp_unschedule_event($timestamp, 'aviflosu_daily_event');
            }
            return;
        }

        // compute next based on time option
        $time = (string) get_option('aviflosu_schedule_time', '01:00');
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '01:00';
        }
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $now = (int) current_time('timestamp', true);
        // Use DateTime with site timezone and compare using GMT epoch for correctness
        $tz = wp_timezone();
        $dt = new \DateTimeImmutable('@' . $now);
        $dt = $dt->setTimezone($tz);
        $targetToday = $dt->setTime($hour, $minute, 0);
        $nextDt = ($targetToday->getTimestamp() <= $now)
            ? $targetToday->modify('+1 day')
            : $targetToday;
        $next = $nextDt->getTimestamp();
        $existing = \wp_next_scheduled('aviflosu_daily_event');
        if ($existing === false) {
            \wp_schedule_event($next, 'daily', 'aviflosu_daily_event');
        } else {
            if (abs((int) $existing - (int) $next) > 60) {
                if (function_exists('wp_clear_scheduled_hook')) {
                    \wp_clear_scheduled_hook('aviflosu_daily_event');
                } else {
                    \wp_unschedule_event((int) $existing, 'aviflosu_daily_event');
                }
                \wp_schedule_event($next, 'daily', 'aviflosu_daily_event');
            }
        }
    }

    public function run_daily_scan(): void
    {
        $this->convertAllJpegsIfMissingAvif();
    }

    public function convertGeneratedSizes(array $metadata, int $attachmentId): array
    {
        $convertOnUpload = (bool) get_option('aviflosu_convert_on_upload', true);
        if (!$convertOnUpload) {
            return $metadata;
        }

        $mime = get_post_mime_type($attachmentId);
        if (!\is_string($mime) || !\in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return $metadata;
        }

        $uploadDir = \wp_upload_dir();
        $baseDir = \trailingslashit($uploadDir['basedir'] ?? '');

        // De-duped: convert original and sizes via shared helper
        $this->convertFromMetadata($metadata, $baseDir);

        return $metadata;
    }

    public function convertOriginalOnUpload(array $file): array
    {
        $convertOnUpload = (bool) get_option('aviflosu_convert_on_upload', true);
        if (!$convertOnUpload) {
            return $file;
        }
        $type = isset($file['type']) && \is_string($file['type']) ? strtolower($file['type']) : '';
        $path = isset($file['file']) && \is_string($file['file']) ? $file['file'] : '';
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/pjpeg'];
        $hasAllowedMime = \in_array($type, $allowedMimes, true);
        $hasJpegExt = $path !== '' && \preg_match('/\.(jpe?g)$/i', $path) === 1;
        if (!$hasAllowedMime && !$hasJpegExt) {
            return $file;
        }
        $this->checkMissingAvif($path);
        return $file;
    }

    private function checkMissingAvif(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (!preg_match('/\.(jpe?g)$/i', $path)) {
            return;
        }
        $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $path);
        if ($avifPath !== '' && file_exists($avifPath)) {
            return; // already converted
        }

        // Determine better source based on WordPress logic (if enabled)
        [$sourcePath, $targetDimensions] = $this->getConversionData($path);
        $this->convertToAvif($sourcePath, $avifPath, $targetDimensions);
    }

    private function convertToAvif(string $sourcePath, string $avifPath, ?array $targetDimensions): void
    {
        $quality = max(0, min(100, (int) get_option('aviflosu_quality', 85)));
        $speedSetting = max(0, min(10, (int) get_option('aviflosu_speed', 1)));
        $preserveMeta = (bool) get_option('aviflosu_preserve_metadata', true);
        $preserveICC = (bool) get_option('aviflosu_preserve_color_profile', true);

        // Ensure directory exists
        $dir = \dirname($avifPath);
        if (!is_dir($dir)) {
            \wp_mkdir_p($dir);
        }

        // Prefer Imagick for proper EXIF orientation and LANCZOS resizing
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                // Normalize orientation before any cropping/resizing
                if (method_exists($im, 'autoOrientImage')) {
                    $im->autoOrientImage();
                    // Reset orientation flag to top-left
                    if (defined('Imagick::ORIENTATION_TOPLEFT')) {
                        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                    }
                }

                if ($targetDimensions && isset($targetDimensions['width'], $targetDimensions['height'])) {
                    $srcW = $im->getImageWidth();
                    $srcH = $im->getImageHeight();
                    $tW = max(1, (int) $targetDimensions['width']);
                    $tH = max(1, (int) $targetDimensions['height']);
                    $srcAspect = $srcW / max(1, $srcH);
                    $tAspect = $tW / max(1, $tH);
                    if ($srcAspect > $tAspect) {
                        $cropH = $srcH;
                        $cropW = (int) ($srcH * $tAspect);
                        $cropX = (int) (($srcW - $cropW) / 2);
                        $cropY = 0;
                    } else {
                        $cropW = $srcW;
                        $cropH = (int) ($srcW / $tAspect);
                        $cropX = 0;
                        $cropY = (int) (($srcH - $cropH) / 2);
                    }
                    $im->cropImage($cropW, $cropH, $cropX, $cropY);
                    $im->resizeImage($tW, $tH, \Imagick::FILTER_LANCZOS, 1.0);
                }

                $im->setImageFormat('AVIF');
                $im->setImageCompressionQuality($quality);
                $im->setOption('avif:speed', (string) min(8, $speedSetting));

                if ($preserveMeta || $preserveICC) {
                    try {
                        $src = new \Imagick($sourcePath);
                        if ($preserveICC) {
                            $icc = $src->getImageProfile('icc');
                            if (!empty($icc)) { $im->setImageProfile('icc', $icc); }
                        }
                        if ($preserveMeta) {
                            foreach (['exif', 'xmp', 'iptc'] as $profile) {
                                $blob = $src->getImageProfile($profile);
                                if (!empty($blob)) { $im->setImageProfile($profile, $blob); }
                            }
                        }
                        $src->destroy();
                    } catch (\Exception $e) {
                        // ignore metadata errors
                    }
                }

                $im->writeImage($avifPath);
                $im->destroy();
                return;
            } catch (\Exception $e) {
                // fall through to GD
            }
        }

        // GD fallback with EXIF orientation handling
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo || ($imageInfo[2] !== IMAGETYPE_JPEG)) {
            return;
        }
        $gd = @imagecreatefromjpeg($sourcePath);
        if (!$gd) {
            return;
        }
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            if ($exif && isset($exif['Orientation'])) {
                $gdOriented = $this->applyExifOrientationGd($gd, (int) $exif['Orientation']);
                if ($gdOriented !== $gd) { imagedestroy($gd); $gd = $gdOriented; }
            }
        }
        if ($targetDimensions && isset($targetDimensions['width'], $targetDimensions['height'])) {
            $resized = $this->resizeGdMaintainCrop($gd, imagesx($gd), imagesy($gd), (int) $targetDimensions['width'], (int) $targetDimensions['height']);
            if ($resized) { imagedestroy($gd); $gd = $resized; }
        }
        if (function_exists('imageavif')) {
            $speed = min(8, $speedSetting);
            if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
                @imageavif($gd, $avifPath, $quality, $speed);
            } else {
                @imageavif($gd, $avifPath, $quality);
            }
        }
        imagedestroy($gd);
    }

    private function resizeGdMaintainCrop(\GdImage $source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): ?\GdImage
    {
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) { return null; }
        $sourceAspect = $sourceWidth / max(1, $sourceHeight);
        $targetAspect = $targetWidth / max(1, $targetHeight);
        if ($sourceAspect > $targetAspect) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) ($sourceHeight * $targetAspect);
            $cropX = (int) (($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) ($sourceWidth / $targetAspect);
            $cropX = 0;
            $cropY = (int) (($sourceHeight - $cropHeight) / 2);
        }
        $ok = imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        if (!$ok) { imagedestroy($target); return null; }
        return $target;
    }

    private function applyExifOrientationGd(\GdImage $source, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 1: // top-left
                return $source;
            case 2: // top-right
                if (function_exists('imageflip')) { imageflip($source, IMG_FLIP_HORIZONTAL); }
                return $source;
            case 3: // bottom-right
                $rot = imagerotate($source, 180, 0);
                return $rot ?: $source;
            case 4: // bottom-left
                if (function_exists('imageflip')) { imageflip($source, IMG_FLIP_VERTICAL); }
                return $source;
            case 5: // left-top
                $tmp = imagerotate($source, 270, 0);
                if ($tmp && function_exists('imageflip')) { imageflip($tmp, IMG_FLIP_HORIZONTAL); return $tmp; }
                return $tmp ?: $source;
            case 6: // right-top
                $rot = imagerotate($source, 270, 0);
                return $rot ?: $source;
            case 7: // right-bottom
                $tmp = imagerotate($source, 90, 0);
                if ($tmp && function_exists('imageflip')) { imageflip($tmp, IMG_FLIP_HORIZONTAL); return $tmp; }
                return $tmp ?: $source;
            case 8: // left-bottom
                $rot = imagerotate($source, 90, 0);
                return $rot ?: $source;
            default:
                return $source;
        }
    }

    private function getConversionData(string $jpegPath): array
    {
        $useWpLogic = (bool) get_option('aviflosu_wordpress_logic', true);
        $sourcePath = $jpegPath;
        $target = null;
        if ($useWpLogic) {
            $filename = basename($jpegPath);
            $directory = dirname($jpegPath);
            if (preg_match('/^(.+)-(\d+)x(\d+)\.(jpe?g)$/i', $filename, $m)) {
                $base = $m[1];
                $w = (int) $m[2];
                $h = (int) $m[3];
                $ext = $m[4];
                $candidates = [
                    $directory . '/' . $base . '.' . $ext,
                    $directory . '/' . $base . '-scaled.' . $ext,
                ];
                foreach ($candidates as $candidate) {
                    if (file_exists($candidate)) {
                        $srcReal = @realpath($candidate);
                        $tgtReal = @realpath($jpegPath);
                        if ($srcReal && $tgtReal && $srcReal !== $tgtReal) {
                            $sourcePath = $candidate;
                            $target = ['width' => $w, 'height' => $h];
                            break;
                        }
                    }
                }
            }
        }
        return [$sourcePath, $target];
    }

    /**
     * Shared: Convert original and generated size JPEGs found in attachment metadata.
     */
    private function convertFromMetadata(array $metadata, string $baseDir): void
    {
        if (!empty($metadata['file']) && \is_string($metadata['file'])) {
            $originalPath = $baseDir . $metadata['file'];
            $this->checkMissingAvif($originalPath);
        }
        if (!empty($metadata['sizes']) && \is_array($metadata['sizes'])) {
            $relativeDir = pathinfo((string) ($metadata['file'] ?? ''), PATHINFO_DIRNAME);
            if ($relativeDir === '.' || $relativeDir === DIRECTORY_SEPARATOR) { $relativeDir = ''; }
            foreach ($metadata['sizes'] as $sizeData) {
                if (!empty($sizeData['file'])) {
                    $sizePath = $baseDir . \trailingslashit($relativeDir) . $sizeData['file'];
                    $this->checkMissingAvif($sizePath);
                }
            }
        }
    }

    // Optional: WP-CLI bulk conversion
    public function cliConvertAll(): void
    {
        $this->convertAllJpegsIfMissingAvif(true);
    }

    private function convertAllJpegsIfMissingAvif(bool $cli = false): void
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $count = 0;
        foreach ($query->posts as $attachmentId) {
            $mime = get_post_mime_type($attachmentId);
            if (!\is_string($mime) || !\in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
                continue;
            }
            $path = get_attached_file($attachmentId);
            if ($path) {
                $this->checkMissingAvif($path);
            }
            $meta = wp_get_attachment_metadata($attachmentId);
            if ($meta) {
                $this->convertGeneratedSizesForce($meta, $attachmentId);
            }
            $count++;
        }
        if ($cli && \defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::success("Scanned attachments: {$count}");
        }
    }

    private function convertGeneratedSizesForce(array $metadata, int $attachmentId): void
    {
        $mime = get_post_mime_type($attachmentId);
        if (!\is_string($mime) || !\in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return;
        }
        $uploadDir = \wp_upload_dir();
        $baseDir = \trailingslashit($uploadDir['basedir'] ?? '');

        // De-duped: convert original and sizes via shared helper
        $this->convertFromMetadata($metadata, $baseDir);
    }

    /**
     * Convert the original and all generated JPEG sizes for a specific attachment, using current settings.
     * Returns a structured array with URLs and paths for each size and whether conversion occurred.
     *
     * This is used by the Tools → Upload Test section.
     */
    public function convertAttachmentNow(int $attachmentId): array
    {
        $results = [
            'attachment_id' => $attachmentId,
            'sizes' => [],
        ];

        $mime = get_post_mime_type($attachmentId);
        if (!\is_string($mime) || !\in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return $results;
        }

        $uploads = \wp_upload_dir();
        $baseDir = \trailingslashit($uploads['basedir'] ?? '');
        $baseUrl = \trailingslashit($uploads['baseurl'] ?? '');

        $meta = \wp_get_attachment_metadata($attachmentId) ?: [];
        $originalAbs = \get_attached_file($attachmentId) ?: '';

        // Derive relative paths for URLs
        $originalRel = '';
        if (!empty($meta['file']) && \is_string($meta['file'])) {
            $originalRel = (string) $meta['file'];
        } elseif ($originalAbs !== '' && str_starts_with($originalAbs, $baseDir)) {
            $originalRel = ltrim(substr($originalAbs, strlen($baseDir)), '/');
        }
        $dirRel = pathinfo($originalRel, PATHINFO_DIRNAME);
        if ($dirRel === '.' || $dirRel === DIRECTORY_SEPARATOR) { $dirRel = ''; }

        $addRow = function (string $label, string $jpegAbs, string $jpegRel, ?int $width, ?int $height) use (&$results, $baseUrl): void {
            $avifAbs = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegAbs);
            $avifRel = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegRel);
            $jpegUrl = $jpegRel !== '' ? $baseUrl . $jpegRel : '';
            $avifUrl = $avifRel !== '' ? $baseUrl . $avifRel : '';
            $results['sizes'][] = [
                'name' => $label,
                'jpeg_path' => $jpegAbs,
                'jpeg_url' => $jpegUrl,
                'avif_path' => $avifAbs,
                'avif_url' => $avifUrl,
                'width' => $width,
                'height' => $height,
                'jpeg_size' => file_exists($jpegAbs) ? (int) filesize($jpegAbs) : 0,
                'avif_size' => file_exists($avifAbs) ? (int) filesize($avifAbs) : 0,
                'existed_before' => file_exists($avifAbs),
                'converted' => false,
            ];
        };

        if ($originalAbs !== '' && file_exists($originalAbs)) {
            $w = isset($meta['width']) ? (int) $meta['width'] : null;
            $h = isset($meta['height']) ? (int) $meta['height'] : null;
            $addRow('original', $originalAbs, $originalRel, $w, $h);
        }
        if (!empty($meta['sizes']) && \is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $sizeData) {
                if (empty($sizeData['file']) || !\is_string($sizeData['file'])) { continue; }
                $jpegRel = ($dirRel !== '' ? \trailingslashit($dirRel) : '') . $sizeData['file'];
                $jpegAbs = $baseDir . $jpegRel;
                if (!file_exists($jpegAbs)) { continue; }
                $width = isset($sizeData['width']) ? (int) $sizeData['width'] : null;
                $height = isset($sizeData['height']) ? (int) $sizeData['height'] : null;
                $addRow((string) $sizeName, $jpegAbs, $jpegRel, $width, $height);
            }
        }

        // Perform conversion for each row using the same pipeline
        foreach ($results['sizes'] as &$row) {
            $this->checkMissingAvif($row['jpeg_path']);
            $row['converted'] = file_exists($row['avif_path']);
            // Refresh sizes after conversion
            $row['jpeg_size'] = file_exists($row['jpeg_path']) ? (int) filesize($row['jpeg_path']) : 0;
            $row['avif_size'] = file_exists($row['avif_path']) ? (int) filesize($row['avif_path']) : 0;
        }
        unset($row);

        return $results;
    }
}
