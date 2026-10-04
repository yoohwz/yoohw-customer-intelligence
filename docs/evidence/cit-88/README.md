# CIT-88 monetary readiness and notice correction

Approved base: `6913b52ae196ed5883a88921db73671ff6ae57b3` (CIT-86).
Scope: Issue #88; Controlled; no schema, version, tag, release or publication change.

## Read contract

`Commerce_Metrics_Policy::availability()` separates recorded customer state/currency,
site readiness, effective reason, amount availability, current-store threshold eligibility
and persisted intelligence freshness. Precedence is site attention/preparing, stale
customer metrics, trustworthy none, genuine mixed, comparable recorded currency,
then unknown source currency. Foreign EUR on a VND store retains its EUR amount;
store thresholds remain in the current store unit and never perform FX.

Trustworthy none means version >= 2 and zero recognized orders. It exports numeric
zero plus `none`; unavailable amounts export empty/null plus their machine reason.
CSV retains its first fourteen columns and adds reason, readiness and availability.
Privacy export additionally includes plain numeric available amounts. Provider facts
retain existing keys and add recorded state, reason, readiness, availability, threshold
eligibility, freshness and an available amount. Canonical list rows carry an immutable
page read context, so provider snapshots do not query once per customer. This context
is only a presentation snapshot; write/worker admission continues to read current state.

## Recovery and freshness

The missing-v3 recovery probe reads primary-key pages of at most 100 customers,
including archived rows. A completed negative checkpoint persists in the existing
migration option. Within its 24-hour validity, ordinary requests perform only an indexed
primary-key endpoint lookup; unchanged populations do not rescan or schedule work. New
IDs resume from the saved high-water cursor. Appended clean rows do not postpone the
24-hour deadline: the next request after expiry revalidates old rows in bounded pages,
so in-place stale writes (including external SQL) are not hidden permanently. A policy
version change also starts revalidation; Reset deletes this existing state. The initial
small/fresh clean probe retains its no-op behavior. Legacy facts and v2 issue status are
rechecked on active/revalidation passes. It
uses the existing migration scheduler/worker and Reset/migration ownership boundaries.
No-op requests avoid taking the Reset row lock. Current facts plus a version-1
customer now admit v3, rebuild through authoritative orders and converge idempotently.
No new index or schema is required.

Active currency readiness derives a separate scoring-generation identity from the
persisted base generation. This immediately fences prior monetary classifications
at the migration-state checkpoint even if a companion generation write fails.
Readiness transitions also restart the existing bounded intelligence refresh;
nonmonetary classifications calculated under the active identity remain usable.
Completion changes the effective identity again. State reads are uncached and migration
checkpoints verify persistence. The existing order/refund/link/Reset contracts remain.

Preparing requires pending/in-progress migration, WooCommerce, admitted schema,
readable issue evidence, no unresolved issue and no unrecovered error, plus a worker
scheduled within the fifteen-minute overdue grace or successful progress within that
grace. Later successful progress supersedes an older error only when progress is strictly newer than its valid local WordPress timestamp. A newer error, or missing/invalid error timestamp, remains attention until verified later progress or the worker clears it. A scheduled event is
reported as evidence, not proof of execution. `completed_with_issues`, blocked schema,
missing/overdue scheduling without recent progress, and unreadable state are attention.
Diagnostics retains issue counts, scheduling, last successful progress/error, customer
staleness and distinct none counts after banner dismissal.

## Notice contract and validation map

Only two fixed keys are stored in current-user meta, one revision each. Preparing
identity is stable across batches; attention identity includes material migration,
issue/error/progress and scheduling-category changes. Operational identity uses the
existing incident UUIDs. Native WordPress close posts a nonce-protected authenticated
request. Capability, current user, known key and exact server revision are required.
A per-user owned lock plus CAS preserves bounded storage under simultaneous writes.

1–11: `test_native_notice_transport_is_per_user_revision_scoped_and_presentation_only`
and `test_dismiss_transport_rejects_nonce_capability_and_arbitrary_keys` exercise real
server rendering/endpoint transport, per-user reload, transitions, replay rejection,
no migration writes and bounded preferences.
12: diagnostics snapshot plus real Settings browser navigation after dismissal.
13: existing flash-route tests additionally assert no persistent preference is created.
14: `test_operational_dismissal_preserves_incident_and_reset_blocking` verifies dismissal
does not resolve or acknowledge incidents, details persist and a new occurrence re-notifies.
The blocking Reset-incomplete banner deliberately remains non-dismissible: it is an
inline safety instruction while operations reject. Its recovery authority is unchanged.

Other new regressions cover the exact customer-only stale admission, SQL/getter
classification agreement, failed companion generation write, foreign amount dimensions
recovery beyond the first primary-key page, durable negative completion with repeated
ordinary requests, appended stale rows, and expiry-based revalidation of in-place stale
rows without clean appends extending the deadline, plus the fifteen-minute
scheduling/progress evidence rule. Existing full lifecycle/concurrency,
CSV safety, privacy, query, schema, Reset/epoch and extension contracts run unchanged
except assertions for the explicitly changed output contract and clearer worker failures.

## Historical and browser evidence

`run-upgrades.py` invokes the unchanged owned integration provisioner and the existing
CIT-86 historical seed/upgrade fixtures. It verifies immutable source pins for 1.2.2,
1.3.0, 1.4.0, 1.4.1 and public 1.4.2. `historical.jsonl` contains minimized synthetic
results for both storage modes. The public 1.4.2 source remains untouched.

Real yoplay8 wp-admin QA used a fresh in-app browser session and disposable synthetic
administrator/customer/order fixtures. Candidate loading was scoped to an owned QA
cookie via temporary MU routing; background cron, mail and external HTTP were blocked.
No integration bootstrap, Reset, bulk sync or worker was run on the existing site.
Desktop (1280) and mobile (390) inspected preparing/attention notices, native close,
post-close reload/navigation, Customers RFM, unavailable filter explanation, monetary
sort fallback, Profile KPIs, Overview and the Settings destination. Ready VND and
foreign EUR displayed their recorded amounts. Screenshots contain synthetic identity
or minimized aggregate UI. Original CIT, store currency and cron options were restored
and compared exactly; owned fixtures, user, routing symlink, MU file and credentials
were removed, tabs closed and viewport reset.

- `preparing.png`: native progress notice and synthetic Customers RFM.
- `profile-preparing-390.png`: mobile Profile monetary reason and useful order/R/F data.
- `attention-filter-390.png`: attention reminder and explicit unavailable evaluation.
- `foreign-eur-ready.png`: foreign EUR amount on a VND store.
- `overview-preparing.png`: revenue/AOV explain global preparation.
- `diagnostics-preparing.png`: actual Settings/Diagnostics destination.

Current-head native CI and independent SHA-bound review belong to the PR evidence.
Local development failures were corrected before candidate review; they are not PASS.
