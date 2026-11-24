<?php
/**
 * Lightweight smoke tests for the departure inventory service.
 *
 * These tests exercise argument guards and the service's database update
 * contract with a fake wpdb object. They do not replace WordPress integration
 * tests or prove real database concurrency behaviour.
 */
define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function absint( $value ) {
	return abs( (int) $value );
}

class OTE_Test_WPDB {
	public $prefix = 'wp_';
	public $next_result = 1;
	public $queries = array();

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( $arg ) . "'";
			$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return $this->next_result;
	}
}

function ote_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

global $wpdb;
$wpdb = new OTE_Test_WPDB();

require_once dirname( __DIR__ ) . '/includes/class-ote-departures.php';
$service = OTE_Departures::instance();

ote_assert( true === $service->reserve( 7, 12, 3 ), 'reserve succeeds when one row is updated' );
ote_assert( false !== strpos( end( $wpdb->queries ), 'reserved + 3 <= capacity' ), 'reserve query enforces capacity in SQL' );

$wpdb->next_result = 0;
ote_assert( false === $service->reserve( 7, 12, 3 ), 'reserve fails when no row can be updated' );
ote_assert( false !== strpos( end( $wpdb->queries ), "status = 'open'" ), 'reserve query only targets open departures' );

$before = count( $wpdb->queries );
ote_assert( false === $service->reserve( 7, 12, 0 ), 'zero-seat reservation is rejected' );
ote_assert( $before === count( $wpdb->queries ), 'invalid reservation does not reach the database' );

$wpdb->next_result = 1;
ote_assert( true === $service->release( 7, 2 ), 'release succeeds when one row is updated' );
ote_assert( false !== strpos( end( $wpdb->queries ), 'reserved >= 2' ), 'release query prevents negative reserved seats' );

$before = count( $wpdb->queries );
ote_assert( false === $service->release( 7, 0 ), 'zero-seat release is rejected' );
ote_assert( $before === count( $wpdb->queries ), 'invalid release does not reach the database' );

echo "All departure inventory smoke tests passed.\n";
