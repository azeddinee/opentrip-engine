<?php
/**
 * Contract for optional hosted payment providers.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface OTE_Payment_Gateway {
	/**
	 * Provider identifier used in booking metadata.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Create or reuse a hosted checkout session for a confirmed booking.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array|WP_Error
	 */
	public function create_checkout_session( $booking_id );

	/**
	 * Verify and process a signed provider webhook.
	 *
	 * @param string $payload Raw request body.
	 * @param string $signature Provider signature header.
	 * @return bool|WP_Error
	 */
	public function handle_webhook( $payload, $signature );
}
