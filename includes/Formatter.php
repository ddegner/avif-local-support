<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined('ABSPATH') || exit;

/**
 * Utility class for formatting values for display.
 */
final class Formatter
{
    /**
     * Format bytes into a human-readable string (e.g., "1.5 MB").
     */
    public static function bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;

        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $n, $units[$i]);
    }
}

