# CIT-108 Free 1.4.4 release-candidate evidence

Prepared 2026-10-22 (Asia/Ho_Chi_Minh). [Issue #108](https://github.com/yoohwz/yoohw-customer-intelligence/issues/108), Controlled, [PR #109](https://github.com/yoohwz/yoohw-customer-intelligence/pull/109), branch `codex/cit-108-release-candidate`.

Admitted/current protected main and implementation base: `82941930566484b14e3fefb2301083dec5e9fca2`. Public 1.4.3 is `e5046d261c1de125061a18743a3ccf8794da38b4`; the intervening source change is CIT-106 / PR #107. The approved [Plan Review](https://github.com/yoohwz/yoohw-customer-intelligence/issues/108#issuecomment-6330608003) authorizes the two narrowly justified scanner exceptions inside this task. It does not authorize release or waive any certification gate.

This record supersedes the initial blocked candidate and its package hashes. Exact final pushed head, current base, native CI run and fresh independent Technical Review are bound in the owning PR evidence after push. A source commit cannot embed its own SHA; read that PR evidence together with this record. Acceptance, Human merge and release remain separate.

## Identity and public notes

| Field | Exact staged value |
| --- | --- |
| Main-file Plugin Name / unchanged readme title | `Customer Intelligence for WooCommerce` |
| PHP header / `YOOHW_COS_VERSION` / readme Stable tag | `1.4.4` |
| `YOOHW_COS_DB_VERSION` / installed schema | `0.2.4`, unchanged |
| Readme Changelog headings | exactly `1.4.4`, dated Oct 22, 2026 |
| Full changelog first heading | `1.4.4 (Oct 22, 2026)` |

The full changelog retains every byte from its previous 1.4.3 heading down against the admitted base. Support metadata, title, other readme sections and existing links remain unchanged. The accepted latest-only readme contract and negative fixtures are unchanged.

Both changelogs contain the same four concise public notes: clearer Overview/Customer Details layout and responsive use of space; readable task and purchase-history details with truthful empty/updating/unavailable/currency explanations; Undo through existing task reopen; inline CRM task/digest email heading counts. No internal Issue identifiers, new storage/schema, FX conversion, AI/Premium or publication-success claim is added.

## Scanner correction and query safety

The [initial default scanner output](cit-108/plugin-check.csv) found two warnings on the inherited `get_priority_tasks()` fragment boundary. Public 1.4.3 had 0/0 in a paired scan. The Plan Review permits the smallest justified correction rather than a separate issue.

The complete producer/consumer audit found exactly one local `$view_where`: empty by default, or a fixed `overdue` / `due_soon` predicate selected by strict string equality. Dates are separately prepared with `%s`. No filter, hook, external producer or raw caller text can alter SQL text. The UI caller further normalizes its selector, while direct callers still get the safe empty-fragment fallback for unknown/hostile strings. Tables remain `%i`; status, ordering timestamps, user and limit retain their existing prepared placeholders.

Only two code-specific exceptions were added at the sink and fragment line: `PluginCheck.Security.DirectDB.UnescapedDBParameter` and `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`. The interpolated clause is isolated as an adjacent string literal so its inline PHP annotation is a real comment outside the SQL. No SQL comment, SQL escaping workaround, file-wide disable, new scanner exclusion, cache, duplicate query or runtime behavior change was introduced. Existing DirectQuery/NoCaching explanations remain narrow live-read exceptions.

A new bounded test in the existing guarded smoke entrypoint covers priority/overdue/due-soon, unknown and hostile selector fallback, completed exclusion, null dates, exact inclusive lower/upper and just-outside dates, chronological ordering and the 1–10 limit. A separate exact-package probe calls the admitted baseline and corrected method in the same owned DB/process: all five selectors produce **byte-identical generated SQL and identical rows**, in both HPOS modes. Thus the correction documents scanner context; it does not claim a runtime injection defect was fixed.

The final accepted Plugin Check 2.1.0 **default checks** ran on the extracted exact final ZIP:

```text
wp plugin check yoohw-customer-intelligence --mode=new --format=csv
```

[Final parsed scanner result](cit-108/plugin-check-final.json): **0 ERROR / 0 WARNING**, exit 0, `Success: Checks complete. No errors found.` Actual CSV diagnostic records were counted; exit status alone is not the gate. Both flagged codes are absent and no new finding appears. No checks, paths or codes were excluded. A temporary concatenation representation produced a new diagnostic during implementation; it was discarded. That superseded scan does not certify this payload.

## Reproducible exact payload

Two fresh `scripts/stage-distribution.sh` stages have identical inventories and per-file bytes. The accepted `release_lib.deterministic_zip()` produces identical ZIP bytes from both. [Complete 65-file inventory](cit-108/staged-inventory.json) records path, size and SHA-256; only product resources are included. Repository, test, dependency, documentation, private and nested archive artifacts are absent.

| Artifact | SHA-256 |
| --- | --- |
| Final 1.4.4 product tree | `6eb226247081cdf3badb08db0f7a7f80176d84a1666c8f2425ee0e302bf89096` |
| `yoohw-customer-intelligence-1.4.4.zip` | `9ad3b65a5187cac42a3c7194f4b76e5db9ae453961efad41308544e324d4b9d6` |
| Actual public 1.4.3 ZIP | `a6e274753f3016847e3d68e7d0360062c8926d57baaad7bb129884596779d2ab` |
| Actual public 1.4.3 product tree | `da4238e2fa53bcb814879ebd15305f4f22bad6a80a40594df680bd2ec2f60fe4` |
| Plugin Check 2.1.0 ZIP | `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4` |
| WP-CLI 2.12.0 PHAR | `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c` |

Upgrade origin is the real archive from `https://downloads.wordpress.org/plugin/yoohw-customer-intelligence.1.4.3.zip`, not a relabeled checkout. Its tree matches the previously verified release tree. Read-only remote inspection reconfirmed annotated tag object `3d5ae70fd4baf89af296e5617577ecc923ce6f95`, target `e5046d261c1de125061a18743a3ccf8794da38b4`. Both identities remain immutable. Initial candidate hashes `508aaf…` / `7faaa2…` and intermediate packages are superseded.

## Full regression and runtime

Local tools: PHP 8.4.26, MySQL 8.0.35, WordPress 6.9, WooCommerce 10.8.0, PHPUnit 9.6.36, Node 24.19.0, Python 3.12.6, Google Chrome 155.0.8059.85. Composer used the unchanged lock with `--no-plugins --no-scripts`. WP/WC archives were checksum-verified by the canonical runner. Local PHP runtime coverage is 8.4; exact-head native CI separately supplies PHP 7.4 syntax.

| Check | Result |
| --- | --- |
| Required-gate self-test | PASS, 2 tests |
| Release contracts / latest-only readme | PASS, `release-contracts-ok` |
| Tracked local PHP / JavaScript / Python syntax | PASS, 77 / 4 / 9 files |
| `git diff --check` | PASS |
| Full canonical HPOS=yes | PASS, 294 tests / 6,610 assertions |
| Full canonical HPOS=no | PASS, 294 tests / 6,610 assertions |
| Admission/grant-option controls | PASS, 144 before the local both-mode invocation; native CI runs its own controls per matrix mode |
| Canonical benchmark smokes | PASS, both modes |
| Unrelated synthetic DB sentinel / owned cleanup | PASS |
| Exact package default Plugin Check | PASS, 0 ERROR / 0 WARNING |
| Public-package upgrade cases | PASS, four states in each HPOS mode |
| Fresh exact-ZIP install | PASS, both modes, 12/12 tables |
| Native authenticated browser transport | PASS, bounded coverage and limitations below |

```text
python3 scripts/test-isolated.py --mysql-bin <MySQL 8.0.35 bin> --php <PHP 8.4.26> --inputs <checksum-verified cache> --mode both
```

The unchanged suite retains CIT-88 availability/recovery, CIT-92 native CSV headers/BOM and rejection paths, CIT-93 readable Lifetime money, CIT-94 exactly-one First-time cohort, CIT-95/103 plain email links/entities/escaping, HPOS/legacy, privacy/suppression, Reset, migrations, Diagnostics, RFM, Saved Views and notification contracts. The added task-filter case accounts for the increase from 293/6,584. All required tests completed without skips, failures or risky cases. Earlier implementation runs are supplementary, not substitutes for the final-source run.

## Real public upgrade and fresh install

[Package observations](cit-108/package-results.json) contain eight actual seed/upgrade pairs and two fresh installations. Each seed activated public 1.4.3 before persisting native completed WooCommerce orders, comparable VND, foreign EUR, mixed currency, an initialized zero-order profile and an explicitly constructed unknown-currency profile fixture. The latter is a synthetic persisted unavailable state, not a claim that a new missing-currency WooCommerce order was created.

Fixtures include notes, three tasks (open/completed/reopened), tags, static segments, both memberships, a per-user Saved View, scoring/email settings, a native privacy erasure and real one-way suppression receipt. A valid Reset epoch and a deferred source incident are established through the existing guard API. Four cases per topology preserve ready, preparing, currency attention and pending Reset. Preparing has a genuine scheduled worker obligation; pending Reset remains a separate blocked write boundary even while currency readiness is ready.

After native deactivation, replacing the plugin directory with the exact 1.4.4 ZIP, ordinary WordPress bootstrap and native reactivation, sorted full rows of ten owned tables and exact persisted option/meta strings are equal: customers, order facts, notes, tasks, tags, memberships, segments, suppression and migration issues; scoring/email settings, privacy secret, DB version, Reset boundary, operational incidents, migrations and Saved View. Readiness state and monetary reasons remain truthful. No duplicate customer/fact, destructive Reset or mandatory manual Sync is introduced. Cron scheduling identity may be recreated on activation; it is not misrepresented as a changed persisted business obligation.

The fresh exact ZIP uses native plugin activation, asserts schema 0.2.4 and 12 tables, syncs representative orders/customers and exercises task APIs, Saved Views, RFM, Diagnostics, privacy registration and actual erasure/suppression. Actual HTML/plain CRM digest rendering uses public triggers with intercepted transport: [summary](cit-108/fresh-email-summary.json). The HTML heading count remains in an inline span inside its heading; plain destinations retain `task_id` query semantics and contain no `&amp;`. No mail is sent.

A completed [supplemental native order-retention run](cit-108/upgrade-native-order-retention.json) repeats all eight public upgrades and additionally compares exact `wc_get_orders()` / `get_data()` JSON per order before/after. All native WooCommerce order data, owned rows and raw options/meta remain equal, with unchanged sentinel and owned cleanup. This covers native commerce records as well as CRM facts without post-upgrade manual Sync.

## Native browser, asset URLs and scope limits

Chrome used a fresh nonpersistent context, synthetic credentials and normal WordPress login against a newly owned token-guarded loopback server. No existing signed-in browser/site was used. [Browser measurements](cit-108/browser-results.json) record **126 combinations** at widths **320, 375, 768, 1024, 1440, 1920, 2560**. This is a transparent bounded alternative to the prior 119 Overview / 126 Details snapshot matrices, not a claim to repeat those exact cases: 35 Overview observations, 63 Details observations and 28 Customers/Settings/order/external-profile observations. Details includes zero-order and absent-profile empty states, and the intact profile during an Overview-only read-fault probe.

Overview and Customer Details cover populated, classification updating, preparing, attention, foreign/mixed/unknown currency, empty and unavailable explanations. A read-only fault injected in the private router makes only the Overview summary unavailable; product code/schema is not changed. Profile money unavailability is separately exercised by unknown/attention fixtures. An additional [36 native monetary-view combinations](cit-108/money-browser-results.json) isolate single VND, foreign EUR, mixed and unknown profiles, preparing and attention on both Overview and Details at 375/1024/1440px. Assertions require the reason-specific labels, recorded EUR/VND display, withheld unavailable money and retained recognized-order counts; expanded purchase-history facts remain accessible. No page-wide overflow appears in Overview, Customer Details or Customers. The sidebar measures 340px at 1440/1920/2560, 280px at 1024 and stacks at mobile widths. The actual mobile and desktop screenshots were visually inspected:

- [Overview mobile](cit-108/overview-populated-375.png), [Overview desktop](cit-108/overview-populated-1440.png).
- [Details mobile](cit-108/profile-single-375.png), [Details desktop](cit-108/profile-single-1440.png).

Native create → Complete → Undo uses the existing authenticated nonce/Reset-epoch handlers. Missing nonce and stale epoch requests are rejected (403/409) before authorized completion. A separate guarded PHP process verifies the created task and reopened status persisted. Notes, tags, segments, customer email composer, task routing and purchase details remain present. Optional Blacklist/Loyalty providers are not installed in this isolated topology; their compatibility is represented by the unchanged integration contracts, not fabricated native provider QA.

The native Saved View is reopened through its selector/form. A native CSV request returns 200, `text/csv`, BOM at byte zero and no admin HTML; PHP parses 17 columns and nonempty rows. The canonical suite separately retains independent strict CSV parsing and negative/Reset cases. Settings displays the synthetic recovery obligation; its native current-revision acknowledgement persists, and the final guard/process verifies zero incidents and restored readiness.

All five browser-emitted CIT URLs return HTTP 200, have `ver=1.4.4` and match exact staged file bytes:

| Native path, origin omitted | Observed version |
| --- | --- |
| `/wp-content/plugins/yoohw-customer-intelligence/assets/css/admin.css` | `1.4.4` |
| `/wp-content/plugins/yoohw-customer-intelligence/assets/js/admin.js` | `1.4.4` |
| `/wp-content/plugins/yoohw-customer-intelligence/assets/js/notice-preferences.js` | `1.4.4` |
| `/wp-content/plugins/yoohw-customer-intelligence/assets/css/order-admin.css` | `1.4.4` |
| `/wp-content/plugins/yoohw-customer-intelligence/assets/js/order-admin.js` | `1.4.4` |

Enqueue source/dependencies remain unchanged and use `YOOHW_COS_VERSION`; WC-owned dependencies retain `WC_VERSION`. The external native user-profile screen excludes CIT's layout stylesheet. No filemtime cache-busting is added.

Settings has a 536px document width at 320/375px. A separate native comparison of the actual public 1.4.3 package and exact 1.4.4 confirms identical measured widths at 320/375/768/1440: [paired results](cit-108/settings-baseline-comparison.json). This inherited Settings limitation is outside the admitted Overview/Details redesign and is not silently counted as an overflow-free screen or broadened into a layout fix. WC order/external-profile screens are measured without claiming a new responsive redesign.

The offline router blocks external browser/WP HTTP and non-admitted WC recommendation/REST routes; 26 opaque `Object` pageerrors were observed. Therefore this is not a zero-console-error or full WooCommerce-network certification. No PHP fatal/parse error occurs in the browser server log. The deliberately injected unavailable-summary DB error is expected fault coverage, not an unexpected product failure. WP-CLI reports its pre-defined-ABSPATH advisory because the ownership guard defines the exact private WordPress root before bootstrap; concrete scanner records and success output are checked independently.

## Safety, cleanup and release authority

Each runner uses a new private real temporary root, socket-only MySQL with TCP/MySQL X disabled, random database/account restricted to that DB, exact path/config/token/grant checks, early mail interception and disabled PHP mail. WP HTTP/cron and external browser HTTP are blocked. Existing Local/production sites and credentials were never used. The native browser routes do not bypass authentication, capability, nonce or Reset guards.

Final canonical, scanner, package/browser, monetary-view, supplemental native-order and paired-Settings runs verify their unrelated synthetic sentinel, stop only owned processes and remove their own temporary roots. Supplemental wrappers print the canonical runner's `large` navigation label when invoked with `--benchmark-only`; their actual benchmark PHP environment is explicitly smoke (40 profiles/160 facts), and logs retain those row counts. No 25,000-profile benchmark claim is made. Private fixture development failures (JSON numeric-type comparison, rescheduled cron comparison, duplicated helper include or case-sensitive text assertion) were corrected in the disposable harness; only completed final runs are PASS evidence. Source tests and product runtime were not weakened to accommodate them.

The host's default Node has a missing shared library; bundled Node 24.19.0 runs validation without host changes. Downloads use the existing CA bundle with TLS verification enabled. Non-secret local source archives, staged ZIPs and logs remain outside the repo for recovery; credentials, auth files and owned sites were removed. Scope is WP 6.9 / WC 10.8.0 / PHP 8.4.26 / MySQL 8.0.35, not every advertised runtime/provider combination.

No Prepare/Publish dispatch, tag, GitHub Release, WordPress.org SVN write, merge, deployment, schema change or release-control modification occurred. After fresh exact-head Technical Review and `YCI Required CI`, hand off to ChatGPT `Acceptance Review CIT-108`, then separate Human `Merge CIT-108`. Any later release must freshly bind the actual squash-merged protected main, never assume the candidate branch SHA is the merged SHA.
