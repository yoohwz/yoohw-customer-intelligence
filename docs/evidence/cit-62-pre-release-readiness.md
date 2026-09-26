# CIT-62 pre-release QA and audit readiness

Audit date: 2026-09-26 (Asia/Ho_Chi_Minh). Admitted and audited product baseline: protected `main@69471080e93af67175c74498894522eb64eb3c29` after CIT-60. This is a development-tree audit, not a 1.4.0 release candidate. The plugin header, `YOOHW_COS_VERSION`, and `readme.txt` Stable tag all remain `1.3.0`; the schema constant is `0.2.4`.

## Gate matrix

| Gate | Status | Evidence and limit |
| --- | --- | --- |
| Integrated isolated regression, HPOS=yes/no | PASS | 220 tests and 4,492 assertions per mode; both synthetic smoke benchmarks and runner safety controls passed. |
| Syntax, JavaScript, release contracts | PASS | Local PHP 8.4 lint, Node syntax, Python compile and gate self-tests passed. The baseline [main CI run](https://github.com/yoohwz/yoohw-customer-intelligence/actions/runs/36130013549) passed the PHP 7.4 syntax job and both integrations. |
| Exact evidence-PR `YCI Required CI` | NOT_RUN | Must be evaluated on the committed PR head; baseline main CI does not substitute for this check. |
| Fresh staged install and bounded smoke | PASS | A new disposable WP 6.9/WC 10.8.0 site activated the staged tree at DB version `0.2.4` with 12 tables; synthetic order sync, intelligence, note, task, tag, segment and Saved View flows passed. |
| WooCommerce inactive runtime | PASS | On a disposable site, WP-CLI activated the staged plugin while WooCommerce was inactive; a subsequent WordPress load completed without a fatal error and `WC_Order` remained absent. This is a bounded CLI dependency check, not an admin UI check. |
| Existing 1.3.0 overlay: bounded persisted fixture | PASS | A separate clean site activated tag `1.3.0` first; order/customer/link, note, task, tag/segment memberships survived staged overlay, and DB version converged `0.2.1` to `0.2.4`. |
| Broader existing-install Saved View, reset, privacy and suppression persistence | NOT_RUN | These states were not created on the historical fixture. The two-mode integration suite covers current-tree behavior, but is not an upgrade survival proof. |
| Final version-coupled 1.4.0 upgrade identity | DEFERRED_TO_RELEASE_CANDIDATE | No version bump is admitted in CIT-62. |
| Distribution contents and deterministic staging | PASS | Two independent staging runs each produced 62 files and identical sorted per-file SHA-256 manifests; the staged tree activated in disposable WP. |
| WordPress Plugin Check error-only | BLOCKED | 40 errors on the staged product; release-blocking follow-up [#63](https://github.com/yoohwz/yoohw-customer-intelligence/issues/63). |
| WordPress Plugin Check full warning triage | BLOCKED | 833 warnings, 414 more than the historical 419; material new categories require the #63 triage. |
| Admin visual and interaction smoke | NOT_RUN | No real browser session was executed against the disposable install. Source or PHPUnit HTML assertions are not visual evidence. |
| Release control plane structure | PASS | Read-only inspection found manual protected-main Prepare/Publish workflows, trusted staging, immutable candidate and Human-gated production path. Operational Environment configuration was not exercised. |
| Final release metadata/package/publication | DEFERRED_TO_RELEASE_CANDIDATE | Version, changelog, immutable Prepare, public package and publication require a later separately authorized task. |

## Runtime and automated validation

Local host: macOS arm64; PHP CLI 8.4.21 with `mysqli`, `mbstring`, XML and `posix`; Python 3.12.6; Node 24.14.1; MySQL 8.4.0 from Local's binary directory; WP-CLI 2.9.0; Plugin Check 2.1.0. The isolated runner used checksum-verified WordPress 6.9, WooCommerce 10.8.0 and WordPress development tests 6.9.0. No existing Local WordPress installation or DB was used by the runner or the manual fixture. `vendor/` was already present locally; baseline CI installed the locked Composer dependencies independently.

Commands and results:

```sh
git ls-files -z '*.php' | xargs -0 -n1 php -l
git ls-files -z '*.js' | xargs -0 -n1 node --check
python3 -m py_compile scripts/test-isolated.py .github/scripts/required-gate.py .github/scripts/release_cli.py .github/scripts/release_lib.py tests/release-contract-tests.py
python3 .github/scripts/required-gate.py --self-test
python3 tests/release-contract-tests.py
python3 scripts/test-isolated.py --mysql-bin '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0+2/bin/darwin-arm64/bin' --inputs /tmp/cit-62-cache --mode both
```

All syntax, gate and release-contract commands passed; `release-contracts-ok`. The runner reported 68 entrypoint rejection controls, live global and DB-scoped grant-option rejection, mail interception, 220 tests/4,492 assertions in each mode, both smoke benchmarks, unchanged unrelated synthetic DB sentinel, and owned-resource cleanup. Its first attempt without `--inputs` failed during dependency download because local Python could not validate the TLS certificate; `curl` fetched the three public archives into `/tmp/cit-62-cache`, and the runner verified their pinned SHA-256 values before use. The first attempt reached no test database. PHP 7.4 was unavailable locally; the exact admitted-main syntax job passed in the linked CI run, which also passed `YCI Required CI` on the baseline. This does not establish PHP 7.4 runtime support across the full compatibility matrix.

The two-mode integration tests exercise the merged currency fail-closed, Saved Views, attention/retention, RFM, Option A profile HTML hierarchy, privacy exporter/eraser and suppression, diagnostics, reset, and CIT-60 extension contracts. Their assertions support integrated current-tree behavior, not browser appearance or historical-data migration completeness.

## Fresh install and 1.3.0 overlay

A disposable `/tmp/yci62.*` WordPress installation used a newly initialized socket-only MySQL 8.4.0 data directory, WP 6.9 and WC 10.8.0. The trusted staged product was copied into its plugin directory. `wp plugin activate yoohw-customer-intelligence` succeeded without a fatal error, `wp option get yoohw_cos_db_version` returned `0.2.4`, and `SHOW TABLES LIKE 'wp_yoohw_cos_%'` returned 12 tables: customers, events, notes, tasks, tags, customer_tags, segments, customer_segments, customer_order_facts, notification_log, migration_issues and privacy_suppression. On a second fresh staged site, WP-CLI activated the staged plugin before WooCommerce; a subsequent `wp eval` loaded WordPress without a fatal error while `WC_Order` was absent. After deactivation and activation with WooCommerce, a synthetic order synced to a customer; plugin API calls created one note, task, tag and segment, assigned both relationships, created one Saved View, and calculated a risk score of 20. Reads returned one order, note, task, tag, segment, Saved View and order-fact/relationship row each. This proves a bounded fresh smoke, not all admin UI paths.

A **separate clean** `/tmp/yci62-upgrade.*` site began with repository tag `1.3.0`, before any development-tree activation. Its schema reported `db_version=0.2.1` and 11 Customer Intelligence tables. Plugin APIs then created a synthetic order and synced customer, note, order-linked task, tag and segment with both memberships. Pre-overlay order-fact and relationship tables each had one row. Only then did the fixture deactivate tag 1.3.0, replace the plugin directory with the staged development tree and reactivate. `db_version` became `0.2.4`; customer email and one order remained, `wc_get_order` found that order, and API reads returned one note, task, tag and segment. Order-fact and both relationship tables still had one row each. This is a bounded forward-overlay check. It does not prove Saved View persistence (not present in the 1.3.0 line), reset ownership, or privacy/suppression upgrade behavior. The runner's HPOS=yes/no tests verify both current storage paths, but the overlay fixture did not repeat the historical-data transition in both modes. These gaps remain unverified.

The disposable WP-CLI run emitted PHP 8.4 deprecations from WP-CLI's bundled libraries and a WooCommerce early textdomain notice. The audit did not establish a plugin-specific runtime warning from these messages. Its temporary MySQL process, site, DB and synthetic fixture were removed by the script's exit trap.

## Distribution and Plugin Check

The trusted `scripts/stage-distribution.sh` ran twice from the admitted main tree into fresh `/tmp` destinations:

```sh
bash scripts/stage-distribution.sh . /tmp/cit-62-stage-a
bash scripts/stage-distribution.sh . /tmp/cit-62-stage-b
(cd /tmp/cit-62-stage-a && find . -type f -print0 | sort -z | xargs -0 shasum -a 256) > /tmp/cit-62-stage-a.sha
(cd /tmp/cit-62-stage-b && find . -type f -print0 | sort -z | xargs -0 shasum -a 256) > /tmp/cit-62-stage-b.sha
diff -u /tmp/cit-62-stage-a.sha /tmp/cit-62-stage-b.sha
```

Each staging pass reported `distribution-ok files=62`. The sorted relative-path/SHA-256 manifests compared byte-for-byte and the manifest SHA-256 was `2aa2215c262a2ae2a3275100fe12cdb11d4c5456ced2583a715a841e8672bf6b`. The product contains the expected plugin entrypoint, readme, uninstall file, admin/includes/assets/templates/languages and changelog. No `.git`, `.github`, `tests`, `docs`, `scripts`, `AGENTS.md`, Composer metadata, cache, log, archive or symlink appeared. `.distignore` exclusions and the staging allowlist were honored. This is a tree-content comparison, not a deterministic release ZIP proof. The staged tree activated in the disposable site.

On that staged tree, `wp plugin check yoohw-customer-intelligence --slug=yoohw-customer-intelligence --mode=new --format=table --ignore-warnings` reported 40 `ERROR` rows, so error-only is **blocked**. Counts: `PreparedSQL.NotPrepared` 15; nonliteral i18n domain 12; missing translators comment 4; `DirectDB.UnescapedDBParameter` 4; unescaped output 3; nonliteral i18n text 2. Reports touched the attention, privacy erasure/exporter, install, customer profile and admin menu files. Scanner findings still need individual confirmation; no error was silently classified as harmless.

The full command without `--ignore-warnings` reported the same 40 errors and 833 warnings. Largest warning categories: nonce recommended 269, direct DB query 231, no caching 223, unprefixed globals 31, slow meta key 18, nonce missing 17, unprefixed hooks 11, and input sanitation/SQL subcategories. Historical `docs/wordpress-org-plugin-check-findings.md` recorded 0 errors and 419 warnings on 2026-06-11 for plugin 1.0.0, WP 7.0, WC 10.8.1 and PHP 8.4.18. The numeric delta is +40 errors/+414 warnings, but different product and scanner environments mean it is not a like-for-like regression count. Read-only filters and custom-table SQL explain some previously accepted warnings; the increased nonce, sanitation and SQL reports have not yet been accepted. [#63](https://github.com/yoohwz/yoohw-customer-intelligence/issues/63) owns the release-blocking investigation and correction outside CIT-62.

## Visual, control plane and limitations

`docs/admin-visual-smoke-checklist.md` and the approved Option A design were the visual baseline. No browser viewport, focus, console, overflow or escaped-output inspection was completed. Overview, Customers and Saved Views, Profile, Tasks, Tags, Segments, Activity, Settings/Sync/Diagnostics and privacy notices therefore remain `NOT_RUN` for visual and interaction QA. PHPUnit render assertions must not be reported as a visual pass.

Read-only inspection of `.github/workflows/release-prepare.yml`, `.github/workflows/publish-wordpress-org.yml`, `scripts/stage-distribution.sh` and `docs/releasing.md` found manual dispatch, protected-main/exact-SHA checks, trusted helper replacement, twice-staged payload, Plugin Check input, artifact identity, dry-run preflight, Human-gated production Environment, SVN recheck/atomic commit and public-package verification. `tests/release-contract-tests.py` passed. Later release prerequisites remain: resolve #63, finish unverified audit coverage, separately approve final version/changelog metadata, configure/verify Environment and credentials with the owner, and run the later immutable Prepare/publication process under separate authorization. No release workflow, tag, GitHub Release, SVN write, deployment or production mutation was dispatched here.

## Blockers and disposition

- **Release blocker:** staged Plugin Check error-only fails with 40 errors; [#63](https://github.com/yoohwz/yoohw-customer-intelligence/issues/63) is the bounded correction/triage task. The affected gates remain `BLOCKED` until fixed and freshly re-audited.
- **Unverified audit portions:** direct historical Saved View, reset/privacy/suppression survival and the admin visual checklist are `NOT_RUN`. A release candidate must not infer them from current-tree tests. Version-coupled 1.4.0 behavior is explicitly deferred.

**PRE_RELEASE_AUDIT_BLOCKED.** This is a durable readiness snapshot of the admitted post-CIT-60 development tree. It does not authorize a release or assert that 1.4.0 is already packaged or published.
