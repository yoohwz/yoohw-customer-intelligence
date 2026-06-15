# WordPress.org Release Checklist

Use this checklist before packaging YoOhw Customer Intelligence for WooCommerce for WordPress.org.

## Metadata

- `readme.txt` exists and follows WordPress.org formatting.
- Main plugin header includes `Requires at least`, `Tested up to`, `Requires PHP`, `Requires Plugins`, `WC requires at least`, `WC tested up to`, `License`, and `License URI`.
- `Stable tag` in `readme.txt` matches the release package version.
- `Contributors` contains the final WordPress.org username.
- Tags are limited to five and do not keyword-stuff competitors.

## Current Baseline

- Plugin version: `1.1.0`
- Stable tag: `1.1.0`
- Contributors: `yoohw`
- Requires at least: `6.9`
- Tested up to: `7.0`
- Requires PHP: `7.4`
- Requires Plugins: `woocommerce`
- WC requires at least: `8.2`
- WC tested up to: `10.8.1`
- License: `GPLv2 or later`
- Plugin Check error-only: passes with `No errors found`
- Full Plugin Check: `419` warnings, `0` errors; remaining warnings are triaged in `docs/wordpress-org-plugin-check-findings.md`
- Admin menu label: `Customers`
- Current DB version: `0.1.6`
- Expected custom tables: `8`

## RC Verification

- Date: `2026-06-11`
- Latest hardening refresh: `2026-06-10`
- Status: Release package rebuilt and error-only Plugin Check is green. Manual screenshots/fresh install QA should still be completed before final submission.
- Foundation Pass verification: `2026-06-08`
- PHP lint: pass on PHP `8.4.18` and PHP `8.2.29`
- JavaScript syntax check: pass for `assets/js/admin.js`
- Plugin Check error-only: pass with `No errors found`
- Plugin Check full run: `419` warnings, `0` errors
- Fresh table creation: pass using a temporary `$wpdb->prefix` smoke test; all eight Customer Intelligence tables were created and temporary tables were cleaned up.
- Existing install/upgrade path: pass for DB version `0.1.5`; stored DB version matches the constant and all eight expected tables exist.
- HPOS-enabled order sync: pass on the Local test site, with HPOS reported as enabled.
- Customer data smoke flow: pass for customer create/update, tags, static segments, notes, and WooCommerce order sync.
- Release ZIP: `/private/tmp/yoohw-customer-intelligence-1.1.0.zip`
- Release ZIP size: `112K`
- Release ZIP SHA-256: `8461d6cb24a4f1b4cb199797c2bcd91ebf8dff33d59c4082c191b5643b691a5b`

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

- Automated screenshot capture was not run in this Codex session because Playwright/browser automation is not installed in the workspace.
- Capture the seven screenshots manually from the URLs above, or install Playwright/browser automation and rerun this step.

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
- Upgrade install preserves existing Customer Intelligence data.
- WooCommerce inactive state shows a notice and does not fatal.
- HPOS-enabled order sync works.
- Reset wording matches actual reset behavior.
- Security review confirms write actions have capability checks, nonces, sanitization, escaping, and safe redirects.
- WordPress Plugin Check findings are resolved or explicitly triaged.
