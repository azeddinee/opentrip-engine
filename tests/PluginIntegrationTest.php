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
		$engine->is_submission_rate_limited( 'booking', $email );
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
		$this->assertSame( '0', (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
			"SELECT COUNT(*) FROM " . OTE_Submission_Limiter::table_name() . " WHERE email_hash = %s",
			hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) )
		) ) );
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
		$this->assertFalse( current_user_can( 'delete_post', $booking_id ) );
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




	public function test_public_form_handlers_ignore_array_shaped_action_values() {
		$original_post = $_POST;
		$_POST = array(
			'ote_action'        => array( 'submit_booking' ),
			'ote_review_action' => array( 'submit_review' ),
		);
		$engine = OpenTrip_Engine::instance();
		$engine->handle_booking();
		$engine->handle_review_submission();
		$_POST = $original_post;
		$this->assertTrue( true );
	}

	public function test_email_notification_settings_are_validated_and_can_disable_customer_mail() {
		$engine = OpenTrip_Engine::instance();
		$this->assertSame( '1', $engine->sanitize_boolean_setting( '1' ) );
		$this->assertSame( '0', $engine->sanitize_boolean_setting( '0' ) );
		$previous_email = get_option( 'ote_notification_email', get_option( 'admin_email' ) );
		$this->assertSame( $previous_email, $engine->sanitize_notification_email( 'not-an-email' ) );
		$this->assertSame( 'alerts@example.com', $engine->sanitize_notification_email( 'alerts@example.com' ) );

		$booking_id = self::factory()->post->create(
			array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Notifications Disabled Test' )
		);
		update_post_meta( $booking_id, '_ote_customer_email', 'traveller@example.com' );
		update_option( 'ote_customer_notifications_enabled', '0' );
		$sent = array();
		$capture = function( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		$engine->send_customer_booking_email( $booking_id, 'pending' );
		remove_filter( 'pre_wp_mail', $capture, 10 );
		delete_option( 'ote_customer_notifications_enabled' );
		$this->assertCount( 0, $sent );
	}

	public function test_booking_form_is_not_rendered_without_available_departures() {
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'No Departure Tour' )
		);
		$html = OpenTrip_Engine::instance()->booking_shortcode( array( 'tour_id' => $tour_id ) );
		$this->assertStringContainsString( 'No departures are currently available', $html );
		$this->assertStringNotContainsString( 'class="ote-booking-form"', $html );
	}


	public function test_agency_booking_notification_uses_configured_recipient_and_toggle() {
		$booking_id = self::factory()->post->create(
			array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Agency Email Test' )
		);
		update_post_meta( $booking_id, '_ote_tour_id', 123 );
		update_post_meta( $booking_id, '_ote_booking_reference', 'OTE-ADMIN-1' );
		update_post_meta( $booking_id, '_ote_customer_name', 'Agency Test Customer' );
		update_post_meta( $booking_id, '_ote_customer_email', 'customer@example.com' );
		update_post_meta( $booking_id, '_ote_travel_date', '2027-08-10' );
		update_post_meta( $booking_id, '_ote_travellers', 3 );

		update_option( 'ote_notification_email', 'agency@example.com' );
		update_option( 'ote_admin_notifications_enabled', '1' );
		$sent = array();
		$capture = function( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );

		$engine = OpenTrip_Engine::instance();
		$this->assertTrue( $engine->send_admin_booking_email( $booking_id ) );
		update_option( 'ote_admin_notifications_enabled', '0' );
		$this->assertFalse( $engine->send_admin_booking_email( $booking_id ) );

		remove_filter( 'pre_wp_mail', $capture, 10 );
		delete_option( 'ote_notification_email' );
		delete_option( 'ote_admin_notifications_enabled' );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'agency@example.com', $sent[0]['to'] );
		$this->assertStringContainsString( 'OTE-ADMIN-1', $sent[0]['message'] );
		$this->assertStringContainsString( 'customer@example.com', $sent[0]['message'] );
		$this->assertStringContainsString( 'Travellers: 3', $sent[0]['message'] );
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



	public function test_departure_can_be_closed_and_reopened_only_when_seats_remain() {
		global $wpdb;
		$table = $wpdb->prefix . 'ote_departures';
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Departure Status Test Tour' )
		);
		$wpdb->insert(
			$table,
			array(
				'tour_id' => $tour_id,
				'start_date' => wp_date( 'Y-m-d', strtotime( '+15 days' ) ),
				'end_date' => wp_date( 'Y-m-d', strtotime( '+16 days' ) ),
				'capacity' => 2,
				'reserved' => 0,
				'price' => 100,
				'currency' => 'USD',
				'status' => 'open',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
		);
		$departure_id = (int) $wpdb->insert_id;
		$inventory = OTE_Departures::instance();

		$this->assertTrue( $inventory->set_status( $departure_id, 'closed' ) );
		$this->assertFalse( $inventory->reserve( $departure_id, $tour_id, 1 ) );
		$this->assertTrue( $inventory->set_status( $departure_id, 'open' ) );
		$this->assertTrue( $inventory->reserve( $departure_id, $tour_id, 2 ) );
		$this->assertTrue( $inventory->set_status( $departure_id, 'closed' ) );
		$this->assertFalse( $inventory->set_status( $departure_id, 'open' ) );
		$this->assertTrue( $inventory->release( $departure_id, 2 ) );
		$wpdb->delete( $table, array( 'id' => $departure_id ), array( '%d' ) );
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
		add_post_meta( $booking_id, '_ote_booking_status', 'pending', false );

		$inventory = OTE_Departures::instance();
		$first = $inventory->transition_booking( $booking_id, $tour_id, $departure_id, 2, 'confirmed' );
		$second = $inventory->transition_booking( $booking_id, $tour_id, $departure_id, 2, 'confirmed' );

		$this->assertNotFalse( $first );
		$this->assertTrue( $first['changed'] );
		$this->assertNotFalse( $second );
		$this->assertFalse( $second['changed'] );
		$this->assertSame( array( 'confirmed', 'confirmed' ), array_values( get_post_meta( $booking_id, '_ote_booking_status', false ) ) );
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


	public function test_public_rest_api_lists_only_published_tours_and_public_fields() {
		$published_id = self::factory()->post->create(
			array(
				'post_type'    => 'ote_tour',
				'post_status'  => 'publish',
				'post_title'   => 'Public API Tour',
				'post_content' => 'A public tour description.',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'draft',
				'post_title'  => 'Private Draft Tour',
			)
		);
		update_post_meta( $published_id, '_ote_price', '125.50' );
		update_post_meta( $published_id, '_ote_currency', 'USD' );
		update_post_meta( $published_id, '_ote_duration', '5 days' );
		update_post_meta( $published_id, '_ote_itinerary', array(
			array( 'day' => 1, 'title' => 'Arrival', 'city' => 'Marrakech', 'activities' => 'Old town walk' ),
		) );
		update_post_meta( $published_id, '_ote_inclusions', array( 'Breakfast' ) );
		update_post_meta( $published_id, '_ote_exclusions', array( 'Flights' ) );

		$booking_id = self::factory()->post->create(
			array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Private Booking' )
		);
		update_post_meta( $booking_id, '_ote_customer_email', 'private-customer@example.com' );
		update_post_meta( $booking_id, '_ote_customer_name', 'Private Customer' );

		$request  = new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( $published_id, $data[0]['id'] );
		$this->assertSame( 'Public API Tour', $data[0]['title'] );
		$this->assertSame( 125.5, $data[0]['price'] );
		$this->assertSame( 'Marrakech', $data[0]['itinerary'][0]['city'] );
		$this->assertSame( array( 'Breakfast' ), $data[0]['inclusions'] );
		$this->assertSame( array( 'Flights' ), $data[0]['exclusions'] );
		$this->assertArrayNotHasKey( 'customer_email', $data[0] );
		$this->assertArrayNotHasKey( 'bookings', $data[0] );
		$this->assertStringNotContainsString( 'private-customer@example.com', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'Private Draft Tour', wp_json_encode( $data ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	public function test_public_rest_api_returns_404_for_draft_and_password_protected_tours() {
		$draft_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'draft', 'post_title' => 'Draft API Tour' )
		);
		$password_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Password API Tour', 'post_password' => 'secret' )
		);

		$draft_response = rest_do_request( new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours/' . $draft_id ) );
		$password_response = rest_do_request( new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours/' . $password_id ) );

		$this->assertSame( 404, $draft_response->get_status() );
		$this->assertSame( 404, $password_response->get_status() );
	}

	public function test_public_rest_api_lists_only_current_open_departures() {
		global $wpdb;
		$table = $wpdb->prefix . 'ote_departures';
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Departure API Tour' )
		);
		$today = wp_date( 'Y-m-d' );
		$rows = array(
			array( 'start_date' => wp_date( 'Y-m-d', strtotime( '+12 days' ) ), 'status' => 'open', 'capacity' => 8, 'reserved' => 3 ),
			array( 'start_date' => wp_date( 'Y-m-d', strtotime( '+20 days' ) ), 'status' => 'closed', 'capacity' => 8, 'reserved' => 0 ),
			array( 'start_date' => wp_date( 'Y-m-d', strtotime( '-2 days' ) ), 'status' => 'open', 'capacity' => 8, 'reserved' => 0 ),
		);
		$inserted_ids = array();
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array(
					'tour_id'    => $tour_id,
					'start_date' => $row['start_date'],
					'end_date'   => $row['start_date'],
					'capacity'   => $row['capacity'],
					'reserved'   => $row['reserved'],
					'price'      => 249.95,
					'currency'   => 'USD',
					'status'     => $row['status'],
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' )
			);
			$inserted_ids[] = (int) $wpdb->insert_id;
		}

		$response = rest_do_request( new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours/' . $tour_id . '/departures' ) );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 5, $data[0]['seats_remaining'] );
		$this->assertSame( 249.95, $data[0]['price'] );
		$this->assertSame( 'USD', $data[0]['currency'] );

		foreach ( $inserted_ids as $departure_id ) {
			$wpdb->delete( $table, array( 'id' => $departure_id ), array( '%d' ) );
		}
	}

	public function test_public_rest_api_filters_tours_by_destination_and_lists_destinations() {
		$tour_id = self::factory()->post->create(
			array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Morocco API Tour' )
		);
		$term = wp_insert_term( 'Morocco', 'ote_destination', array( 'slug' => 'morocco-api' ) );
		$this->assertNotWPError( $term );
		wp_set_object_terms( $tour_id, array( (int) $term['term_id'] ), 'ote_destination' );

		$request = new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours' );
		$request->set_param( 'destination', 'morocco-api' );
		$filtered = rest_do_request( $request );
		$destinations = rest_do_request( new WP_REST_Request( 'GET', '/opentrip-engine/v1/destinations' ) );

		$this->assertSame( 200, $filtered->get_status() );
		$this->assertCount( 1, $filtered->get_data() );
		$this->assertSame( 'Morocco', $filtered->get_data()[0]['destinations'][0]['name'] );
		$this->assertSame( 200, $destinations->get_status() );
		$this->assertSame( 'morocco-api', $destinations->get_data()[0]['slug'] );
	}

}
