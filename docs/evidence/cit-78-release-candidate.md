# CIT-78 Free 1.4.1 release candidate

Prepared on 2026-09-29 (Asia/Ho_Chi_Minh) from the admitted protected `main@5db0b0a8d630d044e2e7bea2af72b4a0ffc4c034`. The candidate targets `main` and release version `1.4.1`. The exact committed head SHA, PR, exact-head CI run IDs and SHA-bound independent Technical Review are recorded in GitHub PR evidence after the commit; this tracked file cannot contain its own commit SHA without changing the candidate.

## Release identity and public copy

| Identity | Exact staged value |
| --- | --- |
| Plugin header `Version` | `1.4.1` |
| `YOOHW_COS_VERSION` | `1.4.1` |
| Readme `Stable tag` and latest Changelog heading | `1.4.1` |
| Changelog latest heading | `1.4.1 (Sep 29, 2026)` |
| `YOOHW_COS_DB_VERSION` | `0.2.4`, unchanged |

The readme and standalone changelog have the following new public notes. The existing 1.4.0 and older text below the new entries is byte-identical to the admitted base. The readme's neutral date line lets the existing publisher extract the release notes. The staged metadata has valid readme headings and links; the new notes contain no internal task or governance terms, new schema/delivery/AI capability, or publication claim.

```text
= 1.4.1 (Sep 29, 2026) =

* Corrected Reset Guard warnings so routine read and retryable sync contention no longer appears to be a lost customer-data operation. Interrupted Reset recovery and integration events that need manual replay now have clearer, separate notices; recovered notices can be dismissed without hiding a newer deferred operation.
* Refreshed existing CRM task emails with clearer status, context, and next actions for assignments, reassignment, due-soon, completion, reopening, overdue, escalation, and daily summaries. Messages retain WooCommerce email branding and settings, with matching HTML and plain-text content and Customer Message block-email compatibility.
```

The [WordPress.org public listing](https://wordpress.org/plugins/yoohw-customer-intelligence/) reported version `1.4.0` on 2026-09-29, replacing the admission-time `1.3.0` observation. The real direct public upgrade origin for this candidate is therefore the immutable repository tag `1.4.0@1ac1b8c4ab6e6109ea7746b204efc50caf3b5e1f`.

## Deterministic staged product

`bash scripts/stage-distribution.sh . <fresh-destination>` produced two independent 62-file payloads. Their file inventories and per-file SHA-256 values matched. The accepted `release_lib.deterministic_zip()` created byte-identical `yoohw-customer-intelligence-1.4.1.zip` packages.

| Artifact | SHA-256 or size |
| --- | --- |
| Product tree (`release_lib.tree_digest`) | `dd3423855d9bb67533facecaac011f2d2d7bf1c68bf64fa70b4cd173fc3317c7` |
| ZIP SHA-256 | `0551fdabd78bf41576cd7ae22cd1aba0e58b473d3db863d152f7ed6477cc846a` |
| ZIP size | 1,304,784 bytes |

Both payloads report `1.4.1`. The staging allowlist and forbidden-artifact checks found no `.git`, `.github`, tests, docs, scripts, Composer metadata, archives, logs, symlinks, or other development-only material. `changelog_notes()` extracted the dated 1.4.1 readme text.

## Verification

Local host: macOS arm64; PHP 8.4.26, Python 3.12.6, Node 26.8.1, MySQL 8.0.35, WP-CLI 2.9.0, Composer 2.8.8. Disposable fixtures used WordPress 6.9 and WooCommerce 10.8.0. Plugin Check 2.1.0 package SHA-256: `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`. No existing WordPress installation, shared database, real customer data, or production credential was used.

| Check | Result |
| --- | --- |
| `python3 .github/scripts/required-gate.py --self-test` | PASS, 2 tests |
| `python3 tests/release-contract-tests.py` | PASS, `release-contracts-ok` |
| Tracked PHP syntax on PHP 8.4, tracked JavaScript syntax, Python compilation | PASS |
| `composer install --no-plugins --no-scripts` on the existing lock | PASS |
| `python3 scripts/test-isolated.py --mysql-bin <MySQL-8-bin> --php <PHP-8.4> --inputs <checksum-verified-cache> --mode both` | PASS, 232 tests and 4,760 assertions in each HPOS mode; 68 guard rejection controls; both benchmark smokes; unchanged unrelated synthetic DB sentinel and owned-resource cleanup |
| Staged Plugin Check 2.1.0, `--mode=new --format=csv --ignore-warnings` | PASS, 0 errors |
| Staged Plugin Check 2.1.0, full scan | 0 errors, 874 warnings; no new warning category compared with the accepted 1.4.0 scan |

The full scan's 874 warnings are 32 above the accepted CIT-72 1.4.0 scan's 842. The changed counts are direct database query +6, no caching +6, nonprefixed template/global variable +19, and input not sanitized +1. Those findings occur in the already accepted post-1.4.0 CIT-74/CIT-76 runtime changes, not in this candidate's three metadata files. Direct reads/writes use the plugin's guarded custom tables; WooCommerce template variables retain their upstream names. The new input warning is the `notice_id` read during dismissal: capability and nonce are checked, and the submitted value is compared exactly against the current notice ID while holding its notice lock before deletion. The other 14 warning categories and their counts match the accepted scan. No new error or warning category, or material release blocker, was found in this exact staged payload. The full scan output is local diagnostic data; this document records counts without copying site paths or synthetic credentials.

On a fresh disposable site, the exact staged product activated and WordPress reported `1.4.1`; schema reached `0.2.4`. A synthetic completed USD order produced exactly one customer and one order fact. Reset Guard was ready, 9 existing CRM email classes registered, and the privacy exporters registered. The two-mode isolated suite additionally covers Customers/Profile, Saved Views/RFM, Diagnostics, privacy, currency, identity, HPOS/legacy sync, and representative email rendering and delivery semantics. This local UI check is a bounded API/render smoke, not browser visual QA.

On a separate disposable site, the real `1.4.0` tag activated first at schema `0.2.4`. A synthetic completed order, customer, order fact, note, order-linked task, tag, static segment, and both memberships were created before overlay. After replacement with the exact staged payload and reactivation, WordPress reported `1.4.1` with unchanged schema `0.2.4`; those records remained, with exactly one customer and one order fact. No Reset was run. The Reset Guard stayed ready; deliberate read/migration/order-sync contention did not create a false replay notice, an order retry was scheduled and completed, and a stale deferred banner retired. Nine CRM email classes remained registered.

The exact two-mode integration run includes the CIT-74 pending/malformed Reset and deferred-notice/race tests and the CIT-76 task-email state, WooCommerce `email_improvements` on/off, Customer Message HTML/plain/block, and recipient/transport tests. In particular, `test_reset_preserves_a_new_provider_deferral_during_recovery`, `test_deferred_notice_distinguishes_read_contention_and_recovery`, `test_legacy_deferred_notice_retires_only_after_ready_boundary`, `test_email_content_states_render_with_both_woocommerce_shell_modes`, `test_customer_message_html_plain_and_block_keep_authoritative_body`, and `test_revoked_recipient_never_reaches_transport` passed in both storage modes.

The checks use one local WordPress/WooCommerce/PHP combination plus the repository's PHP 7.4 CI syntax gate. They do not certify every declared compatible version or every browser path. Exact-head `YCI Required CI` and the fresh independent Technical Review are PR evidence, not historical local results.

Protected Prepare and Publish workflows were **not dispatched**. No `1.4.1` Git tag, GitHub Release, WordPress.org SVN publication, or production deployment was created. After exact-head CI, Technical Review, ChatGPT Acceptance Review and separate Human `Merge CIT-78`, re-fetch protected `main` and invoke **Prepare Customer Intelligence WordPress.org Release Candidate** with `candidate_sha=<exact merged protected-main SHA>` and `version=1.4.1`; require terminal `RC_PREPARED` before the publication dry-run.
