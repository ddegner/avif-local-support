# AVIF Local Support
Contributors: ddegner
Tags: avif, images, performance, media, optimization
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 0.5.17
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

High-quality AVIF image conversion for WordPress — local, quality-first.

## Description

Built by a [Boston photographer](https://www.daviddegner.com) who needed it for their own portfolio. This plugin prioritizes **image quality** over everything else — no subscriptions, no external services.

## Features

- **Local Processing** — All conversion happens on your server. No external API calls. Works great on a shared CPU with 2GB RAM.
- **Quality First** — Uses LANCZOS resizing, preserves ICC color profiles, and keeps EXIF/XMP/IPTC metadata intact.
- **Fully Tunable** — Control quality (0–100), speed (0–10), chroma subsampling (4:2:0, 4:2:2, 4:4:4), and bit depth (8/10/12-bit).
- **Smart Fallback** — Serves AVIF to supported browsers, JPEG to everyone else via picture elements.
- **Automatic Conversion** — Convert on upload or via daily scheduled background scans.
- **LQIP Placeholders** — Generate ThumbHash-based low-quality image placeholders for smooth loading.

## How It Works

**Front end:** The plugin wraps your img tags in picture elements with an AVIF source. Browsers that support AVIF load the smaller, higher-quality file — others gracefully fall back to JPEG.

**Conversion:** Uses ImageMagick CLI (fastest), Imagick PHP extension (high quality), or GD Library (fallback) to convert JPEGs to AVIF on upload or via background jobs.

**LQIP:** Generates compact (~30 byte) ThumbHash placeholders that display instantly while images load.

## Installation

1. Upload to `/wp-content/plugins/avif-local-support` or install via **Plugins → Add New**
2. Activate the plugin
3. Navigate to **Settings → AVIF Local Support**

### Requirements

- **PHP:** 8.3 or later
- **WordPress:** 6.8 or later
- **Recommended:** Imagick extension with AVIF-enabled ImageMagick

## WP-CLI Commands

Manage AVIF conversions from the command line.

### Status

Show system status and AVIF support diagnostics:

    wp avif status
    wp avif status --format=json

### Convert

Convert JPEG images to AVIF format:

    wp avif convert --all
    wp avif convert 123
    wp avif convert --all --dry-run

Options:

- `<attachment-id>` — Specific attachment ID to convert
- `--all` — Convert all attachments missing AVIF versions
- `--dry-run` — Show what would be converted without actually converting

### Statistics

Show AVIF conversion statistics:

    wp avif stats
    wp avif stats --format=json

### Logs

View or clear conversion logs:

    wp avif logs
    wp avif logs --limit=50
    wp avif logs --clear

Options:

- `--clear` — Clear all logs
- `--limit=<number>` — Number of logs to show (default: 20)

### Delete

Delete AVIF files for an attachment or all attachments:

    wp avif delete 123
    wp avif delete --all --yes

Options:

- `<attachment-id>` — Attachment ID to delete AVIF files for
- `--all` — Delete all AVIF files in the media library
- `--yes` — Skip confirmation prompt when using --all

### LQIP Commands

Manage LQIP (ThumbHash) placeholders:

    wp lqip stats
    wp lqip generate --all --force
    wp lqip generate 123
    wp lqip delete --all --yes

For more information, visit [wp-cli.org](https://wp-cli.org/).

## Server Setup

The plugin supports three conversion engines, in order of preference:

### ImageMagick CLI (Fastest, Recommended)

Uses the ImageMagick command-line binary directly:

- **System binary:** ImageMagick 7.x built with HEIF/AVIF support (via libheif)
- **No PHP extension required**
- **Benefits:** Fastest performance, LANCZOS resizing, full metadata preservation (EXIF, XMP, IPTC, ICC)
- **Typical paths:** `/usr/bin/magick`, `/usr/local/bin/magick`, or Homebrew on macOS

To verify AVIF support:

    magick -list format | grep -i avif

### Imagick PHP Extension (High Quality)

Uses the PHP Imagick extension:

- **PHP extension:** imagick
- **System libraries:** ImageMagick built with HEIF/AVIF support (via libheif)
- **Benefits:** LANCZOS resizing, full metadata preservation (EXIF, XMP, IPTC, ICC), color profile handling

To install on Ubuntu/Debian:

    apt install php-imagick imagemagick libheif-dev

### GD Library (Fallback)

Uses PHP's built-in GD library:

- **PHP extension:** gd built with AVIF support (provides imageavif on PHP 8.1+)
- **Note:** Some distro builds omit AVIF support; limited metadata preservation

### MIME Type Configuration

Ensure your web server is configured to serve .avif files as image/avif.

### Documentation

- [ImageMagick installation](https://imagemagick.org/script/download.php)
- [PHP Imagick installation](https://www.php.net/imagick)
- [PHP GD installation](https://www.php.net/manual/en/image.installation.php)
- [ImageMagick format support](https://imagemagick.org/script/formats.php)

## FAQ

### Does this modify my original JPEGs?

No. AVIF files are created alongside your existing images. Your originals remain untouched.

### Will this slow down uploads?

If "Convert on upload" is enabled, uploads may take slightly longer. You can disable this and use scheduled background conversion instead.

### Do I need Imagick?

Recommended but not required. Imagick provides the best quality and preserves metadata/color profiles. The plugin falls back to GD if unavailable.

### Does it track users or send data externally?

No. Zero tracking, zero external calls. Everything runs locally.

### Why do I see "High risk of memory exhaustion"?

The plugin estimates memory before processing to prevent crashes. Try switching to "ImageMagick CLI" engine, increasing PHP memory_limit, or checking "Disable memory check" in settings.

### AVIF conversions produce empty files on LiteSpeed?

This is caused by libheif 1.12.0 crashing in LiteSpeed's restricted environment. Upgrade libheif to 1.15+ to fix. See the [WordPress.org FAQ](https://wordpress.org/plugins/avif-local-support/#faq) for build instructions.

### ImageMagick CLI not detected on LiteSpeed/CyberPanel?

LiteSpeed's open_basedir restriction prevents PHP from detecting executables outside allowed paths. The binary may still work — try setting the path manually in settings.

## Screenshots

1. **Settings** — Configure AVIF quality, speed, and conversion options
2. **Tools** — Convert missing AVIFs, test conversions, bulk delete
3. **Status** — Server capability diagnostics and library coverage
4. **About** — Quick reference and version info

## Changelog

### 0.5.17

- Fix: LQIP background now correctly clears for already-loaded/cached images.

### 0.5.16

- Feature: Restored LQIP background cleanup after image load.
- Feature: Added "Pixelated placeholders" option to display ThumbHash as sharp pixels.
- Enhancement: Improved LQIP transition — blur-up effect with scale animation.
- Change: Transition updated to 400ms ease-out for smoother reveal.

### 0.5.15

- Enhancement: Tuned LQIP fade thresholds based on human perception research.
- Change: Page load window increased to 2 seconds (covers initial render).
- Change: Individual load duration increased to 200ms (perception threshold).

### 0.5.14

- Fix: WordPress Plugin Check compliance — proper escaping for inline scripts/styles.
- Fix: Replace `strip_tags()` with `wp_strip_all_tags()` in BackgroundImages.
- Fix: Prefix global variables with plugin prefix.
- Chore: Remove `.DS_Store` files from repository.

### 0.5.13

- Refactor: Simplified LQIP fade logic by removing background cleanup step.

### 0.5.12

- Feature: LQIP operations now log successes, failures, and summaries to the Logs panel.
- Fix: LQIP stats now correctly validate metadata structure, matching generation skip logic.
- Fix: Add object cache clearing before LQIP generation to prevent stale data on servers with persistent caching.
- Fix: Prevent false positive \"success\" reports in `wp lqip generate` when generation fails silently but stale data exists.
- Fix: Remove deprecated `imagedestroy()` calls for PHP 8.0+ compatibility.
- Refactor: Consolidate LQIP generation logic into shared helper for consistency between Admin UI and CLI.

### 0.5.11

- Fix: Critical fix for `wp lqip generate --force` option.
- Fix: `wp lqip delete --all` now correctly clears object cache to prevent stale stats.

### 0.5.9

- Feature: Added `--force` option to `wp lqip generate` command to force regeneration of LQIPs.

### 0.5.8

- Fix: Resolved "Insufficient memory" error when generating LQIP for high-resolution images by optimizing ImageMagick loading.

### 0.5.7

- Feature: CSS background image AVIF support — replaces JPEG background images with AVIF versions. Thanks to [David C.](https://www.rankxpress.com)
- Feature: Works with page builders (Elementor, Divi, Beaver Builder, WPBakery, Bricks, etc.)
- Feature: New setting "Serve AVIF for CSS backgrounds" under AVIF serving options
- Fix: Versioned image URLs (e.g., `image.jpg?ver=123`) now correctly detected and replaced
- Fix: Query string stripping for CSS file path resolution
- Security: Sanitize CSS selectors to prevent XSS injection

### 0.5.6

- Enhancement: Smart fade logic — only apply fade transition for slow-loading images
- Enhancement: Images loading within 1 second of page load display instantly (no fade)
- Enhancement: Cached/fast-loading images skip fade for snappier feel

### 0.5.5

- Feature: Added "Fade in images" option to smoothly transition from LQIP to full image
- Fix: Used img.decode() to prevent white flash during LQIP fade transition
- Fix: CSS selector now correctly handles both picture wrapper and standalone img cases

### 0.5.4

- Feature: Added optional smooth fade-in for LQIP images
- Translations: Added German, Italian, Japanese, Portuguese (Brazil), and Russian translations
- Enhancement: Updated translations for Spanish, French, Hindi, and Chinese
- Fix: `wp lqip generate --all` now correctly processes all eligible images
- Fix: `wp lqip stats` accurately counts all supported image types
- Fix: LQIP JavaScript is now correctly excluded when feature is disabled
- Fix: Improved error logging for LQIP generation failures

### 0.5.3

- Fix: Added missing LQIP options to plugin activation (thumbhash_size, generate_on_upload, generate_via_schedule)
- Fix: Added missing LQIP options to uninstall cleanup for complete data removal
- Fix: Properly minified thumbhash-decoder.min.js (62% size reduction)
- Fix: Excluded developer documentation from WordPress plugin distribution

### 0.5.2

- Feature: Bundled ThumbHash library — no Composer dependency required on deployment
- Enhancement: Improved LQIP generation with better error handling, progress reporting, and memory management
- Enhancement: Added `--limit` and `--verbose` options to `wp lqip generate` command
- Fix: Resolved hanging issue in `wp lqip generate --all` command with better error handling and progress output
- Fix: Clear error messages when ThumbHash library is unavailable

### 0.5.1

- Fix: Improved error handling and logging for ThumbHash generation
- Enhancement: WP-CLI now warns if LQIP feature is disabled in settings
- Enhancement: Better diagnostics for missing source files during LQIP generation

### 0.5.0

- Feature: LQIP (Low Quality Image Placeholder) using ThumbHash for smooth image loading
- Feature: WP-CLI commands for LQIP management (`wp lqip stats`, `wp lqip generate`, `wp lqip delete`)
- Feature: Dedicated LQIP settings tab with enable/disable toggle
- Enhancement: Reorganized admin UI with combined AVIF Tools section
- Enhancement: Consistent stat labels across AVIF and LQIP tools (Images/With/Without)
- Enhancement: Renamed "Test conversion" to "Test AVIF Conversion" for clarity
- Enhancement: Removed beta labels from LQIP feature
- Fix: LQIP stats now correctly count all supported image types (JPEG, PNG, GIF, WebP)
- Dev: Auto-fixed 6,152+ PHPCS issues for WordPress coding standards compliance

### 0.4.9

- Fix: WordPress Plugin Check compliance — proper escaping, Yoda conditions, and PHPCS ignores
- Fix: Improved uninstall cleanup with object cache awareness
- Dev: Code formatting aligned with WordPress coding standards

### 0.4.8

- Fix: Resolved logging pipeline issues where REST API couldn't retrieve logs due to `is_admin()` check
- Fix: Fixed upload test timeout by temporarily disabling synchronous AVIF conversion during test uploads
- Fix: Improved AJAX feedback for log operations (clear/refresh)
- Docs: Updated minimum requirements to WordPress 6.8 and PHP 8.3
- Docs: Cleaned up README header format for WordPress.org compatibility
- Docs: Removed donate link from readme
- Dev: Added WordPress stubs for improved IDE support

### 0.4.7

- Enhancement: Improved time formatting in conversion progress display (hh:mm:ss format)
- Enhancement: CLI code formatting improvements
- Fix: Corrected contributor username in readme

### 0.4.6

- Docs: Added WordPress.org metadata headers
- Docs: Updated tested up to WordPress 6.9

### 0.4.5

- Fix: Corrected "Upload & Test" status display issues (spinner visibility, status text alignment)
- Fix: `wp avif delete` command now correctly reports success/failure counts and handles permission errors
- Fix: `wp avif convert` command output now includes count of missing AVIFs
- Enhancement: Added GitHub Action for automated release creation

### 0.4.4

- Feature: Fully asynchronous "Upload & Test" conversion to prevent timeouts on large images
- Enhancement: Re-architected test conversion to use sequential polling
- Enhancement: Admin UI modularized with template-based architecture
- Optimization: Removed unused `vendor` storage and legacy dependencies, reducing plugin size
- Fix: Restored robust queue rendering for test results

### 0.4.3

- Added WP-CLI commands: status, convert, stats, logs, delete
- Refactored admin interface with modular architecture
- Improved logging and environment diagnostics

### 0.4.2

- Auto-detection for ImageMagick CLI in "Auto" mode
- REST API replaces admin-ajax for better performance
- Smart -define namespace probing (heic/avif)
- Tested with WordPress 6.9

### 0.4.1

- Compatibility: Tested up to WordPress 6.9

### 0.4.0

- Major refactor: strict types, DTOs, dedicated Encoder classes
- Optimized frontend HTML parsing
- Enhanced CLI execution and error handling
- Composer support (PSR-4 ready)

### 0.3.x

- Environment variable injection for restricted PHP environments
- Original image source handling fixes
- Engine priority: CLI, Imagick, GD

### 0.2.x

- ImageMagick CLI support with auto-detection
- Memory pre-check to prevent fatal errors
- Logs panel with detailed entries
- Lightbox anchor rewriting
- Chroma subsampling and bit depth options

### 0.1.x

- Initial release with Imagick/GD support
- ICC profile preservation
- EXIF orientation handling
- Basic admin interface

See [GitHub releases](https://github.com/ddegner/avif-local-support/releases) for complete version history.

## Contributing

Contributions welcome! Please submit issues and pull requests on [GitHub](https://github.com/ddegner/avif-local-support).

## License

GPL v2 or later — [View License](https://www.gnu.org/licenses/gpl-2.0.html)
