<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Encoders;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;

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
        // In Auto mode, we only want to run if a path is actually configured.
        $path = (string) get_option('aviflosu_cli_path', '');
        return $path !== '';
    }

    public function convert(string $source, string $destination, AvifSettings $settings, ?array $dimensions = null): ConversionResult
    {
        if (!function_exists('proc_open')) {
            return ConversionResult::failure('proc_open function is disabled', 'Enable proc_open in PHP configuration.');
        }

        $bin = $settings->cliPath;
        if ($bin === '') {
            return ConversionResult::failure('CLI binary path is empty');
        }

        if (!@file_exists($bin)) {
            return ConversionResult::failure("CLI binary does not exist: $bin");
        }

        if (!@is_executable($bin)) {
            return ConversionResult::failure("CLI binary not executable: $bin");
        }

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

        $args[] = '-define';
        $args[] = 'avif:speed=' . (string) min(8, $settings->speed);

        if ($settings->lossless) {
            $args[] = '-define';
            $args[] = 'avif:lossless=true';
        }

        // Chroma subsampling
        $chromaLabel = $settings->subsampling === '444' ? '4:4:4' : ($settings->subsampling === '422' ? '4:2:2' : '4:2:0');
        if ($settings->lossless) {
            $chromaLabel = '4:4:4';
        }
        $args[] = '-define';
        $args[] = 'avif:chroma-subsample=' . $chromaLabel;

        // Bit depth
        $args[] = '-depth';
        $args[] = (string) (int) $settings->bitDepth;
        $args[] = '-define';
        $args[] = 'avif:bit-depth=' . (string) $settings->bitDepth;

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

        // Construct command using proc_open for safety
        // We will construct the command string for logging, but use array for execution if possible?
        // PHP's exec/proc_open usually takes a string. We must escape carefully.

        $cmdParts = [escapeshellarg($bin)];
        $cmdParts[] = escapeshellarg($sourceArg);
        foreach ($args as $arg) {
            $cmdParts[] = escapeshellarg($arg);
        }
        $cmdParts[] = escapeshellarg($outputArg);

        $command = implode(' ', $cmdParts);

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

            // Prepare environment variables from settings
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

            // Fallback to safe defaults if user env is empty (prevent breaking everything)
            if (empty($env)) {
                $env = [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/opt/homebrew/bin',
                    'HOME' => getenv('HOME') ?: '/tmp',
                    'LC_ALL' => 'C',
                ];
            }

            // Ensure /opt/homebrew/bin is in PATH if we are on macOS and it's missing
            if (isset($env['PATH']) && strpos($env['PATH'], '/opt/homebrew/bin') === false && PHP_OS_FAMILY === 'Darwin') {
                $env['PATH'] .= ':/opt/homebrew/bin';
            }

            // Allow developers to modify the environment if needed (e.g. to add custom library paths)
            /**
             * Filters the environment variables passed to the CLI encoder process.
             *
             * @param array $env The environment variables array.
             */
            $env = apply_filters('aviflosu_cli_environment', $env);

            $process = proc_open($command, $descriptorSpec, $pipes, null, $env);

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
                $isTransient = (str_contains($outLower, 'no decode delegate')
                    || str_contains($outLower, 'readimage')
                    || str_contains($outLower, 'no images defined'));

                if (!$isTransient) {
                    break;
                }

                usleep(250000); // 250ms
                $attempts++;
            } else {
                return ConversionResult::failure("Failed to open process for command: $command");
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
