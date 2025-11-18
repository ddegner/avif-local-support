=== AVIF Local Support ===
Contributors: ddegner
Plugin URI: https://github.com/ddegner/avif-local-support
Tags: images, avif, performance, conversion, media
Requires at least: 6.5
Tested up to: 6.8
Stable tag: 0.2.1
Requires PHP: 8.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AVIF support for converting and serving high quality photos. Built by a photographer, not trying to sell subscriptions.

== Description ==

AVIF Local Support adds modern AVIF image support to WordPress while keeping compatibility with existing JPEG media.  Built by a [Boston photographer](https://www.daviddegner.com) who just wanted their website to look as good as possible.

**GitHub Repository:** [https://github.com/ddegner/avif-local-support](https://github.com/ddegner/avif-local-support)

- Local-first: all processing happens on your own server (works great on local environments like Local, MAMP, Docker) — no external calls.
- Image quality first: uses Imagick when available for high-quality resizing (LANCZOS), corrects EXIF orientation, and preserves ICC color profiles and EXIF/XMP/IPTC metadata when possible.
- Tunable quality and speed: set AVIF quality (0–100) and encoder speed (0–10) to balance fidelity and performance.

- Wraps existing `<img>` tags in `<picture>` with an AVIF `<source>` when an `.avif` version is present.
- Converts JPEG originals and generated sizes to AVIF on upload, or via a daily/background scan.
- Preserves EXIF/XMP/IPTC metadata and ICC color profiles by default (when using ImageMagick/Imagick).
- WordPress-aware resizing logic enabled by default to avoid double-resizing when generating AVIF sizes from originals.
 - New encoder controls: choose chroma subsampling (4:2:0, 4:2:2, 4:4:4) and bit depth (8/10/12-bit). Defaults: 4:2:0 and 8-bit.
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
On LiteSpeed/CyberPanel, the vhost sets a restrictive `open_basedir` (e.g., `/tmp:/home/<site>`). PHP’s `is_executable('/usr/local/bin/magick')` returns false under that restriction even when the binary exists and works from the shell. The plugin currently relies on `is_executable` and bails out instead of offering a fallback or a guided fix.

== Changelog ==
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
