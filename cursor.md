## AVIF Local Support — Developer Guide (Cursor)

### What this plugin does
- **Goal**: Add AVIF support to WordPress while keeping JPEG compatibility.
- **Two parts**:
  - **Support**: On the front end, wrap JPEG `<img>` outputs in `<picture>` with an AVIF `<source>` when a neighboring `.avif` exists.
  - **Converter**: Create `.avif` files for JPEG originals and generated sizes during upload and/or via scheduled/background scans.

### How it works (high-level)
- **Bootstrap**: `avif-local-support.php` defines constants and initializes `AVIFSuite\Plugin` on `init`.
- **Support layer** (`includes/class-support.php`):
  - Hooks: `wp_get_attachment_image`, `the_content`, `post_thumbnail_html`, `render_block`.
  - Finds `.avif` neighbors for JPEG URLs that reside in the uploads directory; caches file existence in a transient.
  - Rewrites HTML by wrapping `<img>` in `<picture><source type="image/avif" ...></picture>`; avoids double-wrapping and respects existing `<picture>`.
- **Conversion layer** (`includes/class-converter.php`):
  - Hooks: `wp_generate_attachment_metadata`, `wp_handle_upload` to convert on upload.
  - Scheduling: daily event and on-demand event; scans attachments and backfills missing `.avif` files.
  - Uses **Imagick** when available (preferred: auto-orientation, LANCZOS resize, optional ICC/EXIF/XMP/IPTC preservation). Falls back to **GD** with `imageavif()` if present, with manual EXIF orientation handling.
  - WordPress-aware resizing: When converting a resized JPEG (e.g., `image-300x200.jpg`), it can source from the original/`-scaled` file to avoid double-resizing and preserve quality.

### Admin UI (Settings → AVIF Local Support)
- Tabs:
  - **Settings**: Feature toggles and quality/speed configuration.
  - **Tools**: Convert-missing action and upload test to preview sizes and resulting AVIFs.
  - **Status**: Library counts (total JPEGs, existing AVIFs, missing AVIFs) and server capability table.
  - **About**: Renders `readme.txt`.
- Assets: `assets/admin.css`, `assets/admin.js` (simple tab UX, AJAX actions, progress feedback).

### Settings (option keys → behavior)
- `avif_local_support_enable_support` (bool, default: true): Enable front-end `<picture>` wrapping for JPEGs.
- `avif_local_support_convert_on_upload` (bool, default: true): Generate AVIFs during upload/metadata generation.
- `avif_local_support_convert_via_schedule` (bool, default: true): Daily scan to backfill missing AVIFs.
- `avif_local_support_schedule_time` (string `HH:MM`, default: `01:00`): Local site time when the daily scan should run.
- `avif_local_support_quality` (int 0–100, default: 85): AVIF quality.
- `avif_local_support_speed` (int 0–10, default: 1): Encoder speed. Lower = smaller files, slower.
- `avif_local_support_preserve_metadata` (bool, default: true): Preserve EXIF/XMP/IPTC where Imagick is available.
- `avif_local_support_preserve_color_profile` (bool, default: true): Preserve ICC profile where Imagick is available.
- `avif_local_support_wordpress_logic` (bool, default: true): Avoid double-resizing by sourcing from original/`-scaled`.
- `avif_local_support_cache_duration` (int seconds, default: 3600): Lifetime for file-existence cache used by Support.

### File layout quick map
- `avif-local-support.php`: Plugin header, constants, activation/deactivation/uninstall, init.
- `includes/class-avif-suite.php`: Main `Plugin` class; admin UI, settings registration, AJAX and form handlers, status.
- `includes/class-support.php`: Front-end HTML rewriting and `.avif` neighbor detection with transient cache.
- `includes/class-converter.php`: Conversion pipeline (upload hooks, scheduler, WP-CLI, quality/speed/metadata logic).
- `assets/`: Admin JS/CSS for tabs, progress, and AJAX.
- `languages/`: `.pot`, `.po`, `.mo` files for i18n (text domain: `avif-local-support`).

### Common entry points while editing
- Initialization: `AVIFSuite\Plugin::init()`.
- Front-end wrap: `AVIFSuite\Support::wrapContentImages()` and `wrapAttachment()`.
- AVIF neighbor resolution: `AVIFSuite\Support::avifUrlFor()`; cache saved in `saveCache()`.
- Conversion on upload: `AVIFSuite\Converter::convertGeneratedSizes()` and `convertOriginalOnUpload()`.
- Bulk scan: `AVIFSuite\Converter::convertAllJpegsIfMissingAvif()` (scheduled and on-demand).
- Admin actions: `handle_convert_now()`, `handle_upload_test()`, `ajax_scan_missing()`, `ajax_convert_now()`.

### Local development notes (Cursor)
- No build step; assets are plain CSS/JS. Keep changes minimal and readable.
- When changing settings or UI strings, ensure translations use the `avif-local-support` text domain.
- Bump version in both `avif-local-support.php` header and `AVIF_SUITE_VERSION` when shipping.
- After edits that impact front-end wrapping, test with posts that include:
  - Regular `<img>` tags in content and featured images.
  - Block editor `core/image` and `core/gallery` blocks.
  - Images already inside `<picture>` (should not double-wrap).

### Testing checklist
- Upload a JPEG and verify `.avif` neighbors are created for original and generated sizes when "Convert on upload" is enabled.
- Disable "Convert on upload", then use Tools → Convert now; watch Status counts decrease.
- Use Tools → Test conversion to see per-size paths, sizes, and view links; verify AVIFs open in supported browsers.
- Verify front end shows `<picture>` with an AVIF `<source>` only when the `.avif` exists and the URL is within uploads.
- Confirm server status table reflects Imagick/GD availability and AVIF support accurately.

### Scheduling and CLI
- Daily scan time: `avif_local_support_schedule_time` (site timezone). The plugin adjusts events if time changes.
- On-demand run: queued via admin Tool or AJAX; fires `avif_local_support_run_on_demand`.
- CLI (if WP-CLI present): `wp avif-local-support convert` runs the same bulk scan.

### Troubleshooting
- "AVIF support not available" notice: Ensure either Imagick supports AVIF (check `queryFormats('AVIF')`) or GD has `imageavif()`/AVIF support.
- File cache seems stale: It’s stored in transient `avif_local_support_file_cache` for `avif_local_support_cache_duration` seconds. You can clear via `wp transient delete avif_local_support_file_cache`.
- Only JPEGs are processed: MIME must be `image/jpeg`/`image/jpg`, and filenames must end with `.jpg`/`.jpeg`.

### Coding standards
- PHP 8+; strict types enabled. Keep code readable, use early returns, and avoid deep nesting.
- Prefer explicit types and meaningful names. Follow existing formatting.

### Release notes
- Update `Stable tag` and changelog in `readme.txt` as needed.
- Ensure i18n strings are extracted into `languages/avif-local-support.pot` if UI strings changed.


### Conversion flow and image quality focus
- Triggers
  - On upload via WordPress hooks: `wp_handle_upload` and `wp_generate_attachment_metadata` (controlled by `avif_local_support_convert_on_upload`).
  - Scheduled daily and on-demand jobs: `avif_local_support_daily_event` and `avif_local_support_run_on_demand` (controlled by `avif_local_support_convert_via_schedule` and `avif_local_support_schedule_time`).
  - Optional CLI: `wp avif-local-support convert`.

- Per-file pipeline (JPEG-only)
  - `checkMissingAvif(path)`: skip if not JPEG or already has a neighboring `.avif`.
  - `getConversionData(path)`: if the JPEG is a resized derivative like `image-300x200.jpg` and `avif_local_support_wordpress_logic` is true, use the original/`-scaled` file as the source and pass target width/height to avoid double-resizing.
  - `convertToAvif(sourcePath, avifPath, targetDimensions)` executes the encoder with quality-first settings.

- Settings-driven parameters
  - Quality: from `avif_local_support_quality` (0–100, default 85).
  - Speed: from `avif_local_support_speed` (0–10, default 1). Imagick speed is capped at 8; GD accepts speed on PHP ≥ 8.1.
  - Metadata: `avif_local_support_preserve_metadata` (bool) controls copying EXIF/XMP/IPTC when using Imagick.
  - Color profile: `avif_local_support_preserve_color_profile` (bool) controls copying ICC when using Imagick.
  - Source selection: `avif_local_support_wordpress_logic` (bool) toggles using original/`-scaled` to avoid double-resizing.

- Imagick path (preferred quality)
  - Auto-orient with `autoOrientImage()` and reset orientation to top-left.
  - If target dimensions are provided, center-crop to target aspect ratio, then resize with LANCZOS.
  - Encode AVIF with `setImageFormat('AVIF')`, `setImageCompressionQuality(quality)`, and `setOption('avif:speed', min(8, speed))`.
  - When enabled, copy ICC and EXIF/XMP/IPTC profiles from the source to preserve color and metadata.

- GD fallback
  - Load with `imagecreatefromjpeg()` and apply EXIF orientation manually if present.
  - If target dimensions are provided, perform center-crop plus `imagecopyresampled()` to target size.
  - Encode AVIF with `imageavif($gd, $avifPath, quality[, speed])` (speed supported on PHP ≥ 8.1).

- Quality-first defaults and safeguards
  - Defaults favor fidelity: `quality=85`, `speed=1` (slower = better compression efficiency and quality).
  - Use original/`-scaled` as the conversion source for resized JPEGs to prevent quality loss from double-resizing.
  - Normalize orientation before any crop/resize.
  - Use LANCZOS filtering (Imagick) and center-crop to preserve composition.
  - Optional ICC and EXIF/XMP/IPTC preservation keeps color accuracy and metadata when Imagick is available.


