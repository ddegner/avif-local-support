<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\DTO;

use Ddegner\AvifLocalSupport\Environment;
use Ddegner\AvifLocalSupport\ImageMagickCli;

defined('ABSPATH') || exit;

/**
 * Immutable settings object for AVIF conversion.
 * Note: readonly keyword removed for WordPress.org SVN compatibility.
 */
final class AvifSettings
{
    public int $quality;
    public int $speed;
    public string $subsampling;
    public string $bitDepth;
    public string $engineMode;
    public string $cliPath;
    public bool $disableMemoryCheck;
    public bool $lossless;
    public bool $convertOnUpload;
    public bool $convertViaSchedule;
    public string $cliArgs;
    public string $cliEnv;
    public int $maxDimension;

    public function __construct(
        int $quality = 85,
        int $speed = 1,
        string $subsampling = '420',
        string $bitDepth = '8',
        string $engineMode = 'auto',
        string $cliPath = '',
        bool $disableMemoryCheck = false,
        bool $lossless = false,
        bool $convertOnUpload = true,
        bool $convertViaSchedule = true,
        string $cliArgs = '',
        string $cliEnv = '',
        int $maxDimension = 4096
    ) {
        $this->quality = $quality;
        $this->speed = $speed;
        $this->subsampling = $subsampling;
        $this->bitDepth = $bitDepth;
        $this->engineMode = $engineMode;
        $this->cliPath = $cliPath;
        $this->disableMemoryCheck = $disableMemoryCheck;
        $this->lossless = $lossless;
        $this->convertOnUpload = $convertOnUpload;
        $this->convertViaSchedule = $convertViaSchedule;
        $this->cliArgs = $cliArgs;
        $this->cliEnv = $cliEnv;
        $this->maxDimension = $maxDimension;
    }

    public static function fromOptions(): self
    {
        $quality = max(0, min(100, (int) get_option('aviflosu_quality', 85)));
        $speed = max(0, min(10, (int) get_option('aviflosu_speed', 1)));

        $subsampling = (string) get_option('aviflosu_subsampling', '420');
        if (!in_array($subsampling, ['420', '422', '444'], true)) {
            $subsampling = '420';
        }

        $bitDepth = (string) get_option('aviflosu_bit_depth', '8');
        if (!in_array($bitDepth, ['8', '10', '12'], true)) {
            $bitDepth = '8';
        }

        $engineMode = (string) get_option('aviflosu_engine_mode', 'auto');
        $cliPath = (string) get_option('aviflosu_cli_path', '');
        $disableMemoryCheck = (bool) get_option('aviflosu_disable_memory_check', false);

        $lossless = ($quality >= 100);
        $convertOnUpload = (bool) get_option('aviflosu_convert_on_upload', true);
        $convertViaSchedule = (bool) get_option('aviflosu_convert_via_schedule', true);

        $cliArgs = (string) get_option('aviflosu_cli_args', '');
        // Default environment if not set
        $defaultEnv = Environment::buildDefaultEnvString();
        $cliEnv = (string) get_option('aviflosu_cli_env', $defaultEnv);

        // Auto-detect ImageMagick CLI when not explicitly configured.
        // This enables Auto mode to use CLI on servers where ImageMagick is installed but the user didn't set a path.
        if ($cliPath === '' && in_array($engineMode, ['auto', 'cli'], true)) {
            $cliPath = ImageMagickCli::getAutoDetectedPath(null);
        }

        return new self(
            quality: $quality,
            speed: $speed,
            subsampling: $subsampling,
            bitDepth: $bitDepth,
            engineMode: $engineMode,
            cliPath: $cliPath,
            disableMemoryCheck: $disableMemoryCheck,
            lossless: $lossless,
            convertOnUpload: $convertOnUpload,
            convertViaSchedule: $convertViaSchedule,
            cliArgs: $cliArgs,
            cliEnv: $cliEnv
        );
    }

    public function toArray(): array
    {
        return [
            'quality' => $this->quality,
            'speed' => $this->speed,
            'subsampling' => $this->subsampling,
            'bit_depth' => $this->bitDepth,
            'engine_mode' => $this->engineMode,
            'cli_path' => $this->cliPath,
            'disable_memory_check' => $this->disableMemoryCheck,
            'lossless' => $this->lossless,
            'convert_on_upload' => $this->convertOnUpload,
            'convert_via_schedule' => $this->convertViaSchedule,
            'cli_args' => $this->cliArgs,
            'cli_env' => $this->cliEnv,
        ];
    }

    /**
     * Get the chroma label string (e.g., '4:2:0') for AVIF encoding.
     * If lossless mode is enabled, always returns '4:4:4'.
     */
    public function getChromaLabel(): string
    {
        if ($this->lossless) {
            return '4:4:4';
        }

        return match ($this->subsampling) {
            '444' => '4:4:4',
            '422' => '4:2:2',
            default => '4:2:0',
        };
    }

    /**
     * Get the numeric chroma value (e.g., '420') for CLI encoding.
     * If lossless mode is enabled, always returns '444'.
     */
    public function getChromaNumeric(): string
    {
        if ($this->lossless) {
            return '444';
        }

        return match ($this->subsampling) {
            '444' => '444',
            '422' => '422',
            default => '420',
        };
    }
}
