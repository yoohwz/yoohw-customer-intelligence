# Free extension contracts (API version 1)

`YoOhw_COS_Extensions::version()` returns `1`. Feature-detect the class and version after Free has loaded, then register during `plugins_loaded` at a priority later than Free's default priority 10. Registration is in-memory for the current PHP request only. Register again in each request. IDs use lowercase ASCII `namespace/key`, at most 80 bytes; `core/*` is reserved. Duplicate IDs within a contract return `false`. No registration creates options, tables, cron events or durable membership.

## Canonical customer query

`YoOhw_COS_Customer_Query::query( array( 'extensions' => array( 'vendor/filter' => 'value' ) ) )` remains the only list query. `register_query( $id, $sanitizer, $predicate_builder )` takes two callables. The sanitizer receives one scalar raw value and returns a canonical nonempty scalar string, at most 200 bytes, or throws. The builder receives that canonical value and returns exactly `array( 'field' => ..., 'operator' => ..., 'value' => ... )`. Allowed fields are `total_orders`, `first_order_date`, `last_order_date`, `last_activity_date`, `customer_status`, `lifecycle_stage`, `vip_status`, `risk_score`, `trust_score`, `total_spent`, and `average_order_value`. Allowed operators are `=`, `<`, `<=`, `>`, `>=`. Free validates the tuple, adds currency and generation guards for relevant derived fields, binds values, and ANDs it with Free scope and filters in both count and item queries. Providers cannot return SQL or alter ORDER/LIMIT.

Unknown IDs, malformed input and callback failures return no customers. A provider must make its sanitizer idempotent because callers may pass already-canonical arguments through `query()`. Empty `extensions` has no query effect. External constraints are not stored in Free Saved Views or automatically added to Free CSV. The canonical query result may carry the normalized map for an add-on that explicitly preserves its own context. Providers should avoid per-customer SQL; the query builder runs once per active constraint per list query.

## Read-only facts and attention

`YoOhw_COS_Customer_Facts::snapshot( $loaded_customer, $context )` returns a scalar map with `core/*` keys. It accepts an already-loaded canonical customer row; it does not fetch one. Money values are null unless the existing currency policy says the row is comparable, and the map includes money state and recorded currency. RFM recency and frequency use the Free RFM helper and recognized order count. The service refreshes stale derived decisions through Free's existing safe decision helper. Contact facts are booleans only. `open_tasks` and `overdue_tasks` are included only when supplied in `$context`; the service does not query them. `register_facts( $id, $callback )` may add scalar keys in its registered namespace and cannot replace `core/*` keys. The callback receives the loaded row and core map; add-ons are responsible for privacy of any facts they choose to publish.

`YoOhw_COS_Attention::reasons( $loaded_customer, array( 'open_tasks' => 0, 'overdue_tasks' => 0 ) )` returns the current deterministic primary `core/*` reason first and up to ten valid external reasons afterward. Core precedence is overdue follow-up, high-value at risk/inactive, at-risk/inactive, missing contact, high-value without open follow-up, then no current reason. Each descriptor has an ID, priority, plain-text message, fixed severity (`info`, `warning`, `urgent`), and optional core action URL/label. `register_attention( $id, $callback )` accepts a provider descriptor whose ID matches registration. Invalid output is ignored. No evaluation is scheduled or persisted. The Customer Profile renders this same primary decision.

## Manual Profile actions

`register_action( $id, $callback )` accepts a callback returning `id`, `label`, `url`, and `capability`. The ID must match registration. Free renders at most ten links after its existing Back/Call/Email/Add task controls. URLs must be HTTP(S) URLs under the current site's WordPress admin path, with matching scheme, host and port. Free checks the current user's declared capability at render time. The destination handler must enforce its own capability and nonce; registering or showing a link does not execute the action. Invalid output is dropped.

## Existing lifecycle hooks

The following pre-existing hooks remain the stable interoperability points. Filters return the same data shape they receive. Action listeners should treat arguments as read-only and must account for retry/duplicate delivery where applicable.

| Hook | Arguments |
| --- | --- |
| `yoohw_cos_customer_sync_data` | mutable customer data array, `WC_Order`, customer ID |
| `yoohw_cos_customer_recalculate_intelligence_data` | customer data array, customer ID |
| `yoohw_cos_customer_intelligence_data` | customer data array, `WC_Order`, customer ID, pre-filter customer array |
| `yoohw_cos_customer_intelligence_recalculated` | customer ID, recalculated customer array, previous customer array, update success boolean |
| `yoohw_cos_customer_risk_score` | numeric score, customer array |
| `yoohw_cos_customer_risk_factors` | factor array, customer array |
| `yoohw_cos_event_recorded` | event ID, event input array |
| `yoohw_cos_task_created` | task ID, loaded task array |
| `yoohw_cos_task_reassigned` | task ID, previous task array, updated task array |
| `yoohw_cos_task_completed` | task ID, previous task array, updated task array |
| `yoohw_cos_task_reopened` | task ID, previous task array, updated task array |

A minimal external add-on can register after Free loads:

```php
add_action( 'plugins_loaded', static function (): void {
    if ( ! class_exists( 'YoOhw_COS_Extensions' ) || YoOhw_COS_Extensions::version() !== 1 ) {
        return;
    }
    YoOhw_COS_Extensions::register_query(
        'example-provider/min-orders',
        static function ( $raw ): string {
            if ( ! is_string( $raw ) || ! preg_match( '/^[1-9][0-9]{0,3}$/D', $raw ) ) {
                throw new InvalidArgumentException( 'Invalid threshold' );
            }
            return $raw;
        },
        static fn( string $value ): array => array( 'field' => 'total_orders', 'operator' => '>=', 'value' => $value )
    );
}, 20 );
```

These contracts do not provide a Smart Segment rule engine, stored membership, Premium code, background evaluator, workflow scheduler, automatic action execution, AI or remote registration. Free remains functional with no providers. Existing Reset, privacy suppression and currency boundaries still govern all underlying customer data.
