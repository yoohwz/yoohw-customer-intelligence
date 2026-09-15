# Customer Profile 1.4.0 — Option A / Action-first

Status: **Human-approved design reference** for roadmap #31.

Implementation task: not admitted by this document. This reference exists so a future `CIT-N` runtime redesign can be implemented and reviewed against a repository-owned source instead of conversation memory.

Interactive/static reference: [`customer-profile-1.4.0-option-a.html`](customer-profile-1.4.0-option-a.html).

## Authority boundary

The approved authority is the **information architecture, relative visual hierarchy, grouping, and progressive-disclosure behavior** shown by this reference.

The following are illustrative only and must not be treated as product defaults, seed data, thresholds, or literal acceptance values:

- customer name/contact details;
- order IDs, amounts, dates and statuses;
- task/note copy and assignee names;
- badge values and counts;
- the exact `Needs attention` sentence;
- sample tag/segment names;
- sample Loyalty/security values.

Runtime implementation must use real Customer Intelligence data and existing capability/security contracts.

## Source basis

This design is based on the current Customer Profile implementation in:

- `admin/class-yoohw-cos-customer-profile.php`
- `includes/class-yoohw-cos-intelligence.php`
- `includes/class-yoohw-cos-integrations.php`

The current profile already exposes the capabilities represented by the prototype: customer identity/contact, direct email/call actions, commerce metrics, recent orders, tasks, notes, tags, static segments, Loyalty when active, risk/trust/lifecycle factors, optional Blacklist Manager Premium security signals, address/acquisition detail and activity history.

This design task does not change any runtime behavior.

## Approved reading order

### 1. Customer header

Keep the customer identity immediately recognizable:

- customer name;
- email and phone when available;
- WP user edit link when linked;
- compact status/lifecycle/value/risk/trust/Loyalty badges when applicable;
- primary actions: Back to customers, Call customer, Email customer, Add task.

The header should remain compact. It is not a dashboard section.

### 2. Compact KPI strip

Place the most useful operational commerce facts directly below the header:

- Total spent;
- Orders;
- Average order value;
- Last order;
- Open tasks.

Avoid repeating the same values again in a large `Commerce summary` table unless the later surface adds materially different context.

Monetary values must follow the accepted 1.4.0 commerce/currency contract when that task is implemented.

### 3. Needs attention

The main column begins with a compact explanation of why the profile currently deserves operator attention.

This surface must:

- use supported deterministic customer facts;
- remain factual and explainable;
- avoid implying AI or predictive advice;
- avoid silently inventing a reason when no supported attention condition exists;
- link to the most useful existing action when appropriate.

The exact sample sentence in the HTML is illustrative.

### 4. Open tasks

Open operational work precedes historical detail.

Preserve the current task capabilities and metadata, including priority, due date, assignee and completion action. The redesign may make task creation more compact, but must not remove the existing task workflow without separate scope.

### 5. Recent orders

Keep recent WooCommerce orders visible in the main flow, with the existing link to all customer orders.

Do not recreate WooCommerce order management inside Customer Intelligence.

### 6. Internal notes + recent activity

Present notes and recent activity after current work/commerce context. The two surfaces may share a row at wider admin widths and stack on narrower widths.

Preserve note edit/delete/add behavior and the link to full Activity where applicable.

### 7. Compact/sticky side column

At wider widths, use a narrower side column for lower-frequency but useful context:

1. Contact & identity;
2. Tags & segments;
3. Loyalty, only when the supported integration is active;
4. Customer intelligence summary.

The side column may be sticky at desktop widths but must become normal document flow on smaller admin viewports.

### 8. Progressive disclosure

Lower-frequency detail should not dominate the profile. In the approved reference:

- Security signals are collapsed/progressive and only exist when Blacklist Manager Premium integration is active.
- Address & Acquisition are collapsed/progressive detail.

A later implementation may use native `<details>` or an equivalent accessible WordPress-admin pattern. It must remain keyboard operable.

## Duplication policy

One of the redesign goals is to reduce repeated data without removing useful context.

Examples from the current profile that should be simplified:

- Total spent / Orders / AOV currently appear in summary cards and again in Commerce summary.
- Status / Lifecycle / Risk / Trust can appear as header badges and again as full panels.

The approved redesign keeps compact headline status in the header/KPI area and reserves longer intelligence/factor explanations for the dedicated Customer intelligence surface.

## Existing capability preservation

Unless a later admitted implementation Issue explicitly changes a behavior, the runtime redesign must preserve:

- direct customer email composer and WooCommerce email settings link;
- phone/call link when a phone exists;
- identity copy actions;
- recent-order links and `View all orders` behavior;
- task create/complete/reopen/list behavior;
- note create/edit/delete behavior;
- tag assignment/removal;
- static segment assignment/removal;
- Loyalty conditional visibility/data;
- risk/trust/lifecycle factors;
- Blacklist/Blacklist Premium conditional visibility and security summary;
- address/acquisition data;
- Activity timeline/full-activity access;
- capability checks, nonces, escaping/sanitization and Reset Guard contracts.

## WordPress/WooCommerce visual constraints

- Keep WordPress/WooCommerce admin conventions.
- Do not convert this screen into a JavaScript SPA.
- Avoid marketing-style decorative dashboards and nested-card excess.
- Reuse existing admin tokens/patterns where practical.
- Responsive behavior must support common WordPress admin widths.
- Controls must remain keyboard accessible and visibly focused.
- Empty states and integration-inactive states must remain understandable.

## Relationship to Free 1.4.0 roadmap

Roadmap #31 admits the runtime Customer Profile redesign after the foundational customer-query/RFM work in the recommended sequence. A future implementation Issue must re-read the current `main`, this Markdown file and the companion HTML before coding.

Future RFM/attention facts may be inserted into this hierarchy, but they should strengthen the `Needs attention`/Customer intelligence surfaces rather than create another dense row of cards.

## Review checklist for the future runtime task

A reviewer should verify at minimum:

- visual hierarchy materially matches the approved Action-first reference;
- no existing profile capability was accidentally lost;
- duplicate commerce/status presentation was reduced;
- integration-conditional sections still obey provider availability/licensing behavior;
- no sample values/copy from the prototype became hardcoded production data;
- narrow admin widths stack cleanly;
- focus/keyboard behavior works for progressive disclosure and actions;
- existing security/capability/nonce/reset contracts remain intact;
- the implementation remains Free 1.4.0 scope and does not add Dynamic Smart Segments, automation, AI or predictive models.
