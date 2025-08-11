=== AVIF Local Support ===
Contributors: daviddegner
Tags: images, avif, performance, conversion, media
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unified AVIF support and conversion for WordPress. Local-first processing with a focus on image quality when converting JPEGs.

== Description ==

AVIF Local Support adds modern AVIF image support to WordPress while keeping compatibility with existing JPEG media.

- Local-first: all processing happens on your own server (works great on local environments like Local, MAMP, Docker) — no external calls.
- Image quality first: uses Imagick when available for high-quality resizing (LANCZOS), corrects EXIF orientation, and can preserve ICC color profiles and EXIF/XMP/IPTC metadata.
- Tunable quality and speed: set AVIF quality (0–100) and encoder speed (0–10) to balance fidelity and performance.

- Wraps existing `<img>` tags in `<picture>` with an AVIF `<source>` when an `.avif` version is present.
- Converts JPEG originals and generated sizes to AVIF on upload, or via a daily/background scan.
- Optional preservation of EXIF/XMP/IPTC metadata and ICC color profiles (when using ImageMagick/Imagick).
- WordPress-aware resizing logic to avoid double-resizing when generating AVIF sizes from originals.
- Allows AVIF uploads (`image/avif`).

No external services. No tracking. All processing happens on your server (including local setups).

= How it works =

- Front end: Filters common image outputs to add an AVIF `<source>` ahead of the original JPEG. Browsers that support AVIF will use it; others fall back to JPEG.
- Conversion: Uses Imagick when available (preferred) — with auto-orientation and LANCZOS resizing — or GD with an AVIF encoder. Quality and speed are configurable.
- Scheduling: Optional daily scan to backfill missing `.avif` files for existing JPEGs.

= Requirements =

- PHP 8.0 or later (8.1+ recommended)
- WordPress 6.5 or later
- Imagick PHP extension recommended for best quality and metadata/ICC preservation. GD fallback supported.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/avif-local-support` directory, or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → AVIF Local Support to configure options.

== Frequently Asked Questions ==

= Does this modify my original JPEGs? =
No. It creates `.avif` files alongside your existing JPEG originals and sizes.

= Will this slow down uploads? =
If “Convert on upload” is enabled, some servers may take longer to process uploads due to AVIF generation. You can disable this and use the scheduled/background conversion instead.

= Do I need Imagick? =
Imagick is recommended for the best results and for preserving metadata and color profiles. If Imagick is not available, the plugin falls back to GD where supported.

= Does it track users or send data externally? =
No. The plugin does not track users or send data to external services.


== Changelog ==

= 0.1.1 =
- Improve accessibility and settings labels; better time input handling for scheduled conversions
- More robust DOM handling for wrapping images and avoiding double-wrapping
- UI: clearer Convert Now button text and styling on the settings page
- Minor performance and reliability improvements in scheduling and conversion pipeline

= 0.1.0 =
Initial release.

== Upgrade Notice ==

= 0.1.1 =
Accessibility and scheduling improvements; recommended update.

= 0.1.0 =
Initial release of AVIF Local Support.
