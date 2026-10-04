# CIT-95 plain CRM task email confirmation

Admission: [Issue #95](https://github.com/yoohwz/yoohw-customer-intelligence/issues/95#issuecomment-5976922763), protected base `288ffcf49957c5f6e09d1f3e0cf0c7bc4e44b866`.

The two shipped task/digest plain templates now serialize body copy through narrowly scoped CRM email helpers. Text decodes entities before stripping tags; task-action URLs decode HTML display entities, reject literal/percent-encoded CR/LF, and use HTTP(S) URL sanitization with literal query separators. Footer/additional/translated copy use the text helper too. HTML templates, task URL producers, recipients, notification logging/timing/retries, settings, branding and transport are unchanged. Customer Message does not use the new helpers and remains outside the runtime change.

## Deterministic regression

Ten added guarded cases extend existing notification-recipient coverage: eight email states, an additional reassignment handoff branch and unsafe-URL template coverage. Each state inspects both `get_content_plain()` before WooCommerce transport normalization and intercepted transport bytes. PHP's standard `parse_url()` plus `parse_str()` verifies scheme/host/path, exact page/task ID and empty fragment; digest sections retain distinct task IDs and a separate task-list URL. HTML DOM action links match the plain destinations. Fixtures include raw/encoded markup in customer/title/note/owner/additional/footer/translated copy, readable punctuation, body-only CR/LF notes with unchanged headers, and rejected unsafe schemes/line-break URLs in notification and digest templates.

Focused owned-runner checks: 10 cases / 520 assertions per HPOS mode PASS. The admitted-base plain-template negative control fails the direct renderer URL check: expected empty fragment, actual `038;task_id=13`. Earlier transport-only checks could miss this because WooCommerce normalizes plain transport content separately. Development/focused/negative-control runs are not full-suite classification; the unfiltered both-HPOS result and native exact-head CI/Technical Review assurance belong to the PR evidence record.

```
composer install --no-plugins --no-scripts
python3 scripts/test-isolated.py --mysql-bin <Local MySQL 8 bin> --php <Local PHP 8.4 binary> --inputs <checksum-verified archive cache> --mode both
python3 tests/release-contract-tests.py
python3 .github/scripts/required-gate.py --self-test
```

## Native Local confirmation and limits

[Minimized receipt](local-confirmation.json) records all eight native public plain/HTML renderers on yoplay8.local (WP 7.1.2 / WC 11.1.2 / PHP 8.4.18 / HPOS enabled), after the deterministic focused regressions passed. Synthetic joined task/customer arrays remain in memory; no user/customer/task/order fixtures were persisted, no endpoint was opened and no trigger/send/transport was invoked. A CLI-scoped MU guard blocked WordPress mail/HTTP and WP-Cron during rendering; observed mail attempts were zero.

Native plain output is entity/markup free, task/customer copy is readable, daily summary uses `Today's queue: 3`, each parsed deep link has its own exact numeric task ID in the query and an empty fragment, and HTML anchors preserve the same destinations. The synthetic IDs are render inputs, not claims of existing Local tasks. Local confirmation covers rendering/action parity, while guarded integration tests exercise the real trigger/intercepted transport and existing recipient suite.

Only the three changed runtime files were temporarily copied. Their originals and unchanged relevant HTML/event/digest/Customer Message sources matched the admitted base; originals were restored byte-for-byte. Email/branding/scoring/migration/Reset option hashes remained unchanged. The guard and helper were removed, raw captures deleted, and minimized receipt/source recovery copies retained privately outside the webroot. No browser session, credentials, raw email, recipients or existing customer data were accessed or committed; no whole-plugin/main or whole-database identity/rollback claim is made.

No schema/version change, production/staging mutation, permanent Local deployment, external email, tag/release/publication or merge. Final exact-head CI plus fresh SHA-bound Technical Review must pass before separate ChatGPT Acceptance. This task does not replace the bounded final recheck of the four CIT-90 corrective surfaces required before release preparation.
