# CIT-93 lifecycle monetary copy

Approved scope: [Issue #93 admission](https://github.com/yoohwz/yoohw-customer-intelligence/issues/93#issuecomment-5976465761), base `f0472e28d9b62e4dcfb2943cbf8ebdcd07b806f1`.

The commerce policy now owns a sentence formatter that normalizes its existing
canonical HTML price into plain text: decode HTML entities and strip tags. Lifecycle
Lifetime value uses that formatter; its availability gate/reason label and the text-only
Profile factor renderer are unchanged. KPI/RFM/recent-order HTML, recorded currency,
WooCommerce localization, thresholds, comparability and scoring formulas are unchanged.

## Deterministic regression

Eleven new cases in the existing guarded monetary integration class cover current/foreign
currency with two decimal/grouping/price-position configurations; genuine mixed orders;
unknown source currency; preparing/attention/stale/none reasons; and malicious description
payloads through each actual risk/trust/lifecycle panel renderer. The full Profile output
is parsed to assert readable Lifetime value text, no element descendants/literal price
markup/entities, and existing KPI/RFM/recent-order HTML. None remains the truthful
`No recognized orders` reason rather than an invented foreign amount.

With the admitted baseline producer, the same regression fails because the visible
Lifecycle text contains literal `<span class="woocommerce-Price-amount ...">` markup.
With the correction, all eleven focused cases pass in both storage modes. The final
canonical full local run also passed 279 tests / 5802 assertions per HPOS mode, five
owned-process controls, 144 guard rejections, smoke benchmarks, unrelated synthetic DB
sentinel preservation and cleanup. Exact-head native CI and fresh Technical Review are
recorded in the PR; focused runs alone are not full-candidate assurance.

## Admitted Local browser confirmation

[Receipt](local-confirmation.json) and [cropped Lifecycle panel](lifecycle-local.jpg)
record the native synthetic-admin Profile view on yoplay8.local after deterministic green.
The unchanged USD store and one completed VND 100000 order produce visible text
`This customer has spent ₫100.000,00 (VND).` KPI/RFM retain their normal price elements;
the recent order displays the localized VND price. The image contains only the synthetic
Lifecycle panel, with no customer identifiers, admin request tokens or unrelated panels.

Only the two changed runtime files were temporarily installed. The original producer,
policy and unchanged Profile renderer matched the admitted base; the receipt does not
claim the entire installed plugin matched main. Source files were restored to their exact
pre-QA bytes. The disposable user/order/customer, related CIT rows, signup coupon, three
order notes, one order-specific analytics action/logs, QA guard and browser session were
removed; explicit owned-reference queries all returned zero. Private snapshots remain
outside the webroot and were never imported. Concurrent LOY-64 synthetic data/scheduled
work and the monotonic data-updated timestamp were preserved. No whole-database identity
or rollback is claimed. No schema/version change, production mutation, merge or release.
