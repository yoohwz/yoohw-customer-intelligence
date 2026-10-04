# CIT-100 final accumulated-main corrective recheck

Scope: [Issue #100](https://github.com/yoohwz/yoohw-customer-intelligence/issues/100).
Risk: Controlled. Product runtime changes and release authority: none.

Recheck disposition: **CIT90_CORRECTIVE_RECHECK_READY** for the bounded Local and deterministic surfaces below. This does not grant Acceptance, merge or a release. Fresh exact-candidate `YCI Required CI` and independent Technical/QA Review remain mandatory; their final SHA-bound outcomes belong in the candidate PR evidence, not a remembered or self-certifying source marker.

## Exact accumulated source and environment

Tested protected main: **`ef5f735c7ddc25dec959a03a6908d81ef3f4af6b`**. GitHub main was freshly read before testing and confirmed unchanged after Local cleanup. The separate recheck branch is based on that commit and changes only this evidence directory.

The initial run stopped at `SOURCE_MISMATCH`: 8 of 65 installed product files were older. The Human subsequently authorized Git-to-Local synchronization as a separate action. The complete distribution tree was synchronized and backed up outside the webroot before this continuation. No selective overlays were used during the recheck.

[Source gate](source-match.json) compares all 65 tracked runtime/resource files selected by the repository distribution allowlist: `admin/`, `assets/`, `includes/`, `languages/`, `templates/`, plugin entrypoint, uninstall, readme and changelog. There are no tracked license files at this baseline. There were zero missing, extra or differing files. [Final source/cleanup receipt](source-final.json) confirms all source bytes remained unchanged throughout this recheck. This source identity claim concerns installed product source, not development files or database identity.

[Environment](environment.json): **yoplay8.local / WordPress 7.1.2 / WooCommerce 11.1.2 / PHP 8.4.18 / HPOS enabled / CIT 1.4.2 / USD store / monetary readiness ready**.

A private SQL recovery snapshot and complete source recovery copy were retained outside the webroot before stateful fixture creation. The SQL snapshot is 166862334 bytes, mode 0600; its checksum is in the environment receipt. It was never imported. Recovery files, existing site data and credentials are excluded from this evidence.

## Local A/B/C/D matrix

| Surface | Outcome | Current-main observation |
| --- | --- | --- |
| A — filtered Customers export | PASS | Real authenticated wp-admin Export CSV POST; 3 selected synthetic subjects, 17 columns, UTF-8 BOM/header at byte zero, HTTP 200, CSV UTF-8 and attachment headers, limit 5000, matching count 3; strict full-response parsing succeeds. No admin HTML prefix, token field names or actual request-token values leaked. Ordinary Customers rendering remains normal after export. |
| A — personal Saved View export | PASS | Browser-created exactly-one-order Saved View reopened/reloaded; real Export CSV POST contains only One, order count 1, matching count 1, and the same complete-response/header/purity/token checks pass. |
| B — Lifecycle foreign-currency copy | PASS | One owned completed/paid VND 100000 order in the USD store. Native Profile Lifetime value is `This customer has spent ₫100.000,00 (VND).`, with zero descendant elements or literal markup/entities. KPI Total spent/AOV, RFM M and the recent-order amount each retain a WooCommerce price element showing ₫100.000,00; recorded VND context remains visible where intended. No FX/store-currency substitution. |
| B — unavailable reason | PASS | Native Zero Profile Lifetime value shows canonical `No recognized orders`, rather than an invented amount. |
| C — 0/1/2-order cohorts | PASS | Native search/all shows Zero, One, Repeat; First-time only One; Repeat only Repeat; counts match rows. Zero is outside both purchase cohorts and remains in New alongside One. Personal First-time Saved View survives Open/reload and its CSV agrees exactly. |
| D — plain notification/daily digest | PASS | Real email class `trigger()` for plain and HTML, intercepted before external transport; direct public plain renderer is inspected before WooCommerce normalization. Task links contain literal `&`, parse to exact task page and numeric IDs 960100/960101 in query, have empty fragments, and equal decoded HTML anchor destinations. Plain output is markup/entity free; `Today's queue` is readable; HTML table layout and escaped/sanitized title remain. |

Receipts: [browser/cohorts](browser-minimized.json), [native CSV responses](csv-minimized.json), [Profile DOM](profile-minimized.json), [persisted fixture metrics](persisted-fixtures.json), [email captures](email-minimized.json).

### Local method and limits

A new managed Browser tab used native WordPress reauthentication and login with one disposable synthetic administrator. No browser cookies/session stores were read and authentication was not bypassed. Customers quick-view links, Save current view, Open, reload, Export CSV and Profile navigation were exercised through the native UI. Minimized DOM observations are retained; this is not a responsive/layout or full CIT-90 inventory audit.

Three synthetic persisted profiles model the canonical stored recognized-count seam at 0/1/2. They have no WooCommerce orders and do not re-certify the complete order-population engine. The separate VND profile has one real synthetic WooCommerce order, synchronized through the current product path. Its name was separated from the cohort search so the search universe contains exactly the three count subjects.

The managed Browser did not expose an OS-saved CSV file. A temporary QA-scoped MU guard captured the actual browser POST response with a byte-preserving output-buffer callback that returned every byte unchanged. Complete response bytes were strictly parsed; headers were limited to the allowed content/disposition/export fields. Actual nonce/request-token values were compared against response bytes inside that callback, with only exclusion booleans/counts retained. No exporter was invoked directly, no output was cleared, and no raw response/request tokens or synthetic contact addresses are committed. This proves native HTTP attachment behavior, not an OS download-manager/save-dialog assertion.

Shared Local Reset/pending-boundary or monetary migration-state mutation was **NOT RUN**: changing those shared controls could affect concurrent work. Their native HTTP fail-closed and Reset/rejection contracts are covered in the exact-source isolated suite. This limitation is not replaced with a Local PASS claim. The safely observed Local `none` monetary reason and ready-state exports are reported separately.

The email case uses synthetic in-memory joined task arrays and the disposable staff recipient, not persisted tasks or scheduling. Both representative classes run `trigger()` twice, plain and HTML. Only instance properties select email type/enabled state; no settings option is changed. An in-memory WooCommerce callback-parameter observer checks direct renderer and HTML anchors, while the QA-scoped `pre_wp_mail` returns false. Failed helper assertions in development were corrected before the final successful captures; they were incorrect fixture expectations about sanitized HTML titles, not product defects. Four final representative trigger captures succeeded with blocked transport. The fixture lifecycle had **11 intercepted mail attempts and zero external deliveries**; all eight states remain deterministic coverage below. No SMTP/inbox/scheduling compatibility is claimed.

## Accumulated cross-regression matrix

| Contract | Outcome / evidence |
| --- | --- |
| CIT-92 early CSV lifecycle after CIT-93/94/95 | PASS — whole accumulated-main source; native filtered and personal-view response bytes/headers |
| CIT-94 Saved View CSV through CIT-92 export dispatch | PASS — reopened exactly-one definition, one native UI row and one CSV subject/order count |
| CIT-93 monetary presentation after later changes | PASS — native foreign Lifetime copy and preserved KPI/RFM/recent-order HTML; canonical unavailable factor |
| CIT-95 plain serialization versus HTML destinations | PASS — standard PHP URL parsing and decoded DOM anchor equality for notification/digest |
| Version/schema/release boundary | PASS — version 1.4.2 unchanged; no product source/schema/version/release mutation; relevant options unchanged except the monotonic data invalidation timestamp |

## Deterministic support and workflow assurance

The current canonical unfiltered owned runner ran in **both HPOS modes** on the unchanged runtime/test source of the tested main. Each mode passed **291 tests / 6505 assertions** with no skipped/risky/empty tests. Five owned-process controls, 144 entrypoint rejection controls, both smoke benchmarks, unchanged unrelated persisted synthetic DB sentinel and owned MySQL/filesystem cleanup passed. These include native authenticated CSV HTTP/Saved View/Reset/monetary rejection, Lifecycle formatting, cohort and all eight email-state regressions. No integration bootstrap ran against installed Local.

```
composer install --no-plugins --no-scripts
python3 scripts/test-isolated.py --mysql-bin <Local MySQL 8 bin> --php <Local PHP 8.4 binary> --inputs <checksum-verified cache> --mode both
python3 tests/release-contract-tests.py
python3 .github/scripts/required-gate.py --self-test
```

Release contract checks and both aggregate gate self-tests also passed. No runtime or test source changed during or after this canonical invocation; this evidence-only candidate requires its own native full CI classification.

The final native exact-head `YCI Required CI` and one fresh independent Controlled Technical/QA Review are separate from the above Local and owned-runner observations. The [candidate branch workflow runs](https://github.com/yoohwz/yoohw-customer-intelligence/actions?query=branch%3Acodex%2Fcit-100-final-recheck) and the CIT-100 PR record must identify the exact frozen candidate, full success/no-skips and SHA-bound reviewer verdict before ChatGPT Acceptance. At source preparation these gates are pending, not labeled PASS; their final links/outcomes are maintained in the PR without a handoff-only source commit.

## Cleanup and concurrent activity

[Scoped cleanup](cleanup.json) confirms zero owned customer/user/user-meta/CIT/order/order-fact/notes/HPOS/post/meta/analytics/action references. Cleanup removed four profiles and related CIT rows, the administrator and its personal Saved View/session metadata, the synthetic order, three order notes, one generated signup coupon and owned analytics/report-transient action/log records. No task/view/label/export/capture fixtures remain. Native browser logout/tab closure completed; the guard, private controller, disposable credentials and all raw response captures were removed. Private SQL/source recovery and minimized receipts remain outside the webroot.

All pre-existing CIT rows remain present. Concurrent unrelated activity added a customer, order facts, events and a task, and updated one pre-existing customer with new order/metric data. The minimized comparison records field names/counts and verifies those changed/new rows have no owned fixture customer/order/namespace references. These rows were preserved. The only changed original observed option is `yoohw_cos_customer_data_updated_at`, a monotonic invalidation marker; it was preserved rather than rewound. Currency/scoring/migration/Reset settings were not edited. No whole-database import, whole-DB identity claim or unrelated row restoration was performed.

## Defects and next boundary

No current-main P0/P1/P2 product defect was found in the admitted four-surface recheck; no follow-up defect Issue was needed. The initial source mismatch was resolved by the separate Human-authorized whole-tree sync, not by a hidden runtime patch inside this recheck.

No version bump, tag, GitHub Release, WordPress.org publication, production/staging access or deployment occurred during this recheck. Only evidence is changed in the candidate. After exact-candidate CI/review and separate ChatGPT Acceptance, this readiness result may support a **separately admitted** corrective patch release-candidate task; it is not release authorization.
