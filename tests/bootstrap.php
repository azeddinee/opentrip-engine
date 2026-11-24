<?php
/**
 * WordPress test bootstrap.
 */
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR and install the WordPress test suite.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

function ote_manually_load_plugin() {
	require dirname( __DIR__ ) . '/opentrip-engine.php';
}
tests_add_filter( 'muplugins_loaded', 'ote_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
