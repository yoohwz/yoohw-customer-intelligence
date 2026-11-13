# Advanced Accounts Premium profile summary

CIT optionally renders Advanced Accounts information in the customer profile side column.
It reads `WCAAP_Customer_Summary::read()` version 2 at request time. No provider data is
persisted, exported, scored, segmented or added to the timeline. Existing generic
Extensions facts/query contracts are unchanged.

A unique persisted CRM `wp_user_id` link, an existing WordPress user, `manage_woocommerce` and `edit_user` for that user are required. CRM IDs are never used
as WordPress IDs; email and phone are never used as fallback identity evidence. Missing,
deleted, ambiguous and stale links show no account data. Staff without WooCommerce management receive no panel. Absent/older AAP, inactive entitlement, invalid/mismatched responses and exceptions hide the panel. No diagnostic or stale account-data card is displayed.

AAP owns verification, effective Membership/source/term decisions and program state.
Missing or malformed verification is unknown. Loyalty evidence and unknown legacy terms
are labelled separately; profile approval, CRM value tiers and payment/entitlement are
not interchangeable. Only summary statuses, labels and source terms are displayed, never
application fields/snapshots, private notes, challenge material or credentials. Dates are
shown as supplied by AAP. Management links require existing AAP permissions and same-site
admin URLs; all changes remain on native AAP/WordPress screens.

The integration requires the coordinated AAP summary API; it deliberately does not probe
private AAP metadata when the API is unavailable. It creates no schema, job, migration,
account link or new account-management endpoint. Events, account discovery, filters and
automation are outside this first version.

`tests/integration/test-aap-integration.php` exercises real WordPress users/capabilities,
CRM links and the rendered profile with a narrow AAP provider stand-in in both owned
HPOS modes. The AAP repository tests its real summary producer and no-write semantics.
These cases do not prove every theme/version or a live commercial licensing provider.

## Availability and presentation

AAP's v2 `features` map is required: `email_verification`, `phone_verification`,
`membership`, `program` are strict booleans. Only true sections are rendered; false
sections and their management links stay hidden even if legacy payload data remains.
An all-false map hides the entire card. AAP owns enablement and local configuration
validation; CIT does not inspect provider settings or credentials. Unknown verification
for an enabled channel remains unknown, never an invented successful status.

Verification uses labelled rows/status badges. Membership keeps effective/unknown/Loyalty
semantics, with keyboard-native expandable source terms. Program approval has its own
badge and native review link. Manage account appears once in the card header; only
applicable group links are shown. Existing profile colors/layout are reused with scoped,
wrapping CSS. No JavaScript, persisted UI state or new management flow is added.
