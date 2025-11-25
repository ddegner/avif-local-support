## AVIF Local Support — Developer Guide (Cursor)

### What this plugin does
- **Goal**: Add AVIF support to WordPress while keeping JPEG compatibility.
- **Two parts**:
  - **Support**: On the front end, wrap JPEG `<img>` outputs in `<picture>` with an AVIF `<source>` when a neighboring `.avif` exists.
  - **Converter**: Create `.avif` files for JPEG originals and generated sizes during upload and/or via scheduled/background scans.

### How it works (high-level)
- **Bootstrap**: `avif-local-support.php` defines constants and initializes `Ddegner\AvifLocalSupport\Plugin` on `init`.
- **Support layer** (`includes/class-support.php`):
  - Hooks: `wp_get_attachment_image`, `the_content`, `post_thumbnail_html`, `render_block`.
  - Finds `.avif` neighbors for JPEG URLs that reside in the uploads directory; caches file existence in a transient.
  - Rewrites HTML by wrapping `<img>` in `<picture><source type="image/avif" ...></picture>`; avoids double-wrapping and respects existing `<picture>`.
  - Automatically rewrites parent anchor `<a>` links to point to AVIF versions (for lightbox compatibility).
- **Conversion layer** (`includes/class-converter.php`):
  - Hooks: `wp_generate_attachment_metadata`, `wp_update_attachment_metadata` to convert on upload and on edit/regeneration.
  - Scheduling: daily event and on-demand event; scans attachments and backfills missing `.avif` files.
  - **Engine priority**: CLI → Imagick → GD (v0.3.0+).
  - Deletion hooks: `delete_attachment` and `wp_delete_file` to remove companion `.avif` files when JPEGs are deleted.
  - WordPress-aware resizing: When converting a resized JPEG (e.g., `image-300x200.jpg`), sources from the original/`-scaled` file to avoid double-resizing and preserve quality.

### Admin UI (Settings → AVIF Local Support)
- Tabs:
  - **Settings**: Feature toggles, engine selection, and conversion configuration.
  - **Tools**: Convert missing actions, upload test, delete all AVIF companions, logs panel with refresh/clear/copy.
  - **About**: In-admin reference/readme.
- Assets: `assets/admin.css`, `assets/admin.js` (tab UX, AJAX actions, progress feedback).

### Settings (option keys → behavior)
- `aviflosu_enable_support` (bool, default: true): Enable front-end `<picture>` wrapping for JPEGs.
- `aviflosu_convert_on_upload` (bool, default: true): Generate AVIFs during upload/metadata generation.
- `aviflosu_convert_via_schedule` (bool, default: true): Daily scan to backfill missing AVIFs.
- `aviflosu_schedule_time` (string `HH:MM`, default: `01:00`): Local site time when the daily scan should run.
- `aviflosu_quality` (int 0–100, default: 85): AVIF quality.
- `aviflosu_speed` (int 0–8, default: 1): Encoder speed. Lower = smaller files, slower. Capped at 8 for libheif stability.
- `aviflosu_subsampling` (enum: `420`/`422`/`444`, default: `420`): Chroma subsampling (Imagick/CLI).
- `aviflosu_bit_depth` (enum: `8`/`10`/`12`, default: `8`): Bit depth (Imagick/CLI).
- `aviflosu_engine_mode` (enum: `auto`/`cli`, default: `auto`): Encoder path selection. Auto = CLI → Imagick → GD.
- `aviflosu_cli_path` (string, default: empty): ImageMagick CLI binary path when using `cli` or when set in `auto` mode.
- `aviflosu_cache_duration` (int seconds, default: 3600): Lifetime for file-existence cache used by Support.
- `aviflosu_disable_memory_check` (bool, default: false): Skip pre-conversion memory availability check (not recommended).

### File layout quick map
- `avif-local-support.php`: Plugin header, constants, activation/deactivation, init.
- `includes/class-avif-suite.php`: Main `Plugin` class; admin UI, settings registration, AJAX/form handlers, tools, logs.
- `includes/class-support.php`: Front-end HTML rewriting and `.avif` neighbor detection with transient cache.
- `includes/class-converter.php`: Conversion pipeline (upload hooks, scheduler, WP-CLI, quality/speed/engine logic, deletion sync).
- `assets/`: Admin JS/CSS for tabs, progress, and AJAX.
- `languages/`: `.pot`, `.po`, `.mo` files for i18n (text domain: `avif-local-support`).
- `uninstall.php`: Cleanup on plugin uninstall (removes options and transients).

### Common entry points while editing
- Initialization: `Ddegner\AvifLocalSupport\Plugin::init()`.
- Front-end wrap: `Ddegner\AvifLocalSupport\Support::wrapContentImages()` and `wrapAttachment()`.
- AVIF neighbor resolution: `Ddegner\AvifLocalSupport\Support::avifUrlFor()`; cache saved in `saveCache()`.
- Conversion on upload: `Ddegner\AvifLocalSupport\Converter::convertGeneratedOnUpload()`.
- Bulk scan: `Ddegner\AvifLocalSupport\Converter::convertAllJpegsIfMissingAvif()` (scheduled and on-demand).
- Tools/AJAX: `ajax_scan_missing()`, `ajax_convert_now()`, `ajax_delete_all_avifs()`, `ajax_get_logs()`, `ajax_clear_logs()`, `ajax_run_magick_test()`, `handle_upload_test_ajax()`.

### Local development notes (Cursor)
- No build step; assets are plain CSS/JS. Keep changes minimal and readable.
- When changing settings or UI strings, ensure translations use the `avif-local-support` text domain.
- Bump version in both `avif-local-support.php` header and `AVIFLOSU_VERSION` when shipping.
- After edits that impact front-end wrapping, test with posts that include:
  - Regular `<img>` tags in content and featured images.
  - Block editor `core/image` and `core/gallery` blocks.
  - Images already inside `<picture>` (should not double-wrap).
  - Images wrapped in `<a>` links (should update href to AVIF).

### Testing checklist
- Upload a JPEG and verify `.avif` neighbors are created for original and generated sizes when "Convert on upload" is enabled.
- Disable "Convert on upload", then use Tools → Convert now; confirm missing counts drop and AVIFs appear alongside JPEGs.
- Use Tools → Test conversion to preview conversions; verify AVIFs open in supported browsers.
- Verify front end shows `<picture>` with an AVIF `<source>` only when the `.avif` exists and the URL is within uploads.
- Test deletion: when a JPEG attachment is deleted, verify companion `.avif` files are also removed.
- Check logs panel for conversion status, engine used, duration, and error details.

### Scheduling and CLI
- Daily scan time: `aviflosu_schedule_time` (site timezone). The plugin adjusts events if time changes.
- Events: `aviflosu_daily_event` and `aviflosu_run_on_demand`.
- CLI (if WP-CLI present): `wp avif-local-support convert` runs the same bulk scan.

### Troubleshooting
- "AVIF support not available" notice: Ensure either Imagick supports AVIF (check `queryFormats('AVIF')`) or GD has `imageavif()`/AVIF support.
- File cache seems stale: It's stored in transient `aviflosu_file_cache` for `aviflosu_cache_duration` seconds. Clear via `wp transient delete aviflosu_file_cache`.
- Only JPEGs are processed: MIME must be `image/jpeg`/`image/jpg`, and filenames must end with `.jpg`/`.jpeg`.
- "High risk of memory exhaustion" error: Switch to CLI engine or increase PHP `memory_limit`. Can disable memory check in settings (not recommended).
- ImageMagick CLI not detected on LiteSpeed/CyberPanel: `open_basedir` may prevent `is_executable()` from working. Manually set the CLI path in settings.
- Silent CLI failures: Check logs for exit codes and output. Use "Run ImageMagick test" in Tools to diagnose.

### Security and WordPress.org Plugin Directory conventions
- Always follow sanitize → validate → escape principles and escape late on output.
- Inputs: sanitize immediately using the appropriate functions (e.g., `sanitize_text_field`, `absint`, `rest_sanitize_boolean`, `sanitize_key`, `sanitize_email`). For nonces from `$_POST`/`$_GET`, use `sanitize_text_field( wp_unslash( ... ) )` before `wp_verify_nonce`.
- Outputs: escape using context-appropriate functions (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). Prefer `esc_html__`, `esc_attr__`, `esc_html_e`, etc., when translating.
- Settings: register all options via the Settings API with explicit `type`, `default`, and `sanitize_callback`.
- AJAX/admin-post: check capabilities, verify nonces, sanitize all request data, and `wp_send_json_success/wp_send_json_error` with `wp_json_encode` as needed.
- Files/paths: never trust user-provided paths; validate against uploads and use WordPress helpers.
- Reference: WordPress.org Plugin Directory "Common issues" guidance on sanitizing and escaping.

### Coding standards
- PHP 8+; strict types enabled. Keep code readable, use early returns, and avoid deep nesting.
- Prefer explicit types and meaningful names. Match existing formatting.

### Release notes
- Update `Stable tag` and changelog in `readme.txt` as needed.
- Ensure i18n strings are extracted into `languages/avif-local-support.pot` if UI strings changed.

### Conversion flow and image quality focus
- Triggers
  - On upload via WordPress hooks: `wp_generate_attachment_metadata`, `wp_update_attachment_metadata` (controlled by `aviflosu_convert_on_upload`).
  - Scheduled daily and on-demand jobs: `aviflosu_daily_event` and `aviflosu_run_on_demand` (controlled by `aviflosu_convert_via_schedule` and `aviflosu_schedule_time`).
  - Optional CLI: `wp avif-local-support convert`.

- Per-file pipeline (JPEG-only)
  - `checkMissingAvif(path)`: skip if not JPEG or already has a valid neighboring `.avif` (>512 bytes).
  - `convertToAvif(sourcePath, avifPath, targetDimensions)` executes the selected engine with quality-first settings.

- Engine priority (v0.3.0+)
  - **CLI**: If `engine_mode` is `cli`, or `auto` with `cli_path` set, attempt CLI first.
  - **Imagick**: If CLI unavailable/fails and Imagick has AVIF support, use Imagick.
  - **GD**: Fallback only if Imagick unavailable (not on Imagick failure in auto mode).

- Settings-driven parameters
  - Quality: from `aviflosu_quality` (0–100, default 85).
  - Speed: from `aviflosu_speed` (0–8, default 1). Capped at 8 for libheif stability.
  - Subsampling/bit depth: from `aviflosu_subsampling` and `aviflosu_bit_depth` (Imagick/CLI).

- CLI path (when enabled)
  - Auto-orient; crop/resize to exact target; quality/speed/subsampling defines; colorspace sRGB.
  - Retries on transient delegate errors; validates output file size.

- Imagick path (preferred quality)
  - Auto-orient; optional ICC handling; LANCZOS resize; set `avif:speed` and compression quality.
  - Preserves ICC profiles; adds sRGB nclx when no ICC present.
  - Validates output file (>512 bytes) to catch silent failures.

- GD fallback
  - Apply EXIF orientation; center-crop + resample; encode via `imageavif()` if available.

- Quality-first defaults and safeguards
  - Defaults favor fidelity: `quality=85`, `speed=1`.
  - Prefer the original/`-scaled` source for derived sizes.
  - Normalize orientation before any crop/resize.
  - Pre-conversion memory check (can be disabled).

### Logs
- Conversion attempts are logged to a transient (`aviflosu_logs`) with:
  - Timestamp, status (success/error/warning), message
  - Details: source/target files, engine used, duration, file sizes, settings used
  - Error suggestions for common issues (memory, delegates, permissions)
- Logs are visible in Tools tab; can refresh, copy, or clear.
- Limited to 50 most recent entries; expires after 24 hours.
