# Persisted intelligence freshness

The existing `yoohw_cos_refresh_risk_score_cache` daily hook now refreshes all
persisted derived intelligence through `refresh_derived_intelligence()`, including
status, lifecycle, value tier, trust and risk. Formulas, activity sources and threshold
sanitation are unchanged. The public risk-only batch helper remains compatible for
existing callers; the automatic hook uses the broader derived refresh.

Each invocation scans at most 250 customers in ascending ID order, including archived
rows so archived queries and later restores do not retain obsolete classifications.
The existing daily event starts a new pass after completion and resumes unfinished
passes. A single deduplicated wakeup on the same hook, with argument `-1`, continues
pending work after one minute. Actual execution depends on WordPress cron traffic;
this is eventual convergence, not immediate read-time freshness or a fixed SLA.
Long passes continue across daily ticks instead of repeatedly restarting at ID zero.

Saving scoring settings through the existing update method writes a fresh opaque
generation and requests that wakeup. This includes the authorized admin-post handler.
The worker reads the generation uncached before/after each batch and invalidates its
settings cache at the start. A superseded pass restarts at ID zero, including when
settings return to the same values after an intervening change. Old scheduled cursor
arguments are compatible wakeups, never authority to skip ahead. A continuation after
completion is a no-op unless a newer generation exists. Init restores missing pending
wakeups while preserving the daily recurrence.

Only generation, cursor and status (`pending`, `in_progress`, `completed`) are retained
in one freshness option, plus the settings generation option; no per-customer ledger
or PII. Existing Reset serialization covers each batch and state acknowledgement.
Contention defers even the initial zero cursor. Exceptions/read/write failures retain
an unfinished acknowledged cursor and request another bounded attempt. Successfully
processed rows may be replayed after interruption; existing derived updates and
integration idempotency apply. Reset clears this worker's cursor along with other
owned worker state; the settings generation and daily recurrence remain applicable.

After convergence, existing profile/list/filter/count paths read the same persisted
fields. Manual recalculation and manual order-sync states remain independent. No order
sync, facts rewrite or new queue is introduced. Ordinary derived-update timestamps
may advance on a no-change pass; customer source fields, commerce and activity records
are not rewritten by this worker. Existing recalculation extension hooks still fire.

Tests use the owned disposable runner in both WooCommerce storage modes. An isolated
WordPress `gmt_offset` option filter advances the timestamp used by the existing
calculators by one day, with fixed source activity rows; it does not wait real days or
change production formulas. Scheduled callbacks are dispatched as WP-Cron does, and
settings tests invoke the real authorized save handler. A 251-customer population
crosses the production batch boundary. Coverage includes persisted query/view counts,
latest-generation restart, interrupted writes, Reset deferral, archived/zero rows and
idempotency. This does not certify real-time cron delivery, arbitrary extension side
effects, browser layout or every supported dependency version.
