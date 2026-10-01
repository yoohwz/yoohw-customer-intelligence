=== Customer Intelligence for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, crm, customer, customer management, analytics
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 11.0
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce CRM for customer profiles, notes, tasks, emails, segments, loyalty context, risk signals, order sync, and HPOS.

== Description ==

[Product page](https://yoohw.com/product/customer-intelligence/) | [Documentation](https://docs.yoohw.com/category/customer-intelligence/) | [Support](https://workspace.yoohw.com/)

YoOhw Customer Intelligence for WooCommerce is a lightweight CRM workspace inside WordPress admin. It turns WooCommerce order history into searchable customer profiles with commerce insights, internal notes, follow-up tasks, direct email, tags, segments, and activity history.

Use it to find customers who need attention, organize retention and support work, review customer value and lifecycle, and connect customer profiles to WooCommerce orders. Data is stored in dedicated plugin tables instead of using `wp_usermeta` as the primary CRM store.

= Customer profiles and insights =

* Search and filter customers by status, value tier, lifecycle, risk, cohort, tags, and segments.
* Review total orders, total spent, average order value, first and last order, addresses, recent orders, and activity.
* Configure thresholds used for customer status, lifecycle, value tier, risk, and trust classifications.
* Use an action-focused dashboard for data freshness, priority customers, attention queues, tasks, and recent activity.
* Archive and restore CRM profiles without deleting WooCommerce orders or WordPress users.
* Export the current filtered customer list to CSV.

= Notes, tasks, email, and segmentation =

* Create, edit, and delete internal customer notes.
* Create follow-up tasks from a profile, the Tasks screen, customer bulk actions, or a WooCommerce order.
* Assign tasks to Administrators or Shop Managers with priority, due date, status, and optional order context.
* Send assignment, due-soon, overdue digest, escalation, completion, reopening, and daily summary emails.
* Compose a direct customer email from the profile using WooCommerce email templates and sender settings.
* Manage reusable customer tags and static segments, including bulk assignment.
* Review customer and task activity in a chronological timeline.

= WooCommerce and optional integrations =

* Sync profiles from existing WooCommerce orders and recalculate customer intelligence in resumable batches.
* Filter WooCommerce orders by customer profile and access customer tasks from the order screen.
* Work with WooCommerce High-Performance Order Storage through WooCommerce order APIs.
* Show loyalty levels, points, and loyalty activity when the supported WooCommerce Loyalty plugin is active and licensed.
* Record suspect, blocked, cleared, and match signals when [Blacklist Manager](https://wordpress.org/plugins/wc-blacklist-manager/) is active.
* Include Premium order risk and security signals when [Blacklist Manager Premium](https://yoohw.com/product/blacklist-manager-premium/) is active and licensed.

Customer Intelligence remains useful without the optional integrations. Integration-specific columns, filters, profile panels, and maintenance tools only appear when the related plugin and required license state are available.

= Data and privacy =

Customer intelligence data remains in the site's WordPress database unless an administrator exports it or a configured integration moves it. WooCommerce continues to manage orders, and WordPress continues to manage users.

WordPress's Personal Data Export tool includes matching Customer Intelligence profiles, notes, tasks, activity, tag and static-segment memberships, plus personal Saved Customer Views for the requested WordPress user. Matching uses the exact request email or its exact WordPress user link. The exporter is read-only and does not include WooCommerce order contents, external plugin records, or internal delivery and migration state. WooCommerce and other plugins provide their own exporters for data they own.

WordPress's Personal Data Erase tool removes matching Customer Intelligence profiles, notes, tasks and their notification state, activity, memberships, derived order facts and Customer Intelligence-owned order links. It also removes Saved Customer Views only for the exact WordPress user. WooCommerce orders and WordPress accounts are not deleted; those systems have their own privacy and retention responsibilities. Erasure retains keyed, one-way identity suppression receipts so routine synchronization cannot silently recreate the erased Customer Intelligence data. These receipts contain no raw email, phone or name and are retained without automatic expiry while the plugin's retained data remains installed. They are removed only by explicitly opting in to full Customer Intelligence data removal on uninstall. A future identity that does not match a retained receipt is treated independently. A temporary, non-autoloaded snapshot of at most 1,000 Customer Intelligence order-link references and fingerprints survives Reset between eraser pages; it contains no raw email, phone or name and is deleted after link cleanup. If one WordPress user is linked to more than 1,000 Customer Intelligence profiles, or the subject has more than 1,000 order facts to prepare, erasure pauses without deleting data and requires site administrator investigation.

Uninstalling the plugin preserves Customer Intelligence tables and records by default. A site owner may explicitly opt in to removal by setting the non-autoloaded `yoohw_cos_remove_data_on_uninstall` option to `yes` before uninstalling.

Optional security signals are normalized and minimized. Raw IP addresses, device identifiers, browser fingerprints, and payment identifiers are not copied into Customer Intelligence activity metadata.

== Installation ==

1. Install the plugin through the WordPress Plugins screen, or upload it to `/wp-content/plugins/yoohw-customer-intelligence/`.
2. Activate WooCommerce, then activate YoOhw Customer Intelligence for WooCommerce.
3. Open **Customers > Settings** and run **Sync Existing Orders**.
4. Review the scoring thresholds and CRM emails.
5. Use **Overview**, **Customers**, **Tasks**, **Tags**, **Segments**, and **Activity** for daily work.
6. If supported Loyalty or Blacklist Manager plugins are active, use Maintenance to sync older integration signals.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce 8.2 or newer must be active.

= Is it compatible with WooCommerce HPOS? =

Yes. The plugin declares HPOS compatibility and reads orders through WooCommerce order APIs.

= Does it replace WooCommerce customers or WordPress users? =

No. It adds operational CRM profiles. WooCommerce orders and WordPress users remain managed by their original systems.

= Where is CRM data stored? =

Profiles, events, notes, tasks, tags, segments, and relationships use dedicated plugin tables rather than `wp_usermeta` as the primary store.

= Can I create and assign customer follow-up tasks? =

Yes. Tasks can be created from several admin screens and assigned to Administrators or Shop Managers. Email notifications are configurable through the WooCommerce email system.

= Can I email a customer from their profile? =

Yes. The Identity panel includes a secure email composer that uses WooCommerce branding and email settings and records the action in customer activity.

= Can I segment or export customers? =

Yes. Use tags and static segments for manually maintained groups, then filter and export the current customer result to CSV.

= Which integrations are optional? =

Supported WooCommerce Loyalty and Blacklist Manager integrations add loyalty or risk context when available. Premium-only signals require the related premium plugin and an active license.

= What does Archive do? =

Archive removes a CRM profile from the main customer list. It does not delete WooCommerce orders or WordPress users, and the profile can be restored.

== Screenshots ==

1. Customer Intelligence overview with data freshness, KPIs, attention queues, tasks, and recent activity.
2. Searchable customer list with filters, bulk actions, export, and customer classifications.
3. Customer profile with commerce insights, email, notes, tasks, tags, segments, risk factors, and activity.
4. WooCommerce order screen with customer profile and task tools.
5. Follow-up task management.
6. Customer tag management.
7. CRM email notification settings.
8. Static segment management.

== Changelog ==

= 1.4.2 =

Oct 1, 2026.

* Replaced the generic deferred-operation warning with source-aware Operational recovery records and clearer guidance in Customer Intelligence Settings, including supported Blacklist and Loyalty recovery links. Blocking Reset states continue to prevent unsafe operations.
* Clarified the “Acknowledge after recovery” action: it acknowledges an incident after its recovery has been completed. Handled legacy notices stay dismissed, while new or concurrent unresolved incidents remain visible and distinct.
* Made redirect-result notices appear once on Customer Intelligence screens without consuming similarly named query parameters on unrelated admin screens. Improved notice severity consistency and prevented repeated order-admin Reset conflict messages from stacking.

= 1.4.1 =

Sep 29, 2026.

* Corrected Reset Guard warnings so routine read and retryable sync contention no longer appears to be a lost customer-data operation. Interrupted Reset recovery and integration events that need manual replay now have clearer, separate notices; recovered notices can be dismissed without hiding a newer deferred operation.
* Refreshed existing CRM task emails with clearer status, context, and next actions for assignments, reassignment, due-soon, completion, reopening, overdue, escalation, and daily summaries. Messages retain WooCommerce email branding and settings, with matching HTML and plain-text content and Customer Message block-email compatibility.

= 1.4.0 =

Sep 27, 2026.

* Added Saved Customer Views, retention and attention quick views, and explainable RFM facts and filters to help teams find and revisit operational customer groups.
* Reorganized Customers as an operations workspace and Customer Profile around immediate actions while keeping notes, tasks, email, tags, static segments, exports, and optional integrations available.
* Aligned recognized-order, refund, and reassignment metrics across customer views. Per-order currency tracking keeps mixed or unknown currencies unavailable for combined monetary figures; no currency conversion is applied. Bounded backfill restores valid single-currency figures after convergence.
* Added WordPress Personal Data Export and Erase support for Customer Intelligence data, with resumable erasure and retained one-way suppression receipts. WooCommerce orders and WordPress users remain under their own systems' control.
* Expanded Diagnostics for data freshness, migrations, and currency state; improved large-data performance and added Free extension contracts for future integrations.
* Hardened migrations, admin screens, HPOS and legacy order-storage coverage, Plugin Check compatibility, and deterministic distribution packaging.

See `changelog.txt` for the complete release history.
