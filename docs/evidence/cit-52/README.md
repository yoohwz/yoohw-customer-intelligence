# CIT-52 runtime visual evidence

These screenshots were captured from `YoOhw_COS_Customer_Profile` in a newly installed, disposable WordPress 6.9 site with WooCommerce 10.8.0 and synthetic customers/orders/tasks. The site used its own socket-only MySQL 8.0.35 instance and was removed after capture. No existing WordPress installation or customer data was used.

- `profile-desktop.png`: populated customer, 1440 × 900 browser viewport. Five KPIs, overdue attention, open work, and the narrower desktop side column are visible.
- `profile-narrow.png`: same customer, 480 × 900 viewport. KPI and main/side content stack; measured page width and scroll width were both 480px.
- `profile-no-open-task.png`: current customer with a recognized order and no open task, 480 × 900 viewport. The attention panel reports no current supported reason.

The live DOM check found five KPI items, desktop grid tracks of about 856px and 364px, a sticky desktop side column, a single column and static side column at 480px, and no horizontal page overflow. Native Address & Acquisition disclosure opened with Enter. The existing email composer opened from the header, and its WooCommerce settings link remained available. Loyalty and Blacklist Manager Premium were not installed in this owned fixture; their conditional guards were inspected in source and are covered by existing integration behavior where available.

The screenshots contain only synthetic `example.test` contacts and order/task values. The PHP-rendered page, WordPress admin styles, WooCommerce runtime, and plugin CSS/JS were loaded by the disposable site.
