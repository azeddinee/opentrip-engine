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
		update_post_meta( $booking_id, '_ote_child_ages', array( 7 ) );
		update_post_meta( $booking_id, '_ote_infants', 1 );
		update_post_meta( $booking_id, '_ote_infant_ages', array( 1 ) );
		update_post_meta( $booking_id, '_ote_quote_snapshot', array( 'currency' => 'USD', 'children' => array( 'ages' => array( 7 ), 'items' => array( array( 'age' => 7, 'unit_price' => 50 ) ), 'subtotal' => 50 ), 'infants' => array( 'ages' => array( 1 ), 'subtotal' => 0 ), 'adults' => array( 'subtotal' => 100 ), 'rooms' => array( 'subtotal' => 0 ) ) );
		update_post_meta( $booking_id, '_ote_payment_provider', 'stripe' );
		update_post_meta( $booking_id, '_ote_payment_status', 'paid' );
		update_post_meta( $booking_id, '_ote_payment_amount', 100 );
		update_post_meta( $booking_id, '_ote_payment_currency', 'USD' );
		update_post_meta( $booking_id, '_ote_payment_session_id', 'cs_test_privacy' );
		update_post_meta( $booking_id, '_ote_payment_url', 'https://checkout.stripe.com/c/pay/cs_test_privacy' );
		update_post_meta( $booking_id, '_ote_payment_transaction_id', 'pi_privacy' );

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
		$this->assertStringContainsString( 'Child ages', wp_json_encode( $export['data'] ) );
		$this->assertStringContainsString( 'Infant ages', wp_json_encode( $export['data'] ) );
		$this->assertStringContainsString( 'Payment status', wp_json_encode( $export['data'] ) );
		$this->assertStringContainsString( 'Payment amount', wp_json_encode( $export['data'] ) );
		$this->assertTrue( $export['done'] );

		$erase = $engine->erase_personal_data( $email, 1 );
		$this->assertSame( 2, $erase['items_removed'] );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_customer_email', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_customer_message', true ) );
		$this->assertSame( 'Anonymized customer', get_post_meta( $booking_id, '_ote_customer_name', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_child_ages', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_infant_ages', true ) );
		$this->assertSame( 'paid', get_post_meta( $booking_id, '_ote_payment_status', true ) );
		$this->assertSame( 100, (float) get_post_meta( $booking_id, '_ote_payment_amount', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_payment_session_id', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_payment_url', true ) );
		$this->assertSame( '', get_post_meta( $booking_id, '_ote_payment_transaction_id', true ) );
		$redacted_quote = get_post_meta( $booking_id, '_ote_quote_snapshot', true );
		$this->assertSame( array(), $redacted_quote['children']['ages'] );
		$this->assertSame( array(), $redacted_quote['infants']['ages'] );
		$this->assertArrayNotHasKey( 'age', $redacted_quote['children']['items'][0] ?? array() );
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





	public function test_public_forms_have_explicit_labels_grouping_and_accessible_instructions() {
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Accessible Form Tour',
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
			)
		);

		$engine = OpenTrip_Engine::instance();
		$booking_html = $engine->booking_shortcode( array( 'tour_id' => $tour_id ) );
		$booking_html_second = $engine->booking_shortcode( array( 'tour_id' => $tour_id ) );
		$review_html = $engine->review_form_shortcode( array( 'tour_id' => $tour_id ) );
		$review_html_second = $engine->review_form_shortcode( array( 'tour_id' => $tour_id ) );

		$this->assertStringContainsString( 'aria-labelledby=', $booking_html );
		$this->assertStringContainsString( '<fieldset class="ote-traveller-fields">', $booking_html );
		$this->assertStringContainsString( '<legend>Travellers</legend>', $booking_html );
		$this->assertStringContainsString( 'aria-describedby=', $booking_html );
		$this->assertSame( 1, preg_match( '/<label for="([^"]+-name)">Full name<\/label><br><input id="\\1"/', $booking_html ) );
		$this->assertSame( 1, preg_match( '/<label for="([^"]+-email)">Email address<\/label><br><input id="\\1"/', $booking_html ) );
		preg_match( '/id="(ote-booking-[^"]+-heading)"/', $booking_html, $first_heading );
		preg_match( '/id="(ote-booking-[^"]+-heading)"/', $booking_html_second, $second_heading );
		$this->assertNotEmpty( $first_heading[1] );
		$this->assertNotEmpty( $second_heading[1] );
		$this->assertNotSame( $first_heading[1], $second_heading[1], 'Repeated shortcodes must not create duplicate IDs.' );

		$this->assertStringContainsString( 'aria-labelledby=', $review_html );
		$this->assertSame( 1, preg_match( '/<label for="([^"]+-rating)">Rating<\/label><br><select id="\\1"/', $review_html ) );
		preg_match( '/id="(ote-review-[^"]+-heading)"/', $review_html, $first_review_heading );
		preg_match( '/id="(ote-review-[^"]+-heading)"/', $review_html_second, $second_review_heading );
		$this->assertNotEmpty( $first_review_heading[1] );
		$this->assertNotEmpty( $second_review_heading[1] );
		$this->assertNotSame( $first_review_heading[1], $second_review_heading[1], 'Repeated review shortcodes must not create duplicate IDs.' );
		$this->assertStringContainsString( 'name="ote_review_content"', $review_html );

		$inclusions = $engine->inclusions_shortcode( array( 'tour_id' => $tour_id ) );
		$this->assertSame( '', $inclusions );
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/opentrip-engine.css' );
		$this->assertStringContainsString( ':focus-visible', $css );
		$this->assertStringContainsString( 'prefers-reduced-motion: reduce', $css );
		$this->assertNotFalse( has_action( 'init', array( $engine, 'load_textdomain' ) ) );
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/opentrip-engine.pot' );
		$this->assertStringContainsString( 'msgid "Guest reviews"', $pot );
		$this->assertStringContainsString( 'msgid "For example: 5, 9"', $pot );
	}



	public function test_public_review_ratings_and_tour_images_have_accessible_alternatives() {
		$tour_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_tour',
				'post_status' => 'publish',
				'post_title'  => 'Accessible Image Tour',
			)
		);
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $tour_id );
		$this->assertNotWPError( $attachment_id );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', '' );
		set_post_thumbnail( $tour_id, $attachment_id );
		update_post_meta( $tour_id, '_ote_gallery_ids', array( $attachment_id ) );

		$review_id = self::factory()->post->create(
			array(
				'post_type'   => 'ote_review',
				'post_status' => 'publish',
				'post_title'  => 'Accessible rating review',
				'post_content' => 'A helpful guest review.',
			)
		);
		update_post_meta( $review_id, '_ote_review_tour_id', $tour_id );
		update_post_meta( $review_id, '_ote_review_name', 'Guest' );
		update_post_meta( $review_id, '_ote_review_rating', 4 );

		$engine = OpenTrip_Engine::instance();
		$tours_html = $engine->tours_shortcode( array( 'limit' => 10 ) );
		$gallery_html = $engine->gallery_shortcode( array( 'tour_id' => $tour_id ) );
		$reviews_html = $engine->reviews_shortcode( array( 'tour_id' => $tour_id ) );

		$this->assertStringContainsString( 'alt="Accessible Image Tour"', $tours_html );
		$this->assertStringContainsString( 'alt="Tour photo 1 for Accessible Image Tour"', $gallery_html );
		$this->assertStringContainsString( 'role="img" aria-label="4 out of 5 stars"', $reviews_html );
		$this->assertFileExists( dirname( __DIR__ ) . '/scripts/make-pot.sh' );
		$this->assertFileExists( dirname( __DIR__ ) . '/languages/README.md' );
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
		update_post_meta( $booking_id, '_ote_quote_snapshot', array( 'currency' => 'USD', 'adults' => array( 'subtotal' => 200 ), 'children' => array( 'subtotal' => 50 ), 'infants' => array( 'subtotal' => 0 ), 'rooms' => array( 'subtotal' => 20 ) ) );

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
		$this->assertStringContainsString( 'Adults: 200.00 USD', $sent[0]['message'] );
		$this->assertStringContainsString( 'Rooms: 20.00 USD', $sent[0]['message'] );
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
				'post_content' => 'A public <strong>tour</strong> description. <script>alert(1)</script>',
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
		$this->assertNotEmpty( $data );
		$ids = wp_list_pluck( $data, 'id' );
		$this->assertContains( $published_id, $ids );
		$api_tour = null;
		foreach ( $data as $item ) {
			if ( $published_id === $item['id'] ) {
				$api_tour = $item;
				break;
			}
		}
		$this->assertNotNull( $api_tour );
		$this->assertSame( 'Public API Tour', $api_tour['title'] );
		$this->assertStringContainsString( '<strong>tour</strong>', $api_tour['content'] );
		$this->assertStringNotContainsString( '<script>', $api_tour['content'] );
		$this->assertSame( 125.5, $api_tour['price'] );
		$this->assertSame( 'Marrakech', $api_tour['itinerary'][0]['city'] );
		$this->assertSame( array( 'Breakfast' ), $api_tour['inclusions'] );
		$this->assertSame( array( 'Flights' ), $api_tour['exclusions'] );
		$this->assertArrayNotHasKey( 'customer_email', $api_tour );
		$this->assertArrayNotHasKey( 'bookings', $api_tour );
		$this->assertStringNotContainsString( 'private-customer@example.com', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'Private Draft Tour', wp_json_encode( $data ) );
		$this->assertSame( (string) count( $data ), $response->get_headers()['X-WP-Total'] );
	}

	public function test_public_rest_api_rejects_invalid_page_size() {
		$request = new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours' );
		$request->set_param( 'per_page', 51 );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
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
					'adult_price' => 199.99,
					'child_price' => 99.50,
					'infant_price' => 0,
					'room_price' => 12.50,
					'infant_max_age' => 1,
					'child_min_age' => 2,
					'child_max_age' => 11,
					'child_age_bands' => '[{"min_age":2,"max_age":11,"price":80}]',
					'currency'   => 'USD',
					'status'     => $row['status'],
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			$inserted_ids[] = (int) $wpdb->insert_id;
		}

		$response = rest_do_request( new WP_REST_Request( 'GET', '/opentrip-engine/v1/tours/' . $tour_id . '/departures' ) );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 5, $data[0]['seats_remaining'] );
		$this->assertSame( 199.99, $data[0]['price'] );
		$this->assertSame( 99.5, $data[0]['child_price'] );
		$this->assertSame( 0.0, $data[0]['infant_price'] );
		$this->assertSame( 12.5, $data[0]['room_price'] );
		$this->assertSame( array( array( 'min_age' => 2, 'max_age' => 11, 'price' => 80.0 ) ), $data[0]['child_age_bands'] );
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


	public function test_traveller_quote_supports_age_bands_infants_and_room_charges() {
		$departure = (object) array( 'price' => 150, 'adult_price' => null, 'child_price' => null, 'infant_price' => 0, 'room_price' => 15, 'currency' => 'EUR', 'infant_max_age' => 1, 'child_min_age' => 2, 'child_max_age' => 11, 'child_age_bands' => wp_json_encode( array( array( 'min_age' => 2, 'max_age' => 5, 'price' => 70 ), array( 'min_age' => 6, 'max_age' => 11, 'price' => 80 ) ) ) );
		$quote = OTE_Pricing::calculate_quote( $departure, 2, array( 2, 5, 6, 11 ), array( 0, 1 ), 2 );
		$this->assertNotWPError( $quote );
		$this->assertSame( 300.0, $quote['adults']['subtotal'] );
		$this->assertSame( 300.0, $quote['children']['subtotal'] );
		$this->assertSame( 0.0, $quote['infants']['subtotal'] );
		$this->assertSame( 30.0, $quote['rooms']['subtotal'] );
		$this->assertSame( 630.0, $quote['total'] );
		$this->assertSame( 'EUR', $quote['currency'] );
		$this->assertSame( array( 2, 5, 6, 11 ), $quote['children']['ages'] );
		$this->assertSame( array( 0, 1 ), $quote['infants']['ages'] );
	}

	public function test_traveller_quote_preserves_legacy_rates_and_rounds_money() {
		$legacy = (object) array( 'price' => 99.99, 'currency' => 'USD' );
		$quote = OTE_Pricing::calculate_quote( $legacy, 1, array( 11 ), array( 0 ), 1 );
		$this->assertNotWPError( $quote );
		$this->assertSame( 99.99, $quote['adults']['subtotal'] );
		$this->assertSame( 99.99, $quote['children']['subtotal'] );
		$this->assertSame( 0.0, $quote['infants']['subtotal'] );
		$this->assertSame( 0.0, $quote['rooms']['subtotal'] );
		$this->assertSame( 199.98, $quote['total'] );

		$rounding = (object) array( 'price' => 10, 'adult_price' => 10.006, 'currency' => 'USD' );
		$rounded = OTE_Pricing::calculate_quote( $rounding, 3, array(), array(), 1 );
		$this->assertNotWPError( $rounded );
		$this->assertSame( 10.01, $rounded['adults']['unit_price'] );
		$this->assertSame( 30.03, $rounded['total'] );
	}

	public function test_traveller_age_boundaries_and_invalid_rules_are_rejected() {
		$departure = (object) array( 'price' => 100, 'currency' => 'USD', 'infant_max_age' => 1, 'child_min_age' => 2, 'child_max_age' => 11, 'child_age_bands' => '[{"min_age":2,"max_age":5,"price":50},{"min_age":6,"max_age":11,"price":75}]' );
		$this->assertWPError( OTE_Pricing::calculate_quote( $departure, 1, array( 1 ), array(), 1 ) );
		$this->assertWPError( OTE_Pricing::calculate_quote( $departure, 1, array( 12 ), array(), 1 ) );
		$overwide_band = (object) array( 'price' => 100, 'currency' => 'USD', 'infant_max_age' => 1, 'child_min_age' => 2, 'child_max_age' => 11, 'child_age_bands' => '[{"min_age":2,"max_age":17,"price":50}]' );
		$this->assertWPError( OTE_Pricing::calculate_quote( $overwide_band, 1, array( 12 ), array(), 1 ) );
		$this->assertWPError( OTE_Pricing::calculate_quote( $departure, 1, array(), array( 2 ), 1 ) );
		$this->assertNotWPError( OTE_Pricing::calculate_quote( $departure, 1, array( 5, 6 ), array(), 1 ) );
		$this->assertWPError( OTE_Pricing::normalize_child_age_bands( '[{"min_age":2,"max_age":6,"price":50},{"min_age":6,"max_age":11,"price":70}]' ) );
		$this->assertWPError( OTE_Pricing::normalize_child_age_bands( '[{"min_age":2,"max_age":5,"price":50},{"min_age":7,"max_age":11,"price":70}]' ) );
		$this->assertWPError( OTE_Pricing::normalize_child_age_bands( '{broken json' ) );
		$gapped = (object) array( 'price' => 100, 'currency' => 'USD', 'infant_max_age' => 1, 'child_min_age' => 2, 'child_max_age' => 11, 'child_age_bands' => '[{"min_age":2,"max_age":5,"price":50},{"min_age":7,"max_age":11,"price":75}]' );
		$this->assertWPError( OTE_Pricing::calculate_quote( $gapped, 1, array( 6 ), array(), 1 ) );
		$this->assertWPError( OTE_Pricing::calculate_quote( $departure, 1, array( 2.5 ), array(), 1 ) );
		$this->assertWPError( OTE_Pricing::calculate_quote( $departure, 1, array(), array( 1.5 ), 1 ) );
		$outside = clone $departure;
		$outside->child_age_bands = '[{"min_age":1,"max_age":5,"price":50}]';
		$this->assertWPError( OTE_Pricing::calculate_quote( $outside, 1, array( 5 ), array(), 1 ) );
	}

	public function test_age_parser_requires_one_valid_age_per_child_or_infant() {
		$this->assertSame( array(), OTE_Pricing::parse_ages( '', 0 ) );
		$this->assertSame( array( 4, 9 ), OTE_Pricing::parse_ages( '4, 9', 2 ) );
		$this->assertWPError( OTE_Pricing::parse_ages( '4', 2 ) );
		$this->assertWPError( OTE_Pricing::parse_ages( '4, eighteen', 2 ) );
		$this->assertWPError( OTE_Pricing::parse_ages( '18', 1 ) );
		$this->assertWPError( OTE_Pricing::parse_ages( str_repeat( '1,', 60 ), 30 ) );
		$this->assertWPError( OTE_Pricing::calculate_quote( (object) array( 'price' => 100, 'currency' => 'USD' ), 1, array( 5.5 ), array(), 1 ) );
		$this->assertWPError( OTE_Pricing::parse_ages( '4', 0 ) );
	}

	public function test_quote_snapshot_is_independent_of_later_departure_rate_changes() {
		$departure = (object) array( 'price' => 120, 'adult_price' => 120, 'child_price' => 60, 'infant_price' => 0, 'room_price' => 0, 'currency' => 'USD' );
		$snapshot = OTE_Pricing::calculate_quote( $departure, 2, array( 8 ), array(), 1 );
		$booking_id = self::factory()->post->create( array( 'post_type' => 'ote_booking', 'post_status' => 'private' ) );
		update_post_meta( $booking_id, '_ote_quote_snapshot', $snapshot );
		update_post_meta( $booking_id, '_ote_total_price', $snapshot['total'] );
		$departure->adult_price = 500;
		$departure->child_price = 250;
		$this->assertSame( 300.0, (float) get_post_meta( $booking_id, '_ote_total_price', true ) );
		$this->assertSame( $snapshot, get_post_meta( $booking_id, '_ote_quote_snapshot', true ) );
	}

	public function test_stripe_amount_conversion_respects_currency_minor_units() {
		$this->assertSame( 1234, OTE_Stripe_Gateway::amount_to_minor_units( 12.34, 'USD' ) );
		$this->assertSame( 500, OTE_Stripe_Gateway::amount_to_minor_units( 500, 'JPY' ) );
		$this->assertSame( 500, OTE_Stripe_Gateway::amount_to_minor_units( 5, 'ISK' ) );
		$this->assertSame( 500, OTE_Stripe_Gateway::amount_to_minor_units( 5, 'UGX' ) );
		$this->assertWPError( OTE_Stripe_Gateway::amount_to_minor_units( 5.25, 'JPY' ) );
		$this->assertWPError( OTE_Stripe_Gateway::amount_to_minor_units( 5.25, 'UGX' ) );
		$this->assertWPError( OTE_Stripe_Gateway::amount_to_minor_units( 10.001, 'USD' ) );
		$this->assertWPError( OTE_Stripe_Gateway::amount_to_minor_units( 10, 'US' ) );
		$deposit = OTE_Stripe_Gateway::calculate_payment_amount( 123.45, 'USD', 25 );
		$this->assertSame( 30.86, $deposit['amount'] );
		$this->assertSame( 3086, $deposit['amount_minor'] );
		$yen_deposit = OTE_Stripe_Gateway::calculate_payment_amount( 101, 'JPY', 50 );
		$this->assertSame( 51.0, $yen_deposit['amount'] );
		$this->assertSame( 51, $yen_deposit['amount_minor'] );
		$this->assertWPError( OTE_Stripe_Gateway::calculate_payment_amount( 10.5, 'JPY', 100 ) );
	}

	public function test_stripe_webhook_signature_requires_valid_hmac_and_fresh_timestamp() {
		$secret = 'whsec_unit_test_secret';
		$payload = '{"id":"evt_signature_test","type":"ignored.event"}';
		$timestamp = 1700000000;
		$digest = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		$header = 't=' . $timestamp . ',v1=' . $digest;
		$this->assertTrue( OTE_Stripe_Gateway::verify_signature( $payload, $header, $secret, $timestamp ) );
		$this->assertFalse( OTE_Stripe_Gateway::verify_signature( $payload . ' ', $header, $secret, $timestamp ) );
		$this->assertFalse( OTE_Stripe_Gateway::verify_signature( $payload, $header, $secret, $timestamp + 301 ) );
		$this->assertFalse( OTE_Stripe_Gateway::verify_signature( $payload, 't=bad,v1=bad', $secret, $timestamp ) );
		$this->assertFalse( OTE_Stripe_Gateway::verify_signature( str_repeat( 'x', 1048577 ), $header, $secret, $timestamp ) );
	}

	public function test_stripe_checkout_is_opt_in_and_requires_confirmed_booking() {
		$booking_id = $this->create_stripe_test_booking( 'pending' );
		$gateway = new OTE_Stripe_Gateway();
		delete_option( 'ote_stripe_enabled' );
		$this->assertWPError( $gateway->create_checkout_session( $booking_id ) );
		update_option( 'ote_stripe_enabled', '1' );
		update_option( 'ote_stripe_secret_key', 'sk_test_unit_test_key_123456789' );
		update_option( 'ote_stripe_webhook_secret', 'whsec_unit_test_secret' );
		$this->assertWPError( $gateway->create_checkout_session( $booking_id ) );
	}

	public function test_stripe_checkout_uses_saved_quote_and_reuses_pending_session() {
		$booking_id = $this->create_stripe_test_booking( 'confirmed' );
		update_option( 'ote_stripe_enabled', '1' );
		update_option( 'ote_stripe_secret_key', 'sk_test_unit_test_key_123456789' );
		update_option( 'ote_stripe_webhook_secret', 'whsec_unit_test_secret' );
		update_option( 'ote_stripe_deposit_percent', 50 );
		$calls = array();
		$mock = function( $preempt, $args, $url ) use ( &$calls ) {
			if ( OTE_Stripe_Gateway::API_URL !== $url ) { return $preempt; }
			$calls[] = $args;
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'cs_test_unit123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_unit123', 'expires_at' => time() + HOUR_IN_SECONDS, 'livemode' => false ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$gateway = new OTE_Stripe_Gateway();
		$session = $gateway->create_checkout_session( $booking_id );
		remove_filter( 'pre_http_request', $mock, 10 );
		$this->assertNotWPError( $session );
		$this->assertSame( 6000, $session['amount_minor'] );
		$this->assertSame( 'USD', $session['currency'] );
		$this->assertCount( 1, $calls );
		$this->assertSame( 6000, $calls[0]['body']['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'OTE-PAY-TEST', $calls[0]['body']['client_reference_id'] );
		$this->assertSame( 'Bearer sk_test_unit_test_key_123456789', $calls[0]['headers']['Authorization'] );
		$reused = $gateway->create_checkout_session( $booking_id );
		$this->assertNotWPError( $reused );
		$this->assertTrue( $reused['reused'] );
		$this->assertCount( 1, $calls );
	}

	public function test_stripe_webhook_updates_payment_idempotently_without_confirming_booking() {
		$booking_id = $this->create_stripe_test_booking( 'confirmed' );
		update_post_meta( $booking_id, '_ote_payment_provider', 'stripe' );
		update_post_meta( $booking_id, '_ote_payment_status', 'pending' );
		update_post_meta( $booking_id, '_ote_payment_session_id', 'cs_test_webhook123' );
		update_post_meta( $booking_id, '_ote_payment_amount_minor', 6000 );
		update_post_meta( $booking_id, '_ote_payment_currency', 'USD' );
		update_option( 'ote_stripe_webhook_secret', 'whsec_unit_test_secret' );
		$gateway = new OTE_Stripe_Gateway();
		$unpaid = $this->stripe_test_event( 'evt_unpaid_completed', 'checkout.session.completed', $booking_id, 'cs_test_webhook123', 'unpaid' );
		$this->assertTrue( $gateway->handle_webhook( $unpaid['payload'], $unpaid['signature'] ) );
		$this->assertSame( 'pending', get_post_meta( $booking_id, '_ote_payment_status', true ) );
		$paid = $this->stripe_test_event( 'evt_paid_async', 'checkout.session.async_payment_succeeded', $booking_id, 'cs_test_webhook123', 'paid' );
		$this->assertTrue( $gateway->handle_webhook( $paid['payload'], $paid['signature'] ) );
		$this->assertSame( 'paid', get_post_meta( $booking_id, '_ote_payment_status', true ) );
		$this->assertSame( 'confirmed', get_post_meta( $booking_id, '_ote_booking_status', true ) );
		$this->assertTrue( $gateway->handle_webhook( $paid['payload'], $paid['signature'] ) );
		$failed = $this->stripe_test_event( 'evt_failed_after_paid', 'checkout.session.async_payment_failed', $booking_id, 'cs_test_webhook123', 'unpaid' );
		$this->assertTrue( $gateway->handle_webhook( $failed['payload'], $failed['signature'] ) );
		$this->assertSame( 'paid', get_post_meta( $booking_id, '_ote_payment_status', true ) );
		$this->assertWPError( $gateway->handle_webhook( $paid['payload'], 't=1,v1=invalid' ) );
		delete_option( 'ote_stripe_event_' . hash( 'sha256', 'evt_paid_async' ) );
		delete_option( 'ote_stripe_event_' . hash( 'sha256', 'evt_failed_after_paid' ) );
	}

	public function test_stripe_webhook_route_and_secret_field_are_safe() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/opentrip-engine/v1/payments/stripe/webhook', $routes );
		update_option( 'ote_stripe_secret_key', 'sk_test_do_not_render_this_value_123' );
		ob_start();
		OpenTrip_Engine::instance()->render_stripe_secret_key_field();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( 'sk_test_do_not_render_this_value_123', $html );
	}

	private function create_stripe_test_booking( $status ) {
		$tour_id = self::factory()->post->create( array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'post_title' => 'Stripe Test Tour' ) );
		$booking_id = self::factory()->post->create( array( 'post_type' => 'ote_booking', 'post_status' => 'private', 'post_title' => 'Stripe Test Booking' ) );
		update_post_meta( $booking_id, '_ote_booking_status', $status );
		update_post_meta( $booking_id, '_ote_booking_reference', 'OTE-PAY-TEST' );
		update_post_meta( $booking_id, '_ote_tour_id', $tour_id );
		update_post_meta( $booking_id, '_ote_customer_email', 'stripe-customer@example.com' );
		update_post_meta( $booking_id, '_ote_customer_name', 'Stripe Test Customer' );
		update_post_meta( $booking_id, '_ote_quote_snapshot', array( 'total' => 120.00, 'currency' => 'USD' ) );
		return $booking_id;
	}

	private function stripe_test_event( $event_id, $event_type, $booking_id, $session_id, $payment_status ) {
		$reference = (string) get_post_meta( $booking_id, '_ote_booking_reference', true );
		$event = array(
			'id' => $event_id,
			'type' => $event_type,
			'data' => array( 'object' => array(
				'id' => $session_id,
				'client_reference_id' => $reference,
				'metadata' => array( 'booking_id' => (string) $booking_id, 'booking_reference' => $reference ),
				'currency' => 'usd',
				'amount_total' => 6000,
				'payment_status' => $payment_status,
				'livemode' => false,
				'payment_intent' => 'pi_test_transaction',
			) ),
		);
		$payload = wp_json_encode( $event );
		$timestamp = time();
		$secret = 'whsec_unit_test_secret';
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		return array( 'payload' => $payload, 'signature' => $signature );
	}
}
