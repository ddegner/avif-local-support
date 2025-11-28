=== AVIF Local Support ===
Contributors: ddegner
Plugin URI: https://github.com/ddegner/avif-local-support
Tags: images, avif, performance, conversion, media
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 0.4.1
Requires PHP: 8.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AVIF support for converting and serving high quality photos. Built by a photographer, not trying to sell subscriptions.

== Description ==

AVIF Local Support adds modern AVIF image support to WordPress while keeping compatibility with existing JPEG media.  Built by a [Boston photographer](https://www.daviddegner.com) who needs it for their own portfolio website.

**GitHub Repository:** [https://github.com/ddegner/avif-local-support](https://github.com/ddegner/avif-local-support)

- Local-first: all processing happens on your own server — no external calls.  Works well for me on a shared CPU with 2GB of RAM on Linode.
- Image quality first: uses Imagick PHP and CLI when available for high-quality resizing (LANCZOS), corrects EXIF orientation, and preserves ICC color profiles and EXIF/XMP/IPTC metadata when possible.
- Tunable quality and speed: set AVIF quality (0–100) and encoder speed (0–10) to balance fidelity and performance.  You can even adjust choose chroma subsampling (4:2:0, 4:2:2, 4:4:4) and bit depth (8/10/12-bit). Defaults: 4:2:0 and 8-bit if your server supports it and you want higher quality images.
- Wraps existing `<img>` tags in `<picture>` with an AVIF `<source>` when an `.avif` version is present.
- Converts JPEG originals and generated sizes to AVIF on upload, or via a daily/background scan.
- Preserves EXIF/XMP/IPTC metadata and ICC color profiles by default (when using ImageMagick/Imagick).
- WordPress-aware resizing logic enabled by default to avoid double-resizing when generating AVIF sizes from originals.
 - Tools include: convert missing AVIFs, upload a test JPEG, and delete all AVIF files in uploads.

= How it works =

- Front end: Filters common image outputs to add an AVIF `<source>` ahead of the original JPEG. Browsers that support AVIF will use it; others fall back to JPEG.
- Conversion: Uses Imagick when available (preferred) — with auto-orientation and LANCZOS resizing — or GD with an AVIF encoder. Quality, speed, chroma subsampling, and bit depth are configurable (subsampling/bit depth applied via Imagick when supported).
- Scheduling: Optional daily scan to backfill missing `.avif` files for existing JPEGs.

= Requirements =

- PHP 8.0 or later (8.1+ recommended)
- WordPress 6.5 or later
- Imagick PHP extension recommended for best quality and metadata/ICC preservation. GD fallback supported.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/avif-local-support` directory, or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → AVIF Local Support to configure options.

== Ubuntu/Debian setup (summary) ==

For local JPEG→AVIF conversion you need either Imagick with AVIF-enabled ImageMagick, or GD with AVIF support:

- Imagick path (recommended)
  - PHP extension: imagick
  - System libraries: ImageMagick built with HEIF/AVIF support (via libheif)
  - Notes: This path enables higher-quality resizing and preserves metadata/ICC profiles

- GD path (fallback)
  - PHP extension: gd built with AVIF support (provides `imageavif` on PHP 8.1+)
  - Notes: Ensure your distro’s PHP GD is compiled with AVIF; some builds omit it

- Helpful extras
  - PHP exif extension (for orientation handling in the GD fallback)
  - Web server configured to serve `.avif` as `image/avif` (Apache/Nginx/LiteSpeed)

Official documentation:
- PHP Imagick installation: https://www.php.net/imagick
- PHP GD installation (AVIF details): https://www.php.net/manual/en/image.installation.php
- ImageMagick format support (AVIF/HEIF): https://imagemagick.org/script/formats.php
- Apache MIME configuration: https://httpd.apache.org/docs/current/mod/mod_mime.html
- Nginx MIME configuration: https://nginx.org/en/docs/http/ngx_http_types_module.html

== Screenshots ==

1. Settings — configure AVIF conversion and output.
2. Tools — convert missing AVIFs, test a conversion, and delete AVIFs.
3. Status — server capability check and library coverage.
4. About — in-admin readme for quick reference.

== Frequently Asked Questions ==

= Does this modify my original JPEGs? =
No. It creates `.avif` files alongside your existing JPEG originals and sizes.

= Will this slow down uploads? =
If “Convert on upload” is enabled, some servers may take longer to process uploads due to AVIF generation. You can disable this and use the scheduled/background conversion instead.

= Do I need Imagick? =
Imagick is recommended for the best results and for preserving metadata and color profiles. If Imagick is not available, the plugin falls back to GD where supported.

= Does it track users or send data externally? =
No. The plugin does not track users or send data to external services.

= ImageMagick CLI not detected on LiteSpeed/CyberPanel due to open_basedir? =
On LiteSpeed/CyberPanel, the vhost sets a restrictive `open_basedir` (e.g., `/tmp:/home/<site>`). PHP's `is_executable('/usr/local/bin/magick')` returns false under that restriction even when the binary exists and works from the shell. The plugin currently relies on `is_executable` and bails out instead of offering a fallback or a guided fix.

= AVIF conversions produce empty/corrupt files on LiteSpeed (252 bytes, "No compressed data")? =
This is caused by **libheif 1.12.0** (the AVIF encoder library) crashing silently under LiteSpeed's restricted process environment. The fix is to upgrade libheif to version 1.15 or newer.

**Symptoms:**
- AVIF files are created but only ~252 bytes (empty container, no image data)
- ImageMagick reports "Item has no data" or "No compressed data"
- WebP conversions work fine, only AVIF fails
- CLI conversions work, but web-triggered conversions fail

**Solution: Upgrade libheif from source**

`# Install build dependencies
sudo apt install cmake git build-essential pkg-config libde265-dev libaom-dev

# Build and install latest libheif
cd /tmp
git clone --depth 1 https://github.com/strukturag/libheif.git
cd libheif && mkdir build && cd build
cmake -DCMAKE_INSTALL_PREFIX=/usr/local -DWITH_AOM_ENCODER=ON ..
make -j$(nproc)
sudo make install
sudo ldconfig

# Rebuild ImageMagick to use new libheif
cd /tmp
wget https://imagemagick.org/archive/ImageMagick.tar.gz
tar xzf ImageMagick.tar.gz && cd ImageMagick-*
export PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:$PKG_CONFIG_PATH
./configure --with-heic=yes --prefix=/usr/local
make -j$(nproc)
sudo make install
sudo ldconfig

# Verify: should show version 1.15+ 
/usr/local/bin/magick -list format | grep -i avif`

= Why do I see a "High risk of memory exhaustion" error in the logs? =
The plugin now estimates memory usage before processing to prevent fatal errors (crashes) on servers with limited RAM. If you see this, try switching to the "ImageMagick CLI" engine or increasing your PHP `memory_limit`. As a last resort, you can check "Disable memory check" in the settings to bypass this safety measure.

== Changelog ==

= 0.4.1 =
- Compatibility: Tested up to WordPress 6.9.

= 0.4.0 =
- Architecture: Major refactor to use strict types, DTOs, and dedicated Encoder classes.
- Performance: Optimized frontend HTML parsing to reduce overhead.
- Reliability: Improved CLI execution robustness and error handling.
- Security: Enhanced XML entity protection in SVG/XML processing.
- Dev: Added Composer support and improved code organization (PSR-4 ready).

= 0.3.2 =
- Docs: Added FAQ and troubleshooting guide for LiteSpeed/libheif compatibility issue (empty AVIF files).
- Fix: Added environment variable injection (LD_LIBRARY_PATH, HOME, PATH) for restricted PHP environments.

= 0.3.1 =
- Fix: Ensure original image source is used for conversions instead of scaled versions.
- Fix: Switch CLI command to use `-resize` to prevent malformed files on large images.

= 0.3.0 =
- Feature: Engine priority update: CLI > Imagick > GD.
- UI: Updated engine selection UI to reflect new priority and show CLI path.
- Fix: Resolved contradictory server support diagnosis.
- Fix: Improved pipeline robustness and error handling.

= 0.2.9 =
- Fix: Capture and display detailed CLI output and exit codes in logs when a CLI conversion fails silently.
- Fix: Ensure error messages are correctly populated in the log details even when empty.

= 0.2.8 =
- Change: Downgrade memory limit exhaustion error to a warning and continue conversion attempt.
- Logs: Added warning log type when memory limit is exceeded but conversion continues.

= 0.2.7 =
- Change: Prevent fallback to GD if Imagick is available but fails conversion in "Auto" mode. Ensures expected engine is used.

= 0.2.6 =
- Fix: Validate output size for Imagick PHP extension conversions (rejects invalid 0-byte/tiny files).
- Fix: "Copy logs" button now correctly captures the latest logs after a refresh.
- Fix: Ensure debug details for failed CLI conversions are always included in the log error message.

= 0.2.4 =
- Fix: Automatically clean up invalid/empty AVIF files if conversion fails (e.g., exit 0 but no output).
- Fix: Treat existing 0-byte or tiny AVIF files as "missing" so they are regenerated during scans.
- Fix: Restrict macOS-specific ImageMagick environment variables to Darwin systems to prevent interference on Linux.
- Logs: Added verbose debug logging for silent CLI failures (exit 0, no file) to help diagnose resource exhaustion.

= 0.2.3 =
- Support: Automatically rewrite parent anchor links to point to AVIFs (improves compatibility with lightboxes like SimpleLightbox).

= 0.2.2 =
- Reliability: Added pre-conversion memory check to prevent fatal errors on low-RAM servers.
- Settings: Added "Disable memory check" option for advanced users who want to bypass safety limits.
- Logs: Improved error reporting with actionable suggestions (highlighted) for memory limits, missing delegates, and configuration issues.
- Logs: Include memory check status in conversion log details.

= 0.2.1 =
- Admin: Add Logs panel to Tools with refresh/clear and detailed entries (status, engine, duration, sizes, settings, errors).
- Admin: Add “Run ImageMagick test” diagnostic action with inline CLI output and exit code.
- Tools: Show inline progress with a progress bar and faster polling during Convert now.
- Scheduling: Respect site timezone and selected time; reschedule daily task if time changes.
- Engine/CLI: Improve binary validation, set env paths for Homebrew modules, retry transient delegate/read errors, and add helpful AVIF support hint when no output.
- Uploads: Allow `.avif` uploads via WordPress mime filter.
- Output: Remove stray XML declaration from HTML output when using picture tag replacement.
- Docs: Add FAQ for LiteSpeed/CyberPanel `open_basedir` causing `is_executable` to return false.

= 0.2.0 =
- Engine: Add ImageMagick CLI support as alternative to Imagick extension with auto-detection of available binaries.
- Engine: New engine selection settings with automatic detection of ImageMagick CLI installations.
- Admin: Improved header styling to match WordPress metabox design patterns.
- Admin: Enhanced tab navigation with better CSS handling and removal of Status tab.
- Admin: Added reset defaults functionality for settings management.
- UI: Better spacing and visual consistency in admin interface.

= 0.1.8 =
- Performance: Optimize Imagick conversion by using single instance for both processing and metadata extraction, reducing memory usage and file I/O operations.

= 0.1.7 =
- Bugfix: Correct AdobeRGB desaturation by preserving ICC from a fresh read and only adding nclx when untagged; continue to prefer Imagick so ICC survives.

= 0.1.6 =
- Color: Preserve ICC profiles when present; add sRGB nclx only when no ICC.
- Color: Avoid transforming tagged images; transform untagged to sRGB to prevent desaturation.
- Color: Explicitly set AVIF nclx to sRGB/full range when applicable.
- Orientation: Normalize EXIF Orientation to 1 after auto-orienting to prevent double-rotate.
- UI: Status tab warns when GD is the preferred method about lack of color management.

= 0.1.5 =
- Tools: Add “Delete all AVIF files” action with security checks.
- Admin: Show “Running…” on convert start and poll progress more frequently.
- Admin: Increase spacing and add non-breaking spaces between radio options.
- Admin: Place “Convert now” under file input in Upload Test.
- Status: Deduplicate counts by real JPEG path to avoid double-counting.

= 0.1.4 =
- Defaults: Always preserve metadata (EXIF/XMP/IPTC) and ICC color profiles.
- Defaults: Always avoid double-resizing by using WordPress original/-scaled logic.
- Settings: Remove toggles for metadata/ICC/avoid double-resizing (now defaults).
- Settings: Add chroma subsampling radios (4:2:0, 4:2:2, 4:4:4) and bit depth radios (8/10/12-bit). Defaults: 4:2:0 and 8-bit.
- Security: Continue strict sanitization of new settings with whitelists.
- Docs: Update descriptions to reflect new defaults and controls.

= 0.1.3 =
- Cleanup: remove unused admin-post “Convert now” handler; AJAX is the single path.
- Cleanup: simplify deactivation routine to directly clear scheduled hooks.
- Cleanup: remove unused global polling object and helpers in `assets/admin.js`.
- Internal: no user-facing changes; reduces complexity and maintenance overhead.

= 0.1.2 =
- Security: ensure all settings use sanitize_callback and validate/escape inputs consistently
- Admin: sanitize GET/FILES handling for the Tools → Upload Test
- Prefix: standardize on `aviflosu_*` options/defines and `Ddegner\\AvifLocalSupport` namespace
- Docs: update contributor username to `ddegner`

= 0.1.1 =
- Improve accessibility and settings labels; better time input handling for scheduled conversions
- More robust DOM handling for wrapping images and avoiding double-wrapping
- UI: clearer Convert Now button text and styling on the settings page
- Minor performance and reliability improvements in scheduling and conversion pipeline

= 0.1.0 =
Initial release.

== Upgrade Notice ==
= 0.4.1 =
Tested up to WordPress 6.9.

= 0.4.0 =
Major refactor for improved performance, reliability, and security. Recommended update.

= 0.3.2 =
Documents fix for LiteSpeed/libheif AVIF encoding issues. If you see empty 252-byte AVIF files on LiteSpeed, see the FAQ for the libheif upgrade solution.

= 0.3.1 =
Fixes source file selection and large file conversion issues. Recommended update.

= 0.3.0 =
Major update: Improved engine priority (CLI first), UI updates, and fixes for server diagnosis. Recommended update.

= 0.2.9 =
Fixes silent CLI failure logging by capturing detailed output and exit codes. Recommended update for troubleshooting.

= 0.2.8 =
Downgrades strict memory checks to warnings, allowing conversion to proceed on systems with complex memory limit reporting. Recommended update.

= 0.2.7 =
Strict engine selection: prevents silent fallback to GD if Imagick encounters an error. Recommended update.

= 0.2.6 =
Fixes validation for Imagick PHP extension conversions and improves log copying. Recommended update.

= 0.2.4 =
Fixes handling of silent conversion failures and cleans up invalid/empty files. Improved Linux server compatibility. Recommended update.

= 0.2.3 =
Improves compatibility with lightboxes (SimpleLightbox) by linking to AVIFs automatically. Recommended update.

= 0.2.2 =
Improved stability on low-RAM servers with pre-conversion memory checks and better error logging suggestions. Recommended update.

= 0.2.1 =
New Logs panel, diagnostic tools, improved scheduling, and fixes for HTML output. Recommended update.

= 0.2.0 =
Major update: Adds ImageMagick CLI support with auto-detection, improved admin interface, and enhanced engine selection options. Recommended update.

= 0.1.8 =
Performance optimization for Imagick-based conversions: reduces memory usage and I/O operations while maintaining all quality features. Recommended update.

= 0.1.7 =
Fixes color desaturation for AdobeRGB JPEGs converted to AVIF by preserving ICC correctly and avoiding conflicting nclx. Recommended update.

= 0.1.6 =
More accurate color handling: preserves ICC for tagged images, avoids sRGB-desaturation of AdobeRGB/P3, and normalizes EXIF Orientation. Adds Status warning for GD color limits. Recommended update.

= 0.1.5 =
New Tools action to delete AVIF files; improved progress UI and more accurate Status counts. Recommended update.

= 0.1.4 =
New encoder controls; improved defaults. Recommended update.

= 0.1.3 =
Cleanup and maintenance. No behavior changes.

= 0.1.2 =
Security and compatibility improvements; recommended update.

= 0.1.1 =
Accessibility and scheduling improvements; recommended update.

= 0.1.0 =
Initial release of AVIF Local Support.
