<?php
/**
 * Registry for optional payment gateway adapters.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OTE_Payment_Gateway_Registry {
	/**
	 * Registered providers.
	 *
	 * @var array
	 */
	private static $gateways = array();

	/**
	 * Register a provider once.
	 *
	 * @param OTE_Payment_Gateway $gateway Gateway instance.
	 * @return bool
	 */
	public static function register( OTE_Payment_Gateway $gateway ) {
		$id = sanitize_key( $gateway->get_id() );
		if ( '' === $id || isset( self::$gateways[ $id ] ) ) {
			return false;
		}
		self::$gateways[ $id ] = $gateway;
		return true;
	}

	/**
	 * Retrieve a registered provider.
	 *
	 * @param string $id Provider identifier.
	 * @return OTE_Payment_Gateway|null
	 */
	public static function get( $id ) {
		$id = sanitize_key( $id );
		return isset( self::$gateways[ $id ] ) ? self::$gateways[ $id ] : null;
	}

	/**
	 * List registered provider identifiers.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_keys( self::$gateways );
	}
}
