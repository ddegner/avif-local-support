<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

// Prevent direct access
\defined('ABSPATH') || exit;

final class Converter
{
    private ?Plugin $plugin = null;
    // Debug info for last CLI run
    private string $lastCliCommand = '';
    private int $lastCliExitCode = 0;
    private string $lastCliOutput = '';

    public function set_plugin(Plugin $plugin): void
    {
        $this->plugin = $plugin;
    }

    public function init(): void
    {
        // Convert on upload (toggle)
        add_filter('wp_generate_attachment_metadata', [$this, 'convertGeneratedSizes'], 20, 2);
        // Also catch edits/regenerations that update metadata outside of generation
        add_filter('wp_update_attachment_metadata', [$this, 'convertGeneratedSizes'], 20, 2);
        add_filter('wp_handle_upload', [$this, 'convertOriginalOnUpload'], 20);

        // Scheduling
        add_action('init', [$this, 'maybe_schedule_daily']);
        add_action('aviflosu_daily_event', [$this, 'run_daily_scan']);
        add_action('aviflosu_run_on_demand', [$this, 'run_daily_scan']);

        // Deletion: keep .avif companions in sync when media is removed
        add_action('delete_attachment', [$this, 'deleteAvifsForAttachment']);
        add_filter('wp_delete_file', [$this, 'deleteCompanionAvif']);

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
        $start_time = microtime(true);
        $engine_used = 'unknown';
        $error_message = null;

        $quality = max(0, min(100, (int) get_option('aviflosu_quality', 85)));
        $speedSetting = max(0, min(10, (int) get_option('aviflosu_speed', 1)));
        $lossless = ($quality >= 100);
        // Preserve only ICC by default; strip other metadata to let quality dominate file size
        $preserveMeta = false;
        $preserveICC = true;

        // New options: chroma subsampling and bit depth (Imagick only)
        $subsampling = (string) get_option('aviflosu_subsampling', '420');
        if (!in_array($subsampling, ['420', '422', '444'], true)) {
            $subsampling = '420';
        }
        $bitDepth = (string) get_option('aviflosu_bit_depth', '8');
        if (!in_array($bitDepth, ['8', '10', '12'], true)) {
            $bitDepth = '8';
        }

        // Capture the actual settings used for accurate logging
        $actualSettings = [
            'quality' => $quality,
            'speed' => $speedSetting,
            'subsampling' => $subsampling,
            'bit_depth' => $bitDepth,
            'engine_mode' => (string) get_option('aviflosu_engine_mode', 'auto'),
            'cli_path' => (string) get_option('aviflosu_cli_path', ''),
            'convert_on_upload' => (bool) get_option('aviflosu_convert_on_upload', true),
            'convert_via_schedule' => (bool) get_option('aviflosu_convert_via_schedule', true),
            'disable_memory_check' => (bool) get_option('aviflosu_disable_memory_check', false),
            'lossless' => $lossless,
        ];

        // Ensure directory exists
        $dir = \dirname($avifPath);
        if (!is_dir($dir)) {
            \wp_mkdir_p($dir);
        }

        // Pre-check memory usage to avoid fatal errors on low-memory environments
        // Skip if disabled via settings
        $disableMemoryCheck = (bool) get_option('aviflosu_disable_memory_check', false);
        if (!$disableMemoryCheck) {
            $memoryWarning = $this->check_memory_safe($sourcePath);
            if ($memoryWarning) {
                $actualSettings['error_suggestion'] = 'Increase PHP memory_limit, switch to CLI engine, or disable memory check in settings.';
                $this->log_conversion('error', $sourcePath, $avifPath, $engine_used, $start_time, $memoryWarning, $actualSettings);
                return;
            }
        }

        // Engine selection: CLI or Auto (Imagick/GD)
        $engineMode = (string) get_option('aviflosu_engine_mode', 'auto');

        // If CLI selected, try CLI first
        if ($engineMode === 'cli') {
            $engine_used = 'cli';
            $ok = $this->convertViaCli($sourcePath, $avifPath, $targetDimensions, $quality, $speedSetting, $subsampling, $bitDepth, $lossless);
            if ($ok) {
                $this->log_conversion('success', $sourcePath, $avifPath, $engine_used, $start_time, null, $actualSettings);
                return;
            }
            // Do not fall back when user explicitly selects CLI; make failure visible in logs
            $snippet = $this->lastCliOutput !== '' ? substr($this->lastCliOutput, 0, 800) : '';
            $err = 'CLI conversion failed'
                . ' (exit ' . $this->lastCliExitCode . ')'
                . ($this->lastCliCommand !== '' ? ' cmd: ' . $this->lastCliCommand : '')
                . ($snippet !== '' ? ' output: ' . $snippet : '');

            // Analyze CLI error for suggestions
            $suggestion = 'Check if the binary path is correct and executable.';
            $outLower = strtolower($this->lastCliOutput);
            if ($this->lastCliExitCode === 127) {
                $suggestion = 'Binary not found or not executable. Verify path in settings.';
            } elseif (str_contains($outLower, 'delegate') || str_contains($outLower, 'no decode delegate')) {
                $suggestion = 'ImageMagick is missing a delegate (libjpeg/libavif). Install required libraries.';
            } elseif (str_contains($outLower, 'memory') || str_contains($outLower, 'resource limit')) {
                $suggestion = 'Server ran out of memory/resources. Increase RAM or swap.';
            }
            $actualSettings['error_suggestion'] = $suggestion;

            $this->log_conversion('error', $sourcePath, $avifPath, $engine_used, $start_time, $err, $actualSettings);
            return;
        }

        // Try Imagick
        $engine_used = 'imagick';
        $imagickResult = $this->convertViaImagick($sourcePath, $avifPath, $targetDimensions, $quality, $speedSetting, $subsampling, $bitDepth, $lossless, $preserveMeta, $preserveICC);

        if ($imagickResult['success']) {
            $this->log_conversion('success', $sourcePath, $avifPath, $engine_used, $start_time, null, $actualSettings);
            return;
        }

        // If Imagick failed but was the ONLY selected engine (unlikely with current 'auto'/'cli' logic, but good for future), stop.
        // Current logic: 'auto' means Imagick -> GD.
        // If Imagick was attempted and failed, we check if we should fall back.
        // The previous logic fell back to GD if Imagick failed OR wasn't available.

        // If Imagick failed with a specific error, we might want to log it if we don't fall back, 
        // OR if we do fall back, we might want to note it.
        // For now, let's stick to the original behavior: Fallback to GD if Imagick fails or is missing.

        // GD Fallback
        $engine_used = 'gd';
        $gdResult = $this->convertViaGd($sourcePath, $avifPath, $targetDimensions, $quality, $speedSetting);

        if ($gdResult['success']) {
            $this->log_conversion('success', $sourcePath, $avifPath, $engine_used, $start_time, null, $actualSettings);
        } else {
            // If GD also failed, or was the only option and failed
            $errorMsg = $gdResult['error'] ?? 'GD conversion failed';
            $actualSettings['error_suggestion'] = $gdResult['suggestion'] ?? '';

            // If we previously tried Imagick and it failed, maybe mention that too? 
            // The original code just logged the last error.

            $this->log_conversion('error', $sourcePath, $avifPath, $engine_used, $start_time, $errorMsg, $actualSettings);
        }
    }

    private function convertViaImagick(string $sourcePath, string $avifPath, ?array $targetDimensions, int $quality, int $speedSetting, string $subsampling, string $bitDepth, bool $lossless, bool $preserveMeta, bool $preserveICC): array
    {
        if (!extension_loaded('imagick')) {
            return ['success' => false, 'error' => 'Imagick extension not loaded'];
        }

        try {
            // Quick check for AVIF support
            try {
                $tmpImagick = new \Imagick();
                $supportsAvif = (bool) $tmpImagick->queryFormats('AVIF');
                $tmpImagick->destroy();
                if (!$supportsAvif) {
                    return ['success' => false, 'error' => 'Imagick installed but AVIF format not supported'];
                }
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Imagick check failed: ' . $e->getMessage()];
            }

            $im = new \Imagick($sourcePath);

            // Capture ICC/metadata profiles
            $originalIcc = '';
            $hadIcc = false;
            try {
                $originalIcc = $im->getImageProfile('icc');
                $hadIcc = !empty($originalIcc);
            } catch (\Exception $e) {
                $hadIcc = false;
            }

            // Normalize orientation
            if (method_exists($im, 'autoOrientImage')) {
                $im->autoOrientImage();
                if (defined('Imagick::ORIENTATION_TOPLEFT')) {
                    $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                }
            }

            // Resize/Crop
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

            // Settings
            @$im->setOption('avif:quality', (string) $quality);
            $im->setImageCompressionQuality($quality);
            @$im->setOption('avif:speed', (string) min(8, $speedSetting));
            if ($lossless) {
                @$im->setOption('avif:lossless', 'true');
            }

            // Metadata stripping (keep ICC if requested)
            if (method_exists($im, 'stripImage')) {
                $im->stripImage();
            }

            // Color space handling
            if (!$hadIcc) {
                $originalColorspace = method_exists($im, 'getImageColorspace') ? (int) $im->getImageColorspace() : null;
                if (method_exists($im, 'transformImageColorspace')) {
                    $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                } elseif (method_exists($im, 'setImageColorspace')) {
                    $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                }
            }

            // Subsampling and Bit Depth
            $chromaLabel = $subsampling === '444' ? '4:4:4' : ($subsampling === '422' ? '4:2:2' : '4:2:0');
            if ($lossless) {
                $chromaLabel = '4:4:4';
            }
            @$im->setOption('avif:chroma-subsample', $chromaLabel);
            @$im->setOption('avif:bit-depth', $bitDepth);
            $im->setImageDepth((int) $bitDepth);

            // NCLX for untagged images
            if (!$hadIcc) {
                @$im->setOption('avif:color-primaries', '1');
                @$im->setOption('avif:transfer-characteristics', '13');
                @$im->setOption('avif:matrix-coefficients', '1');
                @$im->setOption('avif:range', 'full');
            }

            // Restore ICC if needed
            if ($preserveMeta || $preserveICC) {
                try {
                    if ($preserveICC && $hadIcc && !empty($originalIcc)) {
                        $im->setImageProfile('icc', $originalIcc);
                    }
                } catch (\Exception $e) {
                }
            }

            // Fix EXIF Orientation tag
            if (method_exists($im, 'setImageProperty')) {
                try {
                    $im->setImageProperty('exif:Orientation', '1');
                } catch (\Exception $e) {
                }
            }

            $im->writeImage($avifPath);
            $im->destroy();

            if (@file_exists($avifPath) && @filesize($avifPath) > 512) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => 'Imagick produced an invalid AVIF (missing delegate or placeholder output)',
                'suggestion' => 'Your ImageMagick build seems to lack proper AVIF write support, or the file is empty.'
            ];

        } catch (\Exception $e) {
            $msg = strtolower($e->getMessage());
            $suggestion = 'Check PHP error logs for detailed Imagick crashes.';
            if (str_contains($msg, 'unable to load module')) {
                $suggestion = 'Imagick PHP module configuration is broken. Reinstall Imagick.';
            } elseif (str_contains($msg, 'no decode delegate')) {
                $suggestion = 'Imagick cannot read this file format.';
            }

            return [
                'success' => false,
                'error' => 'Imagick conversion failed: ' . $e->getMessage(),
                'suggestion' => $suggestion
            ];
        }
    }

    private function convertViaGd(string $sourcePath, string $avifPath, ?array $targetDimensions, int $quality, int $speedSetting): array
    {
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo || ($imageInfo[2] !== IMAGETYPE_JPEG)) {
            return ['success' => false, 'error' => 'Invalid JPEG file or could not read image info'];
        }

        $gd = @imagecreatefromjpeg($sourcePath);
        if (!$gd) {
            return [
                'success' => false,
                'error' => 'Failed to create GD resource from JPEG',
                'suggestion' => 'Input file may be corrupt or PHP memory limit is too low.'
            ];
        }

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            if ($exif && isset($exif['Orientation'])) {
                $gdOriented = $this->applyExifOrientationGd($gd, (int) $exif['Orientation']);
                if ($gdOriented !== $gd) {
                    imagedestroy($gd);
                    $gd = $gdOriented;
                }
            }
        }

        if ($targetDimensions && isset($targetDimensions['width'], $targetDimensions['height'])) {
            $resized = $this->resizeGdMaintainCrop($gd, imagesx($gd), imagesy($gd), (int) $targetDimensions['width'], (int) $targetDimensions['height']);
            if ($resized) {
                imagedestroy($gd);
                $gd = $resized;
            }
        }

        if (function_exists('imageavif')) {
            $speed = min(8, $speedSetting);
            $success = false;
            if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
                $success = @imageavif($gd, $avifPath, $quality, $speed);
            } else {
                $success = @imageavif($gd, $avifPath, $quality);
            }

            imagedestroy($gd);

            if ($success && file_exists($avifPath)) {
                return ['success' => true];
            } else {
                return [
                    'success' => false,
                    'error' => 'GD imageavif function failed or file not created',
                    'suggestion' => 'GD failed to write AVIF. Check directory permissions or GD version.'
                ];
            }
        }

        imagedestroy($gd);
        return [
            'success' => false,
            'error' => 'imageavif function not available in GD',
            'suggestion' => 'Upgrade to PHP 8.1+ or compile GD with AVIF support.'
        ];
    }


    /**
     * Safely check if we have enough memory to process this image via PHP (GD/Imagick).
     * Returns a warning string if memory is dangerously low, null if it seems okay.
     */
    private function check_memory_safe(string $path): ?string
    {
        $limit = ini_get('memory_limit');
        // No limit
        if ($limit === '-1') {
            return null;
        }

        $limitBytes = $this->parse_memory_limit((string) $limit);
        if ($limitBytes <= 0) {
            return null; // Could not parse or zero, assume fine
        }

        // Get current usage
        $currentUsage = memory_get_usage(true);

        // Estimate image memory usage:
        // (width * height * channels * bits_per_channel) + overhead
        // Approximating RGBA (4 channels) at 1 byte per channel (8-bit) * 1.8 overhead for GD/Imagick structures
        $info = @getimagesize($path);
        if (!$info) {
            // Can't read info, so can't estimate. Proceed cautiously.
            return null;
        }

        $width = isset($info[0]) ? (int) $info[0] : 0;
        $height = isset($info[1]) ? (int) $info[1] : 0;
        $channels = isset($info['channels']) ? (int) $info['channels'] : 4;
        if ($channels <= 0) {
            $channels = 4;
        }

        // Rough estimation: width * height * 4 (RGBA) * 1.7 (overhead factor)
        $estimatedNeed = (int) ($width * $height * 4 * 1.7);

        // Add buffer (10MB) for other script overhead
        $buffer = 10 * 1024 * 1024;

        if (($currentUsage + $estimatedNeed + $buffer) > $limitBytes) {
            $fmtLimit = size_format($limitBytes);
            $fmtNeed = size_format($estimatedNeed);
            return "High risk of memory exhaustion. Memory limit: $fmtLimit. Estimated need: $fmtNeed. Current usage: " . size_format($currentUsage) . ".";
        }

        return null;
    }

    private function parse_memory_limit(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $n = (int) $val;
        switch ($last) {
            case 'g':
                $n *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $n *= 1024 * 1024;
                break;
            case 'k':
                $n *= 1024;
                break;
        }
        return $n;
    }

    /**
     * Get current settings for logging
     */
    private function get_current_settings(): array
    {
        return [
            'quality' => (int) get_option('aviflosu_quality', 85),
            'speed' => (int) get_option('aviflosu_speed', 1),
            'subsampling' => (string) get_option('aviflosu_subsampling', '420'),
            'bit_depth' => (string) get_option('aviflosu_bit_depth', '8'),
            'engine_mode' => (string) get_option('aviflosu_engine_mode', 'auto'),
            'cli_path' => (string) get_option('aviflosu_cli_path', ''),
            'convert_on_upload' => (bool) get_option('aviflosu_convert_on_upload', true),
            'convert_via_schedule' => (bool) get_option('aviflosu_convert_via_schedule', true),
            'disable_memory_check' => (bool) get_option('aviflosu_disable_memory_check', false),
        ];
    }


    /**
     * Log conversion attempt with all relevant details
     */
    private function log_conversion(string $status, string $sourcePath, string $avifPath, string $engine_used, float $start_time, ?string $error_message = null, ?array $actualSettings = null): void
    {
        if (!$this->plugin) {
            return;
        }

        $end_time = microtime(true);
        $duration = round(($end_time - $start_time) * 1000, 2); // Convert to milliseconds

        $details = [
            'source_file' => basename($sourcePath),
            'target_file' => basename($avifPath),
            'engine_used' => $engine_used,
            'duration_ms' => $duration,
            'source_size' => file_exists($sourcePath) ? filesize($sourcePath) : 0,
            'target_size' => file_exists($avifPath) ? filesize($avifPath) : 0,
        ];

        // Use actual settings used during conversion if provided, otherwise fall back to current settings
        if ($actualSettings !== null) {
            $details = array_merge($details, $actualSettings);
        } else {
            $details = array_merge($details, $this->get_current_settings());
        }

        // Add error message if provided
        if ($error_message) {
            $details['error'] = $error_message;
        }

        $message = $status === 'success'
            ? "Successfully converted " . basename($sourcePath) . " to AVIF using $engine_used"
            : "Failed to convert " . basename($sourcePath) . " to AVIF using $engine_used";

        if ($error_message) {
            $message .= ": $error_message";
        }

        $this->plugin->add_log($status, $message, $details);
    }

    private function convertViaCli(string $sourcePath, string $avifPath, ?array $targetDimensions, int $quality, int $speedSetting, string $subsampling, string $bitDepth, bool $lossless): bool
    {
        $bin = (string) get_option('aviflosu_cli_path', '');
        if ($bin === '') {
            $this->lastCliCommand = '';
            $this->lastCliExitCode = 127;
            $this->lastCliOutput = 'CLI binary path is empty';
            return false;
        }

        // More detailed validation of the binary
        if (!@file_exists($bin)) {
            $this->lastCliCommand = '';
            $this->lastCliExitCode = 127;
            $this->lastCliOutput = 'CLI binary does not exist: ' . $bin;
            return false;
        }

        if (!@is_executable($bin)) {
            // Try to get more detailed error information
            $perms = @fileperms($bin);
            $permStr = $perms ? sprintf('%o', $perms & 0777) : 'unknown';
            $this->lastCliCommand = '';
            $this->lastCliExitCode = 127;
            $this->lastCliOutput = 'CLI binary not executable: ' . $bin . ' (permissions: ' . $permStr . ')';
            return false;
        }

        // Test if the binary actually works by running a simple command
        $testCmd = escapeshellarg($bin) . ' -version 2>&1';
        @exec($testCmd, $testOutput, $testCode);
        if ($testCode !== 0) {
            $this->lastCliCommand = $testCmd;
            $this->lastCliExitCode = $testCode;
            $this->lastCliOutput = 'CLI binary test failed (exit ' . $testCode . '): ' . implode("\n", $testOutput);
            return false;
        }

        $args = [];
        // Auto-orient and crop/resize to exact target size
        $resizeArgs = [];
        if ($targetDimensions && isset($targetDimensions['width'], $targetDimensions['height'])) {
            $tW = max(1, (int) $targetDimensions['width']);
            $tH = max(1, (int) $targetDimensions['height']);
            $resizeArgs = ['-auto-orient', '-thumbnail', $tW . 'x' . $tH . '^', '-gravity', 'center', '-extent', $tW . 'x' . $tH];
        } else {
            $resizeArgs = ['-auto-orient'];
        }

        $defines = [];
        // Keep CLI simple and consistent with Imagick path
        $defines[] = '-strip';
        $defines[] = '-quality';
        $defines[] = (string) $quality;
        // Clamp speed to <=8 for libheif stability
        $defines[] = '-define';
        $defines[] = 'avif:speed=' . (string) min(8, $speedSetting);
        if ($lossless) {
            $defines[] = '-define';
            $defines[] = 'avif:lossless=true';
        }
        // Chroma subsampling
        $chromaLabel = $subsampling === '444' ? '4:4:4' : ($subsampling === '422' ? '4:2:2' : '4:2:0');
        if ($lossless) {
            $chromaLabel = '4:4:4';
        }
        $defines[] = '-define';
        $defines[] = 'avif:chroma-subsample=' . $chromaLabel;
        // Bit depth
        $defines[] = '-depth';
        $defines[] = (string) (int) $bitDepth;
        $defines[] = '-define';
        $defines[] = 'avif:bit-depth=' . (string) $bitDepth;

        // Colorspace handling when no ICC is present: attempt to ensure sRGB
        // We cannot cheaply inspect ICC here; applying sRGB transform safely for JPEGs
        $colorArgs = ['-colorspace', 'sRGB'];

        $cmd = [];
        $cmd[] = escapeshellarg($bin);
        // Input file — force JPEG coder explicitly to avoid delegate detection quirks
        $sourceArg = $sourcePath;
        if (preg_match('/\.(jpe?g)$/i', $sourceArg)) {
            $sourceArg = 'jpeg:' . $sourceArg;
        }
        $cmd[] = escapeshellarg($sourceArg);
        foreach (array_merge($resizeArgs, $defines, $colorArgs) as $a) {
            $cmd[] = escapeshellarg($a);
        }
        // Output file — keep standard path, but allow explicit AVIF coder prefix
        $outputArg = $avifPath;
        if (preg_match('/\.avif$/i', $outputArg)) {
            $outputArg = 'avif:' . $outputArg;
        }
        $cmd[] = escapeshellarg($outputArg);

        $full = implode(' ', $cmd) . ' 2>&1';
        $output = [];
        $code = 0;
        // Capture command, exit code, and output for debugging
        $this->lastCliCommand = $full;
        $this->lastCliExitCode = 0;
        $this->lastCliOutput = '';

        // Retry loop for transient delegate/read errors observed on some environments
        $attempts = 0;
        $maxAttempts = 3;
        do {
            $output = [];
            $code = 0;
            @exec($full, $output, $code);
            $this->lastCliExitCode = (int) $code;
            $this->lastCliOutput = is_array($output) ? implode("\n", $output) : '';
            if ($code === 0) {
                break;
            }
            $outLower = strtolower($this->lastCliOutput);
            $isTransient = (str_contains($outLower, 'no decode delegate')
                || str_contains($outLower, 'readimage')
                || str_contains($outLower, 'no images defined'));
            if (!$isTransient) {
                break;
            }
            usleep(250000); // 250ms backoff
            $attempts++;
        } while ($attempts < $maxAttempts);

        if ($code !== 0) {
            return false;
        }
        // Success exit code but no/invalid output file: treat as failure and provide a helpful hint
        if (!@file_exists($avifPath) || @filesize($avifPath) <= 512) {
            $this->lastCliOutput = trim($this->lastCliOutput . "\n")
                . 'No output file created or file too small; AVIF may not be supported by this ImageMagick build. '
                . 'Try: "' . escapeshellarg($bin) . ' -list format | grep -i AVIF"';
            return false;
        }
        return true;
    }

    private function resizeGdMaintainCrop(\GdImage $source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight): ?\GdImage
    {
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target) {
            return null;
        }
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
        if (!$ok) {
            imagedestroy($target);
            return null;
        }
        return $target;
    }

    private function applyExifOrientationGd(\GdImage $source, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 1: // top-left
                return $source;
            case 2: // top-right
                if (function_exists('imageflip')) {
                    imageflip($source, IMG_FLIP_HORIZONTAL);
                }
                return $source;
            case 3: // bottom-right
                $rot = imagerotate($source, 180, 0);
                return $rot ?: $source;
            case 4: // bottom-left
                if (function_exists('imageflip')) {
                    imageflip($source, IMG_FLIP_VERTICAL);
                }
                return $source;
            case 5: // left-top
                $tmp = imagerotate($source, 270, 0);
                if ($tmp && function_exists('imageflip')) {
                    imageflip($tmp, IMG_FLIP_HORIZONTAL);
                    return $tmp;
                }
                return $tmp ?: $source;
            case 6: // right-top
                $rot = imagerotate($source, 270, 0);
                return $rot ?: $source;
            case 7: // right-bottom
                $tmp = imagerotate($source, 90, 0);
                if ($tmp && function_exists('imageflip')) {
                    imageflip($tmp, IMG_FLIP_HORIZONTAL);
                    return $tmp;
                }
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
        // Always use WordPress logic to avoid double-resizing
        $useWpLogic = true;
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
            if ($relativeDir === '.' || $relativeDir === DIRECTORY_SEPARATOR) {
                $relativeDir = '';
            }
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
        if ($dirRel === '.' || $dirRel === DIRECTORY_SEPARATOR) {
            $dirRel = '';
        }

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
                if (empty($sizeData['file']) || !\is_string($sizeData['file'])) {
                    continue;
                }
                $jpegRel = ($dirRel !== '' ? \trailingslashit($dirRel) : '') . $sizeData['file'];
                $jpegAbs = $baseDir . $jpegRel;
                if (!file_exists($jpegAbs)) {
                    continue;
                }
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

    /**
     * When an attachment is permanently deleted, remove any companion .avif files
     * for the original and its generated sizes.
     */
    public function deleteAvifsForAttachment(int $attachmentId): void
    {
        $mime = get_post_mime_type($attachmentId);
        if (!\is_string($mime) || !\in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return;
        }

        $uploads = \wp_upload_dir();
        $baseDir = \trailingslashit($uploads['basedir'] ?? '');

        $meta = \wp_get_attachment_metadata($attachmentId) ?: [];

        $paths = [];
        if (!empty($meta['file']) && \is_string($meta['file'])) {
            $paths[] = $baseDir . $meta['file'];
        } else {
            $attached = \get_attached_file($attachmentId);
            if ($attached) {
                $paths[] = (string) $attached;
            }
        }

        if (!empty($meta['sizes']) && \is_array($meta['sizes'])) {
            $dirRel = pathinfo((string) ($meta['file'] ?? ''), PATHINFO_DIRNAME);
            if ($dirRel === '.' || $dirRel === DIRECTORY_SEPARATOR) {
                $dirRel = '';
            }
            foreach ($meta['sizes'] as $sizeData) {
                if (!empty($sizeData['file']) && \is_string($sizeData['file'])) {
                    $paths[] = $baseDir . \trailingslashit($dirRel) . $sizeData['file'];
                }
            }
        }

        foreach ($paths as $jpegPath) {
            if (!\is_string($jpegPath) || $jpegPath === '' || !@file_exists($jpegPath)) {
                continue;
            }
            if (!preg_match('/\.(jpe?g)$/i', $jpegPath)) {
                continue;
            }
            $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegPath);
            if ($avifPath !== '' && @file_exists($avifPath)) {
                // Do not follow symlinks
                if (@is_link($avifPath)) {
                    continue;
                }
                \wp_delete_file($avifPath);
            }
        }
    }

    /**
     * When WordPress deletes a specific file (e.g., a resized JPEG), also delete its
     * .avif companion if present. Must return the original path so core proceeds.
     */
    public function deleteCompanionAvif(string $path): string
    {
        if (\is_string($path) && $path !== '' && preg_match('/\.(jpe?g)$/i', $path)) {
            $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $path);
            if ($avifPath !== '' && @file_exists($avifPath) && !@is_link($avifPath)) {
                \wp_delete_file($avifPath);
            }
        }
        return $path;
    }
}
