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
