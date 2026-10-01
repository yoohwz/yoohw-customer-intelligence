# CIT-84 Free 1.4.2 release candidate

Prepared on 2026-10-01 (Asia/Ho_Chi_Minh) from admitted protected `main@bdddac1780d37e81cb5c3d1b4b7d2f7a069f4c31`. Target: `main`, version `1.4.2`, Controlled. Exact committed head/base, PR, Actions run IDs and SHA-bound independent Technical Review are recorded in GitHub PR evidence after committing: embedding this file's own commit SHA would change the candidate.

## Identity and public metadata

| Identity | Exact staged value |
| --- | --- |
| Plugin header `Version` | `1.4.2` |
| `YOOHW_COS_VERSION` | `1.4.2` |
| Readme Stable tag and latest Changelog entry | `1.4.2` |
| Standalone latest Changelog entry | `1.4.2 (Oct 1, 2026)` |
| `YOOHW_COS_DB_VERSION` and installed schema | `0.2.4`, unchanged |

The readme's latest entry includes `Oct 1, 2026.` so the accepted publisher extracts the dated notes. All 1.4.1 and older history remains byte-identical to the admitted base. Apart from Stable tag and the new Changelog entry, the readme is unchanged. Its headers/links and WordPress.org formatting are preserved. Historical version references, including the translation catalog's generator metadata, remain historical. New public notes contain no task/control-plane jargon, schema change, Premium feature, automatic-recovery promise or publication claim.

```text
= 1.4.2 (Oct 1, 2026) =

* Replaced the generic deferred-operation warning with source-aware Operational recovery records and clearer guidance in Customer Intelligence Settings, including supported Blacklist and Loyalty recovery links. Blocking Reset states continue to prevent unsafe operations.
* Clarified the “Acknowledge after recovery” action: it acknowledges an incident after its recovery has been completed. Handled legacy notices stay dismissed, while new or concurrent unresolved incidents remain visible and distinct.
* Made redirect-result notices appear once on Customer Intelligence screens without consuming similarly named query parameters on unrelated admin screens. Improved notice severity consistency and prevented repeated order-admin Reset conflict messages from stacking.
```

## Deterministic staged product

Two fresh `bash scripts/stage-distribution.sh . <destination>` invocations produced matching 63-file inventories and per-file SHA-256 values. Both staged payloads identify as 1.4.2. The accepted `release_lib.deterministic_zip()` produced byte-identical `yoohw-customer-intelligence-1.4.2.zip` packages; `changelog_notes()` extracted the latest dated public text. Staging's allowlist, forbidden-path, local-artifact and symlink controls passed.

| Artifact | SHA-256 or size |
| --- | --- |
| Product tree (`release_lib.tree_digest`) | `97867c0c4cd330fb43f0854f058c86f8001ab09656eb982dcf69f929f51c4e3b` |
| ZIP SHA-256 | `663da5e014e005efa87e29cf0162f9abe21027d6adfb9fbed9b6d48d68dce68d` |
| ZIP size | 1,322,221 bytes |

## Verification

Local macOS arm64; PHP 8.4.26, Python 3.12.6, Node 26.8.1, Composer 2.8.8, PHPUnit 9.6.36, MySQL 8.0.35, WP-CLI 2.12.0. Fixtures use checksum-pinned WordPress 6.9 and WooCommerce 10.8.0. Plugin Check 2.1.0 archive SHA-256: `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`. No existing site, shared database, real customer data or production credential was used.

| Check | Result |
| --- | --- |
| `python3 .github/scripts/required-gate.py --self-test` | PASS, 2 tests |
| `python3 tests/release-contract-tests.py` | PASS, `release-contracts-ok` |
| Tracked PHP 8.4 syntax, tracked JavaScript syntax, Python compilation, `git diff --check` | PASS |
| `composer install --no-plugins --no-scripts` using the existing lock | PASS |
| `python3 scripts/test-isolated.py --mysql-bin <MySQL-8-bin> --php <PHP-8.4> --inputs <verified-cache> --mode both` | PASS: 256 tests / 5,004 assertions in each mode, 68 rejection controls, both benchmark smokes, unchanged unrelated synthetic sentinel and owned-resource cleanup |

The complete two-mode suite includes topic #950/stale acknowledgment, equivalent aggregation/distinct incident preservation, TTL/capacity/privacy, resolve/new-event race, old REPEATABLE READ protection, failed persistence, pending/malformed/stale Reset, retryable-work classification, Blacklist Core/Premium routing, Loyalty live callbacks, CIT flash consumption and negative unrelated-admin isolation, HPOS/legacy order-edit flash scoping, severity mapping and repeated 409 replacement. Existing privacy, currency, RFM, Saved Views, identity, migrations, Diagnostics and email tests also pass.

## Version-coupled disposable-site verification

The exact staged product activated on clean, separately provisioned sites in **both HPOS=yes and HPOS=no**. WordPress reported 1.4.2, schema inspection passed at 0.2.4, and one synthetic completed USD order produced one customer and one fact. Bounded rendering checked Customers, Profile, Saved Views, RFM, Diagnostics and Operational recovery; nine CRM emails registered and rendered HTML/plain content, and both privacy callbacks registered. No plugin fatal or database error appeared in these completed fixture runs.

The real upgrade origin was immutable annotated tag `1.4.1` (tag object `b6e81ed9c7cbfc772e6cff90e32492858a3099e7`, commit `0ac831c622b6ecf20323deb4bb88d0b23d2649db`). Its own canonical staging helper generated the prior 62-file product. In each HPOS mode, that actual 1.4.1 payload activated first, then created an order/customer/fact, note, order-linked task, tag, static segment, both memberships, custom scoring and CRM email settings, a ready Reset epoch, and a real one-way privacy suppression receipt for another synthetic subject. Full sorted rows of those nine owned tables plus the selected settings, suppression secret and Reset boundary were snapshotted before deactivation, overlay with exact staged 1.4.2 and reactivation. The snapshots remained identical; WordPress reported 1.4.2 and schema 0.2.4, with exactly one customer and one fact. No Reset was executed.

Each upgrade seeded both an active `yoohw_cos_reset_notice` and deferred transient. They migrated into explicit `customer_intelligence:legacy_deferred_unattributed` / `manual_replay_required` state before the old storage was retired. A real capability/nonce-checked acknowledgment executed in a separate request. Subsequent fresh-request Overview/Customers/Settings navigation stayed clear. A genuinely new `blacklist_premium:js_proof_failed` callback under controlled boundary contention remained visible as a distinct source-specific incident. Unrelated admin query flags remained unchanged when consumed through the actual `admin.php` route. Privacy suppression and both exporter/eraser registrations remained functional after upgrade.

All four sites used fresh random database/user credentials on a newly initialized, socket-only MySQL instance; accounts had grants restricted to their own database. Mail was intercepted before plugin hooks, native PHP mail disabled and WordPress HTTP blocked. A separate synthetic database sentinel remained unchanged; the owned server and every temporary site/config/database directory were removed on completion.

### Plugin Check

The accepted Plugin Check 2.1.0 flow scanned the exact staged payload with `wp plugin check yoohw-customer-intelligence --mode=new --format=csv --ignore-warnings`: **0 errors**. The full scan had **888 warnings**, versus **874** from the actual staged immutable 1.4.1 origin using the same scanner. No new warning category appeared. Differences: direct database query +2, no caching +2, recommended nonce verification +7, error_log +1 and input not sanitized +2. These findings are in the already merged CIT-80 runtime: fresh guarded boundary reads, route/flash token handling, exact incident-key matching after capability/nonce checks, and a source/event-only operational error log. They are absent from this candidate's metadata changes. The flash reads do not perform product mutations; incident acknowledgment verifies the current ID under the incident lock. No new material release blocker was identified.

CLI dependency activation emits WooCommerce's early-translation notice, and WP-CLI reports the acknowledgment endpoint's expected URL redirect. Neither is a product fatal or database failure. UI smoke is rendered HTML/API coverage, without an existing signed-in browser.

The reproducibility fixtures and diagnostic output remain outside the product checkout in the local verification directory; no fixture/config/credential is included in the distribution. The committed evidence records results; the exact GitHub CI and independent-review bindings belong in PR evidence.

## Limits and release boundary

Local UI checks are bounded API/rendered-HTML smokes, not browser visual QA. Runtime checks cover WordPress 6.9 / WooCommerce 10.8.0 / PHP 8.4; PHP 7.4 is the exact-head CI syntax gate. This does not certify every supported combination. Exact-head `YCI Required CI` and fresh Controlled Technical Review must pass before ChatGPT Acceptance.

Protected Prepare/Publish workflows were **not dispatched**. No 1.4.2 tag, GitHub Release, WordPress.org SVN write or production deployment was performed. After ChatGPT `Acceptance Review CIT-84` and separate Human `Merge CIT-84`, re-fetch protected main and invoke **Prepare Customer Intelligence WordPress.org Release Candidate** with `candidate_sha=<exact merged current protected-main SHA>` and `version=1.4.2`; require `RC_PREPARED` before publication dry-run. Existing release authorization remains subject to the protected Environment and recovery rules.
