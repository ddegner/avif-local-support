<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\DTO;

use Ddegner\AvifLocalSupport\ImageMagickCli;

defined('ABSPATH') || exit;

/**
 * Immutable settings object for AVIF conversion.
 */
readonly class AvifSettings
{
    public function __construct(
        public int $quality = 85,
        public int $speed = 1,
        public string $subsampling = '420',
        public string $bitDepth = '8',
        public string $engineMode = 'auto',
        public string $cliPath = '',
        public bool $disableMemoryCheck = false,
        public bool $lossless = false,
        public bool $convertOnUpload = true,
        public bool $convertViaSchedule = true,
        public string $cliArgs = '',
        public string $cliEnv = ''
    ) {
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
        $path = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin';
        if (PHP_OS_FAMILY === 'Darwin') {
            if (@is_dir('/opt/homebrew/bin')) {
                $path .= ':/opt/homebrew/bin';
            }
            if (@is_dir('/opt/local/bin')) {
                $path .= ':/opt/local/bin';
            }
        }
        $defaultEnv = "PATH=$path\nHOME=/tmp\nLC_ALL=C";
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
}
