=== AVIF Local Support Extended ===
Contributors: ddegner, af1
Tags: avif, images, performance, media, optimization
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 0.6.1
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A practical fork of AVIF Local Support focused on real-world UX, logging clarity, and compatibility improvements.

== Description ==

**AVIF Local Support Extended** is a fork of the original **AVIF Local Support** plugin by **David Degner (ddegner)**.

Original project:
- https://github.com/ddegner/avif-local-support

Fork project:
- https://github.com/af1/avif-local-support

This fork exists to improve day-to-day operability on production sites: better log readability, clearer conversion context, and compatibility-focused behavior for mixed plugin/theme environments.

== Credit ==

This plugin is based on the original AVIF Local Support by:
- **David Degner (ddegner)**
- Site: https://www.daviddegner.com
- Original repository: https://github.com/ddegner/avif-local-support

All core AVIF conversion architecture and original plugin foundation are credited to the original author.

== Why This Fork Exists ==

The fork was created to solve practical issues discovered during real usage:

1. Better operational visibility
- Conversion logs were hard to scan quickly when processing many files.
- This fork improves log readability for faster troubleshooting and QA checks.

2. Clearer conversion context
- Thumbnail conversions can be confusing if source/target naming and size comparisons are not explicit.
- This fork improves message clarity around what was encoded and how savings are computed.

3. Safer compatibility behavior
- Real sites often combine multiple image/gallery/lazy-load systems.
- This fork prioritizes compatibility safeguards to avoid breaking front-end behavior while still improving AVIF delivery.

4. Workflow-friendly administration
- UX changes were made to reduce friction when reviewing results and identifying files that still need conversion.

== Main Changes In This Fork ==

- Plugin identity updated to **AVIF Local Support Extended**.
- Logging UX enhancements for easier scanning and troubleshooting.
- Clearer per-entry conversion metadata in logs.
- Better visibility into files without AVIF.
- Compatibility-oriented adjustments for gallery/lazy-load edge cases.
- Multiple admin/tooling refinements for production use.

== License ==

This fork remains licensed under the **GNU General Public License v2 or later (GPLv2+)**, same as the original project.

License details:
- https://www.gnu.org/licenses/gpl-2.0.html

== Installation ==

1. Upload this plugin to `/wp-content/plugins/avif-local-support`.
2. Activate it from WordPress admin.
3. Open plugin settings/tools to configure conversion behavior.

== Changelog ==

= 0.6.1 =
- Rebranded fork as AVIF Local Support Extended.
- Added fork-oriented documentation and attribution.
- Maintained GPLv2+ license and original credit.
