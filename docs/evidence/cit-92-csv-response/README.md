# CIT-92 CSV response evidence

Approved boundary: [Issue #92 admission](https://github.com/yoohwz/yoohw-customer-intelligence/issues/92#issuecomment-5975990520), base `093c86d2379da3332cc633db3237bae591e2f785`.

The native HTTP regression in `tests/integration/test-csv-http.php` requests the actual
WordPress admin lifecycle using synthetic authenticated sessions and an owned loopback
server. It checks BOM at byte zero, a strict parse of the complete 17-column response,
selected rows, Saved View identity, selected/empty matching-count and limit headers,
nonce/capability/stale-view/monetary-evaluation rejection, ordinary rendering, unrelated
routes and pending Reset. With the baseline late dispatcher, the same regression failed
on prefix `<!D` instead of the BOM. The corrected regression passed in both storage modes.

`tests/owned-process-tests.py` checks success, child failure and runner SIGTERM with a
synthetic grandchild listener and an independent listener sentinel. Two further controls
cover Darwin's dead/zombie process-group EPERM case without hiding a living-group refusal.
The canonical runner executes these controls before provisioning its isolated database.
GitHub's exact-head full matrix and fresh Technical Review remain the assurance sources;
this directory contains no workflow-state markers.

## Admitted Local browser confirmation

[Minimized native attachment receipt](browser-download.json) records a real Export CSV
button request on yoplay8.local after deterministic regression green. One disposable
administrator and one synthetic customer were used. The filter adds minimum frequency
zero to retain the ordinary list rather than its existing single-search profile redirect.
No Saved View was active in this one browser confirmation; its success/rejections are
covered through native HTTP in the deterministic suite.

The managed Browser did not expose an OS-saved file. A temporary Local MU guard recorded
the actual browser POST attachment through a byte-preserving output-buffer callback,
returning every byte unchanged. This verifies the browser-triggered native response,
not an OS save-dialog/download-manager assertion. The response was HTTP 200, CSV UTF-8,
attachment filename, limit 5000, matching count 1, BOM at byte zero, strict 17-column parse
and exactly the selected synthetic row. No direct exporter invocation or buffer clearing
was used. Only the receipt is retained; raw response/request credentials are excluded.

Temporary Local candidate files were restored to their exact pre-QA bytes. The admin,
customer, signup coupon, MU guard and browser tab were removed. Private pre/post SQL
snapshots established unchanged existing CIT rows and options, no remaining new CIT
rows, and unchanged posts/postmeta after scoped fixture cleanup. An unrelated user with
`_loy61_fixture_namespace`, shared cron, blacklist scheduled work and normal transient/
session updates were preserved. The snapshots remain private outside the webroot; no
whole-database import/rollback was performed or byte-identical database claim made.
No production/staging mutation, schema/version change, merge or release was performed.
