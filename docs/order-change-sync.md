# Persisted order change synchronization

WooCommerce's `woocommerce_update_order` dispatches CIT synchronization after an
existing order update in both the pinned 10.8.0 CPT and HPOS data stores. The callback
uses the existing canonical reload, Reset boundary, identity rules and commerce
fact replacement. It does not use pending properties from the saving object.
Checkout, status, refund, refund deletion, order deletion and retry hooks remain.

The callback observes metadata-only and identical saves as well as relevant changes.
This deliberately relies on existing idempotent fact replacement and event keys,
without adding a persistent snapshot or a request-wide “already synced” set that
could hide later changes. In HPOS, `save_meta_data()` can itself call `save()` when the stored modified date
is older than the current second. CIT therefore marks only its own link/epoch
persistence per order and ignores update callbacks during that write, including
between its two metadata keys. The marker is cleared in `finally` (and preserves
an outer owner's marker on nested calls). It does not disable WooCommerce hooks
or change the Reset token rules. Other metadata-triggered saves still use ordinary
canonical synchronization; an incomplete foreign link can be rejected and then
repaired through existing identity resolution. Admin link save and status
transitions may also invoke the existing explicit sync; persisted contributions
and idempotent events remain single. This is bounded repeated work, not an exactly-once callback guarantee.

An active automatic update is guarded per order, only for its execution. A nested
save schedules the existing deduplicated retry because it may contain a newer
persisted state than the outer synchronization consumed. The outer state may be
transiently behind that nested save; retry reloads and converges. The guard is always
removed, so another genuinely different save later in the request is immediately
observable. Exceptions use the same retry mechanism; existing aggregate/Reset/identity
contention error paths retain their retry behavior. No second queue is introduced.

Evidence uses real WooCommerce CRUD saves, item total recalculation and the pinned
REST v3 orders controller `update_item()` with permission checks and a supported
billing update. It also covers the actual admin profile-link method with valid
synthetic nonce/capability/epoch, identity conflicts, persisted totals/date bounds,
status overlap, metadata/identical saves and automatic exception/nested-save retry.
Explicitly old modified dates exercise HPOS's metadata-triggered full save without
waiting for a wall-clock second boundary. Controls observe rejection of an invalid
token before automatic repair, no recursive sync/retry during CIT-owned link writes,
and normal subsequent updates after both successful and failed link persistence.
Baseline CRUD total/name and REST billing saves persist in WooCommerce while CIT
remains stale; candidate tests read CRM facts, aggregates, profile, link, events
and retry state back from storage in both HPOS modes.

This evidence covers the pinned single-site WP/WC/MySQL environment, not every
extension or transport. REST controller execution does not certify HTTP routing or
external authentication. Raw SQL/postmeta writes bypassing WooCommerce APIs, currency
conversion, historical data repair and arbitrary third-party recursive save behavior
are outside this task. Existing identity, commerce, Reset and bounded retry policies
are unchanged.
