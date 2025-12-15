<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Encoders;

use Ddegner\AvifLocalSupport\Contracts\AvifEncoderInterface;
use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;
use Imagick;
use Throwable;

defined( 'ABSPATH' ) || exit;

class ImagickEncoder implements AvifEncoderInterface {

	public function getName(): string {
		return 'imagick';
	}

	public function isAvailable(): bool {
		if ( ! extension_loaded( 'imagick' ) ) {
			return false;
		}
		try {
			$im      = new Imagick();
			$formats = $im->queryFormats( 'AVIF' );
			return ! empty( $formats );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	public function convert( string $source, string $destination, AvifSettings $settings, ?array $dimensions = null ): ConversionResult {
		if ( ! extension_loaded( 'imagick' ) ) {
			return ConversionResult::failure( 'Imagick extension not loaded' );
		}

		try {
			$im = new Imagick( $source );

			// Check output dimensions against AVIF specification limits.
			// AVIF Advanced Profile max: 35,651,584 pixels (16384×8704).
			$srcWidth  = $im->getImageWidth();
			$srcHeight = $im->getImageHeight();
			$maxPixels = 35651584; // AVIF Advanced Profile limit

			// Determine output dimensions
			if ( $dimensions && isset( $dimensions['width'], $dimensions['height'] ) ) {
				$outputWidth  = (int) $dimensions['width'];
				$outputHeight = (int) $dimensions['height'];
			} else {
				// No resize - output will be same as source
				$outputWidth  = $srcWidth;
				$outputHeight = $srcHeight;
			}

			$outputPixels = $outputWidth * $outputHeight;
			if ( $outputPixels > $maxPixels ) {
				$im->destroy();
				$megapixels = round( $outputPixels / 1000000, 1 );
				return ConversionResult::failure(
					"Output exceeds AVIF maximum size: {$outputWidth}×{$outputHeight} ({$megapixels}MP)",
					'AVIF Advanced Profile supports max 35.6 megapixels (16384×8704). ' .
					'Resize the image before conversion or use WordPress media settings to generate smaller sizes.'
				);
			}

			// Capture ICC
			$originalIcc = '';
			$hadIcc      = false;
			try {
				$originalIcc = $im->getImageProfile( 'icc' );
				$hadIcc      = ! empty( $originalIcc );
			} catch ( Throwable $e ) {
				// ignore
			}

			// Auto Orient
			if ( method_exists( $im, 'autoOrientImage' ) ) {
				$im->autoOrientImage();
				if ( defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
					$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
				}
			}

			// Resize/Crop
			if ( $dimensions && isset( $dimensions['width'], $dimensions['height'] ) ) {
				$this->resizeAndCrop( $im, (int) $dimensions['width'], (int) $dimensions['height'] );
			}

			$im->setImageFormat( 'AVIF' );

			// Settings
			// Suppress warnings for setOption as some versions might complain
			@$im->setOption( 'avif:quality', (string) $settings->quality );
			$im->setImageCompressionQuality( $settings->quality );
			@$im->setOption( 'avif:speed', (string) min( 8, $settings->speed ) );

			if ( $settings->lossless ) {
				@$im->setOption( 'avif:lossless', 'true' );
			}

			// Preserve all metadata (EXIF, XMP, IPTC, ICC profiles)
			// Do not call stripImage()

			// Colorspace
			if ( ! $hadIcc ) {
				if ( method_exists( $im, 'transformImageColorspace' ) ) {
					$im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
				} elseif ( method_exists( $im, 'setImageColorspace' ) ) {
					$im->setImageColorspace( Imagick::COLORSPACE_SRGB );
				}
			}

			// Subsampling & Bit Depth
			@$im->setOption( 'avif:chroma-subsample', $settings->getChromaLabel() );
			@$im->setOption( 'avif:bit-depth', $settings->bitDepth );
			$im->setImageDepth( (int) $settings->bitDepth );

			// NCLX defaults if no ICC
			if ( ! $hadIcc ) {
				@$im->setOption( 'avif:color-primaries', '1' );
				@$im->setOption( 'avif:transfer-characteristics', '13' );
				@$im->setOption( 'avif:matrix-coefficients', '1' );
				@$im->setOption( 'avif:range', 'full' );
			}

			// Restore ICC (always preserve for now as per original logic)
			if ( $hadIcc && ! empty( $originalIcc ) ) {
				try {
					$im->setImageProfile( 'icc', $originalIcc );
				} catch ( Throwable $e ) {
					// ignore
				}
			}

			// Fix EXIF Orientation
			if ( method_exists( $im, 'setImageProperty' ) ) {
				try {
					$im->setImageProperty( 'exif:Orientation', '1' );
				} catch ( Throwable $e ) {
					// ignore
				}
			}

			$im->writeImage( $destination );
			$im->destroy();

			if ( file_exists( $destination ) && filesize( $destination ) > 512 ) {
				return ConversionResult::success();
			}

			return ConversionResult::failure(
				'Imagick produced invalid AVIF',
				'Your ImageMagick build may lack AVIF write support.'
			);

		} catch ( Throwable $e ) {
			$msg        = strtolower( $e->getMessage() );
			$suggestion = 'Check PHP error logs.';
			if ( str_contains( $msg, 'unable to load module' ) ) {
				$suggestion = 'Imagick PHP module broken.';
			} elseif ( str_contains( $msg, 'no decode delegate' ) ) {
				$suggestion = 'Imagick cannot read this file format.';
			}
			return ConversionResult::failure( 'Imagick error: ' . $e->getMessage(), $suggestion );
		}
	}

	private function resizeAndCrop( Imagick $im, int $targetW, int $targetH ): void {
		$srcW = $im->getImageWidth();
		$srcH = $im->getImageHeight();
		$tW   = max( 1, $targetW );
		$tH   = max( 1, $targetH );

		$srcAspect = $srcW / max( 1, $srcH );
		$tAspect   = $tW / max( 1, $tH );

		if ( $srcAspect > $tAspect ) {
			$cropH = $srcH;
			$cropW = (int) ( $srcH * $tAspect );
			$cropX = (int) ( ( $srcW - $cropW ) / 2 );
			$cropY = 0;
		} else {
			$cropW = $srcW;
			$cropH = (int) ( $srcW / $tAspect );
			$cropX = 0;
			$cropY = (int) ( ( $srcH - $cropH ) / 2 );
		}

		$im->cropImage( $cropW, $cropH, $cropX, $cropY );
		$im->resizeImage( $tW, $tH, Imagick::FILTER_LANCZOS, 1.0 );
	}
}
