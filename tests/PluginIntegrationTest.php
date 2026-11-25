<?php
/**
 * WordPress integration tests for OpenTrip Engine.
 */

class OTE_Plugin_Integration_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		OpenTrip_Engine::instance()->register_types();
		OTE_Departures::install();
		OTE_Submission_Limiter::install();
	}

	public function test_plugin_registers_expected_content_types_and_shortcodes() {
		$this->assertTrue( post_type_exists( 'ote_tour' ) );
		$this->assertTrue( post_type_exists( 'ote_booking' ) );
		$this->assertTrue( post_type_exists( 'ote_review' ) );
		$this->assertTrue( taxonomy_exists( 'ote_destination' ) );

		$this->assertTrue( shortcode_exists( 'opentrip_tours' ) );
		$this->assertTrue( shortcode_exists( 'opentrip_booking_form' ) );
		$this->assertTrue( shortcode_exists( 'opentrip_itinerary' ) );
		$this->assertTrue( shortcode_exists( 'opentrip_inclusions' ) );
		$this->assertTrue( shortcode_exists( 'opentrip_gallery' ) );
		$this->assertTrue( shortcode_exists( 'opentrip_reviews' ) );
		$this->assertTrue( shortcode_exists( 'opentrip_review_form' ) );
	}

	public function test_itinerary_shortcode_renders_saved_tour_data_safely() {
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Integration Test Tour',
			)
		);
		update_post_meta(
			$tour_id,
			'_ote_itinerary',
			array(
				array(
					'day'        => 1,
					'title'      => 'Old town walk',
					'city'       => 'Marrakech',
					'activities' => '<script>alert(1)</script>',
				),
			)
		);

		$html = do_shortcode( '[opentrip_itinerary tour_id="' . $tour_id . '"]' );

		$this->assertStringContainsString( 'Old town walk', $html );
		$this->assertStringContainsString( 'Marrakech', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_inclusions_shortcode_hides_empty_lists_and_escapes_items() {
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Inclusions Test Tour',
			)
		);

		$this->assertSame( '', do_shortcode( '[opentrip_inclusions tour_id="' . $tour_id . '"]' ) );

		update_post_meta( $tour_id, '_ote_inclusions', array( 'Breakfast', '<img src=x onerror=alert(1)>' ) );
		$html = do_shortcode( '[opentrip_inclusions tour_id="' . $tour_id . '"]' );

		$this->assertStringContainsString( 'Breakfast', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	public function test_departure_inventory_reserves_and_releases_real_database_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'ote_departures';
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Inventory Test Tour',
			)
		);
		$start = wp_date( 'Y-m-d', strtotime( '+7 days' ) );
		$end   = wp_date( 'Y-m-d', strtotime( '+9 days' ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'tour_id'    => $tour_id,
				'start_date' => $start,
				'end_date'   => $end,
				'capacity'   => 3,
				'reserved'   => 0,
				'price'      => 100.00,
				'currency'   => 'USD',
				'status'     => 'open',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);

		$this->assertSame( 1, $inserted, 'The departure row should be inserted into MySQL.' );
		$departure_id = (int) $wpdb->insert_id;
		$inventory = OTE_Departures::instance();

		$this->assertTrue( $inventory->reserve( $departure_id, $tour_id, 2 ) );
		$this->assertSame( '2', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$table} WHERE id = %d", $departure_id ) ) );

		$this->assertFalse( $inventory->reserve( $departure_id, $tour_id, 2 ), 'A reservation exceeding remaining capacity must fail.' );
		$this->assertTrue( $inventory->release( $departure_id, 2 ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$table} WHERE id = %d", $departure_id ) ) );
		$this->assertFalse( $inventory->release( $departure_id, 1 ), 'Releasing more seats than reserved must fail.' );

		$wpdb->delete( $table, array( 'id' => $departure_id ), array( '%d' ) );
	}

	public function test_confirmed_booking_keeps_status_when_inventory_release_fails() {
		$booking_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_booking',
				'post_status' => 'private',
				'post_title'  => 'Inventory release failure test',
			)
		);
		update_post_meta( $booking_id, '_ote_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_ote_departure_id', 999999 );
		update_post_meta( $booking_id, '_ote_travellers', 2 );
		update_post_meta( $booking_id, '_ote_tour_id', 123 );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_POST['ote_booking_status_nonce'] = wp_create_nonce( 'ote_save_booking_status' );
		$_POST['ote_booking_status'] = 'cancelled';

		OpenTrip_Engine::instance()->save_booking_status( $booking_id );

		$this->assertSame( 'confirmed', get_post_meta( $booking_id, '_ote_booking_status', true ) );
		$this->assertNotFalse( get_transient( 'ote_inventory_error_' . $user_id ) );
		unset( $_POST['ote_booking_status_nonce'], $_POST['ote_booking_status'] );
	}

	public function test_privacy_exporter_and_eraser_cover_booking_and_review_data() {
		$email = 'privacy-test@example.com';
		$booking_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_booking',
				'post_status' => 'private',
				'post_title'  => 'Privacy Test Customer',
			)
		);
		update_post_meta( $booking_id, '_ote_customer_email', $email );
		update_post_meta( $booking_id, '_ote_customer_name', 'Privacy Test Customer' );
		update_post_meta( $booking_id, '_ote_customer_message', 'Please call me.' );

		$review_id = self::factory()->post->create(
			array(
				'post_type'    => 'ote_review',
				'post_status'  => 'pending',
				'post_title'   => 'Review by Privacy Test Customer',
				'post_content' => 'A personal review.',
			)
		);
		update_post_meta( $review_id, '_ote_review_email', $email );
		update_post_meta( $review_id, '_ote_review_name', 'Privacy Test Customer' );

		$engine = OpenTrip_Engine::instance();
		$export = $engine->export_personal_data( $email, 1 );
		$this->assertCount( 2, $export['data'] );
		$this->assertTrue( $export['done'] );

		$erase = $engine->erase_personal_data( $email, 1 );
		$this->assertSame( 2, $erase['items_removed'] );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_customer_email', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_customer_message', true ) );
		$this->assertSame( 'Anonymized customer', get_post_meta( $booking_id, '_ote_customer_name', true ) );
		$this->assertSame( '', get_post_meta( $review_id, '_ote_review_email', true ) );
		$this->assertSame( '', get_post_field( 'post_content', $review_id ) );
		$this->assertSame( 'draft', get_post_status( $review_id ) );
	}

	public function test_booking_and_review_records_are_admin_only() {
		$booking_id = self::factory()->post->create(
			array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Private booking' )
		);
		$review_id = self::factory()->post->create(
			array( 'post_type' => 'ote_review', 'post_status' => 'pending', 'post_title' => 'Private review' )
		);

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );
		$this->assertFalse( current_user_can( 'edit_post', $booking_id ) );
		$this->assertFalse( current_user_can( 'read_post', $booking_id ) );
		$this->assertFalse( current_user_can( 'edit_post', $review_id ) );
		$this->assertFalse( current_user_can( 'read_post', $review_id ) );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'edit_post', $booking_id ) );
		$this->assertTrue( current_user_can( 'read_post', $booking_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $review_id ) );
		$this->assertTrue( current_user_can( 'read_post', $review_id ) );
	}

	public function test_public_submission_rate_limit_is_email_scoped_and_separates_forms() {
		$engine = OpenTrip_Engine::instance();
		$email = 'limit-' . wp_generate_password( 8, false ) . '@example.com';

		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$this->assertFalse( $engine->is_submission_rate_limited( 'booking', $email ) );
		}
		$this->assertTrue( $engine->is_submission_rate_limited( 'booking', strtoupper( $email ) ) );
		global $wpdb;
		$hash = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT attempts FROM " . OTE_Submission_Limiter::table_name() . " WHERE email_hash = %s AND form_type = %s",
			$hash,
			'booking'
		) );
		$this->assertSame( '6', (string) $count );
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertFalse( $engine->is_submission_rate_limited( 'review', $email ) );
		$this->assertTrue( $engine->is_submission_rate_limited( 'invalid', $email ) );
		$this->assertTrue( $engine->is_submission_rate_limited( 'booking', 'not-an-email' ) );
	}

	public function test_booking_form_renders_honeypot_and_bounded_input_fields() {
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Booking Form Test Tour',
			)
		);
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ote_departures',
			array(
				'tour_id' => $tour_id,
				'start_date' => wp_date( 'Y-m-d', strtotime( '+10 days' ) ),
				'end_date' => wp_date( 'Y-m-d', strtotime( '+11 days' ) ),
				'capacity' => 8,
				'reserved' => 0,
				'price' => 120,
				'currency' => 'USD',
				'status' => 'open',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);
		$html = OpenTrip_Engine::instance()->booking_shortcode( array( 'tour_id' => $tour_id ) );

		$this->assertStringContainsString( 'name="ote_website"', $html );
		$this->assertStringContainsString( 'name="ote_name"', $html );
		$this->assertStringContainsString( 'name="ote_email"', $html );
		$this->assertStringContainsString( 'name="ote_message"', $html );
		$this->assertStringContainsString( 'name="ote_adults"', $html );
	}


	public function test_booking_form_is_not_rendered_without_available_departures() {
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'No Departure Tour' )
		);
		$html = OpenTrip_Engine::instance()->booking_shortcode( array( 'tour_id' => $tour_id ) );
		$this->assertStringContainsString( 'No departures are currently available', $html );
		$this->assertStringNotContainsString( 'class="ote-booking-form"', $html );
	}

	public function test_customer_booking_emails_are_sent_for_supported_statuses() {
		$booking_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_booking',
				'post_status' => 'private',
				'post_title'  => 'Email Test Booking',
			)
		);
		update_post_meta( $booking_id, '_ote_customer_email', 'traveller@example.com' );
		update_post_meta( $booking_id, '_ote_customer_name', 'Test Traveller' );
		update_post_meta( $booking_id, '_ote_tour_id', 123 );
		update_post_meta( $booking_id, '_ote_booking_reference', 'OTE-EMAIL-1' );
		update_post_meta( $booking_id, '_ote_travel_date', '2027-06-01' );
		update_post_meta( $booking_id, '_ote_travellers', 2 );
		update_post_meta( $booking_id, '_ote_total_price', 250 );
		update_post_meta( $booking_id, '_ote_currency', 'USD' );

		$sent = array();
		$capture = function( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );

		$engine = OpenTrip_Engine::instance();
		$engine->send_customer_booking_email( $booking_id, 'pending' );
		$engine->send_customer_booking_email( $booking_id, 'confirmed' );
		$engine->send_customer_booking_email( $booking_id, 'unsupported' );

		remove_filter( 'pre_wp_mail', $capture, 10 );
		$this->assertCount( 2, $sent );
		$this->assertSame( 'traveller@example.com', $sent[0]['to'] );
		$this->assertStringContainsString( 'received your booking request', strtolower( $sent[0]['subject'] ) );
		$this->assertStringContainsString( 'OTE-EMAIL-1', $sent[0]['message'] );
		$this->assertStringContainsString( 'confirmed', strtolower( $sent[1]['subject'] ) );
	}


	public function test_booking_status_and_seat_reservation_are_idempotent_and_transactional() {
		global $wpdb;
		$departures_table = $wpdb->prefix . 'ote_departures';
		$reservations_table = $wpdb->prefix . 'ote_booking_reservations';
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Transactional Test Tour' )
		);
		$wpdb->insert(
			$departures_table,
			array(
				'tour_id' => $tour_id,
				'start_date' => wp_date( 'Y-m-d', strtotime( '+20 days' ) ),
				'end_date' => wp_date( 'Y-m-d', strtotime( '+22 days' ) ),
				'capacity' => 3,
				'reserved' => 0,
				'price' => 100,
				'currency' => 'USD',
				'status' => 'open',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);
		$departure_id = (int) $wpdb->insert_id;
		$booking_id = self::factory()->post->create(
			array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Transactional Booking' )
		);
		update_post_meta( $booking_id, '_ote_booking_status', 'pending' );

		$inventory = OTE_Departures::instance();
		$first = $inventory->transition_booking( $booking_id, $tour_id, $departure_id, 2, 'confirmed' );
		$second = $inventory->transition_booking( $booking_id, $tour_id, $departure_id, 2, 'confirmed' );

		$this->assertNotFalse( $first );
		$this->assertTrue( $first['changed'] );
		$this->assertNotFalse( $second );
		$this->assertFalse( $second['changed'] );
		$this->assertSame( '2', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$departures_table} WHERE id = %d", $departure_id ) ) );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations_table} WHERE booking_id = %d", $booking_id ) ) );

		$cancelled = $inventory->transition_booking( $booking_id, $tour_id, $departure_id, 2, 'cancelled' );
		$this->assertNotFalse( $cancelled );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$departures_table} WHERE id = %d", $departure_id ) ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations_table} WHERE booking_id = %d", $booking_id ) ) );

		$reconfirmed = $inventory->transition_booking( $booking_id, $tour_id, $departure_id, 2, 'confirmed' );
		$this->assertNotFalse( $reconfirmed );
		$this->assertSame( '2', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$departures_table} WHERE id = %d", $departure_id ) ) );
		$wpdb->delete( $reservations_table, array( 'booking_id' => $booking_id ), array( '%d' ) );
		$wpdb->delete( $departures_table, array( 'id' => $departure_id ), array( '%d' ) );
	}

	public function test_failed_booking_confirmation_rolls_back_status_and_reservation() {
		global $wpdb;
		$departures_table = $wpdb->prefix . 'ote_departures';
		$reservations_table = $wpdb->prefix . 'ote_booking_reservations';
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Full Departure Test Tour' )
		);
		$wpdb->insert(
			$departures_table,
			array(
				'tour_id' => $tour_id,
				'start_date' => wp_date( 'Y-m-d', strtotime( '+20 days' ) ),
				'end_date' => wp_date( 'Y-m-d', strtotime( '+21 days' ) ),
				'capacity' => 1,
				'reserved' => 1,
				'price' => 100,
				'currency' => 'USD',
				'status' => 'open',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);
		$departure_id = (int) $wpdb->insert_id;
		$booking_id = self::factory()->post->create(
			array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Cannot Fit Booking' )
		);
		update_post_meta( $booking_id, '_ote_booking_status', 'pending' );

		$result = OTE_Departures::instance()->transition_booking( $booking_id, $tour_id, $departure_id, 1, 'confirmed' );
		$this->assertFalse( $result );
		$this->assertSame( 'pending', get_post_meta( $booking_id, '_ote_booking_status', true ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations_table} WHERE booking_id = %d", $booking_id ) ) );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$departures_table} WHERE id = %d", $departure_id ) ) );
		$wpdb->delete( $departures_table, array( 'id' => $departure_id ), array( '%d' ) );
	}

	public function test_repeated_single_seat_reservations_never_exceed_capacity() {
		global $wpdb;
		$table = $wpdb->prefix . 'ote_departures';
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Capacity Boundary Test Tour',
			)
		);
		$wpdb->insert(
			$table,
			array(
				'tour_id'    => $tour_id,
				'start_date' => wp_date( 'Y-m-d', strtotime( '+10 days' ) ),
				'end_date'   => wp_date( 'Y-m-d', strtotime( '+11 days' ) ),
				'capacity'   => 3,
				'reserved'   => 0,
				'price'      => 100,
				'currency'   => 'USD',
				'status'     => 'open',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);
		$departure_id = (int) $wpdb->insert_id;
		$inventory = OTE_Departures::instance();
		$successes = 0;
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			if ( $inventory->reserve( $departure_id, $tour_id, 1 ) ) {
				$successes++;
			}
		}
		$this->assertSame( 3, $successes );
		$this->assertSame( '3', (string) $wpdb->get_var( $wpdb->prepare( "SELECT reserved FROM {$table} WHERE id = %d", $departure_id ) ) );
		$wpdb->delete( $table, array( 'id' => $departure_id ), array( '%d' ) );
	}

}
