# CIT-102 Free 1.4.3 release-candidate evidence

Prepared 2026-10-04 (Asia/Ho_Chi_Minh). [CIT-102](https://github.com/yoohwz/yoohw-customer-intelligence/issues/102), Controlled, target `main`, branch `codex/cit-102-release-candidate`.

Original admitted protected main: `1e4dd12261f065394dd755cd7b3c367eb7379dd2`. Final implementation/review base: `e88e8fef80bb15e603e031a49ce1f458cf4ad55c`, the protected main after separately admitted [CIT-103 / PR104](https://github.com/yoohwz/yoohw-customer-intelligence/pull/104) was accepted and merged. The branch was fast-forwarded without discarding the bounded CIT-102 changes. CIT-103's serializer sink is inherited base content, not an additional runtime correction inside CIT-102. Governance is unchanged.

This record supersedes the provisional package hashes and 64-error certification blocker. All version-coupled checks below were repeated on the post-CIT-103 payload. Exact final candidate SHA, PR diff/base, native CI run ID and fresh Technical Review URL/SHA are bound by the owning PR's final evidence comment. Those self-referential post-push identities are deliberately not fabricated or embedded through a source-only handoff commit; Acceptance must read that exact-head PR evidence together with this record. This document alone does not grant Acceptance, merge or release authority.

## Identity and public metadata

| Field | Exact staged value |
| --- | --- |
| Plugin header / `YOOHW_COS_VERSION` / readme Stable tag | `1.4.3` |
| `YOOHW_COS_DB_VERSION` / installed schema | `0.2.4`, unchanged |
| Readme Changelog release inventory | exactly `1.4.3` |
| Dedicated Changelog release inventory | `1.4.3 (Oct 4, 2026)`, `1.4.2 (Oct 1, 2026)`, `1.4.1 (Sep 29, 2026)`, `1.4.0 (Sep 27, 2026)`, `1.3.0 (Aug 28, 2026)`, `1.2.2 (Jul 30, 2026)`, `1.2.1 (Jul 23, 2026)`, `1.2.0 (Jul 10, 2026)`, `1.1.2 (Jun 29, 2026)`, `1.1.1 (Jun 19, 2026)`, `1.1.0 (Jun 15, 2026)`, `1.0.0` |

The dedicated changelog retains its title and all prior release history byte for byte against the final base. Readme support metadata, links, other sections and legitimate historical references outside Changelog remain unchanged. The Human latest-only addendum takes precedence over the earlier multi-version readme instruction. A bounded release-contract check rejects multiple, wrong, absent and duplicate-section release inventories, while allowing historical headings outside Changelog. Protected release workflows are unchanged.

Public readme entry is `= 1.4.3 =`; full-history entry is `= 1.4.3 (Oct 4, 2026) =`. Both contain these six user-facing bullets:

```text
* Improved monetary availability messages to distinguish currency-data preparation, items needing attention, multiple currencies, and missing order currencies. Stale customer monetary data can recover safely in the background; recorded currencies are retained without exchange-rate conversion.
* Clarified automatic commerce-data update progress and Settings guidance. Admin notices can be dismissed per user for the current state; Settings and Diagnostics continue to show the authoritative state.
* Fixed Customers CSV downloads containing admin HTML before the CSV. Filtered and Saved View exports remain clean, parseable CSV.
* Fixed Lifecycle Lifetime value displaying escaped price markup. Recorded foreign-currency amounts remain readable, while KPI, RFM, and order prices retain their formatting.
* Corrected First-time customers to include exactly one recognized order. Repeat customers have two or more; zero-order profiles remain available outside purchase cohorts.
* Fixed HTML entities and broken task links in plain-text CRM emails. Task IDs remain query parameters, and the HTML email presentation is unchanged.
```

No Issue/governance vocabulary, unshipped schema/FX/Premium/AI feature or publication-success claim appears in these notes.

## Reproducible staged payload

Two fresh canonical `bash scripts/stage-distribution.sh . <destination>` invocations produced identical 65-file inventories and per-file SHA-256 hashes. Accepted `release_lib.deterministic_zip()` produced byte-identical ZIPs. Distribution filters exclude repository/test/dependency/evidence files; the inventory below contains product/runtime resources only.

| Artifact | Exact value |
| --- | --- |
| File count | 65 |
| Product tree SHA-256 | `1e17d9ade7586f692091e15fda8714ca255a070b3def32d4479fda61687357d8` |
| `yoohw-customer-intelligence-1.4.3.zip` SHA-256 | `f22bc07b3bad1d10f42aa18e1c2f9f1c961902c64f4e644c2d6ab050f4cf8873` |
| Real public 1.4.2 ZIP SHA-256 | `6480ace7fa5a40c40094e24a92398964129f4d54a18bf06460ec5dd4cae30124` |
| Plugin Check 2.1.0 ZIP SHA-256 | `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4` |
| WP-CLI 2.12.0 PHAR SHA-256 | `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c` |

Actual upgrade source: `https://downloads.wordpress.org/plugin/yoohw-customer-intelligence.1.4.2.zip`. The 63-file public payload was compared byte for byte with immutable annotated tag 1.4.2: tag object `ed5f96bc34605e80bedff7c7eb058bd339566486`, dereferenced commit `22cbb91c1ddd4a6c6723cd52c1f2b94485220ece`. Read-only remote inspection reconfirmed both identities after the CIT-103 merge. The comparison checkout was removed. No tag or public package was changed.

## Validation and tools

Tools: PHP 8.4.18, MySQL 8.0.35, WordPress 6.9, WooCommerce 10.8.0, WP-CLI 2.12.0, PHPUnit 9.6.36, Python 3.12.6, Node 26.8.1. Composer used the existing lock with `--no-plugins --no-scripts`; canonical WordPress/WooCommerce archives were checksum verified by the isolated runner. Minimum PHP 7.4 syntax is additionally required from exact-head native CI; local runtime coverage is PHP 8.4.

| Check | Result |
| --- | --- |
| Required-gate self-test | PASS, 2 tests |
| Release contracts | PASS, `release-contracts-ok` |
| Local tracked syntax | PASS, 77 PHP / 4 JavaScript / 9 Python files |
| Full canonical HPOS=yes | PASS, 292 tests / 6,539 assertions |
| Full canonical HPOS=no | PASS, 292 tests / 6,539 assertions |
| Entrypoint rejection / grant-option controls | PASS, 144 controls per topology |
| Both owned benchmark smokes / unrelated sentinel / cleanup | PASS |
| Public 1.4.2 ready upgrade, HPOS=yes | PASS |
| Public 1.4.2 preparing upgrade, HPOS=yes | PASS; bounded worker converged to ready |
| Public 1.4.2 customer-only stale upgrade, HPOS=no | PASS; no Reset/manual Sync |
| Public 1.4.2 handled operational notice upgrade, HPOS=no | PASS; no ghost unresolved operation |
| Public 1.4.2 unresolved ledger / pending Reset upgrade, HPOS=yes | PASS; obligations retained fail closed |
| Fresh exact ZIP install, HPOS=yes | PASS, activation/schema/API/native admin/transport |
| Plugin Check 2.1.0 default checks | PASS, 0 ERROR / 907 WARNING; dispositions below |
| Browser-emitted five asset URLs | PASS, `ver=1.4.3`, HTTP 200 and exact staged bytes |
| Native filtered / Saved View CSV | PASS, 200 / correct headers / BOM byte zero / strict parse |
| Native AJAX per-user notice dismissal | PASS, persisted exact revision; readiness unchanged preparing |
| Worker after browser smoke | PASS, preparing converged to ready |

Full-suite command:

```text
python3 scripts/test-isolated.py --mysql-bin <owned-provisioner MySQL 8 bin> --php <PHP 8.4 executable> --inputs <checksum-verified cache> --mode both
```

The exact source suite retains the focused CIT-88 availability/reason/recovery/probe/freshness/notice-revision contracts; CIT-92 native wp-admin CSV headers/BOM/strict parsing/nonce/capability/stale Saved View/monetary/Reset rejection; CIT-93 current/foreign Lifetime plain copy and narrow HTML trust boundary; CIT-94 0/1/2/>2 cohorts, Saved View reopen/export and count/list/pagination parity; CIT-95 all eight task email states, public plain renderer, parsed task query/empty fragment, apostrophes/entities/unsafe scheme/CRLF rejection and HTML action parity. CIT-103 adds numeric entities/uppercase percent CRLF and scanner-compatible sink coverage. Identity, HPOS/legacy, privacy/suppression, Reset, migrations, Diagnostics, RFM, Saved Views and delivery remain represented. Full CIT-100 shared Local recheck was not repeated; no version-coupled discrepancy requiring it appeared.

## Upgrade and fresh-install state

Each upgrade actually activated public 1.4.2 before seeding a completed VND 125000 WooCommerce order/customer/fact, note, order-linked task, tag, static segment, both memberships, per-user Saved View and settings. A second synthetic identity was erased through the native privacy API to create a real one-way suppression receipt. A valid Reset epoch was established before creating CRM state.

Before deactivation/overlay and immediately after 1.4.3 reactivation, exact sorted full rows of ten owned tables (`customers`, `order_facts`, `notes`, `tasks`, `tags`, `customer_tags`, `segments`, `customer_segments`, `privacy_suppression`, `migration_issues`), scoring/email settings, privacy secret, Reset boundary, operational-incidents option and Saved View user meta were equal. Version/schema readiness assertions required 1.4.3 / 0.2.4. No duplicate profile/fact was created.

Preparing and customer-only-stale scenarios then converged to ready through at most 20 normal worker iterations. The handled scenario migrated a valid legacy notice under the public API and resolved it with the native capability/nonce handler before overlay; no operation reappeared afterwards. Its fixture reads persisted incidents directly from SQL to avoid process-cache assumptions. The unresolved scenario retained an unresolved currency issue and pending Reset epoch; readiness stayed attention, the ledger retained the issue, and ordinary Reset-guard entry was rejected. No force completion, destructive Reset or manual Sync ran.

The fresh site extracted the exact deterministic 1.4.3 ZIP into a clean plugin directory before native activation. Schema was ready with 12/12 tables. Representative order/customer sync, Overview, Customers, Profile Lifecycle/recent-order monetary formatting, Saved Views, RFM, privacy exporter/eraser registration and suppression lookup, Settings/Operational recovery, Diagnostics and all nine CRM email registrations with HTML/plain templates passed. The browser then deliberately exercised a preparing migration revision: normal WordPress login, Customers, Profile, HPOS order-edit and Settings; native JS AJAX dismissal hid only the current user's notice. A fresh process checked the exact persisted revision and authoritative preparing state, then normal worker iterations restored ready.

Filtered and Saved View export requests used native `wp-admin/admin.php`, a separate legitimate synthetic WordPress auth/session cookie pair and session-bound nonce, with no auth bypass. Both responses were HTTP 200, `text/csv; charset=utf-8`, attachment filenames, UTF-8 BOM at byte zero, 17 columns, exactly one expected fixture row and Python strict CSV parsing. No admin HTML prefix/body was present.

## Asset audit and native admin smoke

All five distributed CIT enqueues now use `YOOHW_COS_VERSION`; physical filenames, handles, dependencies, localization, hooks and page scoping are unchanged. Four filemtime-derived arguments and the notice script's old DB-version argument were replaced. No CIT enqueue remains filemtime-based.

| Screen | Native emitted product URLs (origin omitted) |
| --- | --- |
| Customers / Profile | `/wp-content/plugins/yoohw-customer-intelligence/assets/css/admin.css?ver=1.4.3`; `assets/js/admin.js?ver=1.4.3`; `assets/js/notice-preferences.js?ver=1.4.3` |
| HPOS order-edit | `/wp-content/plugins/yoohw-customer-intelligence/assets/css/order-admin.css?ver=1.4.3`; `assets/js/order-admin.js?ver=1.4.3`; `assets/js/notice-preferences.js?ver=1.4.3` |
| External dependency | WooCommerce `select2.css?ver=10.8.0` and `wc-enhanced-select.min.js?ver=10.8.0` |

Native browser DOM supplied these URLs; independent loopback requests fetched all five with status 200 and matched exact staged file SHA-256/bytes. Customers/Settings screenshots showed styled controls, active Saved View and dismissed notice; Profile rendered its task/user selectors and recent-order formatted VND amount; order-edit rendered the CIT customer/task panels and enhanced customer selector. The new integration regression uses native WordPress loaders to parse all five URLs, assert dependency version ownership, dependency arrays and unrelated-page exclusion in both HPOS modes.

The private loopback fixture blocks outbound WordPress HTTP/mail/cron and routes only admitted admin endpoints. WooCommerce background REST/recommendation requests to `/index.php` were consequently rejected by that fixture; browser console contained opaque `Object` errors and the order page displayed its recommendations-loading message. This is a recorded offline-fixture limitation, not a claim of zero browser console errors or full WooCommerce-network QA. No CIT asset failed the direct HTTP/hash checks; no PHP fatal/error appeared in the native HTTP server log. CLI WP-CLI reports include its pre-defined-ABSPATH advisory and dependency early-translation notices; these are not silently counted as product failures or proof of broad runtime compatibility.

## Plugin Check and warning disposition

Paired scans used actual public 1.4.2 and the exact staged 1.4.3 bytes in the same owned WP/WC topology, in fresh WP-CLI processes with accepted Plugin Check 2.1.0 and default checks:

```text
wp plugin check yoohw-customer-intelligence --mode=new --format=csv --ignore-warnings
wp plugin check yoohw-customer-intelligence --mode=new --format=csv
```

Public 1.4.2: **0 ERROR / 888 WARNING**. Candidate: **0 ERROR / 907 WARNING**. Error-only outputs report `Success: Checks complete. No errors found.`. Full records were parsed by file/type/code/message, excluding repeated per-file CSV headers; command exit zero alone was not treated as proof. No checks/error codes/paths were excluded and no scanner configuration was weakened. CIT-103's one audited plain serializer sink is the accepted inherited correction; no further annotation was added here.

| Warning code | Public 1.4.2 | Candidate | Delta |
| --- | ---: | ---: | ---: |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | 1 | +0 |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 244 | 251 | +7 |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 236 | 243 | +7 |
| `WordPress.DB.DirectDatabaseQuery.SchemaChange` | 1 | 1 | +0 |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 4 | 4 | +0 |
| `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | 4 | 4 | +0 |
| `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` | 1 | 1 | +0 |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` | 18 | 18 | +0 |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | 2 | 2 | +0 |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | 11 | 11 | +0 |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | 50 | 50 | +0 |
| `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 2 | 2 | +0 |
| `WordPress.Security.NonceVerification.Missing` | 17 | 17 | +0 |
| `WordPress.Security.NonceVerification.Recommended` | 278 | 281 | +3 |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 11 | 13 | +2 |
| `WordPress.Security.ValidatedSanitizedInput.InputNotValidated` | 3 | 3 | +0 |
| `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | 4 | 4 | +0 |
| `mismatched_plugin_name` | 1 | 1 | +0 |

The 19 additional warning records versus the public package are inherited accepted CIT-88 runtime paths: seven DirectQuery plus seven NoCaching records (six migration retry/issue read/write/count operations and one live site-readiness issue aggregate), three read-only `$_GET['page']` NonceVerification.Recommended records, and two notice key/revision InputNotSanitized records. The seven SQL operations use prepared identifier/value placeholders and read live persisted obligations; caching would require additional invalidation and could stale a readiness decision. The page selector is string-checked, unslashed and `sanitize_key`-normalized; it chooses notice display only, not a mutation. Dismissal requires `manage_woocommerce`, a logged-in current user and `check_ajax_referer`; key is an exact known descriptor and revision must match a 64-hex digest plus the current descriptor. Existing regressions cover forged users/nonces, stale/replayed revisions and safety-state independence. These records do not establish a new unguarded mutation or raw SQL input.

The remaining 888 records have the same warning-code counts as public 1.4.2 and are inherited before CIT-102: prepared direct database operations/uncached reads and slow-query diagnostics; custom prepared-SQL assembly diagnostics; nonce/input diagnostics on existing admin/query flows; fixed WordPress/WooCommerce interoperability hook/variable names; owned schema creation; diagnostic `error_log`; and `mismatched_plugin_name` (existing plugin branding versus slug-generated suggestion). The sole DirectDB `$where` warning is unchanged fixed-fragment/placeholder SQL assembly; its message's source line changed from 852 to 848 because CIT-102 removed four asset path lines, not because query behavior changed. It is not an additional warning. Existing nonce/capability/Reset/privacy/CSV rejection and integration controls remain green. No warning was reclassified as an error, suppressed or fixed opportunistically in this release-identity task. This comparison and focused source inspection identify no new material release blocker; independent Technical Review still owns verification of that disposition.

## Safety, limitations and release boundary

Every fixture used a newly owned private real temporary root, new socket-only MySQL with TCP/MySQL X disabled, random DB/user limited to that DB, exact dependency/config paths and ownership-token/grant checks before WordPress. Existing installed Local/production sites and credentials were not used. PHP mail was disabled, WordPress HTTP was blocked before plugin hooks, WP-Cron disabled, and only a token-guarded 127.0.0.1 development server served the synthetic admin browser workflow. Browser used normal WordPress authentication; test ownership admits the environment, never substitutes for authentication.

Both canonical suite topologies, five upgrade roots, the fresh browser root and paired scanner root confirmed unrelated synthetic database sentinels and terminated only their own processes/removed only their own site directories. The native post-browser process verified persisted dismissal and ready convergence before cleanup. Private transport/auth files contain synthetic data only and are not repository artifacts. Runtime certification is bounded to WP 6.9 / WC 10.8.0 / PHP 8.4.18 / MySQL 8.0.35, not all advertised combinations. Native CI supplies its separately provisioned runtime/minimum-syntax checks. No shared Local CIT-100 mutation was needed.

No Prepare/Publish workflow dispatch, tag, GitHub Release, WordPress.org SVN write, deployment or merge occurred inside CIT-102. Acceptance/merge/release are separate gates. After fresh exact-candidate Technical Review, native required CI, ChatGPT Acceptance and separate Human `Merge CIT-102`, re-fetch protected main and use **Prepare Customer Intelligence WordPress.org Release Candidate**, `version=1.4.3`, `candidate_sha=<exact merged current protected-main SHA>`. The Prepare SHA is not this branch SHA assumed in advance.

## Exact staged inventory

| Path | SHA-256 |
| --- | --- |
| `admin/class-yoohw-cos-activity-list.php` | `7c8182ba708d842bb3fcf13e48140d66d182cc7deae57c90204b324836fe2681` |
| `admin/class-yoohw-cos-admin-menu.php` | `59485aaf4c3e951044205b6692d76c08da07626ba1d32112ab8e1d189d83f9f8` |
| `admin/class-yoohw-cos-admin-tools.php` | `92b04fc67f1cbc40a424c697bc0adf2551aeaa042a83cacd635c0f7d0bd61aa1` |
| `admin/class-yoohw-cos-admin-ui.php` | `e3074a55ff288245babe129e8090c5a29599d9603a5d8182fc58915fa8849a81` |
| `admin/class-yoohw-cos-customer-exporter.php` | `34ebbb15ef60e58a9962830c650c3df486d097706959d30897486cb249805cca` |
| `admin/class-yoohw-cos-customer-profile.php` | `9701cf7615a51b0baff1590e02c40aa5c15d486814cea1eb3f6800a384e5a6c5` |
| `admin/class-yoohw-cos-customers-list.php` | `7c22b09a0227d98a624230ebefeeb55447ed450e5fe24bbc2ad26c703412705b` |
| `admin/class-yoohw-cos-flash-notices.php` | `6285794e0143410f70fa742bbfe04316745037bc38e70f982bb7feee1a1c8890` |
| `admin/class-yoohw-cos-notice-preferences.php` | `0e3e4ce91b7b16c07fd492178b03261e518e6731ca2955032fa7306eee171a9a` |
| `admin/class-yoohw-cos-order-admin.php` | `8037ecff1bf5dbd83f0b3977611880953c2dd306d4a628db1f917754663d38d0` |
| `admin/class-yoohw-cos-segments-list.php` | `bcf30e1d5a2cb1182beaf0b12598fd7ac22eac2c6ec887f2cc586dec07741503` |
| `admin/class-yoohw-cos-tags-list.php` | `b9f8e68a498fbd6e4e2812085750635f2f71eb076d10e00fd8a6725dea139070` |
| `admin/class-yoohw-cos-tasks-list.php` | `7592317f8b252dc629d3d9bdfe84a3e080e71a4d5beb372366a26178319ca740` |
| `assets/css/admin.css` | `491cc6dfeb8a09477bffa68652441fafb1139d41aed54038d6658e53367c9237` |
| `assets/css/order-admin.css` | `484427cc3bc4d8c62d090397567290f153a087ba179da931c1e2e4309b4b4244` |
| `assets/js/admin.js` | `917f9bdcfb6283e004c425b610f11a99ee6caa102b941228c096197ba54a7b07` |
| `assets/js/notice-preferences.js` | `97cdd58ce03a2c22a222c585c067777cd78ad37031ccd279d1943ae8405cec30` |
| `assets/js/order-admin.js` | `25c0d295e9e7f5b63e1dfcc945008399dbc2f092ef5b869b168073b4feff4f46` |
| `changelog.txt` | `c8cad74439318c5665385f0ae50b69ce0f927dc343104cc7899ff08312cd8f14` |
| `includes/class-yoohw-cos-attention.php` | `db76eb0009a64ecf493dbf79326e5be3dd4ac92f0eb408c735b5230aa4cb32a6` |
| `includes/class-yoohw-cos-blacklist-manager-integration.php` | `f2bea1d508579a6c2da3986c4d418042df7b0b0bf4d36bc6a9f3e3b50f7262f2` |
| `includes/class-yoohw-cos-blacklist-manager-premium-integration.php` | `e4dce4ebecfae428062365edac005d7fa6b0f95da5e7aa4c856c6e918b787170` |
| `includes/class-yoohw-cos-commerce-aggregates.php` | `a0b624148d02fb20165cec56159b559663bbfe2bd125c35922512c01fbfaa8d6` |
| `includes/class-yoohw-cos-commerce-metrics-policy.php` | `1f0bca17ccddf19efaadd16ca2c4cc3fe526ad25144c4e0ad3a7a42e87be7c08` |
| `includes/class-yoohw-cos-customer-facts.php` | `ee62653038a03b9cfd6e24bf4f42514bbdda033fb05cd4b537585e6e85a0114d` |
| `includes/class-yoohw-cos-customer-identity.php` | `fbe0c41806c9330975140c4c474ce879db4b496958c4ce97546df3112a5e6bc7` |
| `includes/class-yoohw-cos-customer-query.php` | `ec7329082f9ce16d5e0e668e55ea50f5d6d141f0f98ea1afcf94107aebd328ce` |
| `includes/class-yoohw-cos-customers.php` | `c4486dcaf3e3a1d6f872bbe00960af95cd6eec5189694f765345bb7195284744` |
| `includes/class-yoohw-cos-db.php` | `691f12e41607ff5fab5fba80f358b4ccda864e59015cd23d453877c76bf26103` |
| `includes/class-yoohw-cos-diagnostics.php` | `ebf3f180ef1bff7b8ac0ce9aa97b55384b1f1e6adc2f850940334476617d8e9f` |
| `includes/class-yoohw-cos-email-notifications.php` | `ea105a3c45c831c2c713aecc8cfedad1e9db3e9dc2054fe88585ea9c3b593866` |
| `includes/class-yoohw-cos-events.php` | `ecb8f1db766841b28ade8a38debf060ae78180bd8a993636f41dbfa84d5f45a2` |
| `includes/class-yoohw-cos-extensions.php` | `bc381b4f44e0ad085af3d9884261eb71bbd79b10fc4946d696d33f2be5e2df2d` |
| `includes/class-yoohw-cos-install.php` | `c604ffdd843e297239064da762d3ce6ae4848f8db9de95f1af16971eb10138f4` |
| `includes/class-yoohw-cos-integrations.php` | `5997fb2723cd1fc4ad0ac0c8e36f5cfa5ed90409c42600ae18f983a150557f5d` |
| `includes/class-yoohw-cos-intelligence.php` | `c1c849a9e2d50993bcf6b787fad61ad604a987e38e8af4b10d3ee66ca3490cfc` |
| `includes/class-yoohw-cos-loader.php` | `6966cb1a93e4ca2e936bccf052fb153ef44feec8b40a2b8cb4afe7e1e39e0113` |
| `includes/class-yoohw-cos-loyalty-integration.php` | `dec16c5b7e9e6b48c9e890ba891f1fb559c409039f12bd6a5fefaedd06c4f297` |
| `includes/class-yoohw-cos-migration-runner.php` | `fa313c2a3909c3a4aec813c4b9a6e701939e005b449d402cc7ad5b4368f25b4c` |
| `includes/class-yoohw-cos-notes.php` | `843b0979b0ad710d748ec2b3c84c5d1c922cc8780af291b8bcd609f5e9ec405b` |
| `includes/class-yoohw-cos-notification-ledger.php` | `67bc656d3fd9adef203dc4d2672f493d4e780ee33164d88f345b0f90d4bc2e50` |
| `includes/class-yoohw-cos-overview.php` | `35f09f3be515acc4f7746e7c5369e8a6f29a30f2db4b86e85bd70c2cb24fcd7c` |
| `includes/class-yoohw-cos-privacy-erasure.php` | `250e03377bbf37d8030f757a4536a7a2b7c9fe4cbcfe4b3b2cc5f57784b49a78` |
| `includes/class-yoohw-cos-privacy-exporter.php` | `b6ffe278f177042785f2ba8c77015ee58e1432e2c7c27151d51769309f742849` |
| `includes/class-yoohw-cos-reset-guard.php` | `4cbafbda65307dc1ed7e5b9a129aea64857c9cad4068fc9bff63608239519e11` |
| `includes/class-yoohw-cos-rfm.php` | `b048c48ba94b9b7d5ccd3c5536213d15654712e51672c0ac2e9d8c8ed52f2a5e` |
| `includes/class-yoohw-cos-saved-views.php` | `fbe18c7680842be5d176525915c23d04dc6683993b5584e60ccf4928c2e7176e` |
| `includes/class-yoohw-cos-segments.php` | `b15e33e473bbe7357c02454fdd7ff90ca63b5be21fd611135f33188c1cc74acb` |
| `includes/class-yoohw-cos-tags.php` | `16b5c1a62a9179dd08584b65320ca7ffefbbddbd8370186e7566eeff14d9a0d6` |
| `includes/class-yoohw-cos-tasks.php` | `8d79f65bdca2a49bc236adec69af14e7e6db1ce147b47707f374ba35edf3dad5` |
| `includes/emails/class-yoohw-cos-email-crm-base.php` | `a7f5e58a990c95a88643e77a4f0e16929edf82d0985248b23b1853be1cae3e9d` |
| `includes/emails/class-yoohw-cos-email-customer-message.php` | `1b1ea8da885469f8742dffbff6f822d79d3184495222e33058e368d3fbc106dc` |
| `includes/emails/class-yoohw-cos-email-task-digests.php` | `6792bbabb315f54c3e48de2dff37787cf3fd285e927b01f9e61e8098bf28d621` |
| `includes/emails/class-yoohw-cos-email-task-events.php` | `a8538e953478e495ad49410899bce13191e231658d7fa799484f57ed52ddf40c` |
| `languages/yoohw-customer-intelligence.pot` | `fc2f929159bd61c1ecc5297ac27d21c3c00455dd4745c5f84c0b3525671477c8` |
| `readme.txt` | `640d4a6bc826fbc30fdd3c03285bbb66627884bd03d1480538d59446cd151a4f` |
| `templates/emails/block/customer-message.php` | `50e1089ecf34ae9456fc1ae1f91d20fe04bd8f354bdbd66375c0f24c851c8041` |
| `templates/emails/crm-task-digest.php` | `9834ee3c7afee7b24a3a68a4769d3c8b142b7a69a2b77a5b02a7bc97e60604ff` |
| `templates/emails/crm-task-notification.php` | `b7428bbadf568907bdc840499e20339cd6908a3d816bd2471b1a41d334e417ab` |
| `templates/emails/customer-message.php` | `36d0dd95c92f774a59bc052dfae829970f57ffad91dacaffd0bfcd98bbdfad5f` |
| `templates/emails/plain/crm-task-digest.php` | `a0ec73200d0a4a2225d14b7b92e2af424302176fcdcc5998f27589c30420d87b` |
| `templates/emails/plain/crm-task-notification.php` | `4f9d775aa88ddbccb49ad6aca16f5db0d6e35edf036a539bdf164d739340555a` |
| `templates/emails/plain/customer-message.php` | `56ccddd3a70ec2ba313c032d35c784c337c7852651f4d381382a914a3ac074be` |
| `uninstall.php` | `6df2718455469dd9144ede5bb86e5f000978cede337e093b9e79e3736095749e` |
| `yoohw-customer-intelligence.php` | `a9fdd8e0c9ea442eb63d1809b966ac818ae909ddc375b58e872f06566e591da5` |
