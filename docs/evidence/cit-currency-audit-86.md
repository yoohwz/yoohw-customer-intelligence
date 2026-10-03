# CIT-86 — Currency readiness and monetary availability audit

## Decision and authority

**CURRENCY_AUDIT_RELEASE_BLOCKER**

Audited baseline: `22cbb91c1ddd4a6c6723cd52c1f2b94485220ece` (protected main,
Free 1.4.2, schema 0.2.4). This is an audit/design candidate, not a product fix.
Healthy VND and EUR monetary aggregation works. The reported site was temporarily
blocked by global currency migration despite valid VND customer/fact data; the UI
incorrectly attributed that condition to mixed/unknown currency. Two additional
recovery/freshness gaps are reproduced below. This is a **post-publication release
blocker / corrective-release finding**: 1.4.2 is already public. Admit a separate
runtime correction task, then prepare a new corrective patch release under normal
versioned release controls after the blockers are resolved. Do not republish 1.4.2,
move/delete/reseal its tag, or recommit the same SVN release. This audit grants no
runtime implementation, merge or publication authority.

### Corrected release-state evidence

The original Issue and first audit candidate incorrectly assumed that 1.4.2 had
not been production-published. [Acceptance finding A1](https://github.com/yoohwz/yoohw-customer-intelligence/pull/87#issuecomment-5965025018)
required this factual correction; the historical Issue premise is not current release
authority. Read-only rechecks on **2026-10-03** establish:

- [Production run 36817211851](https://github.com/yoohwz/yoohw-customer-intelligence/actions/runs/36817211851)
  executed on **2026-10-01** against `22cbb91c1ddd4a6c6723cd52c1f2b94485220ece`
  and succeeded through the publication mutation boundary. Its logs record
  `TAG_SEALED`, the atomic WordPress.org SVN revision **3722391**, and terminal
  verification state `WPORG_PROPAGATION_PENDING`. That historical propagation
  state does not mean the SVN mutation was absent.
- [Annotated tag 1.4.2](https://api.github.com/repos/yoohwz/yoohw-customer-intelligence/git/tags/ed5f96bc34605e80bedff7c7eb058bd339566486)
  exists, with tag object `ed5f96bc34605e80bedff7c7eb058bd339566486` pointing to
  the audited baseline.
- The [public WordPress.org listing](https://wordpress.org/plugins/yoohw-customer-intelligence/)
  currently exposes **Version 1.4.2**, with its October 1 changelog. This listing
  check is not a new certification of every package/propagation endpoint.
- The authenticated GitHub Release-by-tag API returns **404** for `1.4.2` at
  the same observation date; that GitHub Release object is absent. The earlier
  workflow's GitHub Release job was skipped. An annotated Git tag, a GitHub
  Release object and WordPress.org publication are separate facts; absence of
  the GitHub Release does not imply absence of WordPress.org publication.

Those external tag/SVN mutations were performed by the earlier release workflow,
before CIT-86. CIT-86 itself performed no release/tag/SVN/publication mutation and
does not authorize a release retry or corrective publication. Preserve the existing
immutable release evidence; remediation requires a separately admitted correction
and a new version, not resumption of the already-published 1.4.2 release.

The Human corrected the original environment during execution: the affected CIT
installation is **veeveestore.local**, not veevee.store. The correction is recorded
in [Issue #86](https://github.com/yoohwz/yoohw-customer-intelligence/issues/86#issuecomment-5964910187).
Production SSH verified veevee.store, but it currently has no CIT directory,
options or tables. Do not attribute the reported CIT symptom to production.
Actual-site classification therefore uses read-only SQL/source inspection on
veeveestore.local. Synthetic mutations use yoplay8 and the owned isolated harness.

A fresh independent discovery agent inspected the baseline, Issue and historical
PRs before conclusions were frozen. Its source-grounded candidate failure paths
were subsequently tested by the implementer. This discovery is separate from the
fresh exact-candidate Technical Review required before Acceptance.

## Historical contract

- [CIT-42 / PR #43](https://github.com/yoohwz/yoohw-customer-intelligence/pull/43):
  persist authoritative per-order currency; distinguish customer monetary state;
  do not sum unlike currencies, guess historical currency or introduce FX;
  guard display, decisions and queries until currency facts converge.
- [CIT-67 / PR #68](https://github.com/yoohwz/yoohw-customer-intelligence/pull/68):
  recover missing current-schema currency migration from legacy facts or old
  commerce issue status; reschedule active work; reconcile old/new ledgers;
  skip identityless noncontributing orders without manufacturing facts.
- RFM M (#50/#51), Profile (#52/#53), privacy export (#54/#55) consume this policy.
  Customers UI (#65/#66) did not redefine it. CIT-70/CIT-72 release evidence
  exercised convergence but did not establish a different currency model.

## Exact-baseline mechanism map

Paths and line numbers in this table refer to the audited baseline.

| Layer / scope | Mechanism / authority |
|---|---|
| Order / per-order | `includes/class-yoohw-cos-commerce-metrics-policy.php:16`: normalize `WC_Order::get_currency()`; accept three uppercase letters, otherwise null. No CIT store-currency fallback and no USD branch. The regex validates shape, not membership in WooCommerce's configured currency registry. |
| Population / per-order | Policy lines 71–130: paid-status non-refund `shop_order`; recognized count and net revenue use this population. Partial refunds reduce net revenue; zero/fully refunded revenue does not imply no recognized order unless the parent status leaves the paid population. |
| Mutation hooks | `includes/class-yoohw-cos-customers.php:14`: shared WooCommerce CRUD hooks cover create/update, same-status changes, refunds, deletion and reassociation; HPOS and CPT use the same API. Canonical reload and Reset link/epoch checks still apply. |
| Facts / per-order | `includes/class-yoohw-cos-commerce-aggregates.php:20`: transactionally upsert order currency, policy version 2, recognized contribution and net amount; lock affected customers in order; update old/new customer on reassignment. Clear persisted intelligence marker before commit. |
| Aggregates / per-customer | Aggregates lines 375–408: recognized facts determine count, net sum and min/max currency. Untrusted aggregate or null/empty currency → `unknown`; zero recognized orders → `none`; one currency → `comparable`; unlike currencies → `mixed`. Mixed/unknown persist numeric zero placeholders, not trustworthy monetary zero. AOV = net sum / recognized count when comparable. |
| Aggregate trust / per-customer | Aggregates lines 259–272: explicit trusted backfill, previous metrics version >=2, or previously uninitialized version 0 admits current aggregation. Previous version 1 remains untrusted under ordinary rebuild. |
| Schema / site-global | `includes/class-yoohw-cos-install.php:716`: verify schema/upgrade; current schema with missing v3 calls missing-backfill registration. Schema status is not checked directly by the display comparability predicate. |
| Registration / site-global | `includes/class-yoohw-cos-migration-runner.php:75`: v3 is admitted if missing and a legacy fact exists (policy<2 or recognized null/empty currency), or old v2 status is `completed_with_issues`. Customer-only stale state is not scanned. |
| Worker / readiness-only | Runner lines 91–163, 208 onward: Reset guard, connection-owned lock, bounded order pages, retries, customer rebuild, ledger reconciliation, terminal state. Active pending/in-progress can be scheduled; terminal issues do not automatically re-enter the worker. |
| Global readiness | Runner lines 168–178: DB version must be >=0.2.2. Existing v3 must be `completed`; absent v3 falls back to v2 absent/completed. Ledger/schema/cron are not independently read by this predicate. A missing v3 alone is legitimate on fresh/Reset sites. |
| Effective comparability | Policy lines 21–25: customer state comparable + global complete + customer metrics version >=2 + valid currency shape. `intelligence_currency_ready` and store-currency match are not required for displaying amounts. |
| Threshold eligibility / store-specific | Policy lines 28–47: additionally require recorded currency = current store currency. Foreign historical amounts remain displayable in their own currency, but cannot use store-unit monetary thresholds. |
| Decision freshness / per-customer and generation | `includes/class-yoohw-cos-intelligence.php:14–40`; `customers.php:408`: marker and generation guard persisted classifications. Store currency changes and migration completion invalidate generation and restart bounded refresh. Marker=1 means decisions were refreshed safely, not that money is comparable. |
| Rendering | Policy lines 50–68: trustworthy-looking `none` with global readiness returns store-formatted zero; comparable uses persisted currency in `wc_price`; foreign currency gets a currency-code suffix. Every other condition returns the same generic unavailable string. |
| Filters / queries | `includes/class-yoohw-cos-customer-query.php:43`, 110–121, 280–289: monetary filters fail closed while unready; monetary sort falls back to activity; monetary predicates require comparable/current version/current store currency. Classification queries use persisted marker/generation. |

No audited CIT extraction, aggregation or readiness rule assumes USD. Store-unit
threshold settings are currency-specific and are not exchange rates. Current tests
often use the default/current currency or USD/EUR as the alternate; that historic
coverage is not evidence of an intended USD-only product.

## Actual-site minimized evidence

Source files in `includes/`, `admin/`, `assets/`, `templates/` and the plugin
entrypoint on veeveestore.local matched baseline bytes. Installed CIT 1.4.2,
DB 0.2.4, WooCommerce 11.1.2, HPOS enabled, store VND, schema ready.

| Aggregate population | Count |
|---|---:|
| Customer `comparable/VND`, metrics 2, intelligence marker 1, with recognized orders | 950 |
| Customer `none/null`, metrics 2, intelligence marker 1, without recognized orders | 379 |
| Recognized facts, VND, policy 2 | 1,414 |
| Customers with exactly one valid recognized currency and no unknowns | 950 |
| Customers with multiple recognized currencies | 0 |
| Customers with any null/empty/invalid recognized fact currency | 0 |
| Pending/unresolved migration issues | 0 |
| Authoritative HPOS paid orders: completed / processing | 1,410 / 4 |
| Recognized facts differing from authoritative order currency / missing authoritative order | 0 / 0 |

First read: v3 `in_progress`, phase `rebuild_retries`, attempts 35, page 20,
scanned/processed/successful 1,994, processed customers 1,329,
last batch `2026-10-03 09:52:02` (site-stored timestamp). Its cron event existed
at Unix timestamp `1790995928`. Old v2 was completed, with processed/scanned
1,991, attempts 36, page 20, processed customers 1,329, zero pending/unresolved
issues. Identity normalization v2 was also completed. No stored last-error flag
was present in these migration records. Customer cursors existed; their values
are intentionally omitted because they are customer identifiers.

Later read: v3 `completed`, attempts 36, last batch and completion
`2026-10-03 09:54:03`; the populations above were unchanged, ledger still empty,
and no migration event remained. No audit command loaded WordPress/plugin code
or triggered a worker on this site. Normal site activity completed the final batch.
The initial marker values do not establish intelligence-generation freshness;
the report does not infer that from marker=1 alone.

**Classification: B — temporary global migration/backfill readiness**, with a
reproduced UX misrepresentation. A scheduled event alone would not prove progress;
the subsequent attempts/status/timestamp transition supplies that evidence.
There is no evidence here for genuine mixed currency, unknown currency, stale
customer metrics, unresolved ledger or permanent non-USD failure. The audit cannot
prove how or when the operator originally installed/upgraded CIT from these snapshots.

Production veevee.store discovery was limited to directory/version inspection,
configuration lookup without exposing credentials, and `SELECT`/schema reads.
WooCommerce 11.1.2, HPOS yes, VND, 1,424 completed + 6 processing source orders;
no CIT installation existed. Its CIT-specific classifications are not applicable.

## Synthetic reproduction and historical convergence

The supplemental [probe](cit-86-currency-probe.php), guarded
[runner](cit-86/run-audit.py) and minimized
[focused results](cit-86/focused-results.jsonl) are reproducible evidence.
Characterization assertions confirm current behavior, including defects; a passing
audit probe is not a claim that those defects are fixed.

### yoplay8 admin-renderer checks

Loaded exact baseline from the audit checkout while leaving the installed Local
plugin files unchanged. The installed beta runtime files also matched baseline
runtime bytes; metadata identity differed. WooCommerce loaded without unrelated
plugins; mail was intercepted, outgoing WordPress HTTP blocked, cron spawning disabled.
No integration bootstrap, Reset, install/update, whole-site sync or bulk worker ran.

Created and removed only two synthetic order/profile fixtures. Each currency used
123,456.78 with persisted version 2/comparable. For VND and EUR, completed readiness
rendered the correct currency amount in actual Profile and Customers commerce-column
renderers. Pending/in-progress/completed-with-issues all rendered the generic string
while recency and frequency stayed usable. Site number/decimal formatting was retained.
Currency, migration, intelligence generation/freshness and cron options were restored
and compared with their initial values; fixtures were removed.
[Minimized renderer results](cit-86/yoplay8-results.json) contain no existing-site data.
Synthetic Profile HTML was retained locally for inspection, not committed.

These are real PHP admin-renderer/HTML checks, not browser screenshots or visual layout
certification. Browser-control execution was unavailable in this session. The corrected
affected site's UI output is inferred from its exact source/state plus the reproduced
renderer, not from loading its wp-admin and risking background changes.

### Deterministic HPOS=yes/no matrix

WP 6.9 / WC 10.8.0 / PHP 8.4.18 / MySQL 8.0.35, private socket-only owned database,
scoped random user, synthetic data, early mail interception and HTTP block.

| Scenario | Both-mode observed result |
|---|---|
| Healthy VND / EUR, two same-currency orders | Net 200,000; AOV 100,000; count 2; comparable/persisted currency; Profile/list/RFM/Overview/providers agree on availability. |
| Partial refund 25,000 | Net 175,000; recognized count retained. |
| Same-status total 75,000 → 80,000 | Net 180,000. |
| Authorized link/epoch reassignment | Old customer net 100,000; new customer 80,000; no unlike-currency summation. Changing billing identity alone does not override the canonical CIT link. |
| Order deletion | New customer has no recognized orders; state none. |
| Foreign EUR customer in VND store | 123.45 EUR remains comparable/displayed with `(EUR)`; store threshold eligibility false. Store change to EUR then VND changes eligibility without converting amount. |
| Genuine EUR+VND customer | Mixed, numeric placeholders zero; UI unavailable, provider amounts null, Overview mixed; R/F retained. |
| Recognized fact currency NULL | Unknown; fail closed until source reconciliation. |
| No recognized orders | UI store-formatted zero; R unavailable/F zero; provider amounts null and state none. |
| Pending / in-progress without cron | Same generic unavailable label; normal runner init restores scheduled event. |
| Completed-with-issues without cron | Same generic label; init does not schedule terminal issue state. Explicit recovery is required. |
| Missing v3/current schema/legacy null fact | maybe_update registers pending and schedules; bounded batches converge to comparable VND. |
| Replayed worker from saved initial cursor | One recognized order, net 100,000; no duplicate contribution. |
| Global ready/customer metrics version 1 | Still unknown/unavailable after ordinary rebuild; missing v3 not admitted from customer-only staleness. |
| Ready monetary classification → missing-v3 recovery pending | Persisted top-customer classification and high-value filter membership survive while live calculation returns none and amount comparability is false. |

The full existing integration suite separately covers genuine migration retries,
old v2 stale/unresolved ledger, identityless noncontributing order, interrupted
intelligence refresh, current-schema registration, full refund/status changes,
Reset boundary and cross-request currency invalidation. Those existing tests do not
form a full VND×every-failure cross-product; the new explicit VND/EUR probes supply
the non-USD evidence for the matrix above.

### Immutable historical tag fixtures

Historical sources were extracted with `git archive` from these exact tag commits,
not simulated solely by editing the schema version. In separate processes the old
runtime/schema created VND orders of 125,000 + 75,000; current runtime then upgraded
the same owned database and ran bounded migrations. Ordinary WordPress loading was
used during upgrade to preserve CPT orders; the test-library bootstrap deletes
all posts even with skip-install enabled and would invalidate a legacy upgrade test.

| Origin tag / exact commit | Origin DB | HPOS=yes / no → current |
|---|---|---|
| 1.2.2 / `4db849bf93d7c3505b02fab123e8f139935f0b47` | 0.1.10, pre-currency schema | PASS / PASS |
| 1.3.0 / `fed2c8ec4010d01a24a3d69bbb6d9929b2d0ccc1` | 0.2.1 | PASS / PASS |
| 1.4.0 / `1ac1b8c4ab6e6109ea7746b204efc50caf3b5e1f` | 0.2.4 | PASS / PASS |
| 1.4.1 / `0ac831c622b6ecf20323deb4bb88d0b23d2649db` | 0.2.4 | PASS / PASS |

Every final upgraded case has one profile, two
recognized VND orders, net 200,000, state comparable, global ready, schema 0.2.4,
and actual storage mode equals requested mode.
[Historical process results](cit-86/historical-results.jsonl) retain both seed
and upgraded state. This is focused currency convergence evidence, not certification
of every old-version feature or all declared platform combinations.

## Distinct failures currently collapsed by display

| Condition | Actual scope/action | Current display effect |
|---|---|---|
| Genuine mixed recognized currencies | Customer; preserve each source currency, no FX | Generic mixed-or-unknown |
| NULL/empty source currency; invalid/missing recorded currency | Customer/source; inspect/reconcile actual order | Same |
| Missing registration with incomplete v2 fallback | Site recovery admission | Same |
| Pending / in-progress migration | Site; await verified progress | Same |
| Unscheduled / overdue/stalled worker | Site; inspect scheduler/progress, do not pretend it is customer mixed | Same while readiness remains false |
| Completed-with-issues / unresolved or stale ledger | Site recovery; resolve/retry actual source failures | Same via terminal status; predicate itself does not read ledger |
| Metrics version stale / aggregate unknown despite valid facts | Customer; bounded trusted recovery | Same despite globally ready site |
| Old DB version | Schema/site; verified supported upgrade | Same |
| Schema mismatch with current version | Schema can prevent migration, but display predicate does not independently inspect schema | Often same indirectly; not a guaranteed direct gate |
| Fresh installation before historic commerce import | Site convergence | Same, even for already-comparable partial customer |
| Reset boundary | Operation deferral and reconstruction; missing migrations after Reset can be legitimate | Indirect according to remaining facts/state; no separate Reset reason in formatter |
| Store-currency change | Amount stays in recorded currency; decisions invalidate and eligibility changes | Does not by itself produce unavailable; do not label as source mixed |

## Surface inventory and discrepancies

| Surface | Current behavior / evidence | Required follow-up presentation |
|---|---|---|
| Customers/RFM M | `admin/class-yoohw-cos-customers-list.php:414`, RFM:38; renderer matrix confirms generic M string and usable R/F | Concise state, one site notice, reason tooltip/link |
| Profile KPIs | `admin/class-yoohw-cos-customer-profile.php:150`; same formatter | Same canonical availability; preserve Orders/dates |
| Overview revenue/AOV | `includes/class-yoohw-cos-overview.php:18–67`; sum only when purchasing customers share a trustworthy currency; otherwise state unknown/mixed and numeric zero placeholders gated by formatter | Explain store-summary scope, including different currencies across individually-comparable customers |
| Saved Views / monetary filters | Query:43,280; saved-views:11; false readiness makes WHERE 1=0; monetary sort silently falls back; UI remains usable | Explicit unsupported/disabled money filter + reason, keep R/F; show saved-view monetary condition cannot be evaluated |
| Monetary intelligence / attention | Intelligence:26,43,70,554; stored marker/generation versus live monetary gate; monetary lifetime explanation also blames mixed/unknown for readiness and none | Freshness gate consistent across getters/SQL/counts; truthful explanation; order/contact/task-only signals remain |
| CSV | `admin/class-yoohw-cos-customer-exporter.php:110–134`; blanks unavailable amounts/currency; unready coerces state unknown; none amounts blank while UI shows zero | Keep machine-null vs zero explicit; separate customer state and effective availability/reason/currency |
| Personal-data export | `includes/class-yoohw-cos-privacy-exporter.php:241,270`; amount fail closed; state/currency raw fields can say comparable while amount is globally unavailable; none text says no recognized orders | Label recorded state separately from effective reason; no raw internal/third-party data |
| WooCommerce order context | `admin/class-yoohw-cos-order-admin.php:482`; same customer formatter | Same short availability and Diagnostics link |
| Diagnostics / Store setup | `includes/class-yoohw-cos-diagnostics.php:25,143–190`; none counted in unknown, counts validate nonempty rather than currency shape; current UI “Converging” can include attention | Separate none, source quality, progress/failure and intelligence freshness; actionable site-level state |
| Extension facts | `includes/class-yoohw-cos-customer-facts.php:14–29`; comparable/none/unavailable; amounts null when unready; loses mixed/readiness distinction | Additive versioned reason fields; preserve existing keys/consumer compatibility |

CSV streaming route and privacy/order-context UI were source-audited; the supplemental
probe executes provider, Profile/list/RFM/Overview and query paths. Existing suite
coverage is additional assurance, not a claim that every export route was manually
clicked on the real site. No unlike currencies were normalized. The UI's trustworthy
none branch currently checks global readiness but not metrics version; a follow-up
must verify no-order/trust before selecting zero, not copy that shortcut blindly.

## Root-cause findings and bounds

1. **Actual symptom: global readiness + UX defect.** Site B converged naturally.
   It has valid same-currency VND data, not mixed/unknown customer money. Hiding
   partial-import totals is correct fail-closed behavior; explaining it as customer
   mixed/unknown is materially misleading, and filters give misleading empty results.
2. **Reproduced recovery gap (customer-only staleness).** Current schema, current
   policy-2 valid VND facts, v2 complete, v3 absent, customer version1/unknown:
   registration never admits v3; default rebuild retains version1/untrusted state.
   This is a seeded valid recovery boundary, not observed on the affected site and
   not proof that ordinary tested upgrade automatically creates it. v3 already
   complete likewise does not re-admit customer-only recovery via maybe_update.
3. **Reproduced freshness inconsistency when recovery starts.** Start from naturally
   calculated top-customer/ready-marker/current generation using VND source orders.
   Introduce a legacy fact, call maybe_update, register pending v3. Generation is
   unchanged; getter preserves top-customer and SQL high-value filter returns one,
   although money comparability is false and live VIP calculation returns none.
   Registration should invalidate or gate those persisted monetary decisions immediately.
   The fault-injected fact establishes a defensive recovery defect; production provenance
   is not inferred. Completion eventually invalidates the generation.
4. **Terminal issue recovery remains operationally distinct.** Existing v3
   completed-with-issues is not auto-scheduled, even when a stale ledger may no longer
   reflect a real failure. Reconciliation can finish it after verified ledger resolution;
   do not simply force its status complete. Diagnostics must communicate recovery action.

These findings are a combination of UX defect and reproduced recovery/freshness
correctness gaps. They are not evidence of a generic non-USD arithmetic bug.

## Recommended canonical availability seam (proposal only)

Add a read-only result in the existing commerce policy, consuming a once-per-request
site currency/readiness snapshot. Preserve existing persisted enums/schema initially:

```text
customer_state: comparable | none | mixed | unknown
site_state: ready | preparing | attention
reason: comparable | none | mixed | unknown_source_currency
        | preparing_currency_data | currency_data_attention | metrics_stale
currency: persisted currency or null
amount: numeric only when verified comparable; explicit zero for trustworthy none
matches_store_currency: boolean, independent of display availability
intelligence_fresh: boolean, independent of amount comparability
action: optional authorized Diagnostics/recovery URL
```

Compute customer state/trust independently; site attention/preparing wins the effective
display reason while genuine mixed/unknown remains retained in customer_state. Stale
metrics precede claims about unknown source currency. Never infer completion from
non-null facts, marker=1, an event existing, or absence of a v3 record alone. Do not
mark mixed/unknown comparable to repair UX. Store-currency mismatch is threshold
ineligibility, not amount unavailability. No FX conversion is introduced.

For progress/stall determination use schema, active status, last progress/attempts,
dependency availability, overdue/missing events and ledger counts. A single snapshot
cannot prove a stalled worker; Diagnostics can identify attention and explain the
observed evidence without pretending it knows a permanent failure.

| State / surface | Proposed copy and interaction |
|---|---|
| Comparable | Existing formatted amount in recorded currency; retain explicit foreign code |
| None | “No recognized orders”; optional explicit zero where useful, not a generic error |
| Mixed | “Multiple currencies”; tooltip: amounts are not combined because no currency conversion is performed |
| Unknown source | “Order currency unavailable”; concise source-data explanation |
| Preparing | “Preparing currency data…”; one global notice, short row dash/label and Diagnostics link |
| Attention | “Currency data needs attention”; one actionable site notice with role-safe Diagnostics/recovery link |
| Stale metrics | “Updating monetary data…” only when scheduled/progress is evidenced; otherwise attention/action, not an indefinite promise |
| Overview | Reason scoped to entire summary; e.g. revenue cannot combine different recorded currencies |
| Filters / Saved Views | Disabled monetary evaluation with canonical reason; never unexplained empty results; R/F remain available |
| CSV / personal export | Recorded customer state, effective availability/reason and currency in distinct fields; null unavailable amounts; document none as explicit no-orders state |

Saved Views currently retain numeric thresholds but no bound currency identity; after
store currency changes they reinterpret the number in current store units. Follow-up
must preserve/document this existing dynamic contract or separately seek approval for
currency-bound thresholds. It is not silently redesigned in this audit.

## Smallest follow-up implementation boundary

- Fix bounded customer-only recovery admission and pending-transition freshness in
  migration-runner/commerce-aggregates/intelligence/query paths; retain schema/Reset/
  privacy/source trust and connection ownership. Verify concrete issue-ledger recovery
  rather than setting completion from a display condition.
- Add the policy availability result and read-only migration/diagnostics snapshot;
  make formatter, RFM, Profile/list/order context and Overview consume it consistently.
- Expose explicit monetary-query readiness to list/Saved View UX; retain fail-closed SQL.
- Keep CSV/privacy exports machine-truthful with recorded state/effective reason;
  add provider reason fields compatibly. Correct diagnostics none/unknown/freshness counts.

Expected files: commerce-metrics-policy, migration-runner, commerce-aggregates,
intelligence, customer-query, diagnostics, overview, rfm, customer-facts,
privacy-exporter, customer-exporter, customers-list, customer-profile, order-admin
and admin-menu as needed for the one site notice/Diagnostics. A follow-up should avoid
new schema, release metadata, FX, premium behavior or unrelated layout redesign unless
separately admitted. None of this proposal is implemented by CIT-86.

Required follow-up regression: every matrix row above in HPOS/CPT; explicit VND and
two-decimal EUR; refund/full refund, reassignment/link epoch, same-status edit/delete;
each historical tag; old ledger/identityless/batch replay/interruption; v3 missing
and completed with customer-only stale metrics; natural ready monetary classification
then registration pending across getter/query/count/Overview; source unknown/mixed;
none/version trust; store change and cross-request cache/generation; foreign currency
display versus threshold eligibility; truthful reason parity in all consumers and
safe null/zero export contracts; browser QA on synthetic yoplay8 when control is available.

## Validation, reproducibility and safety

The unchanged full required runner is executed independently from supplemental probes:

```sh
composer install --no-plugins --no-scripts --no-interaction
python3 scripts/test-isolated.py --mysql-bin "$CIT86_MYSQL_BIN" --php "$CIT86_PHP"
python3 docs/evidence/cit-86/run-audit.py --kind focused --output-dir "$CIT86_OUTPUT" \
  --mysql-bin "$CIT86_MYSQL_BIN" --php "$CIT86_PHP"
python3 docs/evidence/cit-86/run-audit.py --kind historical --output-dir "$CIT86_OUTPUT" \
  --mysql-bin "$CIT86_MYSQL_BIN" --php "$CIT86_PHP"
```

Optional `--inputs` uses the canonical archive checksum pins. Git tag refs must be
present for the historical probe; checkout source remains read-only. Output directories
hold minimized synthetic JSON only. The supplemental runner selects its own temporary
PHPUnit config and preserves the provisioner's ownership, rejection controls, sentinel,
mail/HTTP boundaries and cleanup. It never accepts an existing site. Between supplemental
modes it drops only `wptests_*` tables in its guard-verified private DB to prevent leftover
synthetic benchmark rows affecting the next scenario; the ownership/sentinel is preserved.

Final local supplemental results: four focused cases in each mode (58 assertions per
mode), both smoke benchmarks; all four tag upgrade origins in both modes plus both large
synthetic benchmarks. All controls/sentinels/owned-process/filesystem cleanup pass.
The unchanged full baseline suite passed **256 tests / 5,004 assertions per mode**,
both smoke benchmarks, 68 rejection controls and owned cleanup. Candidate native CI
results are recorded with exact head/base in the PR; the report does not manufacture a self-referential candidate SHA or claim
Technical Review/Acceptance before their external evidence exists.

Early audit-fixture attempts failed due to missing synthetic user capability, protected
link epoch, leftover custom-table fixtures, and the test library deleting CPT posts.
Those attempts are not product regressions and are excluded from PASS evidence. Final
guarded reproducible helpers correct fixture setup/loading/cleanup without changing
runtime or the canonical test runner. A TLS trust-store failure fetching archives was
resolved with TLS-verifying curl plus canonical SHA-256 verification, not disabled TLS.

No production plugin/file/option/data write, migration/cron trigger, Reset, sync,
recalculation, install/update, currency change, release/tag/publication or deployment was
performed **by CIT-86 audit execution**. The earlier production release workflow's
tag/SVN mutations are recorded separately above. Affected Local site investigation
also remained read-only. yoplay8 mutations
were limited to admitted synthetic fixtures/options with verified restoration. Harness
resources were privately owned and cleaned. No credentials, host configuration, real
customer identifiers/contact data, order payloads, dumps or third-party payloads are
included. Only evidence/probes under `docs/evidence/` change in this candidate; product
runtime, schema, migration implementation and release control plane remain baseline bytes.
