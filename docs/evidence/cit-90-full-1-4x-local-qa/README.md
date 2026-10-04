# CIT-90 — full 1.4.x local QA preflight

**Disposition: `FULL_1_4X_LOCAL_QA_BLOCKED`.**

The required existing-site source gate failed before browser testing. This report is
an environment-blocked audit, not functional acceptance of the 1.4.x product. It does
not claim that the unexecuted capabilities pass or contain product defects.

Issue: [#90](https://github.com/yoohwz/yoohw-customer-intelligence/issues/90).
Admitted/current protected main: `f44ff6b22eb799cc934e732a70f1fc852f08db99`.
Preflight date: 2026-10-04. The audit worktree started exactly at this main SHA; its
only additions are evidence and a read-only source comparison helper. The runtime
intended for the audit is this main tree. No exact-main browser scenario was executed.

## Stop condition and reproduction

Issue #90 requires: "If yoplay8.local is not actually running the intended source,
**stop** and report rather than copying code into an unrelated site or silently
switching environments."

Persisted `active_plugins` includes the standard Customer Intelligence entrypoint.
Its installed source header is **1.4.2-beta.1**, while admitted main declares **1.4.2**.
Comparison of all 62 tracked runtime/resource files under the main entrypoint,
`includes`, `admin`, `assets`, `templates` and `languages` found:

- 45 matching files;
- 15 different files, including the entrypoint, commerce metrics policy and migration runner;
- 2 missing files: `admin/class-yoohw-cos-notice-preferences.php` and
  `assets/js/notice-preferences.js`.

This is a material source mismatch, not just an unverified version label. In particular,
the current-main notice preference implementation is absent from the installed tree.
[Minimized comparison evidence](source-identity.json) contains file identities and
SHA-256 hashes, without local paths, customer data, database credentials or dumps.
The inspected MU-plugin sources contained no Customer Intelligence candidate routing.
No HTTP/runtime reflection was performed; persisted activation plus the installed
source comparison establishes the failed preflight, not certification of every
possible plugin filter or effective runtime class origin.

Reproduce from this repository using the actual yoplay8 installed plugin directory:

```sh
python3 docs/evidence/cit-90-full-1-4x-local-qa/probe-source.py --plugin-root <yoplay8-installed-CIT-directory>
```

The helper reads files and Git objects only. It never loads WordPress, changes code,
reads credentials, queries a database or performs migration/Reset/Sync.

## Environment inventory

The existing Local site was inspected through files, Local service metadata and a
single SELECT of fixed customer-independent WordPress options. WordPress was not
bootstrapped and no customer/order/user rows were read.

| Field | Observed evidence | Limit |
| --- | --- | --- |
| Site | `https://yoplay8.local` in home/siteurl | No browser/HTTP acceptance |
| WordPress | 7.1.2 in `wp-includes/version.php` | Source identity, not runtime reflection |
| WooCommerce | 11.1.2 stored in `woocommerce_version` | Stored version, not runtime reflection |
| PHP | Local service configured 8.4.18 | Active HTTP runtime not verified |
| MySQL | Local service configured 8.0.35 | No server-version probe |
| HPOS | `woocommerce_custom_orders_table_enabled = yes` | Option, not active datastore assertion |
| CIT plugin | Installed header 1.4.2-beta.1; main header 1.4.2 | Source gate failed |
| CIT DB | Stored `yoohw_cos_db_version = 0.2.4`; main constant 0.2.4 | Actual schema/ledger readiness NOT_RUN |
| Activation | Standard CIT entrypoint in `active_plugins` | No runtime filter certification |

Related activation inventory: WooCommerce, Blacklist Manager Core, Blacklist Manager
Premium, Loyalty, Yoro AI Core and the Local audit snapshot plugin are active by
persisted configuration. Licenses, provider availability, conditional integration UI
and external services were **NOT_RUN**. No plugins, licenses or accounts were changed.
Other unrelated active plugin identities are omitted from the evidence.

## Recovery strategy and safety

No live-site scenario was started after the source mismatch. No recovery snapshot
was created, because no stateful/destructive operation was admitted past the gate.
A verified recovery point is still required before future Local stateful/destructive
scenarios. If exact shared-site restoration cannot be guaranteed, Issue #90 directs
those backend scenarios to the owned isolated harness and requires truthful limits
on the corresponding non-destructive browser surface.

No WordPress bootstrap, browser login, test users, customer/order fixtures, routing,
currency/cron options, emails, HTTP requests, Sync, Reset, privacy requests, migrations
or license operations were performed on yoplay8 by this audit. Database access was
SELECT-only and limited to activation, site identity, versions and the HPOS option.
No production/staging access occurred.

## Complete feature inventory and execution matrix

All rows below are **NOT_RUN — E1 source gate failure**. Each row maps the admitted
material feature family to the checks to execute after the environment is aligned.
The stop condition prevents attributing browser/persistence outcomes from the old
installed code to current main. No prior CIT-86/CIT-88 test or screenshot is reused
as CIT-90 exact-main acceptance.

| Origin | Feature / required flow | Browser and persisted checks deferred |
| --- | --- | --- |
| 1.4.0 | Personal Saved Customer Views | Create/save/reopen/update/rename/delete; active/dirty state; pagination/search/filter restoration; two-user isolation; malformed/unavailable filters; active-view CSV; back/forward/reload; distinction from Dynamic Smart Segments |
| 1.4.0 | Retention/attention quick views | First-time/repeat/inactive/missing contact/open/overdue/high-value; count/list parity, factual reasons, empty copy, no implied automation |
| 1.4.0 | Explainable RFM | R/F/M on Customers, Profile, Saved Views, Overview/query; VND/EUR/foreign/mixed/unknown/none/preparing/attention/stale; useful R/F while M unavailable |
| 1.4.0 | Customers Option D | Heading/search alignment; Saved Views; quick/status strip; filter disclosure; bulk controls; RFM/amounts; pagination/row links; CSV; no duplicated/lost controls |
| 1.4.0 | Profile Option A | Action-first reading order, compact KPIs/attention/tasks/orders/notes/activity/side column; Back/Call/Email/task actions; tags/static segments/copy/order links/WP relationship; address/acquisition/security disclosure |
| 1.4.0 | Commerce lifecycle | Paid population, totals/AOV, partial/full refund, same-status changes, reassignment, delete/recreate; persisted facts/aggregates; foreign units, mixed/unknown fail-closed, no FX, current-store thresholds |
| 1.4.0 | Personal data export | Real WP request/subject matching; bounded pages; profile/notes/tasks/activity/tags/segments/Saved Views; monetary fields and safe payload limits |
| 1.4.0 | Personal data erasure | Real WP request and resumable removal; retain WooCommerce orders/WP users; suppression receipt, resync rejection and intentional recovery guidance |
| 1.4.0 | Diagnostics 2.0 | Live schema/profile/fact/sync/migration/currency/stale/cron/issues/none/mixed/unknown and links; truthful eventual freshness |
| 1.4.0 | Performance / Free extensions | Browser latency/N+1 symptoms; owned large benchmark; fully functional Free without provider; safe existing facts/query/action descriptors only |
| 1.4.1 | Reset/recovery lifecycle | Read-side/no false incident, retry contention, interrupted Reset fail-closed, manual replay distinction, resolution/new incident/stale revision handling |
| 1.4.1 | CRM email redesign | Assignment/reassignment/due soon/completed/reopened/overdue/escalation/daily summary; intercepted HTML/plain, WooCommerce shell/sender/settings, next action and Local links; Customer Message block compatibility |
| 1.4.2 | Source-aware Operational recovery | CIT legacy/Core/Premium/Loyalty where available; automatic retry/backfill/manual replay; source/event/count/guidance/links, coexistence, bounded storage, expiry/revision and no callback payload |
| 1.4.2 | Acknowledge after recovery | Acknowledge performs no recovery; exact revision; stale cannot remove newer; handled stays gone, new independently visible |
| 1.4.2 | Flash lifecycle | One-shot CIT-owned routes, unrelated query parameters untouched, correct severity, no repeated order-admin conflict stacking |
| Post-1.4.2 | Canonical availability / continuity | All seven reasons across Customers/RFM/Profile/Overview/Saved Views/filter/sort/CSV/privacy/order/Diagnostics/provider facts |
| Post-1.4.2 | Automatic migration UX | Automatic progress/attention distinction; progress destination; no misleading manual Sync instruction; natural resolution |
| Post-1.4.2 | Per-user native dismissal | Two admins; native close/reload/navigation; preparing-to-attention/new revisions; stale replay/nonce/capability/key/cross-user failures; bounded storage; Diagnostics retained; presentation-only; Reset non-dismissible |
| Post-1.4.2 | Bounded stale recovery | Clean negative completion without restart/UX churn; stale discovery/admission/convergence; progress/attention matches actual worker |
| Preserved core | Existing operations | Overview/search/direct email/notes/tasks/tags/static segments/order context/profile link/Sync Center/recalculation/first-order backfill/HPOS/Reset/privacy/activity/email-settings shortcut |
| Conditional | Loyalty | Real availability/level/points/activity/settings/task automation/recovery links; hidden coherent Free when absent |
| Conditional | Blacklist Core/Premium | Real availability/status/security/risk/profile/activity/maintenance/recovery links; Premium conditions; hidden coherent Free when absent |

## Browser, responsive, accessibility and UX matrix

| Required evidence | Status / reason |
| --- | --- |
| Real wp-admin major screens and flows | NOT_RUN — E1 |
| 1440 / 1280 / 768 / 390 viewport matrix | NOT_RUN — E1 |
| Table scrolling versus page-level overflow, wrapping/clipping/overlap/dropdowns/notice stacking | NOT_RUN — E1 |
| Tab order, visible focus, Enter/Space/details, names/labels, notice dismissal, row/link/control interaction | NOT_RUN — E1 |
| Information hierarchy/action grouping/duplication/disclosure | NOT_RUN — E1 |
| Native WordPress/WooCommerce patterns, copy and cross-screen continuity | NOT_RUN — E1 |
| Empty/loading/success/partial/attention/blocked/validation/unavailable/resolved states | NOT_RUN — E1 |
| Screenshot/video evidence | NOT_RUN — no screen was tested; none fabricated |

## Backend, email, notice and upgrade matrix

| Evidence layer | Status / reason |
| --- | --- |
| Browser-linked synthetic persistence | NOT_RUN — E1; no live fixtures |
| Local destructive/privacy/suppression/recovery restoration | NOT_RUN — E1; no snapshot/scenarios |
| Captured eight-state HTML/plain email render matrix and common widths | NOT_RUN — E1; no delivery/capture attempted |
| Live notice/flash/recovery/acknowledgment/dismissal/security matrix | NOT_RUN — E1 |
| Integration-conditional live display and provider matrix | NOT_RUN — E1; activation inventory only |
| Local responsiveness / owned large-data benchmark | NOT_RUN — audit stopped at E1 |
| Supplemental immutable-origin 1.3.0 / 1.4.0 / 1.4.1 / public 1.4.2 upgrades, HPOS=yes/no | NOT_RUN — audit stopped at E1; prior task evidence is not this audit |
| Audit-specific owned destructive/concurrency/backend/security scenarios, HPOS=yes/no | NOT_RUN — audit stopped at E1 |

The evidence PR still requires the repository's full native HPOS=yes/no suite,
guard/sentinel/cleanup checks and `YCI Required CI`, plus fresh independent review.
Their exact candidate, outcomes and durable links belong to the PR record. Those
repository checks validate the unchanged runtime/evidence candidate; they do not
convert any NOT_RUN Local audit item, large benchmark or historical matrix into PASS.

## Findings, restoration and next action

**E1 — environment blocker, not a product defect.** Intended main is absent from the
active configured installed plugin tree. Expected: exact main source before testing.
Actual: 15 differing and 2 missing runtime/resource files, beta plugin identity.
Evidence: `source-identity.json`; source helper reproduction above. E1 blocks this
local audit and prevents a release-readiness conclusion. No P0/P1/P2 product defect
was established because product scenarios were not run; no product fix/follow-up Issue
was fabricated. This report is the bounded Issue #90 blocker record.

Post-preflight source comparison was repeated and matched the first result exactly.
No Local state was mutated, so no fixture removal or state restoration was necessary.
This is proof of this audit's read-only boundary, not a recovery-point certification.
No raw customer/site content, credentials or database dump enters this directory.

To resume, the Human must arrange for yoplay8 to use the exact admitted main under an
explicitly approved installation/routing boundary, then run `Run CIT-90` again.
Reverify origin/main, source identity and real runtime versions/HPOS; establish the
recovery point before scenarios. If protected main changes, resolve the new baseline
against the Issue before attributing evidence. This audit did not copy/install code,
switch environments, downgrade the site, fix runtime, bump a version, merge or release.

Final disposition: **`FULL_1_4X_LOCAL_QA_BLOCKED`**.
