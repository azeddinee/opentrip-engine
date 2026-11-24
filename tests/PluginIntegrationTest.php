<?php
/**
 * WordPress integration tests for OpenTrip Engine.
 */

class OTE_Plugin_Integration_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		OpenTrip_Engine::instance()->register_types();
		OTE_Departures::install();
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
	}

}
