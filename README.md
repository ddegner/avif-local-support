# AVIF Local Support

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/avif-local-support)](https://wordpress.org/plugins/avif-local-support/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/avif-local-support)](https://wordpress.org/plugins/avif-local-support/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**High-quality AVIF image conversion for WordPress — no subscriptions, no external services.**

Built by a [Boston photographer](https://www.daviddegner.com) who needed it for their own portfolio. This plugin prioritizes **image quality** over everything else.

## Features

- **Local Processing** — All conversion happens on your server. No external API calls. Works great on a shared CPU with 2GB RAM.
- **Quality First** — Uses LANCZOS resizing, preserves ICC color profiles, and keeps EXIF/XMP/IPTC metadata intact.
- **Fully Tunable** — Control quality (0–100), speed (0–10), chroma subsampling (4:2:0, 4:2:2, 4:4:4), and bit depth (8/10/12-bit).
- **Smart Fallback** — Serves AVIF to supported browsers, JPEG to everyone else via picture elements.
- **Automatic Conversion** — Convert on upload or via daily scheduled background scans.

## How It Works

**Front end:** The plugin wraps your img tags in picture elements with an AVIF source. Browsers that support AVIF load the smaller, higher-quality file — others gracefully fall back to JPEG.

**Conversion:** Uses ImageMagick CLI (fastest), Imagick PHP extension (high quality), or GD Library (fallback) to convert JPEGs to AVIF on upload or via background jobs.

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
