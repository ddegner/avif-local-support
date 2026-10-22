# Regression tests

Run each suite in its own PHP process from the plugin directory. PHP 8.3+ is required. These scripts do not use PHPUnit or require additional Composer packages.

```sh
php tests/admin-regression.php
php tests/conversion-regression.php
php tests/lqip-regression.php
php tests/frontend-regression.php /path/to/wordpress
```

- Admin: settings, playground collision handling, quality-filter arguments, durable cursors, and cancellation with stale caches. No WordPress installation or image extension required.
- Conversion: companion deletion, crop-source selection, compact/invalid AVIF validation, engine memory guards, and filesystem cancellation. Real GD checks run when GD is available; remaining cases use encoder doubles.
- LQIP: requires GD with GIF/PNG support. Tests palette colors, forced CLI generation, queued cancellation, and deletion races.
- Frontend: loads the supplied WordPress installation's actual HTML API without connecting to its database. Tests responsive markup, CSS background preservation, ThumbHash hook registration, and explicit fade configuration without changing saved settings.

## ThumbHash browser regression

Install Playwright and its Chromium/WebKit browsers in a separate development environment, then run:

```sh
PLAYWRIGHT_MODULE=/path/to/node_modules/playwright \
LOOKAHEAD_FILE=/path/to/image-lookahead.js \
ARTIFACT_DIR=/path/to/test-results \
node tests/thumbhash-browser.cjs
```

The harness serves local fixtures and the plugin's actual inline decoder, using in-memory PHP settings. It does not connect to WordPress's database or change site settings. PHP with GD is required to generate the default image fixture; `PHP_BIN` can select the PHP executable. Optional `PHOTO_FILE` uses a local photograph for the visual recording. Set `LOOKAHEAD_FILE` to the existing 3,000-pixel loading-buffer script; the buffer check is explicitly skipped if it is omitted.

Coverage includes Chromium and WebKit at desktop and mobile sizes, delayed image loading and decoding, first-screen and high-priority exclusions, cached images, reduced motion, disabled fading, missing/invalid hashes, errors, responsive changes, and loading-buffer compatibility. Compare selected sources, image requests, dimensions, and layout across fade settings. Results and visual evidence are written to `ARTIFACT_DIR`.

After changing the decoder, rebuild the shipped inline asset before running tests:

```sh
npm exec --yes --package terser@5.51.2 -- terser assets/thumbhash-decoder.js \
  --compress --mangle --comments '/@license/' --output assets/thumbhash-decoder.min.js
```

## WordPress integration

Use a **separate disposable installation and database** with this plugin active, GD image support, WP-CLI, and an administrator with ID 1. This suite exercises destructive bulk tools, changes plugin options, and leaves fixtures for inspection. Never point it at an existing content database.

The database name must begin with `avif_review_`, and the environment variable must match its exact name:

```sh
AVIFLOSU_TEST_DATABASE=avif_review_example wp --path=/path/to/disposable/wordpress \
  eval-file /absolute/path/to/avif-local-support/tests/wordpress-integration.php --use-include
```

Coverage includes real WordPress JPEG-to-WebP metadata, REST deletion, playground uploads, three-argument quality callbacks, responsive `auto` sizes, LQIP cancellation, and access control. Remove the disposable database and uploads afterward.

Browser acceptance should also exercise a cropped core Gallery containing landscape and portrait JPEGs, captions, and enabled lightboxes. Compare image bounds against AVIF delivery disabled at desktop and mobile widths; verify AVIF `currentSrc`, hover/focus controls, open/next/close, and console errors.

The repository PHPCS configuration reports historical coding-standard violations. Run syntax checks and the regression suites in addition to PHPCS; a PHPCS failure alone does not identify a runtime regression. Development tests are excluded by `.distignore`.
