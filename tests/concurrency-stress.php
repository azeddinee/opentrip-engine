<?php
/**
 * Multi-process MySQL stress tests for transactional departure inventory.
 *
 * Run after the WordPress test library has been installed:
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib php tests/concurrency-stress.php
 */

function ote_stress_departure( $tour_id, $capacity ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ote_departures';
	$start = wp_date( 'Y-m-d', strtotime( '+40 days' ) );
	$inserted = $wpdb->insert(
		$table,
		array(
			'tour_id'    => $tour_id,
			'start_date' => $start,
			'end_date'   => wp_date( 'Y-m-d', strtotime( '+41 days' ) ),
			'capacity'   => $capacity,
			'reserved'   => 0,
			'price'      => 100,
			'currency'   => 'USD',
			'status'     => 'open',
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
	);
	if ( 1 !== $inserted ) {
		throw new RuntimeException( 'Could not create stress-test departure: ' . $wpdb->last_error );
	}
	return (int) $wpdb->insert_id;
}

function ote_stress_booking() {
	$booking_id = wp_insert_post(
		array(
			'post_type'   => 'ote_booking',
			'post_status' => 'private',
			'post_title'  => 'Concurrency stress fixture',
		),
		true
	);
	if ( is_wp_error( $booking_id ) ) {
		throw new RuntimeException( $booking_id->get_error_message() );
	}
	update_post_meta( $booking_id, '_ote_booking_status', 'pending' );
	// The WP test suite can reuse post IDs after its cleanup while custom
	// plugin tables retain rows. Remove any orphaned ledger row for this
	// freshly-created fixture before starting concurrent workers.
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'ote_booking_reservations', array( 'booking_id' => $booking_id ), array( '%d' ) );
	return (int) $booking_id;
}

function ote_stress_workers( $booking_ids, $tour_id, $departure_id, $seats ) {
	$barrier = tempnam( sys_get_temp_dir(), 'ote-stress-' );
	if ( false === $barrier ) {
		throw new RuntimeException( 'Could not create worker barrier.' );
	}
	unlink( $barrier );
	$workers = array();
	$results = array();

	try {
		foreach ( $booking_ids as $index => $booking_id ) {
			$ready = $barrier . '.' . $index . '.ready';
			$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' worker '
				. (int) $booking_id . ' ' . (int) $tour_id . ' ' . (int) $departure_id . ' '
				. (int) $seats . ' ' . escapeshellarg( $barrier ) . ' ' . escapeshellarg( $ready );
			$pipes = array();
			$process = proc_open( $command, array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			), $pipes );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start worker ' . $index );
			}
			fclose( $pipes[0] );
			$workers[] = array( 'process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'ready' => $ready );
		}

		$deadline = microtime( true ) + 45;
		do {
			$all_ready = true;
			foreach ( $workers as $worker ) {
				if ( ! file_exists( $worker['ready'] ) ) {
					$all_ready = false;
					break;
				}
			}
			if ( $all_ready ) {
				break;
			}
			usleep( 20000 );
		} while ( microtime( true ) < $deadline );

		if ( ! $all_ready ) {
			throw new RuntimeException( 'Workers did not all become ready before the 45-second deadline.' );
		}
		file_put_contents( $barrier, 'go' );

		foreach ( $workers as $worker ) {
			$stdout = stream_get_contents( $worker['stdout'] );
			$stderr = stream_get_contents( $worker['stderr'] );
			fclose( $worker['stdout'] );
			fclose( $worker['stderr'] );
			$exit_code = proc_close( $worker['process'] );
			$decoded = json_decode( trim( $stdout ), true );
			if ( 0 !== $exit_code || ! is_array( $decoded ) || ! empty( $decoded['error'] ) ) {
				throw new RuntimeException( 'Worker failed (exit ' . $exit_code . '): ' . trim( $stderr . ' ' . $stdout ) );
			}
			$results[] = $decoded;
		}
	} finally {
		foreach ( $workers as $worker ) {
			if ( is_resource( $worker['process'] ) ) {
				proc_terminate( $worker['process'] );
				proc_close( $worker['process'] );
			}
			if ( file_exists( $worker['ready'] ) ) {
				unlink( $worker['ready'] );
			}
		}
		if ( file_exists( $barrier ) ) {
			unlink( $barrier );
		}
	}
	return $results;
}

$is_worker = isset( $argv[1] ) && 'worker' === $argv[1];
if ( $is_worker ) {
	$booking_id   = isset( $argv[2] ) ? (int) $argv[2] : 0;
	$tour_id      = isset( $argv[3] ) ? (int) $argv[3] : 0;
	$departure_id = isset( $argv[4] ) ? (int) $argv[4] : 0;
	$seats        = isset( $argv[5] ) ? (int) $argv[5] : 0;
	$barrier      = isset( $argv[6] ) ? $argv[6] : '';
	$ready        = isset( $argv[7] ) ? $argv[7] : '';
	if ( ! $booking_id || ! $tour_id || ! $departure_id || ! $seats || '' === $barrier || '' === $ready ) {
		fwrite( STDERR, "Invalid worker arguments.\\n" );
		exit( 2 );
	}
	// Signal readiness before loading WordPress so all processes start their
	// WordPress bootstrap together, rather than serializing at the barrier.
	touch( $ready );
	$deadline = microtime( true ) + 60;
	while ( ! file_exists( $barrier ) && microtime( true ) < $deadline ) {
		usleep( 1000 );
	}
	if ( ! file_exists( $barrier ) ) {
		fwrite( STDERR, "Timed out waiting for worker barrier.\\n" );
		exit( 3 );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( $is_worker ) {
	// Load the already-installed test site without invoking the test suite's
	// installer or its destructive _delete_all_posts() reset in each worker.
	$_tests_dir = getenv( 'WP_TESTS_DIR' );
	if ( ! $_tests_dir ) {
		$_tests_dir = '/tmp/wordpress-tests-lib';
	}
	$config_file = $_tests_dir . '/wp-tests-config.php';
	if ( ! defined( 'OTE_CONCURRENCY_STRESS_TEST' ) ) {
		define( 'OTE_CONCURRENCY_STRESS_TEST', true );
	}
	if ( ! is_readable( $config_file ) ) {
		fwrite( STDERR, "WordPress test config not found: " . $config_file . "\\n" );
		exit( 4 );
	}
	require_once $config_file;
	require_once $_tests_dir . '/includes/functions.php';
	tests_reset__SERVER();
	define( 'WP_TESTS_TABLE_PREFIX', $table_prefix );
	define( 'DIR_TESTDATA', $_tests_dir . '/data' );
	define( 'DIR_TESTROOT', realpath( $_tests_dir ) );
	define( 'WP_LANG_DIR', realpath( DIR_TESTDATA . '/languages' ) );
	define( 'DISABLE_WP_CRON', true );
	define( 'WP_MEMORY_LIMIT', '-1' );
	define( 'WP_MAX_MEMORY_LIMIT', '-1' );
	define( 'REST_TESTS_IMPOSSIBLY_HIGH_NUMBER', 99999999 );
	$PHP_SELF = '/index.php';
	$GLOBALS['PHP_SELF'] = '/index.php';
	$_SERVER['PHP_SELF'] = '/index.php';
	if ( ! defined( 'WP_DEFAULT_THEME' ) ) {
		define( 'WP_DEFAULT_THEME', 'default' );
	}
	$GLOBALS['wp_theme_directories'] = array();
	if ( file_exists( DIR_TESTDATA . '/themedir1' ) ) {
		$GLOBALS['wp_theme_directories'][] = DIR_TESTDATA . '/themedir1';
	}
	function ote_manually_load_plugin() {
		require dirname( __DIR__ ) . '/opentrip-engine.php';
	}
	tests_add_filter( 'muplugins_loaded', 'ote_manually_load_plugin' );
	require_once ABSPATH . 'wp-settings.php';

	$result = OTE_Departures::instance()->transition_booking( $booking_id, $tour_id, $departure_id, $seats, 'confirmed' );
	$success = false !== $result;
	if ( ! $success ) {
		global $wpdb;
		$transition_error = $wpdb->last_error;
		$departure_table = $wpdb->prefix . 'ote_departures';
		$departure = $wpdb->get_row( $wpdb->prepare(
			"SELECT reserved, capacity, status, start_date FROM {$departure_table} WHERE id = %d AND tour_id = %d",
			$departure_id,
			$tour_id
		) );
		$booking_status = get_post_meta( $booking_id, '_ote_booking_status', true );
		$expected_capacity_rejection = $departure
			&& ( 'open' !== $departure->status
				|| $departure->start_date < wp_date( 'Y-m-d' )
				|| (int) $departure->reserved + $seats > (int) $departure->capacity );
		if ( ! $expected_capacity_rejection || '' !== $transition_error ) {
			fwrite( STDERR, 'Unexpected booking transition failure: ' . wp_json_encode( array(
				'db_error' => $transition_error,
				'transition_failure' => isset( $GLOBALS['ote_transition_failure'] ) ? $GLOBALS['ote_transition_failure'] : '',
				'prefix' => $wpdb->prefix,
				'booking_id' => $booking_id,
				'booking_status' => $booking_status,
				'departure' => $departure,
			) ) . "\\n" );
			exit( 5 );
		}
	}
	echo wp_json_encode( array(
		'success' => $success,
		'changed' => $success && ! empty( $result['changed'] ),
	) );
	exit( 0 );
}

require_once __DIR__ . '/bootstrap.php';

global $wpdb;
OTE_Departures::install();
$tour_id = wp_insert_post( array(
	'post_type'   => 'ote_tour',
	'post_status' => 'publish',
	'post_title'  => 'Concurrent inventory stress fixture',
) );
if ( is_wp_error( $tour_id ) ) {
	fwrite( STDERR, $tour_id->get_error_message() . "\n" );
	exit( 1 );
}

$departures_table = $wpdb->prefix . 'ote_departures';
$reservations_table = $wpdb->prefix . 'ote_booking_reservations';
$created_bookings = array();
$created_departures = array();

$failure = null;
try {
	// Twenty independent bookings race for five seats.
	$capacity_departure = ote_stress_departure( $tour_id, 5 );
	$created_departures[] = $capacity_departure;
	$booking_ids = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$booking_ids[] = ote_stress_booking();
	}
	$created_bookings = array_merge( $created_bookings, $booking_ids );
	$results = ote_stress_workers( $booking_ids, $tour_id, $capacity_departure, 1 );
	$successes = count( array_filter( $results, function( $result ) { return ! empty( $result['success'] ); } ) );
	$reserved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$departures_table} WHERE id = %d", $capacity_departure ) );
	$ledger_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations_table} WHERE departure_id = %d", $capacity_departure ) );
	if ( 5 !== $successes || 5 !== $reserved || 5 !== $ledger_count || $reserved > 5 ) {
		throw new RuntimeException( 'Capacity race failed: successes=' . $successes . ', reserved=' . $reserved . ', ledger=' . $ledger_count );
	}
	echo "PASS: 20 independent processes competed for 5 seats; exactly 5 confirmations succeeded and inventory remained at capacity.\n";

	// Multiple processes confirm the same booking; only one may create its ledger entry.
	$idempotency_departure = ote_stress_departure( $tour_id, 20 );
	$created_departures[] = $idempotency_departure;
	$shared_booking = ote_stress_booking();
	$created_bookings[] = $shared_booking;
	$duplicate_ids = array_fill( 0, 10, $shared_booking );
	$duplicate_results = ote_stress_workers( $duplicate_ids, $tour_id, $idempotency_departure, 1 );
	$successful = count( array_filter( $duplicate_results, function( $result ) { return ! empty( $result['success'] ); } ) );
	$changed = count( array_filter( $duplicate_results, function( $result ) { return ! empty( $result['changed'] ); } ) );
	$duplicate_reserved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$departures_table} WHERE id = %d", $idempotency_departure ) );
	$duplicate_ledger = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations_table} WHERE booking_id = %d", $shared_booking ) );
	if ( 10 !== $successful || 1 !== $changed || 1 !== $duplicate_reserved || 1 !== $duplicate_ledger ) {
		throw new RuntimeException( 'Idempotency race failed: successful=' . $successful . ', changed=' . $changed . ', reserved=' . $duplicate_reserved . ', ledger=' . $duplicate_ledger );
	}
	echo "PASS: 10 processes confirmed the same booking; exactly one inventory reservation was recorded.\n";
	echo "All multi-process inventory stress tests passed.\n";
} catch ( Throwable $exception ) {
	$failure = $exception->getMessage();
} finally {
	foreach ( $created_bookings as $booking_id ) {
		$wpdb->delete( $reservations_table, array( 'booking_id' => $booking_id ), array( '%d' ) );
		wp_delete_post( $booking_id, true );
	}
	foreach ( $created_departures as $departure_id ) {
		$wpdb->delete( $departures_table, array( 'id' => $departure_id ), array( '%d' ) );
	}
	wp_delete_post( $tour_id, true );
}
if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\\n" );
	exit( 1 );
}
