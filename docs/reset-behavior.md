# Customer Reset and order link validity

Reset clears the same CRM tables and worker state as before, while retaining tag and
segment definitions. It does not modify WooCommerce orders, refunds, billing, totals,
statuses, users or other plugins' metadata. Orders can be rebuilt with **Sync orders**.
Existing incorrect historical assignments are not repaired automatically; recovery
requires a separate decision based on evidence.

A private `yoohw_cos_reset_boundary` option records a random epoch and `pending` or
`ready`. Reset persists `pending` before its first TRUNCATE. Old numeric order links
lose authority immediately; no historical order scan is needed. TRUNCATE is not
reversible. A failed or interrupted Reset remains pending until an intentional retry
clears and verifies the same tables/state. Completion rotates the epoch again, so
repeated initial or recovery form submissions cannot clear newly rebuilt profiles.
Direct PHP calls without an expected epoch intentionally request a new Reset.

CIT reads metadata through the active WooCommerce data store (including its callable
proxy), never an in-memory fallback. After Reset, `_yoohw_cos_link_epoch` must match
both the ready epoch and `_yoohw_cos_customer_id`. A partial metadata update cannot
make a different numeric ID authoritative. Both link fields are saved with WooCommerce
APIs. Bare explicit/manual links remain valid on a site with no Reset marker. The
order editor renders profile data and its epoch inside the same critical section; a stale form must be reloaded before selecting a
profile. HPOS and legacy list filters apply the same epoch/ID binding. Existing
identity precedence and ambiguity rules remain unchanged.

## Reset-local execution boundary

The reset helper uses one database/blog-scoped MySQL named lock. It is connection
owned, not a time-expiring lease: Reset does not take over a paused writer's lock.
Normal operations wait at most two seconds to acquire it; Reset returns busy without
waiting. Nested operations reuse the owner and release in finally blocks. Terminating
a connection releases its MySQL lock. A request snapshots its epoch at plugin load;
a request predating Reset cannot begin a writer afterward. Under the lock the marker
uses a current locking read, including inside a pre-existing repeatable-read transaction.
This assumes the ordinary WordPress MySQL connection and support for GET_LOCK; an
unavailable lock fails closed. This is not a general rewrite of identity/migration TTL
locks, nor proof of compatibility with arbitrary database drop-ins/proxies.

The protected paths are order sync/refund/deletion aggregates, customer create/update
and intelligence batches, event writes/reassociation, the migration batch and its
cursor, admin sync operations through cursor persistence, order-profile save/display,
and the core/premium blacklist and loyalty adapter entrypoints/backfills. The wrappers
start before identity resolution, so callbacks cannot carry a CRM ID across Reset.

Order-sync and deletion-cleanup contention queue the existing order retry hook. If
the order no longer exists when retried, the hook idempotently removes its persisted
fact/contribution. If deletion did not complete, normal order sync reconciles it. Scheduled migration,
activity, loyalty and reassociation jobs reschedule their existing hooks; batch callers
retain their cursor. A blocked adapter cannot publish stale customer references. A
payload-free operational warning is logged and shown to managers for one day, calling
for sync/backfill or upstream event replay. Transient provider events with no persisted
source cannot be reconstructed by CIT; no new raw-payload queue is introduced. Reset
still intentionally clears CRM history, and operators should retry interrupted Reset
before rebuilding. These provider-replay limits are separate from automatically
recoverable WooCommerce order sync.

The SQL lock does not certify arbitrary third-party code that writes CIT tables,
manual mutation of the marker, or database middleware transparently replaying writes
across connections. Run independent validation of such deployments before Reset.

## Ordinary CRM actions and selection freshness

Notes (`add_note`, `update_note`, `delete_note`), Tasks (`create_task`,
`create_idempotent_task`, `update_task`, `set_task_status`, `delete_task`, including
complete/reopen), tag/segment membership assignment/removal and existing definition
deletion cascades enter the same boundary **before** checking customer/note/task,
source-key or membership references. Their events and task hooks run within it.
Definitions retained by Reset and existing definition CRUD behavior are unchanged.
Inline definition creation stays within the enclosing, validated relationship action.

Admin_Tools validates an explicitly present `yoohw_cos_epoch` before all corresponding
POST and destructive GET actions, including the existing profile email form. It does
not change email recipient policy. An explicit empty string represents pre-first-Reset
forms; missing, non-string, malformed and stale values are rejected. The order editor
applies the same strict rule to its existing `yoohw_cos_link_epoch` field. Permission,
nonce and note ownership checks remain mandatory. A fresh PHP request cannot substitute
its current epoch for the submitted form's epoch.

The customer profile, task editor and dashboard widget render their bounded record
sections under the guard. The four customer/task/tag/segment list `prepare_items`
methods retain the epoch with their selected rows; form and row actions never obtain
a replacement epoch later during display. Overview captures only its actionable task
rows with an epoch, without locking the unrelated overview panels. Order task forms
(including their external `form` attribute) and completion links use the metabox's
existing guarded snapshot. Bulk handlers validate before reference reads and limit a
submission to 100 records; inline relationship names share that bound.

The editable order-customer AJAX selector sends the original form epoch and the server
reads results under that same generation. A 409 disables this selector and asks for a
reload; it does not upgrade the form's epoch. The separate read-only order-list search
keeps its response contract. Stale or pending user-authored actions show a reload/recovery
error and produce no success redirect, record-specific event or mail. They are never
queued for automatic replay against reused IDs. Internal synchronous callers use the
request epoch; callers retaining user selections across requests must carry and validate
the captured epoch before resolving those selections.

## Regression evidence boundaries

The isolated integration suite covers guest and registered ID reuse, conflicting
explicit/email identity, missing contact, legacy manual links, stale order objects,
repeated sync, persisted facts/totals, retained definitions/orders/refunds/users, token
mismatch and server-rendered admin field/save/list-query smoke. It uses a second PHP
process and connection for Reset-versus-paused-sync, request resumption after Reset
with an old transaction snapshot, process exit between actual TRUNCATE statements, Reset attempted between renderer
profile resolution and epoch output, and contended permanent deletion followed by
repeated cleanup retry.
A query-hook exception separately exercises interruption handling. These are owned
synthetic fixtures, not production tests or real-provider/browser certification.

The ordinary-writer matrix covers pending rejection and valid current operations for
15 service operations, with persisted row/relationship/event comparisons and intercepted
mail. Fresh PHP processes execute actual nonce/capability-protected GET/POST/bulk/AJAX
handlers using pre-Reset rendered inputs after customer, note and task ID reuse. Separate
processes also pause an ordinary note writer at INSERT and resume an old request after
Reset. A renderer pause attempts Reset between profile reads and epoch output. Persistence
checks end the PHPUnit transaction snapshot before reading another connection's changes.
The selector's shipped JavaScript executes in a Node synthetic jQuery/SelectWoo transport
smoke (`node tests/reset-selector-smoke.js`, also run by the isolated runner); this is
not a browser-layout, real-provider or existing-site test. Node 18+ is required for that
smoke. Test isolation, CLI-only admission, early mail/HTTP interception and owned cleanup
remain unchanged.

A render-fixture setup exposed a pre-existing assigned-task email failure:
`YoOhw_COS_Email_Task_Event::trigger()` calls an undefined `send_notification()` method
at the admitted baseline too. The rendering fixture assigns its synthetic row directly;
this suite does not certify successful assigned-task delivery. This separate email
implementation defect is not repaired or suppressed in #9. Rejected reset/stale actions
are checked before any such hook or mail attempt, and the existing task hooks remain.

Resolution update (2026-09-08, [Issue #13](https://github.com/yoohwz/yoohw-customer-intelligence/issues/13)):
the shared send prerequisite now uses WooCommerce transport/content APIs. CIT-A03 adds
real-template intercepted transport regressions alongside recipient authorization; this
resolution does not reclassify the older #9 evidence. See [recipient policy](notification-recipient-policy.md).


The individual tag/segment delete warning preserves the validated selection epoch in
its redirect and confirmation URL, including the explicit empty legacy epoch. Warning
rendering validates that context under the existing reset guard before reading the
selected definition and its current membership count; it does not trust an old count
from a redirect or issue a force link for stale/pending/malformed/missing context.
The final handler validates the same epoch again. Reload/reselection starts a new flow.
Inline segment assignment parses and deduplicates all names, including the no-JavaScript
fallback, and checks the 100-name bound before assigning an optional existing ID.

Focused regressions follow real row links, warning redirects, rendered force URLs and
protected final handlers in separate PHP requests for both relationship families.
They cover Reset/rebuild before warning render and after confirmation render, rejected
warning contexts, legacy-empty epochs and valid reload/cascades. A separate reset
process attempts Reset during warning output and must observe busy; warning counts
come from persistence. Current segment HTTP tests cover named/no-JS/ID/mixed input,
deduplication, 100 names and over-limit atomic rejection, with definition/membership/
event and mail assertions. The request probe treats meaningful PHP warnings/notices
as failures rather than hiding them. These are synthetic CLI request/render tests,
not browser or assigned-task mail delivery certification.
