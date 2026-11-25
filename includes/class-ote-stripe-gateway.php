<?php
/**
 * Stripe-hosted Checkout adapter.
 *
 * The plugin never receives or stores card numbers or security codes.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OTE_Stripe_Gateway implements OTE_Payment_Gateway {
	const API_URL = 'https://api.stripe.com/v1/checkout/sessions';
	const WEBHOOK_TOLERANCE = 300;

	/**
	 * @return string
	 */
	public function get_id() {
		return 'stripe';
	}

	/**
	 * Whether the adapter is enabled and its credentials are structurally valid.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$key = (string) get_option( 'ote_stripe_secret_key', '' );
		return '1' === (string) get_option( 'ote_stripe_enabled', '0' ) &&
			( 0 === strpos( $key, 'sk_test_' ) || 0 === strpos( $key, 'sk_live_' ) ) &&
			0 === strpos( (string) get_option( 'ote_stripe_webhook_secret', '' ), 'whsec_' );
	}

	/**
	 * Return test or live mode based on the secret key prefix.
	 *
	 * @return string
	 */
	public function get_mode() {
		return 0 === strpos( (string) get_option( 'ote_stripe_secret_key', '' ), 'sk_live_' ) ? 'live' : 'test';
	}

	/**
	 * Convert a decimal amount into Stripe's integer minor unit.
	 *
	 * Stripe uses zero-decimal amounts for a defined currency set. ISK and UGX
	 * retain two-decimal API representation but only permit whole-unit charges.
	 *
	 * @param float|string $amount Amount in major units.
	 * @param string       $currency ISO currency code.
	 * @return int|WP_Error
	 */
	public static function amount_to_minor_units( $amount, $currency ) {
		$currency = strtoupper( (string) $currency );
		if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount <= 0 ||
			(float) $amount > 99999999 || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new WP_Error( 'ote_invalid_payment_amount', __( 'The payment amount or currency is invalid for Stripe.', 'opentrip-engine' ) );
		}
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$whole_only_two_decimal = array( 'ISK', 'UGX' );
		$factor = in_array( $currency, $zero_decimal, true ) ? 1 : 100;
		if ( in_array( $currency, $whole_only_two_decimal, true ) ) {
			if ( abs( (float) $amount - round( (float) $amount ) ) > 0.00001 ) {
				return new WP_Error( 'ote_fractional_stripe_currency', __( 'This Stripe currency only accepts whole-unit amounts.', 'opentrip-engine' ) );
			}
		}
		$minor = round( (float) $amount * $factor, 0, PHP_ROUND_HALF_UP );
		if ( $minor < 1 || $minor > 99999999 || abs( (float) $amount - ( $minor / $factor ) ) > 0.00001 ) {
			return new WP_Error( 'ote_payment_amount_precision', __( 'The payment amount has unsupported precision or exceeds Stripe limits.', 'opentrip-engine' ) );
		}
		return (int) $minor;
	}

	/**
	 * Calculate the full or deposit amount from the immutable booking quote.
	 *
	 * @param float  $total Total quote in major units.
	 * @param string $currency ISO currency code.
	 * @param int    $deposit_percent Deposit percentage, 1–100.
	 * @return array|WP_Error
	 */
	public static function calculate_payment_amount( $total, $currency, $deposit_percent ) {
		$deposit_percent = (int) $deposit_percent;
		if ( $deposit_percent < 1 || $deposit_percent > 100 || ! is_numeric( $total ) || (float) $total <= 0 ) {
			return new WP_Error( 'ote_invalid_payment_percentage', __( 'The deposit percentage or booking total is invalid.', 'opentrip-engine' ) );
		}
		$currency = strtoupper( (string) $currency );
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$whole_unit_currencies = array_merge( $zero_decimal, array( 'ISK', 'UGX' ) );
		if ( in_array( $currency, $whole_unit_currencies, true ) && abs( (float) $total - round( (float) $total ) ) > 0.00001 ) {
			return new WP_Error( 'ote_fractional_booking_currency', __( 'The saved booking quote contains fractional units for a whole-unit Stripe currency.', 'opentrip-engine' ) );
		}
		$amount = round( (float) $total * $deposit_percent / 100, 2, PHP_ROUND_HALF_UP );
		if ( in_array( $currency, $whole_unit_currencies, true ) ) {
			$amount = round( $amount, 0, PHP_ROUND_HALF_UP );
		}
		$minor = self::amount_to_minor_units( $amount, $currency );
		if ( is_wp_error( $minor ) ) {
			return $minor;
		}
		return array(
			'amount'          => $amount,
			'amount_minor'    => $minor,
			'currency'        => $currency,
			'deposit_percent' => $deposit_percent,
		);
	}

	/**
	 * Create or reuse a hosted Checkout Session for a confirmed booking.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array|WP_Error
	 */
	public function create_checkout_session( $booking_id ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'ote_stripe_not_configured', __( 'Stripe payments are not enabled or configured.', 'opentrip-engine' ) );
		}
		$booking_id = absint( $booking_id );
		$booking = get_post( $booking_id );
		if ( ! $booking || 'ote_booking' !== $booking->post_type || 'confirmed' !== get_post_meta( $booking_id, '_ote_booking_status', true ) ) {
			return new WP_Error( 'ote_booking_not_payable', __( 'Only confirmed booking requests can receive a payment link.', 'opentrip-engine' ) );
		}
		if ( 'paid' === get_post_meta( $booking_id, '_ote_payment_status', true ) ) {
			return new WP_Error( 'ote_booking_already_paid', __( 'This booking already has a recorded payment.', 'opentrip-engine' ) );
		}
		$quote = get_post_meta( $booking_id, '_ote_quote_snapshot', true );
		if ( ! is_array( $quote ) || empty( $quote['total'] ) || empty( $quote['currency'] ) ) {
			return new WP_Error( 'ote_booking_quote_missing', __( 'A valid saved booking quote is required before payment.', 'opentrip-engine' ) );
		}
		$deposit_percent = absint( get_option( 'ote_stripe_deposit_percent', 100 ) );
		$amount = self::calculate_payment_amount( $quote['total'], $quote['currency'], $deposit_percent );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}
		$existing_url = (string) get_post_meta( $booking_id, '_ote_payment_url', true );
		$existing_expiry = absint( get_post_meta( $booking_id, '_ote_payment_expires_at', true ) );
		if ( 'pending' === get_post_meta( $booking_id, '_ote_payment_status', true ) && $existing_url && $existing_expiry > time() + 60 && self::is_checkout_url( $existing_url ) ) {
			return array(
				'id'             => (string) get_post_meta( $booking_id, '_ote_payment_session_id', true ),
				'url'            => $existing_url,
				'expires_at'     => $existing_expiry,
				'amount'         => (float) get_post_meta( $booking_id, '_ote_payment_amount', true ),
				'amount_minor'   => (int) get_post_meta( $booking_id, '_ote_payment_amount_minor', true ),
				'currency'       => (string) get_post_meta( $booking_id, '_ote_payment_currency', true ),
				'reused'         => true,
			);
		}
		$reference = sanitize_text_field( get_post_meta( $booking_id, '_ote_booking_reference', true ) );
		$email = sanitize_email( get_post_meta( $booking_id, '_ote_customer_email', true ) );
		$tour_id = absint( get_post_meta( $booking_id, '_ote_tour_id', true ) );
		$tour_name = sanitize_text_field( get_the_title( $tour_id ) );
		if ( '' === $reference || ! is_email( $email ) || ! $tour_id || '' === $tour_name ) {
			return new WP_Error( 'ote_booking_payment_details_missing', __( 'The booking is missing a customer email, reference or tour.', 'opentrip-engine' ) );
		}
		$base_url = get_permalink( $tour_id );
		if ( ! $base_url ) {
			$base_url = home_url( '/' );
		}
		$success_url = add_query_arg( array( 'ote_payment' => 'returned', 'ote_ref' => $reference ), $base_url );
		$cancel_url = add_query_arg( array( 'ote_payment' => 'cancelled', 'ote_ref' => rawurlencode( $reference ) ), $base_url );
		$attempt = absint( get_post_meta( $booking_id, '_ote_payment_attempt', true ) ) + 1;
		$idempotency_key = 'ote-booking-' . $booking_id . '-attempt-' . $attempt;
		$body = array(
			'mode'                => 'payment',
			'success_url'         => $success_url,
			'cancel_url'          => $cancel_url,
			'customer_email'      => $email,
			'client_reference_id' => $reference,
			'line_items'           => array(
				array(
					'price_data' => array(
						'currency'     => strtolower( $amount['currency'] ),
						'unit_amount'  => $amount['amount_minor'],
						'product_data' => array(
							'name'        => sprintf( __( 'Booking deposit — %s', 'opentrip-engine' ), $tour_name ),
							'description' => sprintf( __( 'Booking reference %s', 'opentrip-engine' ), $reference ),
						),
					),
					'quantity' => 1,
				),
			),
			'metadata' => array(
				'booking_id'        => (string) $booking_id,
				'booking_reference' => $reference,
			),
			'payment_intent_data' => array(
				'metadata' => array(
					'booking_id'        => (string) $booking_id,
					'booking_reference' => $reference,
				),
			),
		);
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'  => 'Bearer ' . get_option( 'ote_stripe_secret_key', '' ),
					'Content-Type'   => 'application/x-www-form-urlencoded',
					'Idempotency-Key' => $idempotency_key,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ote_stripe_connection_failed', __( 'Stripe could not be reached. No payment link was created.', 'opentrip-engine' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['id'] ) || empty( $data['url'] ) ||
			! preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', (string) $data['id'] ) ||
			( ( 'test' === $this->get_mode() ) !== ( 0 === strpos( (string) $data['id'], 'cs_test_' ) ) ) ||
			! self::is_checkout_url( $data['url'] ) || ! isset( $data['livemode'] ) || (bool) $data['livemode'] !== ( 'live' === $this->get_mode() ) ) {
			return new WP_Error( 'ote_stripe_session_failed', __( 'Stripe did not return a valid hosted checkout session. Check the Stripe settings and try again.', 'opentrip-engine' ) );
		}
		$expires_at = isset( $data['expires_at'] ) ? absint( $data['expires_at'] ) : time() + DAY_IN_SECONDS;
		$history = get_post_meta( $booking_id, '_ote_payment_session_history', true );
		$history = is_array( $history ) ? $history : array();
		$previous_session = (string) get_post_meta( $booking_id, '_ote_payment_session_id', true );
		if ( $previous_session && $previous_session !== $data['id'] ) {
			$history[] = array(
				'id' => $previous_session,
				'amount_minor' => (int) get_post_meta( $booking_id, '_ote_payment_amount_minor', true ),
				'currency' => strtoupper( (string) get_post_meta( $booking_id, '_ote_payment_currency', true ) ),
			);
			$history = array_slice( $history, -10 );
			update_post_meta( $booking_id, '_ote_payment_session_history', $history );
		}
		update_post_meta( $booking_id, '_ote_payment_provider', 'stripe' );
		update_post_meta( $booking_id, '_ote_payment_status', 'pending' );
		update_post_meta( $booking_id, '_ote_payment_session_id', sanitize_text_field( $data['id'] ) );
		update_post_meta( $booking_id, '_ote_payment_url', esc_url_raw( $data['url'] ) );
		update_post_meta( $booking_id, '_ote_payment_amount', (float) $amount['amount'] );
		update_post_meta( $booking_id, '_ote_payment_amount_minor', (int) $amount['amount_minor'] );
		update_post_meta( $booking_id, '_ote_payment_currency', $amount['currency'] );
		update_post_meta( $booking_id, '_ote_payment_expires_at', $expires_at );
		update_post_meta( $booking_id, '_ote_payment_attempt', $attempt );
		update_post_meta( $booking_id, '_ote_payment_created_at', current_time( 'mysql', true ) );
		return array(
			'id'           => sanitize_text_field( $data['id'] ),
			'url'          => esc_url_raw( $data['url'] ),
			'expires_at'   => $expires_at,
			'amount'       => (float) $amount['amount'],
			'amount_minor' => (int) $amount['amount_minor'],
			'currency'     => $amount['currency'],
			'reused'       => false,
		);
	}

	/**
	 * Verify and process a Stripe webhook.
	 *
	 * @param string $payload Raw request body.
	 * @param string $signature Stripe-Signature header.
	 * @return bool|WP_Error
	 */
	public function handle_webhook( $payload, $signature ) {
		$secret = (string) get_option( 'ote_stripe_webhook_secret', '' );
		if ( ! self::verify_signature( $payload, $signature, $secret ) ) {
			return new WP_Error( 'ote_invalid_stripe_signature', __( 'The Stripe webhook signature is invalid or expired.', 'opentrip-engine' ), array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || ! preg_match( '/^evt_[A-Za-z0-9]+$/', (string) $event['id'] ) || empty( $event['type'] ) || empty( $event['data']['object'] ) || ! is_array( $event['data']['object'] ) ) {
			return new WP_Error( 'ote_invalid_stripe_event', __( 'The Stripe webhook payload is invalid.', 'opentrip-engine' ), array( 'status' => 400 ) );
		}
		$event_type = sanitize_text_field( $event['type'] );
		$statuses = array(
			'checkout.session.completed'             => 'paid',
			'checkout.session.async_payment_succeeded' => 'paid',
			'checkout.session.async_payment_failed'  => 'failed',
			'checkout.session.expired'               => 'expired',
		);
		if ( ! isset( $statuses[ $event_type ] ) ) {
			return true;
		}
		$session = $event['data']['object'];
		$booking_id = isset( $session['metadata']['booking_id'] ) ? absint( $session['metadata']['booking_id'] ) : 0;
		$booking = get_post( $booking_id );
		if ( ! $booking || 'ote_booking' !== $booking->post_type ) {
			return new WP_Error( 'ote_stripe_booking_not_found', __( 'The booking linked to this Stripe event was not found.', 'opentrip-engine' ), array( 'status' => 404 ) );
		}
		$reference = (string) get_post_meta( $booking_id, '_ote_booking_reference', true );
		$session_id = (string) get_post_meta( $booking_id, '_ote_payment_session_id', true );
		$expected_currency = strtolower( (string) get_post_meta( $booking_id, '_ote_payment_currency', true ) );
		$expected_amount = (int) get_post_meta( $booking_id, '_ote_payment_amount_minor', true );
		$session_matches = ! empty( $session['id'] ) && $session_id && hash_equals( $session_id, (string) $session['id'] );
		if ( ! $session_matches && ! empty( $session['id'] ) ) {
			$history = get_post_meta( $booking_id, '_ote_payment_session_history', true );
			foreach ( is_array( $history ) ? $history : array() as $previous ) {
				if ( isset( $previous['id'], $previous['amount_minor'], $previous['currency'] ) && hash_equals( (string) $previous['id'], (string) $session['id'] ) ) {
					$session_matches = true;
					$expected_amount = (int) $previous['amount_minor'];
					$expected_currency = strtolower( (string) $previous['currency'] );
					break;
				}
			}
		}
		if ( 'stripe' !== get_post_meta( $booking_id, '_ote_payment_provider', true ) ||
			! $session_matches ||
			empty( $session['client_reference_id'] ) || ! hash_equals( $reference, (string) $session['client_reference_id'] ) ||
			empty( $session['metadata']['booking_reference'] ) || ! hash_equals( $reference, (string) $session['metadata']['booking_reference'] ) ||
			empty( $session['currency'] ) || strtolower( (string) $session['currency'] ) !== $expected_currency ||
			! isset( $session['amount_total'] ) || (int) $session['amount_total'] !== $expected_amount ||
			! isset( $session['livemode'] ) || (bool) $session['livemode'] !== ( 'live' === $this->get_mode() ) ) {
			return new WP_Error( 'ote_stripe_session_mismatch', __( 'The Stripe event does not match the saved booking payment session.', 'opentrip-engine' ), array( 'status' => 400 ) );
		}
		$event_key = 'ote_stripe_event_' . hash( 'sha256', (string) $event['id'] );
		if ( false !== get_option( $event_key, false ) ) {
			return true;
		}
		if ( ! add_option( $event_key, $booking_id, '', 'no' ) ) {
			if ( false !== get_option( $event_key, false ) ) {
				return true;
			}
			return new WP_Error( 'ote_stripe_event_ledger_failed', __( 'The payment event could not be recorded safely. Stripe can retry this delivery.', 'opentrip-engine' ), array( 'status' => 500 ) );
		}
		if ( 'checkout.session.completed' === $event_type && ( ! isset( $session['payment_status'] ) || 'paid' !== $session['payment_status'] ) ) {
			return true;
		}
		$new_status = $statuses[ $event_type ];
		$current_status = (string) get_post_meta( $booking_id, '_ote_payment_status', true );
		if ( 'paid' !== $current_status || 'paid' === $new_status ) {
			update_post_meta( $booking_id, '_ote_payment_status', $new_status );
		}
		if ( 'paid' === $new_status && 'confirmed' !== get_post_meta( $booking_id, '_ote_booking_status', true ) ) {
			update_post_meta( $booking_id, '_ote_payment_attention', __( 'Payment received after the booking request was no longer confirmed. Review the booking and arrange any required refund manually.', 'opentrip-engine' ) );
		}
		update_post_meta( $booking_id, '_ote_payment_last_session_id', sanitize_text_field( $session['id'] ) );
		update_post_meta( $booking_id, '_ote_payment_last_event_id', sanitize_text_field( $event['id'] ) );
		update_post_meta( $booking_id, '_ote_payment_last_event_type', $event_type );
		update_post_meta( $booking_id, '_ote_payment_updated_at', current_time( 'mysql', true ) );
		if ( 'paid' === $new_status ) {
			update_post_meta( $booking_id, '_ote_payment_transaction_id', isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ? sanitize_text_field( $session['payment_intent'] ) : '' );
		}
		return true;
	}

	/**
	 * Verify Stripe's signed timestamped webhook header.
	 *
	 * @param string   $payload Raw request body.
	 * @param string   $header Stripe-Signature header.
	 * @param string   $secret Webhook signing secret.
	 * @param int|null $now Current timestamp for deterministic tests.
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $now = null ) {
		if ( ! is_string( $payload ) || strlen( $payload ) > 1048576 || ! is_string( $header ) ||
			0 !== strpos( (string) $secret, 'whsec_' ) ) {
			return false;
		}
		$timestamp = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && preg_match( '/^[a-f0-9]{64}$/i', $pair[1] ) ) {
				$signatures[] = strtolower( $pair[1] );
			}
		}
		$now = null === $now ? time() : (int) $now;
		if ( null === $timestamp || empty( $signatures ) || abs( $now - $timestamp ) > self::WEBHOOK_TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, (string) $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register the public webhook endpoint. Requests are authenticated by signature.
	 */
	public static function register_webhook_route() {
		register_rest_route(
			'opentrip-engine/v1',
			'/payments/stripe/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST endpoint callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_webhook( $request ) {
		$gateway = OTE_Payment_Gateway_Registry::get( 'stripe' );
		if ( ! $gateway ) {
			return new WP_REST_Response( array( 'error' => 'payment_gateway_unavailable' ), 503 );
		}
		$result = $gateway->handle_webhook( $request->get_body(), $request->get_header( 'stripe-signature' ) );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'error' => $result->get_error_code() ), $status );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Validate a Stripe Checkout URL before it is stored or rendered.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_checkout_url_public( $url ) {
		return self::is_checkout_url( $url );
	}

	private static function is_checkout_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) &&
			'https' === strtolower( $parts['scheme'] ) && 'checkout.stripe.com' === strtolower( $parts['host'] );
	}
}
