# CIT-80 notice lifecycle audit

Admitted base: `main@0ac831c622b6ecf20323deb4bb88d0b23d2649db` (Customer Intelligence 1.4.1). The PR records the exact candidate SHA, CI run, and independent review URL once available. This task does not bump a version, tag, publish a release, or deploy.

## Notice inventory

| Surface / trigger | Before → after severity | Class | Scope and lifecycle |
| --- | --- | --- | --- |
| Overview task result / failure | success/error → same | Flash | CIT overview; redirect token consumed once, URL cleaned; dismissible |
| Overview incomplete sync | warning → same | State | CIT overview; derived from sync state, no permanent dismissal |
| Customers bulk result / validation / no changes | success/error/error → success/error/info | Flash | Customers; consumed redirect token; dismissible |
| Saved Views create, update, rename, delete / invalid action | success/error → same | Flash | Customers; consumed redirect token; dismissible |
| Saved View invalid filters | error → same | State | Customers; recomputed from saved filter validity, non-dismissible |
| Profile tag and segment add/remove | success/warning → success/success | Flash | Customer profile; consumed redirect token; inline |
| Profile task and note create/update/delete/failure | success/warning/error → success/success/error | Flash | Customer profile; consumed redirect token; inline |
| Tasks create/update/complete/reopen/delete, bulk, validation | success/error → same | Flash | Tasks; consumed redirect token; dismissible |
| Tags create/update/delete, bulk results and skipped assignments | success/error/warning → same | Flash | Tags; consumed redirect token; dismissible |
| Tags blocked deletion | warning → same | Flash | Tags; consumed redirect token; inline action guidance |
| Segments create/update/delete, bulk results and skipped assignments | success/error/warning → same | Flash | Segments; consumed redirect token; dismissible |
| Segments blocked deletion | warning → same | Flash | Segments; consumed redirect token; inline action guidance |
| Settings sync, blacklist, recalculation, backfill, save and Reset results | success/info/warning/error → same, Reset warning → success | Flash | Settings; consumed redirect token; dismissible |
| Settings resume/partial sync and diagnostics readiness | warning/success → same | State | Settings; derived from persisted sync/diagnostic state |
| WooCommerce order task result/failure | success/error → same | Flash | Order admin; consumed redirect token; inline |
| WooCommerce order customer lookup 409 | error → same | State of current field | Order admin; one alert replaces prior alert, field disabled until reload |
| WooCommerce dependency missing | error → same | State | Admin; derived from plugin availability, non-dismissible |
| Reset pending/invalid | warning → same | State | Global blocking notice; derived from durable Reset boundary, non-dismissible |
| Deferred integration callback | generic warning → source-specific warning | Incident | Compact global link to Settings recovery; per-incident details and acknowledgment after actual recovery |

Flash tokens are generated on CIT redirect URLs, bound to the current user, and expire after five minutes. The first admin render consumes the token and cleans the browser URL. A repeated or stale URL has its flash flags removed server-side. Filter, pagination, sync progress and state parameters are not flash flags.

## Deferred callback classification

All 16 integration callback `enter(true)` call sites at admission now provide explicit source, event class and recovery mode. The seventeenth `enter(true)` in the nested `Events::record` primitive has been changed to `enter(false)`; its owning operation supplies the recovery obligation, avoiding a generic false manual-replay incident.

| Source | Callback | Durable upstream | CIT automatic retry | Bounded backfill | Transient / not replayable | Mode and notice |
| --- | --- | --- | --- | --- | --- | --- |
| Blacklist Manager Core | order suspected | order/blacklist rows | no | yes, blacklist backfill | no | backfill_available; Settings source notice |
| Blacklist Manager Core | order blocked | order/blacklist rows | no | yes | no | backfill_available; Settings source notice |
| Blacklist Manager Core | blacklist removed | removed row may no longer exist | no | no reliable removal replay | yes | manual_replay_required; Settings source notice |
| Blacklist Manager Core | suspect detected | detection log | no | yes | no | backfill_available; Settings source notice |
| Blacklist Manager Core | dashboard row changed | row durable for updates; deletion may remove it | no | yes for remaining rows | deletion only | backfill_available for updates; manual_replay_required for deletion; Settings source notice |
| Blacklist Manager Premium | risk job completion | order risk metadata | no | yes, premium risk order backfill | no | backfill_available; Settings source notice |
| Blacklist Manager Premium | anti-bot risk failed | checkout decision may be transient | no | no reliable complete replay | yes | manual_replay_required; Settings source notice |
| Blacklist Manager Premium | anti-bot challenge required | checkout decision may be transient | no | no reliable complete replay | yes | manual_replay_required; Settings source notice |
| Blacklist Manager Premium | JS proof failed | transient checkout proof | no | no | yes | manual_replay_required; Settings source notice |
| Blacklist Manager Premium | session continuity failed | transient checkout context | no | no | yes | manual_replay_required; Settings source notice |
| Blacklist Manager Premium | fingerprint anomaly | transient device signal | no | no reliable complete replay | yes | manual_replay_required; Settings source notice |
| Blacklist Manager Premium | payment abuse event recorded | payment abuse event row | no | yes, premium payment abuse backfill | no | backfill_available; Settings source notice |
| Loyalty | points log created | points log row | no | yes, points-log backfill | no | backfill_available; Settings source notice |
| Loyalty | loyalty role updated | current role durable, prior transition may be lost | no | no transition replay | yes for transition | manual_replay_required; Settings source notice |
| Loyalty | intelligence recalculated | customer intelligence facts | no scheduled callback retry | yes, Settings recalculation | no | backfill_available; Settings source notice |
| Loyalty | points reconciliation issue found | current points can be reconciled, original finding may be transient | no | no guaranteed issue replay | yes for finding | manual_replay_required; Settings source notice |

The classification is conservative where an upstream removal or transient decision cannot be proven recoverable. The action does not claim to run a backfill; the administrator must perform the source operation before acknowledgment. Reset is not suggested as a generic incident cure.

## Incident representation and race contract

A valid legacy `yoohw_cos_reset_notice` record is migrated at a ready Reset boundary into an explicit unattributed legacy incident; the old option is then retired. The UI states that the earlier version did not record its integration source. `yoohw_cos_operational_incidents` is one non-autoloaded option, serialized as `version: 1` plus a map keyed by `source:event`. Each item holds only revision UUID, source, event, mode, first/last seen Unix timestamps, saturated occurrence count, and expiry. No callback arguments or customer, device, payment, credential, or secret values are stored. Entries expire after 30 days, at most 128 are retained (127 semantic rows plus a visible capacity warning), and equivalent occurrences aggregate under one semantic key. Writes and acknowledgments use the notice advisory lock and direct SQL reads; a new occurrence changes its revision UUID, making older forms stale. Reset does not clear operational incidents because completing Reset alone does not prove that an integration callback was replayed; a concurrent new or updated incident survives.

## Regression and validation record

The topic #950 regression creates a transient premium incident, checks the global link and detailed source notice, acknowledges it, checks it is absent on the next render, creates a new incident, and checks its revision differs. Separate tests cover stale forms, a callback racing with acknowledgment, equivalent aggregation, distinct incident preservation, capacity overflow visibility, Reset versus concurrent callback, expiry, state-derived pending Reset, and flash token consumption. Existing Reset/link integrity tests cover stale epoch, malformed boundary, read contention, migration and order retry behavior. The order-admin 409 handler updates one `role="alert"` element beside the field.

Local syntax checks on PHP 8.4 and `node --check` pass. The disposable HPOS=yes/no integration runs, exact-head `YCI Required CI`, fresh Technical Review URL/SHA and any limitations must be entered in the PR evidence after those gates complete. No existing WordPress installation was used for integration tests.
