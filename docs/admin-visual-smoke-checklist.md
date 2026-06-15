# YoOhw Customer Intelligence for WooCommerce Admin UI Smoke Checklist

Use this checklist after admin UI changes before planning the next product roadmap.

## Customers

- Status filters render as WordPress `subsubsub` links with correct counts and current state.
- Only one table filter row is visible; search, bulk actions, tag, segment, status, VIP, risk, and lifecycle controls do not wrap awkwardly.
- Default hidden columns are Phone, AOV, Trust, and Lifecycle; Screen Options can restore them.
- Empty or invalid dates, including `0000-00-00 00:00:00`, render as a dash instead of a broken historic date.
- Pagination, sorting links, and bulk actions preserve active filters.

## Customer Profile

- Header summary, KPI cards, Commerce, Customer Intelligence, Operations, and Activity sections scan cleanly at desktop admin widths.
- Email and phone copy buttons work without inline scripts and show copied feedback.
- Note edit and cancel controls toggle without layout shift.
- View all activity opens the Activity page filtered by the current customer.
- Empty dates and missing customer fields render as intentional empty states.

## Tags And Segments

- Pages follow the WordPress taxonomy-style two-column layout: add/edit form on the left and list table on the right.
- Name, slug, description, edit, delete, bulk delete, and nonce flows behave consistently with WordPress list tables.
- Duplicate slug, missing name, and assigned-item delete warnings display as admin notices.
- Bulk delete skips assigned tags or segments and reports skipped counts.
- Empty states do not hide primary add forms.

## Activity

- Severity filters render as `subsubsub` links with counts.
- Event type, source, and customer filters can be combined with search and pagination.
- Order object links use WooCommerce order edit URLs and remain HPOS-safe.
- Invalid dates render as a dash.

## Settings And Tools

- Status cards and tool panels use WordPress admin spacing and do not nest card-like panels unnecessarily.
- Auto-sync checkbox submits through delegated JavaScript, not inline handlers.
- Reset actions require explicit confirmation through data attributes.
- Backfill and sync tools surface actionable success/error notices.

## Responsive And Accessibility

- At narrow WordPress admin widths, taxonomy columns stack and list tables remain scrollable.
- Button labels, badges, and table cells do not overlap or clip.
- Focus states remain visible on links, buttons, fields, and copy controls.
- No browser console errors are present while using filters, copy buttons, confirmations, or note editing.

## Pass Criteria

- PHP lint passes for all plugin PHP files.
- `node --check assets/js/admin.js` passes.
- No PHP notices or warnings appear with `WP_DEBUG` enabled during the flows above.
