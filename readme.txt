=== Customer Intelligence for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, crm, customers, customer-notes, customer-analytics
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 10.8
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customer profiles, WooCommerce CRM tasks, notes, tags, segments, activity timeline, sync, export, and HPOS-ready order insights.

== Description ==

Customer Intelligence for WooCommerce adds a practical customer operations workspace to WordPress admin. It helps WooCommerce store owners, support teams, and shop managers understand customer history, organize customer follow-ups, and work from one clear customer profile instead of jumping between order screens.

The plugin focuses on useful free-version customer intelligence: customer profiles, commerce metrics, notes, tasks, tags, static segments, activity timeline, CSV export, order sync, diagnostics, and WooCommerce order admin integration.

It stores Customer Intelligence data in dedicated custom database tables. It does not use `wp_usermeta` as the primary CRM data store.

= Main features =

* Customer overview dashboard for totals, revenue, orders, VIP customers, risk, lifecycle, tasks, and recent activity.
* Searchable customer list with WordPress-style filters, sorting, pagination, archive/restore actions, and CSV export.
* Customer profiles with identity, commerce summary, addresses, recent orders, notes, follow-up tasks, tags, segments, and activity timeline.
* Internal customer notes with create, edit, and delete actions.
* Follow-up tasks with due date, priority, status, assignee, customer link, optional order link, complete/reopen/edit/delete actions, and dashboard reminders.
* Customer tags and static segments with WordPress taxonomy-style management screens.
* Bulk actions for tags, segments, follow-up tasks, archive, and restore.
* WooCommerce order edit integration with Customer Intelligence profile selection and a customer task metabox.
* Sync Center for importing customer profiles from existing WooCommerce orders.
* Batch sync progress, resume support, first-order backfill, and recalculation tools.
* Diagnostics for WooCommerce readiness, HPOS status, and Customer Intelligence database tables.
* High-Performance Order Storage (HPOS) compatibility declaration.

= WooCommerce customer profiles =

Each profile combines WooCommerce order data with operational context:

* Customer name, email, phone, and optional WordPress user link.
* Total orders, total spent, average order value, first order, last order, and last activity.
* Customer status, lifecycle stage, VIP tier, risk score, and trust score.
* Billing and shipping addresses.
* Recent WooCommerce orders.
* Notes, tasks, tags, segments, and activity events.

= Follow-up tasks =

Tasks are built for day-to-day store operations. Create a task from the Tasks screen, a customer profile, the customer list bulk action, or the WooCommerce order edit screen.

Tasks can be assigned to Administrators and Shop Managers. They support priority, due date, open/completed status, customer context, optional order context, and timeline events.

= Tags and segments =

Use tags for flexible labels such as "wholesale", "needs review", "VIP candidate", or "support follow-up".

Use static segments for manually maintained customer groups such as loyal customers, repeat buyers, B2B customers, or retention lists.

= Sync and HPOS =

The Sync Center imports and normalizes customer data from WooCommerce orders. Order data is read through WooCommerce order APIs for HPOS compatibility. When an order is synced or linked from order admin, the order can store the related Customer Intelligence profile ID so order admin, profile views, tasks, and profile queries stay aligned.

= Data and privacy =

The free version keeps customer intelligence data inside your WordPress database unless an administrator exports it manually or another plugin/custom integration moves it.

Customer Intelligence data is stored in custom tables for customers, events, notes, tasks, tags, segments, and relationships. WooCommerce orders and WordPress users remain managed by WooCommerce and WordPress.

== Requirements ==

* WordPress 6.9 or newer.
* PHP 7.4 or newer.
* WooCommerce 8.2 or newer.
* WooCommerce must be active.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/yoohw-customer-intelligence`, or install it through the WordPress Plugins screen.
2. Activate the plugin in WordPress.
3. Make sure WooCommerce is active.
4. Open Customers in WordPress admin.
5. Go to Customers > Settings.
6. Run Sync Existing Orders to import existing WooCommerce customer data.
7. Review Overview, Customers, Tasks, Tags, Segments, Activity, and Settings.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce is required. If WooCommerce is inactive, the plugin shows an admin notice and does not load its Customer Intelligence admin screens.

= Is this compatible with WooCommerce HPOS? =

Yes. The plugin declares compatibility with WooCommerce High-Performance Order Storage and reads orders through WooCommerce order APIs.

= Is this an official WooCommerce plugin? =

No. This plugin is developed by YoOhw Studio. It is not affiliated with, endorsed by, or sponsored by WooCommerce or Automattic.

= Does this replace WooCommerce customers or WordPress users? =

No. It adds Customer Intelligence profiles for store operations. WooCommerce customers and WordPress users remain managed by WooCommerce and WordPress.

= Does it store CRM data in wp_usermeta? =

No. Customer Intelligence uses custom database tables as the primary data store for profiles, notes, tasks, tags, segments, and activity events.

= Can I create customer follow-up tasks? =

Yes. You can create tasks from the Tasks screen, customer profiles, customer list bulk actions, and WooCommerce order edit screens.

= Who can be assigned to tasks? =

Tasks can be assigned to Administrators and Shop Managers.

= Can I export customers? =

Yes. The customer list includes CSV export for the current filter set. The export is capped to avoid heavy single-request exports on large stores.

= What does Archive do? =

Archive hides a Customer Intelligence profile from the main list. It does not delete WooCommerce orders or WordPress users. Archived profiles can be restored.

= What does Reset data remove? =

Reset clears Customer Intelligence customers, events, notes, tasks, and customer tag/segment relationships. It does not delete WooCommerce orders, WordPress users, tag definitions, or segment definitions.

= Are dynamic smart segments included? =

No. The free version includes static segments. Dynamic smart segments, automations, AI workflows, and cross-store intelligence are planned for future/premium scope.

== Screenshots ==

1. Overview dashboard with customer health, lifecycle, risk, tasks, and recent activity.
2. Customer list with filters, search, bulk actions, export, archive, and customer intelligence columns.
3. Customer profile with commerce summary, identity, orders, notes, tasks, tags, segments, and activity.
4. WooCommerce order edit screen with Customer Intelligence profile selection and customer task metabox.
5. Tasks screen for follow-up management.
6. Tags management screen.
7. Segments management screen.

== Changelog ==

= 1.1.0 (Jun 15, 2026) =

* Updated the WordPress Dashboard follow-up tasks widget to appear at the top of the Dashboard.
* Refreshed the Tasks admin page with a task-specific header, summary cards, clearer form/list panels, and responsive layout improvements while staying familiar to WordPress admin.

= 1.0.0 =

* Initial release.
* Added Customer Intelligence overview dashboard.
* Added customer profiles with commerce summary, identity, orders, addresses, notes, tasks, tags, segments, and activity timeline.
* Added customer list filters, sorting, search, pagination, bulk actions, archive/restore, and CSV export.
* Added internal notes and follow-up tasks.
* Added tag and static segment management screens.
* Added WooCommerce order admin Customer Intelligence profile mapping and customer task metabox.
* Added Sync Center, order sync, progress state, backfill, recalculation, diagnostics, and reset tools.
* Added HPOS compatibility declaration and WooCommerce dependency metadata.
* Added custom database tables and migration/versioning baseline.

== Upgrade Notice ==

= 1.1.0 =

Improves the Tasks admin page UI and elevates follow-up task reminders on the WordPress Dashboard.

= 1.0.0 =

Initial release of Customer Intelligence for WooCommerce.
