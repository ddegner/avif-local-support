# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AVIF Local Support is a WordPress plugin that converts JPEG images to AVIF format locally (no external APIs) and serves them via `<picture>` elements with JPEG fallback. It also generates ThumbHash-based LQIP (Low Quality Image Placeholders). Requires PHP 8.3+ and WordPress 6.8+.

## Commands

### Linting
```bash
# Run PHPCS (WordPress coding standards with PSR-4 exceptions)
vendor/bin/phpcs

# Fix auto-fixable issues
vendor/bin/phpcbf
```

### WP-CLI (requires WP-CLI in the WordPress environment)
```bash
wp avif status                    # System diagnostics
wp avif convert --all             # Bulk convert missing AVIFs
wp avif convert 123               # Convert single attachment
wp avif stats                     # Conversion statistics
wp avif logs --limit=20           # View conversion logs
wp avif delete --all --yes        # Delete all generated AVIFs
wp lqip generate --all            # Generate all LQIP placeholders
wp lqip stats                     # LQIP statistics
```

### i18n
```bash
wp i18n make-pot . languages/avif-local-support.pot  # Regenerate after UI string changes
```

### Release
- Bump version in both the plugin header and `AVIFLOSU_VERSION` constant in `avif-local-support.php`
- Update `Stable tag` and changelog in `README.md`
- Git tag triggers stable release → WordPress.org SVN deploy (via `deploy.yml`); `beta-*` tags are skipped
- Every push creates a beta prerelease ZIP on GitHub Releases (via `beta-build.yml`)

## Architecture

### Namespace & Autoloading
PSR-4 under `Ddegner\AvifLocalSupport\` mapped to `includes/`. A custom autoloader in the main plugin file also handles legacy class name mappings (`Plugin` → `class-avif-suite.php`, `Support` → `class-support.php`, `Converter` → `class-converter.php`).

### Two Core Layers

**Support layer** (`includes/class-support.php`): Front-end HTML rewriting. Hooks into `wp_get_attachment_image`, `the_content`, and `post_thumbnail_html`. Finds `.avif` neighbors for JPEG URLs in the uploads directory, wraps `<img>` in `<picture>` elements, rewrites parent `<a>` hrefs for lightbox compatibility. Caches file existence in transient `aviflosu_file_cache`.

**Conversion layer** (`includes/class-converter.php`): AVIF generation pipeline. Hooks into `wp_generate_attachment_metadata` and `wp_update_attachment_metadata` for on-upload conversion, plus scheduled/on-demand bulk scans. Sources from the original/`-scaled` file for derived sizes to avoid double-resizing. Deletion hooks clean up companion `.avif` files.

### Encoder Strategy (priority: CLI → Imagick → GD)
All encoders implement `Contracts\AvifEncoderInterface`:
- **`Encoders/CliEncoder.php`** — ImageMagick CLI binary (fastest, recommended). Handles dimension limits, retries on transient delegate errors.
- **`Encoders/ImagickEncoder.php`** — PHP Imagick extension. LANCZOS resizing, ICC profile handling, metadata preservation.
- **`Encoders/GdEncoder.php`** — GD library fallback. Limited metadata support.

### Admin Architecture
- **REST-first**: Admin UI uses REST API endpoints, not admin-ajax. All endpoints registered in `Admin/RestController.php` under `/aviflosu/v1/`.
- **Settings API**: All options registered in `Admin/Settings.php` with sanitize callbacks. Option prefix: `aviflosu_`.
- **Templates**: `templates/admin/` — tab-based UI (`tab-settings.php`, `tab-lqip.php`, `tab-tools.php`, `tab-about.php`).
- **Assets**: Plain CSS/JS in `assets/` — no build step. `admin.js` handles tab navigation, AJAX progress, settings playground.

### DTOs
- `DTO/AvifSettings.php` — Immutable settings container with `fromOptions()` factory
- `DTO/ConversionResult.php` — Conversion result wrapper (success/failure, message, details)

### Other Key Components
- `ThumbHash.php` — LQIP generation (30-byte hashes stored as post meta `_aviflosu_thumbhash`)
- `BackgroundImages.php` — Replaces CSS `background-image` JPEGs with AVIF via `image-set()` overrides
- `Logger.php` — Logs to transient `aviflosu_logs` (max 50 entries, 24h expiry)
- `Diagnostics.php` — System capability detection (encoder availability, PHP/WP versions)
- `CLI.php` / `LQIP_CLI.php` — WP-CLI command implementations

### Plugin Bootstrap Flow
`avif-local-support.php` → defines constants, loads ThumbHash lib, registers autoloader → `aviflosu_init()` on `init` hook → creates `Plugin` instance → `Plugin::init()` wires up all sub-components.

## Coding Standards

- PHP 8.3+ with `declare(strict_types=1)`. Use early returns, avoid deep nesting.
- PHPCS config: WordPress standards with PSR-4 filename/naming exceptions (see `phpcs.xml`). Short array syntax allowed.
- Text domain: `avif-local-support`. All user-facing strings must be translatable.
- Security: sanitize inputs immediately, escape outputs late (`esc_html__`, `esc_attr__`, `wp_kses_post`). Verify nonces on all form/REST handlers. Never trust user-provided file paths.
- All options registered via Settings API with explicit `type`, `default`, and `sanitize_callback`.

## Testing Workflow

No automated test suite. Manual testing checklist:
1. Upload JPEG with "Convert on upload" enabled → verify `.avif` created for original + sizes
2. Tools → Convert Now → verify missing counts drop
3. Front-end: verify `<picture>` elements appear only when `.avif` exists
4. Delete attachment → verify companion `.avif` files removed
5. Check logs panel for status, engine, duration, errors

## Dependencies

**Composer** (runtime): `srwiez/thumbhash` (LQIP), `symfony/process` (CLI execution)
**Composer** (dev): `wp-coding-standards/wpcs`, `php-stubs/wordpress-stubs`
**No npm/Node build** — assets are plain CSS/JS
