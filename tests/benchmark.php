<?php
/** Synthetic-only benchmark entrypoint; environment guard must run first. */
require_once __DIR__ . '/environment.php';
yci_test_environment();
if ( ! in_array( (string) getenv( 'YCI_BENCHMARK_SIZE' ), array( 'smoke', 'large' ), true ) ) {
	throw new RuntimeException( 'Synthetic benchmark size must be explicit.' );
}
define( 'SAVEQUERIES', true );
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$large = 'large' === getenv( 'YCI_BENCHMARK_SIZE' );
$profiles = $large ? 25000 : 40;
$now = YoOhw_COS_DB::now();
$recent = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
$old = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
$future = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
$generation = '11111111-1111-4111-8111-111111111111';
update_option( 'yoohw_cos_intelligence_generation', $generation, false );
update_option( 'woocommerce_currency', 'USD', false );

$wpdb->query( 'CREATE TEMPORARY TABLE yci_benchmark_sequence (n INT NOT NULL PRIMARY KEY)' );
for ( $start = 1; $start <= $profiles; $start += 500 ) {
	$values = array();
	for ( $n = $start; $n < min( $profiles + 1, $start + 500 ); $n++ ) { $values[] = '(' . $n . ')'; }
	$wpdb->query( 'INSERT INTO yci_benchmark_sequence (n) VALUES ' . implode( ',', $values ) );
}
$customers = YoOhw_COS_DB::customers_table();
$facts = YoOhw_COS_DB::order_facts_table();
$tasks = YoOhw_COS_DB::tasks_table();
$events = YoOhw_COS_DB::events_table();
$tags = YoOhw_COS_DB::tags_table();
$customer_tags = YoOhw_COS_DB::customer_tags_table();
$segments = YoOhw_COS_DB::segments_table();
$customer_segments = YoOhw_COS_DB::customer_segments_table();
$queries = array(
	$wpdb->prepare( "INSERT INTO %i (id,email,display_name,total_orders,total_spent,average_order_value,money_state,money_currency,commerce_metrics_version,intelligence_currency_ready,intelligence_generation,risk_score,customer_status,vip_status,lifecycle_stage,last_order_date,last_activity_date,created_at,updated_at)
		SELECT n, CONCAT('fixture', n, '@example.test'), CONCAT('Synthetic ', n), IF(MOD(n,2)=0,6,2), IF(MOD(n,2)=0,300.000000,100.000000), 50.000000, 'comparable', 'USD', 2, 1, %s,
		IF(MOD(n,5)=0, 80, 20), IF(MOD(n,5)=0, 'at_risk', 'active'), IF(MOD(n,7)=0, 'vip', 'none'), IF(MOD(n,3)=0, 'repeat', 'new'),
		IF(MOD(n,2)=0, %s, %s), IF(MOD(n,2)=0, %s, %s), %s, %s FROM yci_benchmark_sequence", $customers, $generation, $recent, $old, $recent, $old, $now, $now ),
	$wpdb->prepare( "INSERT INTO %i (order_id,customer_id,order_status,order_total,revenue_amount,currency,counts_as_order,counts_as_revenue,order_date,policy_version,updated_at)
		SELECT (n-1)*6+k, n, 'wc-completed', 50.000000, 50.000000, 'USD', 1, 1, %s, 1, %s
		FROM yci_benchmark_sequence CROSS JOIN (SELECT 1 k UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) x
		WHERE k <= IF(MOD(n,2)=0,6,2)", $facts, $recent, $now ),
	$wpdb->prepare( "INSERT INTO %i (customer_id,title,status,due_date,created_at,updated_at)
		SELECT n, 'Synthetic follow-up', IF(MOD(n,10)=0 OR MOD(n+k,3)=0, 'completed', 'open'), IF(MOD(n+k,2)=0, %s, %s), %s, %s
		FROM yci_benchmark_sequence CROSS JOIN (SELECT 1 k UNION ALL SELECT 2) x", $tasks, $old, $future, $now, $now ),
	$wpdb->prepare( "INSERT INTO %i (customer_id,event_type,created_at)
		SELECT n, 'synthetic', %s FROM yci_benchmark_sequence CROSS JOIN (SELECT 1 k UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) x", $events, $now ),
	$wpdb->prepare( "INSERT INTO %i (id,name,slug,created_at,updated_at) VALUES (1,'Synthetic tag','synthetic-tag',%s,%s)", $tags, $now, $now ),
	$wpdb->prepare( "INSERT INTO %i (customer_id,tag_id,created_at) SELECT n,1,%s FROM yci_benchmark_sequence WHERE MOD(n,3)=0", $customer_tags, $now ),
	$wpdb->prepare( "INSERT INTO %i (id,name,slug,segment_type,created_at,updated_at) VALUES (1,'Synthetic segment','synthetic-segment','static',%s,%s)", $segments, $now, $now ),
	$wpdb->prepare( "INSERT INTO %i (customer_id,segment_id,created_at) SELECT n,1,%s FROM yci_benchmark_sequence WHERE MOD(n,4)=0", $customer_segments, $now ),
);
foreach ( $queries as $query ) {
	if ( false === $wpdb->query( $query ) ) { throw new RuntimeException( 'Synthetic fixture insert failed: ' . $wpdb->last_error ); }
}
$row_counts = array();
foreach ( compact( 'customers', 'facts', 'tasks', 'events', 'customer_tags', 'customer_segments' ) as $name => $table ) {
	$row_counts[ $name ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
}
foreach ( array( 'customers' => $profiles, 'facts' => $profiles * 4, 'tasks' => $profiles * 2, 'events' => $profiles * 4 ) as $name => $expected ) {
	if ( $row_counts[ $name ] !== $expected ) { throw new RuntimeException( 'Synthetic row count mismatch: ' . $name ); }
}

$paths = array(
	'default' => static fn() => YoOhw_COS_Customer_Query::query( array() ),
	'composition' => static fn() => YoOhw_COS_Customer_Query::query( array( 'customer_status' => 'at_risk', 'lifecycle_stage' => 'repeat', 'vip_status' => 'high_value', 'risk_level' => 'high' ) ),
	'open_follow_up' => static fn() => YoOhw_COS_Customer_Query::query( array( 'customer_attention' => 'open_follow_up' ) ),
	'overdue_follow_up' => static fn() => YoOhw_COS_Customer_Query::query( array( 'customer_attention' => 'overdue_follow_up' ) ),
	'high_value_needs_follow_up' => static fn() => YoOhw_COS_Customer_Query::query( array( 'customer_attention' => 'high_value_needs_follow_up' ) ),
	'rfm' => static fn() => YoOhw_COS_Customer_Query::query( array( 'rfm_recency_max_days' => 30, 'rfm_frequency_min' => 4, 'rfm_monetary_min' => '200' ) ),
	'overview' => static fn() => array( YoOhw_COS_Overview::get_summary(), YoOhw_COS_Overview::get_attention_counts() ),
	'diagnostics' => static fn() => YoOhw_COS_Diagnostics::snapshot(),
);
$expected = array( 'default' => $profiles, 'composition' => intdiv( $profiles, 105 ), 'open_follow_up' => $profiles - intdiv( $profiles, 10 ), 'overdue_follow_up' => 0, 'high_value_needs_follow_up' => intdiv( $profiles, 70 ), 'rfm' => intdiv( $profiles, 2 ) );
for ( $n = 1; $n <= $profiles; $n++ ) {
	if ( 0 === $n % 10 ) { continue; }
	foreach ( array( 1, 2 ) as $k ) {
		if ( 0 !== ( $n + $k ) % 3 && 0 === ( $n + $k ) % 2 ) { $expected['overdue_follow_up']++; break; }
	}
}
$measurements = array();
foreach ( $paths as $name => $path ) {
	$path(); // Warm-up.
	$times = array();
	$counts = array();
	$dominant = null;
	for ( $run = 0; $run < 5; $run++ ) {
		$before = $wpdb->num_queries;
		$query_offset = count( $wpdb->queries );
		$started = microtime( true );
		$result = $path();
		$times[] = round( ( microtime( true ) - $started ) * 1000, 3 );
		$counts[] = $wpdb->num_queries - $before;
		foreach ( array_slice( $wpdb->queries, $query_offset ) as $entry ) {
			if ( preg_match( '/^\s*SELECT\b/i', $entry[0] ) && ( null === $dominant || $entry[1] > $dominant[1] ) ) { $dominant = $entry; }
		}
		if ( is_array( $result ) && isset( $result['total_items'] ) ) {
			if ( count( $result['items'] ) !== min( 20, $result['total_items'] ) || count( array_unique( array_column( $result['items'], 'id' ) ) ) !== count( $result['items'] ) ) {
				throw new RuntimeException( 'Pagination/result mismatch: ' . $name );
			}
		}
	}
	$plan = array();
	if ( $dominant && preg_match( '/\bFROM\s+`?' . preg_quote( $wpdb->prefix . 'yoohw_cos_', '/' ) . '/i', $dominant[0] ) ) {
		$explain = $wpdb->get_results( 'EXPLAIN ' . $dominant[0], ARRAY_A );
		foreach ( (array) $explain as $item ) {
			$plan[] = array_intersect_key( $item, array_flip( array( 'table', 'type', 'key', 'rows', 'Extra' ) ) );
		}
	}
	sort( $times, SORT_NUMERIC );
	$measurements[ $name ] = array( 'query_count' => $counts, 'median_ms' => $times[2], 'dominant_plan' => $plan, 'total_items' => $result['total_items'] ?? null );
	if ( isset( $expected[ $name ] ) ) {
		if ( $result['total_items'] !== $expected[ $name ] ) { throw new RuntimeException( 'Synthetic total mismatch: ' . $name ); }
		$filters = array( 'composition' => array( 'customer_status' => 'at_risk', 'lifecycle_stage' => 'repeat', 'vip_status' => 'high_value', 'risk_level' => 'high' ), 'open_follow_up' => array( 'customer_attention' => 'open_follow_up' ), 'overdue_follow_up' => array( 'customer_attention' => 'overdue_follow_up' ), 'high_value_needs_follow_up' => array( 'customer_attention' => 'high_value_needs_follow_up' ), 'rfm' => array( 'rfm_recency_max_days' => 30, 'rfm_frequency_min' => 4, 'rfm_monetary_min' => '200' ) );
		$before_small_page = $wpdb->num_queries;
		YoOhw_COS_Customer_Query::query( array_merge( $filters[ $name ] ?? array(), array( 'per_page' => 1 ) ) );
		if ( $wpdb->num_queries - $before_small_page !== $counts[0] ) { throw new RuntimeException( 'Page-size query growth: ' . $name ); }
		$first_ids = array_column( $result['items'], 'id' );
		if ( $expected[ $name ] > 20 ) {
			$args = array( 'paged' => 2 );
			$second = YoOhw_COS_Customer_Query::query( array_merge( $filters[ $name ] ?? array(), $args ) );
			if ( $second['total_items'] !== $expected[ $name ] || count( array_intersect( $first_ids, array_column( $second['items'], 'id' ) ) ) > 0 ) {
				throw new RuntimeException( 'Synthetic second-page mismatch: ' . $name );
			}
		}
	}
}
echo wp_json_encode( array(
	'rows' => $row_counts,
	'versions' => array( 'mysql' => $wpdb->db_version(), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC()->version, 'hpos' => getenv( 'WC_HPOS_ENABLED' ) ),
	'measurements' => $measurements,
), JSON_PRETTY_PRINT ) . "\n";
