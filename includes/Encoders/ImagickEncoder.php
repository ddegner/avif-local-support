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

			// Apply quality before and after selecting AVIF format.
			// Some Imagick builds only honor this once AVIF is set, others preserve prior values.
			$this->applyQualityOptions( $im, $settings->quality );
			$im->setImageFormat( 'AVIF' );
			$this->applyQualityOptions( $im, $settings->quality );

			// Settings.
			// Apply both heic:* and avif:* artifacts to handle delegate namespace differences across builds.
			$this->setBothNamespaceOption( $im, 'speed', (string) min( 8, $settings->speed ) );

			$this->setBothNamespaceOption( $im, 'lossless', $settings->lossless ? 'true' : 'false' );

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
			$this->setCoderOption( $im, 'heic:chroma', $settings->getChromaNumeric() );
			$this->setCoderOption( $im, 'avif:chroma-subsample', $settings->getChromaLabel() );
			$this->setBothNamespaceOption( $im, 'bit-depth', $settings->bitDepth );
			$im->setImageDepth( (int) $settings->bitDepth );

			// NCLX defaults if no ICC
			if ( ! $hadIcc ) {
				$this->setBothNamespaceOption( $im, 'color-primaries', '1' );
				$this->setBothNamespaceOption( $im, 'transfer-characteristics', '13' );
				$this->setBothNamespaceOption( $im, 'matrix-coefficients', '1' );
				$this->setBothNamespaceOption( $im, 'range', 'full' );
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

			if ( file_exists( $destination ) && filesize( $destination ) > 0 ) {
				return ConversionResult::success();
			}

			return ConversionResult::failure(
				'Imagick did not produce a usable AVIF',
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

	private function setBothNamespaceOption( Imagick $im, string $name, string $value ): void {
		$this->setCoderOption( $im, 'heic:' . $name, $value );
		$this->setCoderOption( $im, 'avif:' . $name, $value );
	}

	private function applyQualityOptions( Imagick $im, int $quality ): void {
		$q = (string) max( 0, min( 100, $quality ) );

		// Keep explicit namespace keys for builds that wire AVIF quality through coder-specific options.
		$this->setBothNamespaceOption( $im, 'quality', $q );
		$this->setBothNamespaceOption( $im, 'q', $q );

		// Apply generic quality knobs to mirror CLI "-quality" behavior as closely as possible.
		$this->setCoderOption( $im, 'quality', $q );
		$this->setCoderOption( $im, 'compression-quality', $q );

		try {
			$im->setImageCompressionQuality( (int) $q );
		} catch ( Throwable $e ) {
			// ignore if unsupported by this build
		}

		// Some builds expose quality controls only through object-level APIs.
		if ( method_exists( $im, 'setCompressionQuality' ) ) {
			try {
				$im->setCompressionQuality( (int) $q );
			} catch ( Throwable $e ) {
				// ignore if unsupported by this build
			}
		}

		// Mirror CLI-style quality through image properties as a last-resort path.
		if ( method_exists( $im, 'setImageProperty' ) ) {
			foreach ( array( 'quality', 'heic:quality', 'avif:quality', 'heic:q', 'avif:q' ) as $property ) {
				try {
					$im->setImageProperty( $property, $q );
				} catch ( Throwable $e ) {
					// ignore unsupported properties
				}
			}
		}
	}

	private function setCoderOption( Imagick $im, string $name, string $value ): void {
		// setImageArtifact maps to coder -define/-set behavior in modern Imagick builds.
		if ( method_exists( $im, 'setImageArtifact' ) ) {
			try {
				$im->setImageArtifact( $name, $value );
			} catch ( Throwable $e ) {
				// ignore unsupported artifacts on older delegates
			}
		}

		// Keep setOption as a fallback for older behavior and mixed installations.
		try {
			@$im->setOption( $name, $value );
		} catch ( Throwable $e ) {
			// ignore unsupported options
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
