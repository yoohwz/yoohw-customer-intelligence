# WordPress.org Release Checklist

Use this checklist before packaging YoOhw Customer Intelligence for WooCommerce for WordPress.org.

## Metadata

- `readme.txt` exists and follows WordPress.org formatting.
- Main plugin header includes `Requires at least`, `Tested up to`, `Requires PHP`, `Requires Plugins`, `WC requires at least`, `WC tested up to`, `License`, and `License URI`.
- `Stable tag` in `readme.txt` matches the release package version.
- `Contributors` contains the final WordPress.org username.
- Tags are limited to five and do not keyword-stuff competitors.

## Current Baseline

- Plugin version: `1.2.2`
- Stable tag: `1.2.2`
- Contributors: `yoohw`
- Requires at least: `6.9`
- Tested up to: `7.0`
- Requires PHP: `7.4`
- Requires Plugins: `woocommerce`
- WC requires at least: `8.2`
- WC tested up to: `10.8`
- License: `GPLv2 or later`
- Plugin Check error-only: passes with `No errors found`
- Full Plugin Check: `419` warnings, `0` errors; remaining warnings are triaged in `docs/wordpress-org-plugin-check-findings.md`
- Admin menu label: `Customers`
- Current DB version: `0.1.10`
- Expected custom tables: `8`

## RC Verification

- Date: `2026-07-30`
- Latest hardening refresh: `2026-07-30`
- Status: Version `1.2.2` package rebuilt, error-only Plugin Check is green, isolated fresh install/upgrade QA passes, and the seven release screenshots have been captured.
- Foundation Pass verification: `2026-06-08`
- PHP lint: pass on PHP `7.4.30` and PHP `8.4.18`
- JavaScript syntax check: pass for `assets/js/admin.js` and `assets/js/order-admin.js`
- Plugin Check error-only: pass with `No errors found` using the release-package exclusions.
- Plugin Check full run: `419` warnings, `0` errors
- Fresh table creation: pass in an isolated WordPress/database install using the `1.2.2` release ZIP; all eight customer tables were created, plugin version is `1.2.2`, and DB version is `0.1.10`.
- Existing install/upgrade path: pass from the public WordPress.org `1.2.1` tag to the `1.2.2` release ZIP; all eight expected tables exist and the seeded customer record was preserved.
- HPOS-enabled order sync: pass on the Local test site, with HPOS reported as enabled.
- Customer data smoke flow: pass for customer create/update, tags, static segments, notes, and WooCommerce order sync.
- Release ZIP: `/private/tmp/yoohw-customer-intelligence-1.2.2.zip`
- Release ZIP size: `202255 bytes`
- Release ZIP SHA-256: `242fa3e5bc26b8adf5de24d7fa5b406c9ffb543fa1cc9d33bb1e07cc60ba2d9e`

## Screenshots To Capture

- `screenshot-1.png`: Overview dashboard.
- `screenshot-2.png`: Customers list with filters, export, and intelligence columns.
- `screenshot-3.png`: Customer profile overview.
- `screenshot-4.png`: Tasks follow-up management.
- `screenshot-5.png`: Tags taxonomy-style admin screen.
- `screenshot-6.png`: Segments taxonomy-style admin screen.
- `screenshot-7.png`: Settings, Sync Center, diagnostics, and maintenance tools.

Local screenshot URLs:

- Customers: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence`
- Overview: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence-overview`
- Tasks: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence-tasks`
- Tags: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence-tags`
- Segments: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence-segments`
- Activity: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence-activity`
- Settings: `https://yoplay8.local/wp-admin/admin.php?page=yoohw-customer-intelligence-settings`

Screenshot status:

- Captured on `2026-07-30` from the Local WordPress admin site with an authenticated Chrome session.
- Files: `/Users/nguyenquocbao/.codex/visualizations/2026/07/29/019fad88-81b4-7db2-8e3d-9268104bcb86/yoohw-customer-intelligence-1.2.2/screenshot-1.png` through `screenshot-7.png`.
- Tasks layout check with customer `#241`: the customer selector remains inside the form column, the form and table columns do not overlap, and the page has no horizontal overflow.

## Package Exclusions

Exclude from the WordPress.org release package unless intentionally needed:

- `.DS_Store`
- `.git`
- `.agents`
- `.codex`
- `docs`
- `tests`
- local screenshots outside the WordPress.org assets folder
- temporary files
- test output files

## Pre-Submission Verification

- PHP lint passes for every plugin PHP file.
- JavaScript syntax check passes for `assets/js/admin.js`.
- Admin visual smoke checklist passes.
- Fresh install creates all eight custom tables.
- Upgrade install preserves existing customer data.
- WooCommerce inactive state shows a notice and does not fatal.
- HPOS-enabled order sync works.
- Reset wording matches actual reset behavior.
- Security review confirms write actions have capability checks, nonces, sanitization, escaping, and safe redirects.
- WordPress Plugin Check findings are resolved or explicitly triaged.
