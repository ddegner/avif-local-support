<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Encoders;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;
use Ddegner\AvifLocalSupport\Environment;
use Ddegner\AvifLocalSupport\ImageMagickCli;

defined( 'ABSPATH' ) || exit;

class CliEncoder implements AvifEncoderInterface {




	private string $lastOutput  = '';
	private int $lastExitCode   = 0;
	private int $lastSourceSize = 0;

	public function getName(): string {
		return 'cli';
	}

	public function isAvailable(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		// Use configured path if present, otherwise auto-detect.
		$path = (string) get_option( 'aviflosu_cli_path', '' );
		if ( '' !== $path ) {
			return true;
		}
		$auto = ImageMagickCli::getAutoDetectedPath( null );
		return '' !== $auto;
	}

	public function convert( string $source, string $destination, AvifSettings $settings, ?array $dimensions = null ): ConversionResult {
		if ( ! function_exists( 'proc_open' ) ) {
			return ConversionResult::failure( 'proc_open function is disabled', 'Enable proc_open in PHP configuration.' );
		}

		$bin = $settings->cliPath;
		if ( '' === $bin ) {
			// Safety net: auto-detect if settings didn't populate it.
			$bin = ImageMagickCli::getAutoDetectedPath( null );
			if ( '' === $bin ) {
				return ConversionResult::failure( 'CLI binary path is empty' );
			}
		}

		if ( ! @file_exists( $bin ) ) {
			return ConversionResult::failure( "CLI binary does not exist: $bin" );
		}

		if ( ! @is_executable( $bin ) ) {
			return ConversionResult::failure( "CLI binary not executable: $bin" );
		}

		// Get source file size for diagnostic purposes.
		$sourceSize = @filesize( $source );
		if ( false === $sourceSize ) {
			$sourceSize = 0;
		}
		$this->lastSourceSize = $sourceSize;

		// Check output dimensions against AVIF specification limits.
		// AVIF Advanced Profile max: 35,651,584 pixels (16384×8704).
		// Skip conversion only if the OUTPUT would exceed this limit.
		$imageInfo = @getimagesize( $source );
		if ( false !== $imageInfo && isset( $imageInfo[0], $imageInfo[1] ) ) {
			$srcWidth  = (int) $imageInfo[0];
			$srcHeight = (int) $imageInfo[1];
			$maxPixels = 35651584; // AVIF Advanced Profile limit.

			// Determine output dimensions.
			if ( $dimensions && isset( $dimensions['width'], $dimensions['height'] ) ) {
				$outputWidth  = (int) $dimensions['width'];
				$outputHeight = (int) $dimensions['height'];
			} else {
				// No resize - output will be same as source.
				$outputWidth  = $srcWidth;
				$outputHeight = $srcHeight;
			}

			$outputPixels = $outputWidth * $outputHeight;
			if ( $outputPixels > $maxPixels ) {
				$megapixels = round( $outputPixels / 1000000, 1 );
				return ConversionResult::failure(
					"Output exceeds AVIF maximum size: {$outputWidth}×{$outputHeight} ({$megapixels}MP)",
					'AVIF Advanced Profile supports max 35.6 megapixels (16384×8704). ' .
					'Resize the image before conversion or use WordPress media settings to generate smaller sizes.'
				);
			}
		}

		// Prepare environment variables from settings early (used for probing and execution).
		$env = Environment::parseEnvString( $settings->cliEnv );
		$env = Environment::normalizeEnv( $env );

		// Build arguments.
		$args = array();

		// Resize/Crop logic with LANCZOS filter for high quality.
		if ( $dimensions && isset( $dimensions['width'], $dimensions['height'] ) ) {
			$tW = max( 1, (int) $dimensions['width'] );
			$tH = max( 1, (int) $dimensions['height'] );
			// Use LANCZOS filter for high-quality resizing (same as Imagick).
			$args[] = '-auto-orient';
			$args[] = '-filter';
			$args[] = 'Lanczos';
			$args[] = '-resize';
			$args[] = $tW . 'x' . $tH . '^';
			$args[] = '-gravity';
			$args[] = 'center';
			$args[] = '-extent';
			$args[] = $tW . 'x' . $tH;
		} else {
			$args[] = '-auto-orient';
		}

		// Preserve all metadata (EXIF, XMP, IPTC, ICC profiles).
		// No -strip or +profile commands - keep everything.

		$args[] = '-quality';
		$args[] = (string) $settings->quality;

		// Choose a safe -define namespace for this ImageMagick build.
		$strategy = ImageMagickCli::getDefineStrategy( $bin, $env );
		$ns       = isset( $strategy['namespace'] ) ? (string) $strategy['namespace'] : 'none';

		// Chroma subsampling.
		$chromaLabel   = $settings->getChromaLabel();
		$chromaNumeric = $settings->getChromaNumeric();

		// Speed / Lossless / Chroma (guarded by probe results).
		if ( 'heic' === $ns ) {
			$args[] = '-define';
			$args[] = 'heic:speed=' . (string) min( 9, $settings->speed );
			$args[] = '-define';
			$args[] = 'heic:chroma=' . $chromaNumeric;
			if ( $settings->lossless && ! empty( $strategy['supports_lossless'] ) ) {
				$args[] = '-define';
				$args[] = 'heic:lossless=true';
			}
		} elseif ( 'avif' === $ns ) {
			$args[] = '-define';
			$args[] = 'avif:speed=' . (string) min( 10, $settings->speed );
			$args[] = '-define';
			$args[] = 'avif:chroma-subsample=' . $chromaLabel;
			if ( $settings->lossless && ! empty( $strategy['supports_lossless'] ) ) {
				$args[] = '-define';
				$args[] = 'avif:lossless=true';
			}
		}

		// Bit depth (only when explicitly requested and probed as safe).
		$bitDepth = (int) $settings->bitDepth;
		if ( 8 !== $bitDepth ) {
			if ( ! empty( $strategy['supports_depth'] ) ) {
				$args[] = '-depth';
				$args[] = (string) $bitDepth;
			}
			if ( ! empty( $strategy['supports_bit_depth_define'] ) ) {
				$args[] = '-define';
				$args[] = ( 'heic' === $ns ? 'heic:bit-depth=' : 'avif:bit-depth=' ) . (string) $bitDepth;
			}
		}

		// Colorspace.
		$args[] = '-colorspace';
		$args[] = 'sRGB';

		// Extra CLI arguments from settings.
		if ( ! empty( $settings->cliArgs ) ) {
			// Use str_getcsv to respect quotes, e.g. -set comment "hello world".
			$userArgs = str_getcsv( $settings->cliArgs, ' ', '"', '\\' );
			foreach ( $userArgs as $arg ) {
				if ( trim( $arg ) !== '' ) {
					$args[] = $arg;
				}
			}
		}

		// Input/Output.
		// Do not force prefixes as it can cause "no decode delegate" errors in some environments.
		$sourceArg = $source;
		$outputArg = $destination;

		// Construct command using argv form to avoid invoking a shell.
		$command = array_merge( array( $bin, $sourceArg ), array_map( 'strval', $args ), array( $outputArg ) );
		// A safe, human-readable representation for debugging/logs (not executed).
		$cmdParts       = array_map(
			static fn( $p ): string => escapeshellarg( (string) $p ),
			$command
		);
		$commandForLogs = implode( ' ', $cmdParts );

		// Retry loop.
		$attempts    = 0;
		$maxAttempts = 3;
		$success     = false;

		do {
			$descriptorSpec = array(
				0 => array( 'pipe', 'r' ),  // stdin.
				1 => array( 'pipe', 'w' ),  // stdout.
				2 => array( 'pipe', 'w' ),   // stderr.
			);

			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Required for non-shell command execution.
			$process = proc_open(
				$command,
				$descriptorSpec,
				$pipes,
				null,
				$env,
				array( 'bypass_shell' => true )
			);

			if ( is_resource( $process ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for proc_open pipes.
				fclose( $pipes[0] ); // Close stdin.

				$stdout = stream_get_contents( $pipes[1] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for proc_open pipes.
				fclose( $pipes[1] );

				$stderr = stream_get_contents( $pipes[2] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for proc_open pipes.
				fclose( $pipes[2] );

				$exitCode = proc_close( $process );

				$this->lastExitCode = $exitCode;
				$this->lastOutput   = trim( $stdout . "\n" . $stderr );

				if ( 0 === $exitCode ) {
					$success = true;
					break;
				}

				// Check for transient errors.
				$outLower    = strtolower( $this->lastOutput );
				$isTransient = ( str_contains( $outLower, 'readimage' )
					|| str_contains( $outLower, 'no images defined' ) );

				if ( ! $isTransient ) {
					break;
				}

				usleep( 250000 ); // 250ms
				++$attempts;
			} else {
				return ConversionResult::failure( "Failed to open process for command: $commandForLogs" );
			}
		} while ( $attempts < $maxAttempts );

		if ( ! $success ) {
			return $this->analyzeError();
		}

		if ( ! file_exists( $destination ) || filesize( $destination ) <= 0 ) {
			return ConversionResult::failure(
				'CLI reported success but file is missing or zero bytes.',
				'Verify that your ImageMagick build supports AVIF writing.'
			);
		}

		return ConversionResult::success();
	}

	private function analyzeError(): ConversionResult {
		$snippet = substr( $this->lastOutput, 0, 500 );
		$err     = "CLI failed (exit {$this->lastExitCode}): $snippet";

		$suggestion = 'Check binary path and permissions.';
		$outLower   = strtolower( $this->lastOutput );

		if ( 127 === $this->lastExitCode ) {
			$suggestion = 'Binary not found or not executable.';
		} elseif ( 134 === $this->lastExitCode ) {
			// Exit 134 = SIGABRT (abort signal) - encoder crash, often due to resolution limits.
			$sizeMB     = round( $this->lastSourceSize / 1024 / 1024, 2 );
			$suggestion = 'ImageMagick aborted (exit 134) while processing ' . $sizeMB . 'MB source file. ' .
				'This usually indicates the source image exceeds AVIF encoding limits. ' .
				'AVIF Baseline Profile supports max 8.9 megapixels (8192×4352); ' .
				'AVIF Advanced Profile supports max 35.6 megapixels (16384×8704). ' .
				'Images exceeding these limits require grid tiling, which ImageMagick/libheif may not support automatically. ' .
				'Solutions: (1) Resize the source image before conversion (e.g., max 8000px on longest edge), ' .
				'(2) Use a tool that supports AVIF grid tiling for very large images, or ' .
				'(3) Check if your libheif version supports automatic tiling.';
		} elseif ( str_contains( $outLower, 'delegate' ) || str_contains( $outLower, 'no decode delegate' ) ) {
			$suggestion = 'ImageMagick is missing a delegate (libjpeg/libavif).';
		} elseif ( str_contains( $outLower, 'memory' ) || str_contains( $outLower, 'resource limit' ) ) {
			$suggestion = 'Server ran out of memory/resources.';
		}

		return ConversionResult::failure( $err, $suggestion );
	}
}
