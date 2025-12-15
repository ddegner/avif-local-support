<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;
use Ddegner\AvifLocalSupport\Encoders\CliEncoder;
use Ddegner\AvifLocalSupport\Encoders\GdEncoder;
use Ddegner\AvifLocalSupport\Encoders\ImagickEncoder;

// Prevent direct access
defined('ABSPATH') || exit;

final class Converter
{
    private const JPEG_MIMES = ['image/jpeg', 'image/jpg'];

    private ?Plugin $plugin = null;
    private ?Logger $logger = null;

    /** @var AvifEncoderInterface[] */
    private array $encoders = [];

    public function set_plugin(Plugin $plugin): void
    {
        $this->plugin = $plugin;
    }

    /**
     * Set a logger instance for CLI usage (when Plugin is not available).
     */
    public function set_logger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Check if a MIME type is a JPEG type.
     */
    private function isJpegMime(?string $mime): bool
    {
        return is_string($mime) && in_array($mime, self::JPEG_MIMES, true);
    }

    /**
     * Get the relative directory from attachment metadata.
     */
    private function getMetadataDir(array $metadata): string
    {
        $relativeDir = pathinfo((string) ($metadata['file'] ?? ''), PATHINFO_DIRNAME);
        if ($relativeDir === '.' || $relativeDir === DIRECTORY_SEPARATOR) {
            return '';
        }
        return $relativeDir;
    }

    public function init(): void
    {
        // Initialize encoders
        // Order matters for "Auto" mode: CLI -> Imagick -> GD
        $this->encoders = [
            new CliEncoder(),
            new ImagickEncoder(),
            new GdEncoder(),
        ];

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

        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('avif-local-support convert', [$this, 'cliConvertAll']);
        }
    }

    public function maybe_schedule_daily(): void
    {
        $enabled = (bool) get_option('aviflosu_convert_via_schedule', true);
        if (!$enabled) {
            // Clear if exists
            wp_clear_scheduled_hook('aviflosu_daily_event');
            return;
        }

        // Calculate next run time based on schedule time option
        $time = (string) get_option('aviflosu_schedule_time', '01:00');
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '01:00';
        }
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $now = (int) current_time('timestamp', true);
        $tz = wp_timezone();
        $dt = new \DateTimeImmutable('@' . $now);
        $dt = $dt->setTimezone($tz);
        $targetToday = $dt->setTime($hour, $minute, 0);
        $nextDt = ($targetToday->getTimestamp() <= $now)
            ? $targetToday->modify('+1 day')
            : $targetToday;
        $next = $nextDt->getTimestamp();

        // Schedule or reschedule if needed (tolerance: 60 seconds)
        $existing = wp_next_scheduled('aviflosu_daily_event');
        if ($existing === false || abs((int) $existing - (int) $next) > 60) {
            wp_clear_scheduled_hook('aviflosu_daily_event');
            wp_schedule_event($next, 'daily', 'aviflosu_daily_event');
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

        if (!$this->isJpegMime(get_post_mime_type($attachmentId))) {
            return $metadata;
        }

        $uploadDir = wp_upload_dir();
        $baseDir = trailingslashit($uploadDir['basedir'] ?? '');

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
        $type = isset($file['type']) && is_string($file['type']) ? strtolower($file['type']) : '';
        $path = isset($file['file']) && is_string($file['file']) ? $file['file'] : '';
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/pjpeg'];
        $hasAllowedMime = in_array($type, $allowedMimes, true);
        $hasJpegExt = $path !== '' && preg_match('/\.(jpe?g)$/i', $path) === 1;
        if (!$hasAllowedMime && !$hasJpegExt) {
            return $file;
        }
        $this->checkMissingAvif($path);
        return $file;
    }

    private function checkMissingAvif(string $path): ?ConversionResult
    {
        if ($path === '' || !file_exists($path)) {
            return null;
        }
        if (!preg_match('/\.(jpe?g)$/i', $path)) {
            return null;
        }
        $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $path);
        if ($avifPath !== '' && file_exists($avifPath)) {
            return null; // already converted
        }

        // Determine better source based on WordPress logic (if enabled)
        [$sourcePath, $targetDimensions] = $this->getConversionData($path);
        return $this->convertToAvif($sourcePath, $avifPath, $targetDimensions);
    }

    private function convertToAvif(string $sourcePath, string $avifPath, ?array $targetDimensions): ConversionResult
    {
        $start_time = microtime(true);
        $settings = AvifSettings::fromOptions();

        // Ensure directory exists
        $dir = dirname($avifPath);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Memory Check
        if (!$settings->disableMemoryCheck) {
            $memoryWarning = $this->check_memory_safe($sourcePath);
            if ($memoryWarning) {
                $this->log_conversion('error', $sourcePath, $avifPath, 'none', $start_time, $memoryWarning, $settings->toArray());
                return ConversionResult::failure($memoryWarning);
            }
        }

        // Select Encoders
        $encodersToTry = [];
        if ($settings->engineMode === 'cli') {
            // Force CLI: only try CLI
            foreach ($this->encoders as $encoder) {
                if ($encoder->getName() === 'cli') {
                    $encodersToTry[] = $encoder;
                    break;
                }
            }
        } elseif ($settings->engineMode === 'imagick') {
            // Force Imagick
            foreach ($this->encoders as $encoder) {
                if ($encoder->getName() === 'imagick') {
                    $encodersToTry[] = $encoder;
                    break;
                }
            }
        } elseif ($settings->engineMode === 'gd') {
            // Force GD
            foreach ($this->encoders as $encoder) {
                if ($encoder->getName() === 'gd') {
                    $encodersToTry[] = $encoder;
                    break;
                }
            }
        } else {
            // Auto: Try all available
            foreach ($this->encoders as $encoder) {
                if ($encoder->isAvailable()) {
                    $encodersToTry[] = $encoder;
                }
            }
        }

        if (empty($encodersToTry)) {
            $msg = 'No available encoders found.';
            $this->log_conversion('error', $sourcePath, $avifPath, 'none', $start_time, $msg, $settings->toArray());
            return ConversionResult::failure($msg);
        }

        $lastResult = null;
        $engineUsed = 'none';

        foreach ($encodersToTry as $encoder) {
            $engineUsed = $encoder->getName();

            $result = $encoder->convert($sourcePath, $avifPath, $settings, $targetDimensions);

            if ($result->success) {
                $this->log_conversion('success', $sourcePath, $avifPath, $engineUsed, $start_time, null, $settings->toArray());
                return ConversionResult::success();
            }

            $lastResult = $result;
            // If user forced CLI, do not fallback (loop will end since only CLI is in list)
        }

        // If we reached here, all attempts failed
        $errorMsg = $lastResult ? $lastResult->error : 'Unknown error';
        $suggestion = $lastResult ? $lastResult->suggestion : null;

        $details = $settings->toArray();
        if ($suggestion) {
            $details['error_suggestion'] = $suggestion;
        }

        $this->log_conversion('error', $sourcePath, $avifPath, $engineUsed, $start_time, $errorMsg, $details);
        return ConversionResult::failure($errorMsg ?? 'Unknown error', $suggestion);
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
            } elseif (preg_match('/^(.+)-scaled\.(jpe?g)$/i', $filename, $m)) {
                // Handle -scaled images: try to find the non-scaled original
                $base = $m[1];
                $ext = $m[2];
                $candidate = $directory . '/' . $base . '.' . $ext;
                if (file_exists($candidate)) {
                    $srcReal = @realpath($candidate);
                    $tgtReal = @realpath($jpegPath);
                    if ($srcReal && $tgtReal && $srcReal !== $tgtReal) {
                        $sourcePath = $candidate;
                        // Use dimensions of the scaled file as target to ensure we don't produce a huge AVIF
                        $info = @getimagesize($jpegPath);
                        if ($info) {
                            $target = ['width' => $info[0], 'height' => $info[1]];
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
        if (!empty($metadata['file']) && is_string($metadata['file'])) {
            $originalPath = $baseDir . $metadata['file'];
            $this->checkMissingAvif($originalPath);
        }
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $relativeDir = $this->getMetadataDir($metadata);
            foreach ($metadata['sizes'] as $sizeData) {
                if (!empty($sizeData['file'])) {
                    $sizePath = $baseDir . trailingslashit($relativeDir) . $sizeData['file'];
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
        // Clear any previous stop flag when starting
        \delete_transient('aviflosu_stop_conversion');

        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results' => false,
        ]);
        $count = 0;
        foreach ($query->posts as $attachmentId) {
            // Check for stop flag
            if (\get_transient('aviflosu_stop_conversion')) {
                if ($cli && defined('WP_CLI') && \WP_CLI) {
                    \WP_CLI::warning("Conversion stopped by user after {$count} attachments.");
                }
                \delete_transient('aviflosu_stop_conversion');
                return;
            }

            if (!$this->isJpegMime(get_post_mime_type($attachmentId))) {
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
        if ($cli && defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::success("Scanned attachments: {$count}");
        }
    }

    private function convertGeneratedSizesForce(array $metadata, int $attachmentId): void
    {
        if (!$this->isJpegMime(get_post_mime_type($attachmentId))) {
            return;
        }
        $uploadDir = wp_upload_dir();
        $baseDir = trailingslashit($uploadDir['basedir'] ?? '');

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

        if (!$this->isJpegMime(get_post_mime_type($attachmentId))) {
            return $results;
        }

        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir'] ?? '');
        $baseUrl = trailingslashit($uploads['baseurl'] ?? '');

        $meta = wp_get_attachment_metadata($attachmentId) ?: [];
        $originalAbs = get_attached_file($attachmentId) ?: '';

        // Derive relative paths for URLs
        $originalRel = '';
        if (!empty($meta['file']) && is_string($meta['file'])) {
            $originalRel = (string) $meta['file'];
        } elseif ($originalAbs !== '' && str_starts_with($originalAbs, $baseDir)) {
            $originalRel = ltrim(substr($originalAbs, strlen($baseDir)), '/');
        }
        $dirRel = $this->getMetadataDir($meta);

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
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $sizeData) {
                if (empty($sizeData['file']) || !is_string($sizeData['file'])) {
                    continue;
                }
                $jpegRel = ($dirRel !== '' ? trailingslashit($dirRel) : '') . $sizeData['file'];
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
     *
     * @return array{attempted: int, deleted: int} Count of AVIF files found and successfully deleted.
     */
    public function deleteAvifsForAttachment(int $attachmentId): array
    {
        $result = ['attempted' => 0, 'deleted' => 0];

        if (!$this->isJpegMime(get_post_mime_type($attachmentId))) {
            return $result;
        }

        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir'] ?? '');

        $meta = wp_get_attachment_metadata($attachmentId) ?: [];

        $paths = [];
        if (!empty($meta['file']) && is_string($meta['file'])) {
            $paths[] = $baseDir . $meta['file'];
        } else {
            $attached = get_attached_file($attachmentId);
            if ($attached) {
                $paths[] = (string) $attached;
            }
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dirRel = $this->getMetadataDir($meta);
            foreach ($meta['sizes'] as $sizeData) {
                if (!empty($sizeData['file']) && is_string($sizeData['file'])) {
                    $paths[] = $baseDir . trailingslashit($dirRel) . $sizeData['file'];
                }
            }
        }

        foreach ($paths as $jpegPath) {
            $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegPath);
            if (file_exists($avifPath)) {
                $result['attempted']++;
                if (@unlink($avifPath)) {
                    $result['deleted']++;
                }
            }
        }

        return $result;
    }

    /**
     * Helper for Async Upload Test: Get all sizes from attachment metadata without converting.
     */
    public function getAttachmentSizes(int $attachmentId): array
    {
        $results = [
            'attachment_id' => $attachmentId,
            'sizes' => [],
        ];

        if (!$this->isJpegMime(get_post_mime_type($attachmentId))) {
            return $results;
        }

        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir'] ?? '');
        $baseUrl = trailingslashit($uploads['baseurl'] ?? '');

        $meta = wp_get_attachment_metadata($attachmentId) ?: [];
        $originalAbs = get_attached_file($attachmentId) ?: '';

        // Derive relative paths for URLs
        $originalRel = '';
        if (!empty($meta['file']) && is_string($meta['file'])) {
            $originalRel = (string) $meta['file'];
        } elseif ($originalAbs !== '' && str_starts_with($originalAbs, $baseDir)) {
            $originalRel = ltrim(substr($originalAbs, strlen($baseDir)), '/');
        }
        $dirRel = $this->getMetadataDir($meta);

        $addRow = function (string $label, string $jpegAbs, string $jpegRel, ?int $width, ?int $height) use (&$results, $baseUrl): void {
            $avifAbs = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegAbs);
            $avifRel = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegRel);
            $jpegUrl = $jpegRel !== '' ? $baseUrl . $jpegRel : '';
            $avifUrl = $avifRel !== '' ? $baseUrl . $avifRel : '';
            $avifExists = file_exists($avifAbs);
            $results['sizes'][] = [
                'name' => $label,
                'jpeg_path' => $jpegAbs,
                'jpeg_url' => $jpegUrl,
                'avif_path' => $avifAbs,
                'avif_url' => $avifUrl,
                'width' => $width,
                'height' => $height,
                'jpeg_size' => file_exists($jpegAbs) ? (int) filesize($jpegAbs) : 0,
                'avif_size' => $avifExists ? (int) filesize($avifAbs) : 0,
                'converted' => $avifExists,
                'status' => $avifExists ? 'success' : 'pending',
            ];
        };

        if ($originalAbs !== '' && file_exists($originalAbs)) {
            $w = isset($meta['width']) ? (int) $meta['width'] : null;
            $h = isset($meta['height']) ? (int) $meta['height'] : null;
            $addRow('original', $originalAbs, $originalRel, $w, $h);
        }
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $sizeData) {
                if (empty($sizeData['file']) || !is_string($sizeData['file'])) {
                    continue;
                }
                $jpegRel = ($dirRel !== '' ? trailingslashit($dirRel) : '') . $sizeData['file'];
                $jpegAbs = $baseDir . $jpegRel;
                if (!file_exists($jpegAbs))
                    continue;

                $width = isset($sizeData['width']) ? (int) $sizeData['width'] : null;
                $height = isset($sizeData['height']) ? (int) $sizeData['height'] : null;
                $addRow((string) $sizeName, $jpegAbs, $jpegRel, $width, $height);
            }
        }
        return $results;
    }

    /**
     * Helper for Async Upload Test: Convert a single JPEG file path to AVIF.
     * Returns true if AVIF exists after attempt.
     */
    public function convertSingleJpegToAvif(string $jpegPath): ConversionResult
    {
        if (empty($jpegPath) || !file_exists($jpegPath)) {
            return ConversionResult::failure(__('Source file not found', 'avif-local-support'));
        }

        // This will create the AVIF if it doesn't exist. Returns ConversionResult if attempted, null if skipped.
        $result = $this->checkMissingAvif($jpegPath);

        if ($result !== null) {
            return $result;
        }

        $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $jpegPath);
        if (file_exists($avifPath)) {
            return ConversionResult::success();
        }

        return ConversionResult::failure(__('Unknown error (conversion skipped)', 'avif-local-support'));
    }



    /**
     * When WordPress deletes a specific file (e.g., a resized JPEG), also delete its
     * .avif companion if present. Must return the original path so core proceeds.
     */
    public function deleteCompanionAvif(string $path): string
    {
        if (is_string($path) && $path !== '' && preg_match('/\.(jpe?g)$/i', $path)) {
            $avifPath = (string) preg_replace('/\.(jpe?g)$/i', '.avif', $path);
            if ($avifPath !== '' && @file_exists($avifPath) && !@is_link($avifPath)) {
                wp_delete_file($avifPath);
            }
        }
        return $path;
    }

    /**
     * Log conversion attempt with all relevant details
     */
    private function log_conversion(string $status, string $sourcePath, string $avifPath, string $engine_used, float $start_time, ?string $error_message = null, ?array $details = null): void
    {
        // Skip logging if neither plugin nor logger is available
        if (!$this->plugin && !$this->logger) {
            return;
        }

        $end_time = microtime(true);
        $duration = round(($end_time - $start_time) * 1000, 2); // Convert to milliseconds

        $logDetails = [
            'source_file' => basename($sourcePath),
            'target_file' => basename($avifPath),
            'engine_used' => $engine_used,
            'duration_ms' => $duration,
            'source_size' => file_exists($sourcePath) ? filesize($sourcePath) : 0,
            'target_size' => file_exists($avifPath) ? filesize($avifPath) : 0,
        ];

        if ($details !== null) {
            // Avoid logging overly noisy or sensitive details (env vars / secrets).
            $logDetails = array_merge($logDetails, $this->sanitize_log_details($details));
        }

        // Add error message if provided
        if ($error_message) {
            $logDetails['error'] = $error_message;
        }

        $message = $status === 'success'
            ? "Successfully converted " . basename($sourcePath) . " to AVIF using $engine_used"
            : "Failed to convert " . basename($sourcePath) . " to AVIF using $engine_used";

        if ($error_message) {
            $message .= ": $error_message";
        }

        // Use direct logger if available (CLI), otherwise use plugin's logger
        if ($this->logger) {
            $this->logger->addLog($status, $message, $logDetails);
        } elseif ($this->plugin) {
            $this->plugin->add_log($status, $message, $logDetails);
        }
    }

    /**
     * Sanitize details before persisting them to logs.
     */
    private function sanitize_log_details(array $details): array
    {
        // If the CLI env is provided as a multiline string, only keep PATH (and cap length).
        if (isset($details['cli_env']) && is_string($details['cli_env'])) {
            $envLines = preg_split("/\r\n|\r|\n/", $details['cli_env']) ?: [];
            $path = '';
            foreach ($envLines as $line) {
                $line = trim((string) $line);
                if (stripos($line, 'PATH=') === 0) {
                    $path = substr($line, 5);
                    break;
                }
            }
            if ($path !== '') {
                if (strlen($path) > 500) {
                    $path = substr($path, 0, 500) . '…';
                }
                $details['cli_env'] = 'PATH=' . $path;
            } else {
                // Still indicate it was set, but don't store the full blob.
                $details['cli_env'] = '(set)';
            }
        }

        // Light redaction for common secret-like patterns in CLI args/env.
        foreach (['cli_args', 'cli_env'] as $k) {
            if (!isset($details[$k]) || !is_string($details[$k])) {
                continue;
            }
            $details[$k] = preg_replace(
                '/\b(password|passwd|secret|token|api[_-]?key)\s*=\s*([^\s]+)/i',
                '$1=REDACTED',
                $details[$k]
            ) ?? $details[$k];
        }

        return $details;
    }
}
