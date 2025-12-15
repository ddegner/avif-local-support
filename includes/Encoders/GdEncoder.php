<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Encoders;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;
use GdImage;

defined('ABSPATH') || exit;

class GdEncoder implements AvifEncoderInterface
{
    public function getName(): string
    {
        return 'gd';
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        // Conversion requires the imageavif() function.
        // Some environments may report "AVIF Support" in gd_info() but still lack imageavif().
        return function_exists('imageavif');
    }

    public function convert(string $source, string $destination, AvifSettings $settings, ?array $dimensions = null): ConversionResult
    {
        if (!function_exists('imageavif')) {
            return ConversionResult::failure('imageavif function missing', 'Upgrade PHP or compile GD with AVIF support.');
        }

        // Suppress warnings for getimagesize/imagecreatefromjpeg
        $imageInfo = @getimagesize($source);
        if (!$imageInfo || ($imageInfo[2] !== IMAGETYPE_JPEG)) {
            return ConversionResult::failure('Invalid JPEG or unreadable file');
        }

        // Check output dimensions against AVIF specification limits.
        // AVIF Advanced Profile max: 35,651,584 pixels (16384×8704).
        $srcWidth = (int) $imageInfo[0];
        $srcHeight = (int) $imageInfo[1];
        $maxPixels = 35651584; // AVIF Advanced Profile limit

        // Determine output dimensions
        if ($dimensions && isset($dimensions['width'], $dimensions['height'])) {
            $outputWidth = (int) $dimensions['width'];
            $outputHeight = (int) $dimensions['height'];
        } else {
            // No resize - output will be same as source
            $outputWidth = $srcWidth;
            $outputHeight = $srcHeight;
        }

        $outputPixels = $outputWidth * $outputHeight;
        if ($outputPixels > $maxPixels) {
            $megapixels = round($outputPixels / 1000000, 1);
            return ConversionResult::failure(
                "Output exceeds AVIF maximum size: {$outputWidth}×{$outputHeight} ({$megapixels}MP)",
                'AVIF Advanced Profile supports max 35.6 megapixels (16384×8704). ' .
                'Resize the image before conversion or use WordPress media settings to generate smaller sizes.'
            );
        }

        $gd = @imagecreatefromjpeg($source);
        if (!$gd) {
            return ConversionResult::failure('Failed to create GD resource', 'File corrupt or memory limit too low.');
        }

        // Exif Orientation
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            if ($exif && isset($exif['Orientation'])) {
                $gd = $this->applyOrientation($gd, (int) $exif['Orientation']);
            }
        }

        // Resize/Crop
        if ($dimensions && isset($dimensions['width'], $dimensions['height'])) {
            $resized = $this->resizeAndCrop($gd, (int) $dimensions['width'], (int) $dimensions['height']);
            if ($resized) {
                imagedestroy($gd);
                $gd = $resized;
            }
        }

        // Convert
        $speed = min(8, $settings->speed);
        $success = false;

        // PHP 8.1+ supports the $speed param. Plugin requires PHP 8.3+.
        $success = @imageavif($gd, $destination, $settings->quality, $speed);

        imagedestroy($gd);

        if ($success && file_exists($destination)) {
            return ConversionResult::success();
        }

        return ConversionResult::failure('GD failed to write AVIF', 'Check directory permissions.');
    }

    private function applyOrientation(GdImage $source, int $orientation): GdImage
    {
        // Using match expression for PHP 8.0+
        return match ($orientation) {
            2 => (function_exists('imageflip') && imageflip($source, IMG_FLIP_HORIZONTAL)) ? $source : $source,
            3 => ($rot = imagerotate($source, 180, 0)) ? $rot : $source,
            4 => (function_exists('imageflip') && imageflip($source, IMG_FLIP_VERTICAL)) ? $source : $source,
            5 => ($tmp = imagerotate($source, 270, 0)) && function_exists('imageflip') && imageflip($tmp, IMG_FLIP_HORIZONTAL) ? $tmp : ($tmp ?: $source),
            6 => ($rot = imagerotate($source, 270, 0)) ? $rot : $source,
            7 => ($tmp = imagerotate($source, 90, 0)) && function_exists('imageflip') && imageflip($tmp, IMG_FLIP_HORIZONTAL) ? $tmp : ($tmp ?: $source),
            8 => ($rot = imagerotate($source, 90, 0)) ? $rot : $source,
            default => $source,
        };
    }

    private function resizeAndCrop(GdImage $source, int $targetW, int $targetH): ?GdImage
    {
        $sourceW = imagesx($source);
        $sourceH = imagesy($source);

        $target = imagecreatetruecolor($targetW, $targetH);
        if (!$target) {
            return null;
        }

        $sourceAspect = $sourceW / max(1, $sourceH);
        $targetAspect = $targetW / max(1, $targetH);

        if ($sourceAspect > $targetAspect) {
            $cropH = $sourceH;
            $cropW = (int) ($sourceH * $targetAspect);
            $cropX = (int) (($sourceW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $sourceW;
            $cropH = (int) ($sourceW / $targetAspect);
            $cropX = 0;
            $cropY = (int) (($sourceH - $cropH) / 2);
        }

        $ok = imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

        if (!$ok) {
            imagedestroy($target);
            return null;
        }

        return $target;
    }
}
