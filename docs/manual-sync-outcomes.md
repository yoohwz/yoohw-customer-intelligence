# Manual order scan outcomes

Both manual admin-post and AJAX handlers consume the existing outcome-aware scanner.
Each scanned order contributes exactly one canonical `success`, `retry` or
`unresolved` outcome. Stored batch/cumulative `processed` counts successes;
`retryable` and `unresolved` count the other outcomes; `issues` is their sum.
`scanned = processed + retryable + unresolved`. Only counts and run/page timestamps
are stored in the existing sync-state option; no per-order customer data or ledger.
Unknown filtered outcomes fail closed into the existing retry category.

`in_progress` means more scanning remains. Exhausted pagination yields `completed`
only with zero issues, otherwise `completed_with_issues`. Percent is explicitly
**scan progress**, so 100% with issues is a warning, not complete successful processing.
`completed_at` means the scan ended under that explicit status. A fresh scan publishes
an unfinished state before work, so an exception cannot leave the old success state
as the result of the new run. An interrupted batch is replayed from its saved page;
partial work is not counted as an acknowledged batch and idempotent facts prevent
replay from adding commerce twice. Reset admission remains outside this operation.

Starting from page 1 explicitly resets run counters. Sequential pages accumulate;
a repeated acknowledged page returns saved counts instead of adding them again,
and requests beyond the saved next page cannot skip ahead. This bounded latest-page
response is not a general job journal. Existing Reset serialization protects handler
updates. Existing scans use live offset pagination; concurrent order population
changes are not a snapshot guarantee introduced by this task.

Retry outcomes schedule/dedupe only the existing `yoohw_cos_retry_order_sync` event.
Unresolved conflicts are not automatically retried or reassigned. A successful
background retry does not rewrite the historical manual-run counters. Run a fresh
manual scan to confirm current outcomes: transient recovery can clear retry counts,
and conflicts remain visible until resolved. The old `totalSkipped` AJAX field is
retained as a compatibility alias for total non-success outcomes; explicit retryable,
unresolved and issues fields carry their meaning. No raw outcome payload is returned.

Old stored scans lack outcome counts and cannot certify successful processing. They
require a fresh page-1 scan instead of resuming an unverifiable success-only tally.
Current-format interrupted scans retain their resume page. Server-rendered settings,
redirect notices, AJAX progress and the readiness summary use the same warning/success
semantics; redirect query parameters do not override persisted status/counts.

Tests execute both real manual handlers, actual outcome scans and persistence in
owned WP/WC/MySQL environments. Bounded two-order pages exercise the same handler
batch path with real orders; synthetic query filters restrict the fixture population
using the pinned stores' supported ID parameters. Node controls execute the shipped
AJAX/presentation functions with synthetic responses. They do not certify browser
layout, network transport or arbitrary extensions. No existing site or real mail/data.
