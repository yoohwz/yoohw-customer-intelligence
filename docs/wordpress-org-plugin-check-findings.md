# WordPress Plugin Check Findings

Date: 2026-06-11

Command:

```sh
wp plugin check yoohw-customer-intelligence --slug=yoohw-customer-intelligence --mode=new --format=table
```

Environment:

- WordPress: 7.0
- WooCommerce: 10.8.1
- PHP: 8.4.18
- Plugin: YoOhw Customer Intelligence for WooCommerce 1.0.0

## Current Result

Plugin Check runs successfully. After the release-readme refresh on 2026-06-11, the error-only run passes:

```sh
wp plugin check yoohw-customer-intelligence --slug=yoohw-customer-intelligence --mode=new --format=table --ignore-warnings
```

Result:

```text
Success: Checks complete. No errors found.
```

The full run without `--ignore-warnings` currently reports 419 warnings and 0 errors.

Warning breakdown:

- 198 `WordPress.Security.NonceVerification.Recommended`
- 108 `WordPress.DB.DirectDatabaseQuery.DirectQuery`
- 99 `WordPress.DB.DirectDatabaseQuery.NoCaching`
- 7 `WordPress.DB.DirectDatabaseQuery.SchemaChange`
- 2 `WordPress.Security.NonceVerification.Missing`
- 1 `PluginCheck.Security.DirectDB.UnescapedDBParameter`
- 1 `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber`
- 1 `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`
- 1 `WordPress.DB.SlowDBQuery.slow_db_query_meta_key`
- 1 `WordPress.DB.SlowDBQuery.slow_db_query_meta_value`

The remaining warnings are triaged as read-only admin filter nonce recommendations and expected custom-table database access/schema warnings. The release is still blocked by fresh install/upgrade package QA and screenshots/assets capture, not by Plugin Check errors.

## Fixed During Metadata Step

- Added WordPress.org `readme.txt`.
- Added plugin header metadata for WordPress, PHP, WooCommerce, GPL license, and WooCommerce dependency.
- Added `Requires Plugins: woocommerce`.
- Added `languages/` placeholder so the `Domain Path: /languages` header points to an existing directory.
- Replaced the hidden `languages/.gitkeep` placeholder with `languages/yoohw-customer-intelligence.pot`.
- Removed `.DS_Store` from the plugin tree.
- Escaped internal badge HTML output through `wp_kses_post()`.
- Escaped scalar count output in translated notices.
- Added translators comments for placeholder strings.
- Sanitized request-method checks through a shared helper.
- Reworked many custom table queries to use `$wpdb->prepare()` with `%i` identifier placeholders for customer table names.
- Moved preserved customer-list redirect filters out of the redirect helper and sanitizes them only after the verified bulk-action nonce.
- Reworked migration DDL statements to use `%i` placeholders for table identifiers.
- Replaced dynamic `ALTER TABLE` index fragments with a whitelist of known customer table indexes.
- Removed blank lines inside `dbDelta()` table definitions so activation/install does not emit WordPress core parse warnings on PHP 8.4.
- Reviewed dynamic list-table SQL builders and added scoped suppressions for Plugin Check sniff limitations where fragments are hardcoded/whitelisted and user values are still passed through placeholders.
- Replaced restricted `date()` usage with `gmdate()` in the date helper.
- Removed explicit `load_plugin_textdomain()` for the WordPress.org build because plugin translations are automatically loaded by WordPress under the plugin slug.
- Removed `.DS_Store` Finder metadata files from the plugin tree.
- Escaped readiness and recent-activity status pills through `wp_kses_post()`.
- Reworked task queries to avoid interpolated WHERE fragments while keeping prepared SQL for all branches.
- Reworked CSV export relationship queries to avoid interpolated relationship column fragments.
- Sanitized read-only request helper paths more explicitly and documented nonce handling for verified task POST helpers.

## Findings To Triage

### 1. Remaining Warnings: Read-Only Filter Nonces

Files commonly flagged:

- `admin/class-yoohw-cos-admin-menu.php`
- `admin/class-yoohw-cos-customer-profile.php`
- List table classes using `$_GET`/`$_REQUEST` for search and filter state.

Required action:

- Ensure every write action still has capability checks and nonce verification.
- Keep read-only filters aligned with WordPress list-table behavior instead of adding nonces to normal filter/search/pagination URLs.
- Continue sanitizing read-only `$_GET`/`$_REQUEST` values before use.

### 2. Remaining Warnings: Custom Table Direct DB And Schema Operations

Files flagged:

- `includes/class-yoohw-cos-install.php`
- Other custom table repositories may still produce direct database call or no-caching warnings.

Required action:

- Keep custom tables because they are required by the product architecture.
- Add short-lived object caching only where profiling shows a repeated read-heavy bottleneck.
- Keep schema operations inside install/migration code paths only.
- Keep `%i` identifier placeholders for custom table names.

## Recommended Fix Order

1. Keep Plugin Check error-only green during all Free v1.0 changes.
2. Complete manual admin smoke verification for Customers, Profile, Tags, Segments, Activity, Settings, sync, reset, and HPOS order sync.
3. Decide whether any read-heavy aggregate queries need object caching before v1.0, based on profiling rather than warnings alone.
4. Capture WordPress.org screenshots and package assets.
5. Build a clean release ZIP and test fresh install/upgrade before submission.
