=== Customer Intelligence for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, woocommerce-crm, crm, customer-management, customer-analytics
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 10.8
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce CRM for customer profiles, notes, tasks, emails, tags, segments, loyalty, risk signals, order sync, and HPOS insights.

== Description ==

Customer Intelligence for WooCommerce is a lightweight WooCommerce CRM and customer intelligence workspace inside WordPress admin. It turns WooCommerce order history into customer profiles for notes, follow-up tasks, task email notifications, tags, segments, activity history, loyalty context, risk signals, and order insights.

Use it to search WooCommerce customers, track customer status, lifecycle, VIP level, risk, and trust, create internal notes, schedule customer follow-ups, send task reminders, segment customers, export filtered customer data, and connect customer profiles back to WooCommerce orders.

Optional integrations bring more customer intelligence into the same profile view. When the related plugins are active and licensed, Customer Intelligence can sync loyalty levels and points, ingest Blacklist Manager risk signals, show Premium security signals, and include those signals in customer activity and risk factors.

It stores customer data in dedicated custom database tables. It does not use `wp_usermeta` as the primary CRM data store.

= Main features =

* Action-oriented customer overview dashboard with data freshness, lifetime KPIs, attention queues, priority customers, lifecycle and risk drill-downs, tasks, and recent activity.
* Searchable customer list with status, value tier, risk, lifecycle, purchase cohort, attention, tag, and segment filters, plus sorting, pagination, clickable rows, archive/restore, and CSV export.
* Customer profiles with identity, direct customer email composer, commerce summary, addresses, recent orders, notes, follow-up tasks, tags, segments, and activity timeline.
* Configurable customer scoring thresholds for value tier, lifecycle stage, customer status, risk, and trust review workflows.
* Internal customer notes with create, edit, and delete actions.
* Follow-up tasks with due date, priority, status, assignee, customer/order context, complete/reopen/edit/delete actions, reminders, and a WordPress Dashboard widget.
* CRM task email notifications for task assignment, reassignment, due soon reminders, overdue digests, escalation, completed tasks, reopened tasks, and daily follow-up summaries.
* WooCommerce email settings integration with a CRM email group, editable email subjects/headings, and WooCommerce-style email templates.
* Customer tags and static segments with WordPress taxonomy-style management, autocomplete assignment, existing-term selection, and colored tag chips.
* Bulk actions for tags, segments, follow-up tasks, archive, and restore.
* WooCommerce order list filtering and order edit integration with customer profile selection and a customer task metabox.
* Optional WooCommerce Loyalty integration for loyalty level, available points, earned points, profile badges, activity events, and follow-up task automation.
* Optional Blacklist Manager integration for suspect, blocked, cleared, and match signals in customer activity, risk factors, and customer profile badges.
* Optional Blacklist Manager Premium integration for premium order risk, matched rules, anti-bot signals, payment abuse, device signals, and gateway fraud signals.
* Sync Center for importing customer profiles from existing WooCommerce orders.
* Maintenance tools with AJAX batch progress, resume support, first-order backfill, intelligence recalculation, and legacy signal sync.
* Diagnostics for WooCommerce readiness, HPOS status, and customer database tables.
* High-Performance Order Storage (HPOS) compatibility declaration.

= WooCommerce customer profiles =

Each profile combines WooCommerce order data with operational context:

* Customer name, email, phone, and optional WordPress user link.
* Total orders, total spent, average order value, first order, last order, and last activity.
* Customer status, lifecycle stage, VIP tier, risk score, and trust score.
* Loyalty level and points when the optional Loyalty integration is available.
* Blacklist status, risk factors, and Premium security signal summaries when the optional Blacklist Manager integrations are available.
* Billing and shipping addresses.
* Recent WooCommerce orders.
* Notes, tasks, tags, segments, and activity events.

= Customer scoring and risk signals =

Customer Intelligence calculates customer status, lifecycle stage, value tier, risk score, and trust score from normalized WooCommerce customer data. Store managers can adjust scoring thresholds for classifications such as high value customers, top customers, loyal customers, dormant customers, at-risk customers, and inactive customers.

Risk factors appear on each customer profile so teams can see why a profile needs review. Optional Blacklist Manager and Blacklist Manager Premium integrations can add suspect, blocked, cleared, order risk, payment abuse, anti-bot, device, and gateway fraud signals when those integrations are active.

= Follow-up tasks =

Tasks are built for day-to-day store operations. Create a task from the Tasks screen, a customer profile, the customer list bulk action, or the WooCommerce order edit screen.

Tasks can be assigned to Administrators and Shop Managers. They support priority, due date, open/completed status, customer context, optional order context, and timeline events.

Task email notifications help teams respond faster to customer follow-ups. Assignees can receive emails when tasks are assigned, reassigned, due soon, reopened, or included in daily follow-up summaries. Overdue tasks can be grouped into daily digests to reduce inbox noise, and escalation emails can notify configured manager/admin recipients after a task stays overdue for a defined number of days.

= WooCommerce email notifications =

CRM task emails and direct customer messages use the WooCommerce email system, including WooCommerce email templates, sender settings, content type settings, and email styling. Store managers can compose a direct message from the Identity panel on a customer profile and configure the Customer message template from Customers > Emails or WooCommerce > Settings > Emails > CRM.

= Tags and segments =

Use tags for flexible labels such as "wholesale", "needs review", "VIP candidate", or "support follow-up".

Use static segments for manual customer segmentation such as loyal customers, repeat buyers, B2B customers, or retention lists.

= Optional integrations =

Customer Intelligence is built to stay useful on its own, while adding richer WooCommerce customer intelligence when compatible plugins are active:

* [WooCommerce Loyalty](https://yoohw.com/product/woocommerce-loyalty-points-and-rewards/): sync registered-customer loyalty level, available points, earned points, profile badges, points events, and loyalty-related task automation when the Loyalty plugin is active and licensed.
* [Blacklist Manager](https://wordpress.org/plugins/wc-blacklist-manager/): record customer suspect, blocked, cleared, and match events from core Blacklist Manager workflows into customer activity and risk scoring.
* [Blacklist Manager Premium](https://yoohw.com/product/blacklist-manager-premium/): record Premium order risk scores, matched risk rules, anti-bot checkout signals, payment abuse signals, device signals, and gateway fraud signals when Premium is active and licensed.

Integration-specific customer list columns, profile panels, filters, and Maintenance sync tools are only shown when the related plugin and license state are available.

= Sync and HPOS =

The Sync Center imports and normalizes customer data from WooCommerce orders. Order data is read through WooCommerce order APIs for HPOS compatibility. When an order is synced or linked from order admin, the order can store the related customer profile ID so order admin, profile views, tasks, and profile queries stay aligned.

Maintenance tools support batch progress for long-running jobs such as order sync, customer intelligence recalculation, first-order data backfill, and legacy integration signal sync.

= Data and privacy =

The free version keeps customer intelligence data inside your WordPress database unless an administrator exports it manually or another plugin/custom integration moves it.

Customer data is stored in custom tables for customers, events, notes, tasks, tags, segments, and relationships. WooCommerce orders and WordPress users remain managed by WooCommerce and WordPress.

Optional integration signals are stored as normalized customer activity metadata. Sensitive security data from Premium risk integrations is minimized so raw IP addresses, device identifiers, browser fingerprints, and payment identifiers are not copied into Customer Intelligence metadata.

== Requirements ==

* WordPress 6.9 or newer.
* PHP 7.4 or newer.
* WooCommerce 8.2 or newer.
* WooCommerce must be active.
* Optional: WooCommerce Loyalty, Blacklist Manager, and Blacklist Manager Premium for the related integrations.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/yoohw-customer-intelligence`, or install it through the WordPress Plugins screen.
2. Activate the plugin in WordPress.
3. Make sure WooCommerce is active.
4. Open Customers in WordPress admin.
5. Go to Customers > Settings.
6. Run Sync Existing Orders to import existing WooCommerce customer data.
7. Go to Customers > Emails to review CRM task email notifications.
8. Optional: activate supported Loyalty or Blacklist Manager integrations and use Maintenance tools to sync legacy signals.
9. Review Overview, Customers, Tasks, Tags, Segments, Activity, and Settings.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce is required. If WooCommerce is inactive, the plugin shows an admin notice and does not load its Customer admin screens.

= Is this a WooCommerce CRM? =

Yes. It adds CRM-style customer profiles, internal notes, follow-up tasks, customer tags, static segments, CSV export, and WooCommerce order insights inside WordPress admin.

= Is this compatible with WooCommerce HPOS? =

Yes. The plugin declares compatibility with WooCommerce High-Performance Order Storage and reads orders through WooCommerce order APIs.

= Does this replace WooCommerce customers or WordPress users? =

No. It adds customer profiles for store operations. WooCommerce customers and WordPress users remain managed by WooCommerce and WordPress.

= Does it store CRM data in wp_usermeta? =

No. Customer data uses custom database tables as the primary data store for profiles, notes, tasks, tags, segments, and activity events.

= Can I create customer follow-up tasks? =

Yes. You can create tasks from the Tasks screen, customer profiles, customer list bulk actions, and WooCommerce order edit screens.

= Who can be assigned to tasks? =

Tasks can be assigned to Administrators and Shop Managers.

= Does it send WooCommerce task email notifications? =

Yes. CRM task emails can notify assignees about new assignments, reassigned tasks, due soon tasks, reopened tasks, overdue task digests, overdue escalations, completed tasks, and daily follow-up summaries. These emails are managed through WooCommerce email settings under the CRM group.

= Can I export customers? =

Yes. The customer list includes CSV export for the current filter set. The export is capped to avoid heavy single-request exports on large stores.

= Can I filter WooCommerce orders by customer profile? =

Yes. The WooCommerce orders list can filter by plugin customer profiles, and order edit screens can link to the matching profile.

= Does it integrate with WooCommerce Loyalty? =

Yes. When the supported Loyalty plugin is active and licensed, Customer Intelligence can sync loyalty levels, points, profile badges, activity events, and loyalty-related task automation.

= Does it integrate with Blacklist Manager? =

Yes. When Blacklist Manager is active, Customer Intelligence can record suspect, blocked, cleared, and match signals into customer activity, customer profile badges, and customer risk factors.

= Does it support Blacklist Manager Premium signals? =

Yes. When Blacklist Manager Premium is active and licensed, Customer Intelligence can record premium order risk, matched rules, anti-bot, payment abuse, device, and gateway fraud signals. Premium-specific UI is hidden when Premium is inactive or not licensed.

= What does Archive do? =

Archive hides a customer profile from the main list. It does not delete WooCommerce orders or WordPress users. Archived profiles can be restored.

= What does Reset data remove? =

Reset clears customer data for customers, events, notes, tasks, and customer tag/segment relationships. It does not delete WooCommerce orders, WordPress users, tag definitions, or segment definitions.

= Can I segment WooCommerce customers? =

Yes. Use customer tags and static segments for manually maintained customer groups. Dynamic smart segments, automations, AI workflows, and cross-store intelligence are planned for future/premium scope.

== Screenshots ==

1. Action-oriented Overview dashboard with data freshness, linked KPIs, attention queues, priority customers, tasks, lifecycle, risk, and recent activity.
2. Customer list with filters, search, bulk actions, export, archive, optional integration columns, and customer intelligence data.
3. Customer profile with commerce summary, identity, orders, notes, tasks, tags, segments, risk factors, optional loyalty context, optional security signals, and activity.
4. WooCommerce order edit screen with customer profile selection and customer task metabox.
5. Tasks screen for follow-up management.
6. Tags management screen.
7. Email notification settings.
8. Segments management screen.

== Changelog ==

= 1.2.1 (Jul 23, 2026) =

* Added a direct customer email composer to the Identity panel, with WooCommerce email branding, sender settings, configurable template content, secure AJAX delivery, and customer activity logging.
* Redesigned Overview as an action dashboard with data freshness, linked lifetime KPIs, attention queues, priority customers, due-soon tasks, and drill-down customer distributions.
* Corrected customer activity semantics so historical order sync uses meaningful order or loyalty activity dates, then refreshes status, lifecycle, and risk classifications in background batches.

= 1.2.0 (Jul 10, 2026) =

* Added configurable customer scoring thresholds for value tier, lifecycle stage, and customer status so stores can tune High value, Very high value, Top customer, dormant, at-risk, inactive, and loyal classifications.
* Added optional WooCommerce Loyalty integration that syncs registered-customer loyalty level and points into Customer Intelligence profiles when the Loyalty plugin is active and licensed.
* Added Loyalty-aware customer list/profile display with Loyalty level badges, available points, earned points, and badge text colors from the Loyalty plugin customization settings.
* Added Loyalty event ingestion and configurable task automation for level changes, large redemptions, abnormal downgrades/resets, dormant high-points customers, and reconciliation issues.
* Added optional Blacklist Manager integration that records suspect, blocked, cleared, and match signals into customer activity and customer risk scoring when the core plugin is active.
* Added optional Blacklist Manager Premium integration for premium order risk, matched rules, anti-bot, payment abuse, device, and gateway fraud signals when Premium is active and licensed.
* Added Blacklist Manager status badges, activity source labels, risk factors, and Premium security signals on customer profiles.
* Added a unified Maintenance tool to sync legacy Blacklist Manager signals, including Premium risk signals when Premium is available.
* Improved Loyalty integration guards so Loyalty-specific settings, columns, filters, panels, and profile data are hidden when the Loyalty plugin is inactive or its license is not active.
* Improved Blacklist Manager integration guards so core and Premium-specific UI only appears when the corresponding plugin and license state is available.
* Improved Customer profile overview layout so Commerce summary and Identity fill the section cleanly when optional Loyalty data is not available.
* Improved Maintenance operations with AJAX batch progress feedback for long-running sync, recalculation, and backfill tasks.

See `changelog.txt` for the full changelog.
