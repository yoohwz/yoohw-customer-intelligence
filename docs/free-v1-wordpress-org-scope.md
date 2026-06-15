# YoOhw Customer Intelligence for WooCommerce Free v1.0 Product Scope

This document freezes the Free v1.0 product scope for YoOhw Customer Intelligence for WooCommerce before the WordPress.org release candidate phase.

## Product Decision

YoOhw Customer Intelligence for WooCommerce Free v1.0 will be a complete, useful WooCommerce customer intelligence and lightweight customer operations plugin that can stand on its own in the WordPress.org plugin directory.

Free v1.0 should not be rushed to release just because the current package can pass the basic WordPress.org checks. It must first provide a stable, coherent operating workspace for WooCommerce merchants.

Premium functionality must live in a separate premium add-on plugin that requires the free core. The free plugin must not include locked premium code, trialware behavior, hidden quotas, or aggressive upsells.

## Positioning

YoOhw Customer Intelligence for WooCommerce Free is a lightweight Customer Intelligence and Customer Operations workspace for WooCommerce stores that need a better customer list, customer profile, activity history, notes, tags, static segments, basic customer scoring, manual follow-ups, safe order sync/backfill tools, export, diagnostics, and an understandable onboarding flow.

The free version targets store owners and operators who have outgrown the default WooCommerce customer view but do not yet need automation, AI, cross-store intelligence, dynamic workflows, or enterprise collaboration.

## Non-Negotiable Constraints

- Prefix: `yoohw_cos_`
- Class prefix: `YoOhw_COS_`
- Text domain: `yoohw-customer-intelligence`
- WooCommerce is a required dependency.
- HPOS compatibility must be declared and tested.
- WooCommerce order reads and writes must use WooCommerce CRUD/order APIs unless a direct query is explicitly proven HPOS-safe.
- Customer Intelligence CRM data must use custom database tables, not `wp_usermeta` as the primary store.
- Free version must remain genuinely useful without Premium.
- Premium upsell must be limited, contextual, and never block core free workflows.
- Backward compatibility must be preserved for existing custom tables and saved data.

## Free v1.0 In Scope

### 1. Install, Dependency, And Compatibility

Must ship:

- Activation creates all eight Customer Intelligence tables.
- Migration/versioning can repair missing columns and indexes.
- WooCommerce dependency guard displays a clear admin notice when WooCommerce is inactive.
- HPOS compatibility is declared in the main plugin file.
- Plugin headers are ready for WordPress.org and WooCommerce compatibility metadata.
- Plugin loads only its admin assets on Customer Intelligence screens.

Done when:

- New install creates all expected tables.
- Existing install upgrades without losing data.
- WooCommerce inactive state does not fatal.
- HPOS enabled store can sync and view orders without direct postmeta assumptions.

### 2. Customer Database Core

Must ship:

- `yoohw_cos_customers`
- `yoohw_cos_events`
- `yoohw_cos_notes`
- `yoohw_cos_tasks`
- `yoohw_cos_tags`
- `yoohw_cos_customer_tags`
- `yoohw_cos_segments`
- `yoohw_cos_customer_segments`

Must support:

- Customer lookup by user ID, email, or phone.
- Stored customer metrics: total orders, total spent, AOV, first order, last order, last activity.
- Stored intelligence fields: customer status, lifecycle stage, risk score, trust score, VIP status.
- Relationship tables for tags and static segments.
- Manual task/follow-up records for customer operations.

Out of scope for Free v1.0:

- Cross-store customer identity.
- Device/session identity.
- Predictive identity matching.

### 3. Customers List

Must ship:

- WordPress-style list table.
- Search by name, email, phone, and customer identifiers.
- `subsubsub` status filters.
- Single practical filter row for tag, segment, status, VIP, risk, and lifecycle.
- Sortable key metrics where supported.
- Screen Options-compatible hidden columns.
- Batched tag/segment prefetch to avoid per-row relationship queries.
- Bulk assign/remove tags and segments.

Done when:

- List remains usable with many customers.
- Filters preserve pagination and search state.
- Bulk actions validate selected customers and selected relationship IDs.

### 4. Customer Profile

Must ship:

- Profile header with identity and admin links.
- Summary KPI cards: total spent, order count, AOV, risk, trust.
- Commerce summary.
- Identity, billing, shipping, and acquisition panels.
- Recent orders using WooCommerce order APIs.
- Customer intelligence panels for risk, trust, and lifecycle.
- Notes, tags, and static segments.
- Activity timeline with link to full activity page.

Done when:

- Missing values render cleanly.
- Invalid dates and zero dates render as empty states.
- Copy buttons, note edit, and confirmations work without inline scripts.

### 5. Activity Timeline

Must ship:

- Events table stores customer activity.
- Order sync creates order-synced events without duplicates.
- Tag, segment, note, and bulk operations create meaningful events.
- Activity admin page supports severity, event type, source, customer, search, and pagination filters.
- Order object links use WooCommerce edit order URLs.

Out of scope for Free v1.0:

- Event streaming.
- External event ingestion API.
- Workflow execution logs beyond basic internal events.

### 6. Notes

Must ship:

- Add, edit, and delete internal customer notes.
- Note author, created date, updated date.
- Capability checks, nonces, ownership/integrity checks.
- Activity events for note actions where useful.

Out of scope for Free v1.0:

- Shared inbox.
- Customer-visible notes.

### 7. Tasks / Follow-ups

Must ship:

- Custom table `yoohw_cos_tasks`.
- Manual task/follow-up creation for a customer.
- Optional WooCommerce order reference.
- Title, description, due date, priority, status, assigned user, creator, and completion metadata.
- Customer profile task block.
- WordPress-style Tasks list table.
- Filters for open, overdue, completed, and assigned-to-me tasks.
- Actions to create, edit, complete, reopen, and delete tasks.
- Activity events for task creation, completion, reopening, and deletion where useful.

Done when:

- Task data is stored in the custom table, not `wp_usermeta`.
- Task actions validate customer, order, user, capability, nonce, and ownership/integrity constraints.
- Overdue/open states are computed consistently.
- Customer reset clears task records or clearly preserves them if retention policy changes later.

Out of scope for Free v1.0:

- Auto-created tasks.
- Recurring tasks.
- SLA rules.
- Notifications.
- Kanban/calendar views.
- AI-suggested next actions.
- Workflow-driven task creation.

### 8. Tags

Must ship:

- WordPress taxonomy-style admin page.
- Create, update, delete, and bulk delete tags.
- Slug uniqueness.
- Description and optional color.
- Assignment from profile and customer list bulk actions.
- Prevent accidental deletion when assigned unless force delete is explicitly requested.

Out of scope for Free v1.0:

- Tag automation.
- Tag import/export.
- Tag-based email sync.

### 9. Static Segments

Must ship:

- WordPress taxonomy-style admin page.
- Create, update, delete, and bulk delete static segments.
- Assignment from profile and customer list bulk actions.
- Prevent accidental deletion when assigned unless force delete is explicitly requested.

Out of scope for Free v1.0:

- Dynamic smart segments.
- Rule builder.
- Scheduled segment rebuilding.
- Segment performance analytics.

### 10. Customer Intelligence Lite

Must ship:

- Rule-based customer status.
- Rule-based VIP levels.
- Rule-based risk score and risk level.
- Rule-based trust score.
- Rule-based lifecycle stage.
- Manual/batched intelligence recalculation.
- Human-readable factors in the customer profile.

Out of scope for Free v1.0:

- Custom scoring rules UI.
- Predictive churn model.
- RFM cohorts.
- AI-generated recommendations.
- Score history charts.

### 11. Onboarding, Sync Center, And Maintenance Tools

Must ship:

- First-run onboarding/setup surface.
- WooCommerce, HPOS, database, and sync readiness status.
- Clear CTA to sync existing WooCommerce orders.
- Sync existing orders in batches.
- Sync Center with last sync state, batch size, processed counts, completion notices, and resume state.
- Backfill first order data in batches.
- Recalculate intelligence in batches.
- Reset Customer Intelligence data with clear wording and confirmation.
- Admin notices for processed counts, completion state, and errors.
- Batch operations must avoid unlimited object scans.

Done when:

- Sync can be resumed page by page.
- Auto-continue mode uses delegated JavaScript and does not rely on inline handlers.
- Reset behavior matches the wording and clears relationship data consistently.
- New users can understand whether the plugin has data and what action to take next.

### 12. Dashboard Overview, Export, And Diagnostics

Must ship:

- Free Dashboard Overview for customer totals, order/revenue summary, VIP, at-risk, inactive, lifecycle, risk, recent activity, and task/follow-up counts.
- CSV export for the current customer filter set.
- Export fields for customer identity, contact data, order metrics, intelligence fields, tags, and segments.
- Diagnostics/Health panel for table existence, DB version, WooCommerce active state, HPOS status, sync state, and orphaned relationships.
- Copyable diagnostics report for support.

Deferred to Free v1.1 or Premium:

- Scheduled reports.
- Cohort analysis.
- Revenue-at-risk dashboards.
- Large asynchronous export jobs.
- Advanced diagnostic repair tools.

### 13. Customer Profile Polish

Must ship:

- Customer profile layout optimized around real merchant workflows.
- Summary, commerce metrics, tags/segments, notes, tasks/follow-ups, recent orders, and activity timeline in a clean scan order.
- Empty states for notes, tags, segments, tasks, and activity.
- Inline management where it remains simple and safe.

Done when:

- A merchant can understand a customer's status and next action without leaving the profile.
- Profile sections follow WordPress/WooCommerce admin patterns and remain readable at common admin widths.

### 14. Admin UI Quality

Must ship:

- UI follows WordPress and WooCommerce admin conventions.
- No custom marketing-style dashboard in Free v1.0.
- No nested decorative cards.
- List tables, taxonomy-style pages, postboxes, notices, and controls should feel native.
- Admin JavaScript is centralized in `assets/js/admin.js`.
- Admin CSS is scoped to plugin screens.

Done when:

- The admin visual smoke checklist passes.
- No controls overlap at common WordPress admin widths.
- There are no console errors in supported admin flows.

## Explicitly Out Of Scope For Free v1.0

These features belong in Premium or later phases and must not block Free v1.0:

- Automation Engine.
- Dynamic Smart Segments.
- Workflow builder.
- AI Integration / YoRo.
- Device Identity.
- Cross-store Intelligence.
- External SaaS synchronization.
- Predictive churn and CLV models.
- Advanced revenue intelligence dashboards.
- Email marketing integrations.
- Zapier/Make/webhook automation.
- Scheduled exports/reports.
- Automated team tasks and reminders.
- Multi-role collaboration tools.

## WordPress.org Release Gates

Free v1.0 is not ready for WordPress.org until all gates below pass.

### Packaging

- `readme.txt` exists and follows WordPress.org formatting.
- Plugin header includes stable version metadata.
- License is GPLv2-or-later compatible.
- No development-only files are included in the release package unless intentionally documented.
- Screenshots and assets are prepared for the plugin directory.
- Stable tag maps to the submitted version.

### Compliance

- No locked premium code in the free plugin.
- No trial limits, hidden quotas, or sandbox-only behavior.
- No external tracking without explicit opt-in.
- No remote executable code loading.
- Upsell is limited to a contextual Upgrade page or restrained settings-page content.
- Public readme avoids keyword stuffing and competitor tag spam.

### Security

- Every write action checks capability.
- Every write action verifies nonce.
- Every admin input is sanitized.
- Every output is escaped or passed through an intentional allowlist.
- Relationship actions validate that customer/tag/segment/note/task IDs exist and belong together.
- Destructive actions require confirmation and safe redirects.

### WooCommerce And HPOS

- WooCommerce dependency guard is verified.
- HPOS declaration is present.
- Order reads use WooCommerce CRUD APIs.
- Customer sync works with HPOS enabled.
- Customer sync works with legacy order storage where supported.
- No direct SQL against `wp_posts` or `wp_postmeta` for order data in Free v1.0 release paths.

### Performance

- Customer list avoids N+1 tag/segment queries.
- Sync/backfill/recalculate are batched.
- Filters use indexed custom table columns where practical.
- No `limit => -1` order object scan in release flows.
- Admin pages remain usable on stores with thousands of customers/orders.

### Internationalization

- User-facing strings use the `yoohw-customer-intelligence` text domain.
- Dynamic strings are escaped correctly.
- No hardcoded untranslated admin messages in release paths.

### QA

- PHP lint passes.
- JavaScript syntax check passes.
- Existing integration smoke tests pass in a WordPress/WooCommerce test environment.
- Manual admin visual smoke checklist passes.
- Fresh install, upgrade install, WooCommerce inactive, HPOS enabled, and reset flows are verified.

## Current State Assessment

Already in good shape:

- Eight-table custom database model, including the Tasks / Follow-ups foundation.
- Migration/versioning baseline.
- WooCommerce dependency guard.
- HPOS declaration.
- Customer list and customer profile.
- Events/activity timeline.
- Notes.
- Tags and static segments.
- Lifecycle, risk, trust, and VIP fields.
- Bulk tag/segment operations.
- Sync, backfill, recalculate, reset tools.
- Onboarding and Sync Center foundation in Settings.
- Admin UI cleanup toward WordPress/WooCommerce conventions.
- Central admin JavaScript.
- Date formatting helper for invalid/zero dates.
- WordPress.org `readme.txt` draft and plugin header compatibility metadata.

Needs hardening before Free v1.0:

- Manual visual QA for Overview, Customers, Customer Profile, Tasks, Tags, Segments, Activity, and Settings.
- Final WordPress.org plugin assets, screenshots, contributor username, and release package review.
- Uninstall/data retention policy.
- Dedicated health/status check for tables, DB version, WooCommerce, and HPOS.
- Full security review for all admin action handlers.
- Full i18n scan.
- WordPress Plugin Check warning triage for read-only nonce recommendations and custom table SQL/no-cache warnings.
- Manual HPOS smoke test on a real WooCommerce install.
- Integration test runner setup or documented test harness.
- Performance smoke on larger order/customer datasets.

Deferred:

- Saved filters.
- Dynamic segments.
- Automation.
- AI.
- Integrations.
- Advanced reports.

## Prioritized Free v1.0 Backlog

1. Complete Foundation Pass: scope, migration versioning, Tasks table foundation, and backward compatibility checks.
2. Build Onboarding and Sync Center.
3. Build Free Dashboard Overview.
4. Build Tasks / Follow-ups CRUD, list table, profile block, and activity events.
5. Polish Customer Profile around merchant workflows.
6. Add CSV export for current customer filters.
7. Add Diagnostics/Health checks and support report.
8. Add uninstall/data retention behavior and document whether custom tables are preserved or removed.
9. Run security audit pass across all admin actions: capability, nonce, sanitization, escaping, safe redirects, relationship integrity.
10. Run i18n audit and fix text-domain or untranslated string gaps.
11. Add/complete integration tests for install, migration, order sync, HPOS, notes, tags, segments, tasks, bulk actions, reset, export, and recalculation.
12. Add performance smoke cases for customer list, sync, backfill, export, and relationship prefetch.
13. Run full admin visual smoke checklist and fix layout/accessibility regressions.
14. Finalize WordPress.org assets, screenshots, contributor username, release package exclusions, and release candidate fresh install/upgrade testing.

## Free v1.0 Completion Definition

Free v1.0 is complete when:

- All in-scope modules above are implemented and verified.
- Every WordPress.org release gate passes.
- No Premium-only feature is required to get meaningful value from the plugin.
- Premium roadmap can be designed against stable free-core extension points without changing Free v1.0 business logic.

## Premium Planning Boundary

Premium planning can begin after Free v1.0 scope is accepted and the prioritized Free v1.0 backlog has owners.

Premium should be designed as a separate plugin that depends on the free core and extends it through stable service classes, hooks, filters, database access APIs, and admin extension points. Premium code must not be shipped inside the WordPress.org free plugin.

## Reference Sources

- WordPress.org Detailed Plugin Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WooCommerce HPOS documentation: https://developer.woocommerce.com/docs/features/high-performance-order-storage
- WooCommerce HPOS extension recipe book: https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/
