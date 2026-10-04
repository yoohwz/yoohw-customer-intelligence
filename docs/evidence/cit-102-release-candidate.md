# CIT-102 Free 1.4.3 release-candidate evidence

Prepared 2026-10-04 (Asia/Ho_Chi_Minh). [CIT-102](https://github.com/yoohwz/yoohw-customer-intelligence/issues/102), Controlled, target `main`, branch `codex/cit-102-release-candidate`.

Original admitted protected main: `1e4dd12261f065394dd755cd7b3c367eb7379dd2`. Final implementation/review base: `e88e8fef80bb15e603e031a49ce1f458cf4ad55c`, the protected main after separately admitted [CIT-103 / PR104](https://github.com/yoohwz/yoohw-customer-intelligence/pull/104) was accepted and merged. The branch was fast-forwarded without discarding the bounded CIT-102 changes. CIT-103's serializer sink is inherited base content, not an additional runtime correction inside CIT-102. Governance is unchanged.

This record supersedes the provisional packages, the 64-error certification blocker and the earlier 0-error/907-warning candidate. The Human expanded scope to require zero warnings and to retain the readme name while matching the main-file Plugin Name; the durable [scope addendum](https://github.com/yoohwz/yoohw-customer-intelligence/issues/102#issuecomment-5978458994) owns these corrections. Earlier candidate reviews are invalid for this changed candidate. All staged-package checks below were repeated on the final zero-warning payload (R4). Exact final candidate SHA, PR diff/base, native CI run ID and fresh Technical Review URL/SHA are bound by the owning PR's final evidence comment. Those self-referential post-push identities are deliberately not fabricated or embedded through a source-only handoff commit; Acceptance must read that exact-head PR evidence together with this record. This document alone does not grant Acceptance, merge or release authority.

## Identity and public metadata

| Field | Exact staged value |
| --- | --- |
| Main-file Plugin Name / unchanged readme title | `Customer Intelligence for WooCommerce` |
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
| Product tree SHA-256 | `da4238e2fa53bcb814879ebd15305f4f22bad6a80a40594df680bd2ec2f60fe4` |
| `yoohw-customer-intelligence-1.4.3.zip` SHA-256 | `f7d0e49255c6e4854539b5b43446d43d1bb21a7b0e77635ff927bf1b75a158cf` |
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
| Full canonical HPOS=yes | PASS, 293 tests / 6,584 assertions |
| Full canonical HPOS=no | PASS, 293 tests / 6,584 assertions |
| Entrypoint rejection / grant-option controls | PASS, 144 controls per topology |
| Both owned benchmark smokes / unrelated sentinel / cleanup | PASS |
| Public 1.4.2 ready upgrade, HPOS=yes | PASS |
| Public 1.4.2 preparing upgrade, HPOS=yes | PASS; bounded worker converged to ready |
| Public 1.4.2 customer-only stale upgrade, HPOS=no | PASS; no Reset/manual Sync |
| Public 1.4.2 handled operational notice upgrade, HPOS=no | PASS; no ghost unresolved operation |
| Public 1.4.2 unresolved ledger / pending Reset upgrade, HPOS=yes | PASS; obligations retained fail closed |
| Fresh exact ZIP install, HPOS=yes | PASS, activation/schema/API/native admin/transport |
| Plugin Check 2.1.0 default checks | PASS, 0 ERROR / 0 WARNING; audited corrections below |
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

All five distributed CIT enqueues now use `YOOHW_COS_VERSION`; physical filenames, handles, dependencies, localization, hooks and page scoping are unchanged. All five filemtime-derived version arguments were replaced. No CIT enqueue remains filemtime-based.

| Screen | Native emitted product URLs (origin omitted) |
| --- | --- |
| Customers / Profile | `/wp-content/plugins/yoohw-customer-intelligence/assets/css/admin.css?ver=1.4.3`; `assets/js/admin.js?ver=1.4.3`; `assets/js/notice-preferences.js?ver=1.4.3` |
| HPOS order-edit | `/wp-content/plugins/yoohw-customer-intelligence/assets/css/order-admin.css?ver=1.4.3`; `assets/js/order-admin.js?ver=1.4.3`; `assets/js/notice-preferences.js?ver=1.4.3` |
| External dependency | WooCommerce `select2.css?ver=10.8.0` and `wc-enhanced-select.min.js?ver=10.8.0` |

Native browser DOM supplied these URLs; independent loopback requests fetched all five with status 200 and matched exact staged file SHA-256/bytes. Customers/Settings screenshots showed styled controls, active Saved View and dismissed notice; Profile rendered its task/user selectors and recent-order formatted VND amount; order-edit rendered the CIT customer/task panels and enhanced customer selector. The new integration regression uses native WordPress loaders to parse all five URLs, assert dependency version ownership, dependency arrays and unrelated-page exclusion in both HPOS modes.

An initial normal login redirected to `/wp-admin/`, outside the fixture router, and produced a browser error tab. A fresh tab with a normal login and explicit admitted redirect completed the UI smoke; the browser tool refused cleanup of the error tab because its generated data URL is outside its URL policy. The active successful fixture tab was closed and all owned server/DB/private credential resources were cleaned. This UI cleanup limitation does not weaken authentication or broaden the router.

The private loopback fixture blocks outbound WordPress HTTP/mail/cron and routes only admitted admin endpoints. WooCommerce background REST/recommendation requests to `/index.php` were consequently rejected by that fixture; browser console contained opaque `Object` errors and the order page displayed its recommendations-loading message. This is a recorded offline-fixture limitation, not a claim of zero browser console errors or full WooCommerce-network QA. No CIT asset failed the direct HTTP/hash checks; no PHP fatal/error appeared in the native HTTP server log. CLI WP-CLI reports include its pre-defined-ABSPATH advisory and dependency early-translation notices; these are not silently counted as product failures or proof of broad runtime compatibility.

## Plugin Check and warning disposition

Paired scans used actual public 1.4.2 and the exact staged 1.4.3 bytes in the same owned WP/WC topology, in fresh WP-CLI processes with accepted Plugin Check 2.1.0 and default checks:

```text
wp plugin check yoohw-customer-intelligence --mode=new --format=csv --ignore-warnings
wp plugin check yoohw-customer-intelligence --mode=new --format=csv
```

The final native scan of the exact R4 staged payload reports **0 ERROR / 0 WARNING** in both accepted invocations. Both outputs state `Success: Checks complete. No errors found.`; full CSV record parsing independently counted zero errors and warnings. No checks, paths or error codes were excluded and no scanner configuration was weakened.

The paired prior baseline is comparison evidence only: public 1.4.2 had 0 ERROR / 888 WARNING; the earlier post-CIT-103 candidate had 0 ERROR / 907 WARNING. Neither earlier result certifies the final package. The following counts explain the complete warning disposition:

| Warning code | Public 1.4.2 | Prior candidate | Final R4 |
| --- | ---: | ---: | ---: |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | 1 | 0 |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 244 | 251 | 0 |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 236 | 243 | 0 |
| `WordPress.DB.DirectDatabaseQuery.SchemaChange` | 1 | 1 | 0 |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 4 | 4 | 0 |
| `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | 4 | 4 | 0 |
| `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` | 1 | 1 | 0 |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` | 18 | 18 | 0 |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | 2 | 2 | 0 |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | 11 | 11 | 0 |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | 50 | 50 | 0 |
| `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 2 | 2 | 0 |
| `WordPress.Security.NonceVerification.Missing` | 17 | 17 | 0 |
| `WordPress.Security.NonceVerification.Recommended` | 278 | 281 | 0 |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 11 | 13 | 0 |
| `WordPress.Security.ValidatedSanitizedInput.InputNotValidated` | 3 | 3 | 0 |
| `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | 4 | 4 | 0 |
| `mismatched_plugin_name` | 1 | 1 | 0 |

The main-file Plugin Name now matches the unchanged readme title. Template-owned local variables use `yoohw_cos_` prefixes; required WooCommerce parameters and hooks retain their upstream names. These changes remove 51 warning records. The remaining 856 prior records are addressed by narrowly scoped, code-specific PHPCS annotations at approximately 515 source sites, with the reason attached to each operation. They are audited exceptions, not 856 claimed runtime defects fixed. Existing prepared-SQL exceptions were merged at the actual operation so the new annotations do not override their earlier safety explanation. No file-wide disable or blanket ignore was introduced.

DirectQuery/NoCaching exceptions cover custom CRM/integration tables without a WordPress object API and live privacy, Reset, migration, readiness and atomic transaction operations. Caching these reads without a separate invalidation design could stale persisted obligations. Prepared identifiers/values and fixed fragment maps remain unchanged. Dynamic placeholder counts come from generated `%d` lists or fixed categories and values are supplied to `prepare()`. Signal descriptor `meta_key` arrays are not WP_Query arguments; the two actual bounded upstream meta-query paths retain their existing lookup/list contracts. Owned-table uninstall SchemaChange and validated, PII-free operational `error_log` alerts retain their intended roles.

Nonce/input annotations identify read-only display/filter selection, sanitized filter POST redirects excluding mutation/export/Saved View actions, or the separate capability/nonce/Reset guard that admits order saves. Notice keys/revisions and Reset epochs retain exact validation instead of normalization that could alter replay checks. Bulk input is counted before bounded ID normalization; scoring schema and integration array accessors own their documented validation. Required WooCommerce interoperability hooks keep their fixed upstream names. The annotations do not add mutation authority or remove authentication, capability, nonce, escaping, preparation or Reset/privacy guards.

The R3 warning correction compared all 46 changed product PHP files against its predecessor and found identical executable tokens after reversing template-local prefixes. That historical parity check does not characterize the final R4 runtime correction described below. Supporting R3 component scans (plugin-review, direct database, DirectDB security, configured prefix and slow-query standards) reported 0 errors / 0 warnings. The two R4 admin files repeated plugin-review with 0 errors / 0 warnings, and the complete native R4 default scan above certifies the final product. A separate, undistributed unsafe PHP canary still triggers eight relevant diagnostic codes, including SQLNotPrepared, DirectQuery, NoCaching, nonce, output escaping and input validation/sanitization/unslash diagnostics. This verifies the rules remain active outside the exact local exceptions. The complete native scanner, full regression suite, staged upgrades and native fresh-install transport checks independently validate the final package; fresh Technical Review owns independent assessment of these exceptions.

## Technical Review correction: authenticated automatic continuation

Fresh independent Technical Review of candidate `53462b5996d592e4cfecb183bfa251bec6fcf489` recorded one [blocking P2 finding](https://github.com/yoohwz/yoohw-customer-intelligence/pull/105#issuecomment-5978658191): GET maintenance flags were incorrectly described as read-only even though they enabled JavaScript auto-submit with a newly rendered POST nonce. The POST handler nonce could not authenticate the GET that initiated the write. That candidate is superseded; its otherwise successful CI does not certify R4.

The bounded warning correction now authenticates the incoming continuation before enabling auto-submit for sync, intelligence recalculation, first-order backfill or Blacklist signals. Only capability/nonce-checked admin-post handlers mint the continuation nonce in their redirect. Its action is bound to operation and current Reset epoch; WordPress also binds the nonce to the user and login session. Settings requires `manage_woocommerce`, a ready Reset boundary and a valid incoming string nonce. Missing, malformed, expired, cross-operation, cross-user, cross-session or pre-Reset continuations do not auto-submit. Normal explicit buttons and the existing AJAX batch loop remain available; valid fallback redirects preserve automatic continuation. Each POST keeps its existing capability, nonce and Reset checks. No schema, caching or release-control change is involved.

The new integration regression exercises all four operation bindings with absent/array/forged/expired nonces, valid current nonces, distinct sessions of the same user, another administrator, another action and a changed Reset epoch. Native Settings rendering with crafted GET flags and fresh POST nonces emits zero auto-submit forms; valid recalculate/backfill continuation emits only its own auto-submit form. Native browser Settings independently confirms crafted query flags emit zero auto-submit forms despite intact POST nonce fields. The exact R4 package was rescanned and all five upgrades/fresh native CSV/AJAX/asset checks repeated after this correction. An exploratory earlier local suite was interrupted after the test fixture was finalized; its owned environment cleaned up and it is not PASS evidence. The final full-suite results above are from the completed final-source invocation.

## Safety, limitations and release boundary

Every fixture used a newly owned private real temporary root, new socket-only MySQL with TCP/MySQL X disabled, random DB/user limited to that DB, exact dependency/config paths and ownership-token/grant checks before WordPress. Existing installed Local/production sites and credentials were not used. PHP mail was disabled, WordPress HTTP was blocked before plugin hooks, WP-Cron disabled, and only a token-guarded 127.0.0.1 development server served the synthetic admin browser workflow. Browser used normal WordPress authentication; test ownership admits the environment, never substitutes for authentication.

Both canonical suite topologies, five upgrade roots, the fresh browser root and final scanner root confirmed unrelated synthetic database sentinels and terminated only their own processes/removed only their own site directories. The native post-browser process verified persisted dismissal and ready convergence before cleanup. Private transport/auth files contained synthetic data only, were removed after validation and are not repository artifacts. Runtime certification is bounded to WP 6.9 / WC 10.8.0 / PHP 8.4.18 / MySQL 8.0.35, not all advertised combinations. Native CI supplies its separately provisioned runtime/minimum-syntax checks. No shared Local CIT-100 mutation was needed.

No Prepare/Publish workflow dispatch, tag, GitHub Release, WordPress.org SVN write, deployment or merge occurred inside CIT-102. Acceptance/merge/release are separate gates. After fresh exact-candidate Technical Review, native required CI, ChatGPT Acceptance and separate Human `Merge CIT-102`, re-fetch protected main and use **Prepare Customer Intelligence WordPress.org Release Candidate**, `version=1.4.3`, `candidate_sha=<exact merged current protected-main SHA>`. The Prepare SHA is not this branch SHA assumed in advance.

## Exact staged inventory

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `admin/class-yoohw-cos-activity-list.php` | 19461 | `cbd30021c1143a66a9c899abbefad037dbe7a01bcfd2a9b7ecbc03a43c49d455` |
| `admin/class-yoohw-cos-admin-menu.php` | 200941 | `4f846da059cb5fa93f9fef90ed96f1f23d46195561c99f762e9f7a6cb47f2a89` |
| `admin/class-yoohw-cos-admin-tools.php` | 64136 | `0764ad84fd1d77772f0541db5b3ffe9e66266211599acae8e3b2615af8e01765` |
| `admin/class-yoohw-cos-admin-ui.php` | 4065 | `e3074a55ff288245babe129e8090c5a29599d9603a5d8182fc58915fa8849a81` |
| `admin/class-yoohw-cos-customer-exporter.php` | 11506 | `e09cfbbee463906e16184d35adc6eb475c10a8f21397cfe210e064cf413c4e07` |
| `admin/class-yoohw-cos-customer-profile.php` | 76685 | `b228b7b340baa19d997388f1689f89719cae5eacddbe4e4bda6d7809215d06da` |
| `admin/class-yoohw-cos-customers-list.php` | 46655 | `3d7fef1dca762595d5c2706da997050e83dec22bbc5e90f28dee4425b0de3a8e` |
| `admin/class-yoohw-cos-flash-notices.php` | 6046 | `5cb1b735a337eecca3db001dd218c096c13eaf3de45a0f959a4f8b6a95a78985` |
| `admin/class-yoohw-cos-notice-preferences.php` | 7196 | `d417e260dd27f006e88844d61753a1eab5f8933bd769d5f082cd92b460e13a5d` |
| `admin/class-yoohw-cos-order-admin.php` | 45916 | `a980edefa30d8899042b6cad691ca0c5b39a01cae9e5bf24e312607675733f58` |
| `admin/class-yoohw-cos-segments-list.php` | 6780 | `9a73c6d73bb0e46a14d28c6eeaa26c6102777436c03703335b18f34f2383e227` |
| `admin/class-yoohw-cos-tags-list.php` | 6800 | `b2303954c14b8df794a183275639f24b5694ed451442306418dfb1e8a6728cb2` |
| `admin/class-yoohw-cos-tasks-list.php` | 19173 | `087486ea6e741e3461e4a8e763b1b6ccbe39811078ade631af6bc328a77f30af` |
| `assets/css/admin.css` | 61038 | `491cc6dfeb8a09477bffa68652441fafb1139d41aed54038d6658e53367c9237` |
| `assets/css/order-admin.css` | 16921 | `484427cc3bc4d8c62d090397567290f153a087ba179da931c1e2e4309b4b4244` |
| `assets/js/admin.js` | 27742 | `917f9bdcfb6283e004c425b610f11a99ee6caa102b941228c096197ba54a7b07` |
| `assets/js/notice-preferences.js` | 542 | `97cdd58ce03a2c22a222c585c067777cd78ad37031ccd279d1943ae8405cec30` |
| `assets/js/order-admin.js` | 5802 | `25c0d295e9e7f5b63e1dfcc945008399dbc2f092ef5b869b168073b4feff4f46` |
| `changelog.txt` | 12006 | `c8cad74439318c5665385f0ae50b69ce0f927dc343104cc7899ff08312cd8f14` |
| `includes/class-yoohw-cos-attention.php` | 2655 | `db76eb0009a64ecf493dbf79326e5be3dd4ac92f0eb408c735b5230aa4cb32a6` |
| `includes/class-yoohw-cos-blacklist-manager-integration.php` | 28400 | `26ca4d15955295e0058596615ee527fbeeb19dc87c1b5366e728f7ccfd950642` |
| `includes/class-yoohw-cos-blacklist-manager-premium-integration.php` | 77573 | `90cdc5fdf8038877717b75ab8a35419214ccc199824fb267b884c14f4b4d78c9` |
| `includes/class-yoohw-cos-commerce-aggregates.php` | 20452 | `1da897f257b921e1f5c0ff47385657c027e3fa4c3c9e9975a05f9e4b1e547616` |
| `includes/class-yoohw-cos-commerce-metrics-policy.php` | 10651 | `5140f12f39e7a24902b7edb96c5408df1e52a35ff1565154446b8ce2aaae940e` |
| `includes/class-yoohw-cos-customer-facts.php` | 3411 | `ee62653038a03b9cfd6e24bf4f42514bbdda033fb05cd4b537585e6e85a0114d` |
| `includes/class-yoohw-cos-customer-identity.php` | 8252 | `52635ea4815e62cbc1af6af84c877abb75f922833478f47624ba0a79a7da3589` |
| `includes/class-yoohw-cos-customer-query.php` | 19134 | `664e382cee7d50e7b17c8d47ebd014e0943ac90334bc79b96bff0424a27bd463` |
| `includes/class-yoohw-cos-customers.php` | 62287 | `028f78e2075f6959d3e8071fcf47d035dd1dec4ebd32430ee6ff90d03c4b1c6c` |
| `includes/class-yoohw-cos-db.php` | 5855 | `71e0247e24ff6199a575cbc822d953e1de5fdfe6bf8f22ac3468c8b363f17be7` |
| `includes/class-yoohw-cos-diagnostics.php` | 14185 | `3c45c760c0d10e63a31943ff6f69d9190c931b7dbd8d26ed868838aab55b62a9` |
| `includes/class-yoohw-cos-email-notifications.php` | 36570 | `3868ac447b2b7aabc7e3e55c0f594302816541cc9e38f78fb76c2773232fccdf` |
| `includes/class-yoohw-cos-events.php` | 13963 | `f057de9e848475fcc02e3ac46c4e3b34fee70789a6874ff9f591b56e86db7c50` |
| `includes/class-yoohw-cos-extensions.php` | 8351 | `bc381b4f44e0ad085af3d9884261eb71bbd79b10fc4946d696d33f2be5e2df2d` |
| `includes/class-yoohw-cos-install.php` | 44151 | `6a351e4e39cacf01249a877c9ac2b6ef589b0678c5418aa07b927a07c041f202` |
| `includes/class-yoohw-cos-integrations.php` | 2793 | `5997fb2723cd1fc4ad0ac0c8e36f5cfa5ed90409c42600ae18f983a150557f5d` |
| `includes/class-yoohw-cos-intelligence.php` | 24875 | `0f0fe1ae99532142284f376af37ec08999daf6b77aacd12621275e608189defe` |
| `includes/class-yoohw-cos-loader.php` | 5975 | `6966cb1a93e4ca2e936bccf052fb153ef44feec8b40a2b8cb4afe7e1e39e0113` |
| `includes/class-yoohw-cos-loyalty-integration.php` | 41087 | `14f918ea660549ba7d80f169ce59d256e691991c8b7dfc2cc2bd721e89c3553c` |
| `includes/class-yoohw-cos-migration-runner.php` | 32287 | `7d177f0d810c1a4894ac13bfaf6fa4328757ad80434c9e4675621bbeb546742b` |
| `includes/class-yoohw-cos-notes.php` | 6435 | `a7d914c36acabda2f2e6ab777f5f16bf3f4bf132164436f7920533548c40aa3f` |
| `includes/class-yoohw-cos-notification-ledger.php` | 4955 | `a95645dcd6cad132aa774887f13da64b6b907a126b12962ec1d31be44bbf2a14` |
| `includes/class-yoohw-cos-overview.php` | 11423 | `0c27819be7ed3c599caa3bbc9d01180e89c96ea900be9e5e66e8f7d330e95dc6` |
| `includes/class-yoohw-cos-privacy-erasure.php` | 23736 | `596b8002046fb22b639abdb0abbf23c81f2551a755e8a7ac7123cbbefcb857ae` |
| `includes/class-yoohw-cos-privacy-exporter.php` | 22441 | `11021eeddfcf63c636aa2047ba72d1924d4fd6e186c775b97ab4f4b8ce5ae601` |
| `includes/class-yoohw-cos-reset-guard.php` | 24724 | `f67a96a5be91cc34ed5f77836fabcbe7f8bfecd400d6de5199a11b19194ce609` |
| `includes/class-yoohw-cos-rfm.php` | 1669 | `b048c48ba94b9b7d5ccd3c5536213d15654712e51672c0ac2e9d8c8ed52f2a5e` |
| `includes/class-yoohw-cos-saved-views.php` | 7366 | `7d15f3a538b2b835881a36314ca17fa05de8dd464094798f080478c5f9a612e2` |
| `includes/class-yoohw-cos-segments.php` | 10381 | `9dd7f2be56396fbd7d4fd2ff83624cc10f9ae39dea81d82a89c5eadf9859abba` |
| `includes/class-yoohw-cos-tags.php` | 9984 | `7ee7f3d5fe5c9eb60a16a1e68ee69e0ca0e2198f0dcfef146200f1a7a947a384` |
| `includes/class-yoohw-cos-tasks.php` | 28615 | `d3f6af63777704e01f973392edccd00f9dab27acbd718fc98e8439c78258f0ad` |
| `includes/emails/class-yoohw-cos-email-crm-base.php` | 14944 | `a7f5e58a990c95a88643e77a4f0e16929edf82d0985248b23b1853be1cae3e9d` |
| `includes/emails/class-yoohw-cos-email-customer-message.php` | 3608 | `1b1ea8da885469f8742dffbff6f822d79d3184495222e33058e368d3fbc106dc` |
| `includes/emails/class-yoohw-cos-email-task-digests.php` | 8455 | `6792bbabb315f54c3e48de2dff37787cf3fd285e927b01f9e61e8098bf28d621` |
| `includes/emails/class-yoohw-cos-email-task-events.php` | 8151 | `a8538e953478e495ad49410899bce13191e231658d7fa799484f57ed52ddf40c` |
| `languages/yoohw-customer-intelligence.pot` | 111807 | `fc2f929159bd61c1ecc5297ac27d21c3c00455dd4745c5f84c0b3525671477c8` |
| `readme.txt` | 9956 | `640d4a6bc826fbc30fdd3c03285bbb66627884bd03d1480538d59446cd151a4f` |
| `templates/emails/block/customer-message.php` | 1004 | `24bd819edf67c7e2b2f323add689ed66705023aad3ac22d59637916b042527e8` |
| `templates/emails/crm-task-digest.php` | 6870 | `f79150dc75e0ffb40952a7bf86041af35e763f75165af66406e35f59c75cb6b6` |
| `templates/emails/crm-task-notification.php` | 8658 | `8bce7e487244fb0a79bc3560aac88a9490e6d49b24d056c3a3ba6d1229c740f6` |
| `templates/emails/customer-message.php` | 2580 | `9f4e82d37c3ee67938618deab32767fb5310b142744ffefc5fabc1250362cde3` |
| `templates/emails/plain/crm-task-digest.php` | 4498 | `0b7b814c3adeccb2468591f2a5cafe15a51c5dc2fe8b2c17712fb2840425b284` |
| `templates/emails/plain/crm-task-notification.php` | 5066 | `850b80bf6523b9d06cb78de2f1a6224016fe26eb149616a1503eb21add5d66b1` |
| `templates/emails/plain/customer-message.php` | 973 | `a688775688a9acbe1b5886c1da266ab720a248c03a22eb787b32df70b7c4db3c` |
| `uninstall.php` | 1558 | `a7a1f55275e00f15e2d734d7138da35600485b8d931fc00be4e391caa20ca46b` |
| `yoohw-customer-intelligence.php` | 1630 | `44af8d998197a272fc34af2b9e06beced371f6a028009243dc4900cadb85961f` |
