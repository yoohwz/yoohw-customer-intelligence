# CIT-94 first-purchase cohort confirmation

Admission: [Issue #94](https://github.com/yoohwz/yoohw-customer-intelligence/issues/94#issuecomment-5976698158), protected base `c3e1ff954610f17952553d93cb932cf1807f9b8c`.

The purchase-cohort decision is exactly one recognized order for First-time and at least two for Repeat. Zero-order profiles retain their independent status and ordinary Customers visibility. The runtime change is one predicate in the canonical Customer Query; no list-specific filtering, second cohort engine, status/lifecycle/order-population/Overview formula, schema or version change.

## Deterministic validation

Two new cases extend existing guarded smoke and native CSV HTTP coverage. They cover persisted counts 0, 1, 2 and 4, multiple one-order subjects for pagination/count parity, ordinary/all/New visibility, search, tag, segment, missing contact, open/overdue tasks, archived/current scope, malformed/unsupported request inputs, repeat membership and the Overview purchasing denominator. The native HTTP case saves a personal first-time definition, reopens by ID with conflicting inputs, verifies canonical redirect restoration, exports/reloads twice and checks Repeat exports.

Focused development checks: 2 tests / 183 assertions in each HPOS mode. The baseline query negative control fails the CSV assertion with 3 rows (header + zero/one), where exactly 2 were expected. Fixed source was restored before the final unfiltered runner.

Final unfiltered owned runner: 281 tests / 5985 assertions per HPOS mode, five process controls, 144 guard rejection controls, synthetic benchmarks, unchanged unrelated database sentinel and owned server/filesystem cleanup PASS. Release contracts, required-gate self-tests and diff whitespace PASS. Development failures and the focused filter are not full-suite assurance.

```
composer install --no-plugins --no-scripts
python3 scripts/test-isolated.py --mysql-bin <Local MySQL 8 bin> --php <Local PHP 8.4 binary> --inputs <checksum-verified archive cache> --mode both
python3 tests/release-contract-tests.py
python3 .github/scripts/required-gate.py --self-test
```

## Native Local confirmation and limits

[Minimized receipt](local-confirmation.json) records rendered DOM and parsed CSV outcomes, runtime identity and source hashes. After deterministic checks passed, a new tab and disposable synthetic admin used native WordPress login, Customers quick-view links, Save current view, Open and reload. First-time visibly contains only One; Repeat only Repeat; ordinary search/all shows all three; New shows One and Zero. Searching Zero under either purchase cohort yields zero items. The saved first-time view retains One after reopen/reload.

The three fixture profiles model the canonical stored count seam; no WC orders were created and this does not re-certify recognized-order population or sync. The separate native HTTP CSV check uses a short-lived WordPress-generated session and nonce for the same synthetic administrator and the browser-created Saved View, without reading browser cookies or bypassing authentication. HTTP 200, CSV content type, BOM at byte zero, matching count 1 and exactly One/order-count 1 PASS; no raw CSV, email, request URL, nonce, session or credentials are committed.

Only the changed query and two unchanged baseline CSV admin files were temporarily installed because installed Local still had older CSV files. The unchanged Customers list, Saved Views and Overview consumers matched the admitted base. The original query matched base; the two original CSV files did not. All three originals were restored byte-for-byte; no entire-plugin/main identity claim is made.

QA-scoped mail/HTTP/cron guards and signup/login reward suppression prevented synthetic side effects. Cleanup verified owned IDs/emails before removing only the three profiles and related CIT rows, then the synthetic user and its private Saved View/session metadata. Explicit owned-reference checks are zero; store currency, scoring settings, migration and Reset boundary options are unchanged. Browser logout/tab closure and the QA guard/helper/credential removal completed. Private source recovery copies remain outside the webroot; no shared database rollback or whole-DB identity claim, and the monotonic customer invalidation marker was preserved.

No production/staging mutation, permanent Local deployment, version/tag/release/publication or merge. Native exact-head CI and fresh SHA-bound Technical Review are recorded on the PR before separate ChatGPT Acceptance.
