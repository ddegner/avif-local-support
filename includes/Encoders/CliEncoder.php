<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Encoders;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;
use Ddegner\AvifLocalSupport\ImageMagickCli;

defined('ABSPATH') || exit;

class CliEncoder implements AvifEncoderInterface
{

    private string $lastOutput = '';
    private int $lastExitCode = 0;

    public function getName(): string
    {
        return 'cli';
    }

    public function isAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        // Use configured path if present, otherwise auto-detect.
        $path = (string) get_option('aviflosu_cli_path', '');
        if ($path !== '') {
            return true;
        }
        $auto = ImageMagickCli::getAutoDetectedPath(null);
        return $auto !== '';
    }

    public function convert(string $source, string $destination, AvifSettings $settings, ?array $dimensions = null): ConversionResult
    {
        if (!function_exists('proc_open')) {
            return ConversionResult::failure('proc_open function is disabled', 'Enable proc_open in PHP configuration.');
        }

        $bin = $settings->cliPath;
        if ($bin === '') {
            // Safety net: auto-detect if settings didn't populate it.
            $bin = ImageMagickCli::getAutoDetectedPath(null);
            if ($bin === '') {
                return ConversionResult::failure('CLI binary path is empty');
            }
        }

        if (!@file_exists($bin)) {
            return ConversionResult::failure("CLI binary does not exist: $bin");
        }

        if (!@is_executable($bin)) {
            return ConversionResult::failure("CLI binary not executable: $bin");
        }

        // Prepare environment variables from settings early (used for probing and execution).
        $env = [];
        $envLines = explode("\n", $settings->cliEnv);
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $env[trim($key)] = trim($val);
        }
        if (empty($env)) {
            $fallbackPath = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin';
            if (PHP_OS_FAMILY === 'Darwin') {
                if (@is_dir('/opt/homebrew/bin')) {
                    $fallbackPath .= ':/opt/homebrew/bin';
                }
                if (@is_dir('/opt/local/bin')) {
                    $fallbackPath .= ':/opt/local/bin';
                }
            }
            $env = [
                'PATH' => getenv('PATH') ?: $fallbackPath,
                'HOME' => getenv('HOME') ?: '/tmp',
                'LC_ALL' => 'C',
            ];
        }
        if (PHP_OS_FAMILY === 'Darwin' && isset($env['PATH'])) {
            if (@is_dir('/opt/homebrew/bin') && strpos($env['PATH'], '/opt/homebrew/bin') === false) {
                $env['PATH'] .= ':/opt/homebrew/bin';
            }
            if (@is_dir('/opt/local/bin') && strpos($env['PATH'], '/opt/local/bin') === false) {
                $env['PATH'] .= ':/opt/local/bin';
            }
        }
        /**
         * Filters the environment variables passed to the CLI encoder process.
         *
         * @param array $env The environment variables array.
         */
        $env = apply_filters('aviflosu_cli_environment', $env);

        // Build arguments
        $args = [];

        // Resize/Crop logic
        if ($dimensions && isset($dimensions['width'], $dimensions['height'])) {
            $tW = max(1, (int) $dimensions['width']);
            $tH = max(1, (int) $dimensions['height']);
            // -auto-orient -thumbnail WxH^ -gravity center -extent WxH
            $args[] = '-auto-orient';
            $args[] = '-thumbnail';
            $args[] = $tW . 'x' . $tH . '^';
            $args[] = '-gravity';
            $args[] = 'center';
            $args[] = '-extent';
            $args[] = $tW . 'x' . $tH;
        } else {
            $args[] = '-auto-orient';
        }

        // Settings
        $args[] = '-strip';
        $args[] = '-quality';
        $args[] = (string) $settings->quality;

        // Choose a safe -define namespace for this ImageMagick build.
        $strategy = ImageMagickCli::getDefineStrategy($bin, $env);
        $ns = isset($strategy['namespace']) ? (string) $strategy['namespace'] : 'none';

        // Chroma subsampling
        $chromaLabel = $settings->subsampling === '444' ? '4:4:4' : ($settings->subsampling === '422' ? '4:2:2' : '4:2:0');
        if ($settings->lossless) {
            $chromaLabel = '4:4:4';
        }
        $chromaNumeric = $settings->subsampling === '444' ? '444' : ($settings->subsampling === '422' ? '422' : '420');
        if ($settings->lossless) {
            $chromaNumeric = '444';
        }

        // Speed / Lossless / Chroma (guarded by probe results)
        if ($ns === 'heic') {
            $args[] = '-define';
            $args[] = 'heic:speed=' . (string) min(9, $settings->speed);
            $args[] = '-define';
            $args[] = 'heic:chroma=' . $chromaNumeric;
            if ($settings->lossless && !empty($strategy['supports_lossless'])) {
                $args[] = '-define';
                $args[] = 'heic:lossless=true';
            }
        } elseif ($ns === 'avif') {
            $args[] = '-define';
            $args[] = 'avif:speed=' . (string) min(10, $settings->speed);
            $args[] = '-define';
            $args[] = 'avif:chroma-subsample=' . $chromaLabel;
            if ($settings->lossless && !empty($strategy['supports_lossless'])) {
                $args[] = '-define';
                $args[] = 'avif:lossless=true';
            }
        }

        // Bit depth (only when explicitly requested and probed as safe)
        $bitDepth = (int) $settings->bitDepth;
        if ($bitDepth !== 8) {
            if (!empty($strategy['supports_depth'])) {
                $args[] = '-depth';
                $args[] = (string) $bitDepth;
            }
            if (!empty($strategy['supports_bit_depth_define'])) {
                $args[] = '-define';
                $args[] = ($ns === 'heic' ? 'heic:bit-depth=' : 'avif:bit-depth=') . (string) $bitDepth;
            }
        }

        // Colorspace
        $args[] = '-colorspace';
        $args[] = 'sRGB';

        // Extra CLI arguments from settings
        if (!empty($settings->cliArgs)) {
            // Use str_getcsv to respect quotes, e.g. -set comment "hello world"
            $userArgs = str_getcsv($settings->cliArgs, ' ', '"', '\\');
            foreach ($userArgs as $arg) {
                if (trim($arg) !== '') {
                    $args[] = $arg;
                }
            }
        }

        // Input/Output
        // Do not force prefixes as it can cause "no decode delegate" errors in some environments
        $sourceArg = $source;
        $outputArg = $destination;

        // Construct command.
        // Prefer proc_open() with an argv array (avoids invoking a shell) on PHP 7.4+.
        $command = null;
        $commandForLogs = '';
        if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
            $command = array_merge([$bin, $sourceArg], array_map('strval', $args), [$outputArg]);
            // A safe, human-readable representation for debugging/logs (not executed).
            $cmdParts = array_map(
                static fn($p): string => escapeshellarg((string) $p),
                $command
            );
            $commandForLogs = implode(' ', $cmdParts);
        } else {
            // Legacy fallback: shell-escaped string.
            $cmdParts = [escapeshellarg($bin)];
            $cmdParts[] = escapeshellarg($sourceArg);
            foreach ($args as $arg) {
                $cmdParts[] = escapeshellarg((string) $arg);
            }
            $cmdParts[] = escapeshellarg($outputArg);
            $commandForLogs = implode(' ', $cmdParts);
            $command = $commandForLogs;
        }

        // Retry loop
        $attempts = 0;
        $maxAttempts = 3;
        $success = false;

        do {
            $descriptorSpec = [
                0 => ["pipe", "r"],  // stdin
                1 => ["pipe", "w"],  // stdout
                2 => ["pipe", "w"]   // stderr
            ];

            $process = proc_open(
                $command,
                $descriptorSpec,
                $pipes,
                null,
                $env,
                ['bypass_shell' => true]
            );

            if (is_resource($process)) {
                fclose($pipes[0]); // Close stdin

                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);

                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);

                $exitCode = proc_close($process);

                $this->lastExitCode = $exitCode;
                $this->lastOutput = trim($stdout . "\n" . $stderr);

                if ($exitCode === 0) {
                    $success = true;
                    break;
                }

                // Check for transient errors
                $outLower = strtolower($this->lastOutput);
                $isTransient = (str_contains($outLower, 'readimage')
                    || str_contains($outLower, 'no images defined'));

                if (!$isTransient) {
                    break;
                }

                usleep(250000); // 250ms
                $attempts++;
            } else {
                return ConversionResult::failure("Failed to open process for command: $commandForLogs");
            }

        } while ($attempts < $maxAttempts);

        if (!$success) {
            return $this->analyzeError();
        }

        if (!file_exists($destination) || filesize($destination) <= 512) {
            return ConversionResult::failure(
                "CLI reported success but file is missing or empty.",
                "Verify that your ImageMagick build supports AVIF writing."
            );
        }

        return ConversionResult::success();
    }

    private function analyzeError(): ConversionResult
    {
        $snippet = substr($this->lastOutput, 0, 500);
        $err = "CLI failed (exit {$this->lastExitCode}): $snippet";

        $suggestion = 'Check binary path and permissions.';
        $outLower = strtolower($this->lastOutput);

        if ($this->lastExitCode === 127) {
            $suggestion = 'Binary not found or not executable.';
        } elseif (str_contains($outLower, 'delegate') || str_contains($outLower, 'no decode delegate')) {
            $suggestion = 'ImageMagick is missing a delegate (libjpeg/libavif).';
        } elseif (str_contains($outLower, 'memory') || str_contains($outLower, 'resource limit')) {
            $suggestion = 'Server ran out of memory/resources.';
        }

        return ConversionResult::failure($err, $suggestion);
    }
}
