<?php
/**
 * Traveller pricing and age-band validation for OpenTrip Engine.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OTE_Pricing {

	/**
	 * Parse a comma- or semicolon-separated list of ages.
	 *
	 * @param mixed $value Submitted ages.
	 * @param int   $expected_count Expected number of ages.
	 * @return array|WP_Error
	 */
	public static function parse_ages( $value, $expected_count ) {
		$expected_count = absint( $expected_count );
		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'ote_invalid_ages', __( 'Enter valid traveller ages.', 'opentrip-engine' ) );
		}

		$value = trim( (string) $value );
		if ( 0 === $expected_count ) {
			return '' === $value
				? array()
				: new WP_Error( 'ote_unexpected_ages', __( 'Remove ages when the traveller count is zero.', 'opentrip-engine' ) );
		}
		if ( '' === $value ) {
			return new WP_Error( 'ote_missing_ages', __( 'Enter an age for every child or infant.', 'opentrip-engine' ) );
		}

		$parts = preg_split( '/[,;]+/', $value );
		if ( ! is_array( $parts ) || count( $parts ) !== $expected_count ) {
			return new WP_Error( 'ote_age_count_mismatch', __( 'The number of ages must match the traveller count.', 'opentrip-engine' ) );
		}

		$ages = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part || ! ctype_digit( $part ) ) {
				return new WP_Error( 'ote_invalid_age', __( 'Enter ages as whole numbers separated by commas.', 'opentrip-engine' ) );
			}
			$age = (int) $part;
			if ( $age < 0 || $age > 17 ) {
				return new WP_Error( 'ote_age_out_of_range', __( 'Traveller ages must be between 0 and 17.', 'opentrip-engine' ) );
			}
			$ages[] = $age;
		}
		return $ages;
	}

	/**
	 * Calculate an immutable quote from a departure's configured rates.
	 *
	 * Legacy departures without traveller-specific columns retain the old
	 * per-traveller price for both adults and children. Infants and rooms are
	 * free unless their rates are explicitly configured.
	 *
	 * @param object $departure Departure row.
	 * @param int    $adults Adult count.
	 * @param array  $child_ages Child ages.
	 * @param array  $infant_ages Infant ages.
	 * @param int    $rooms Room count.
	 * @return array|WP_Error
	 */
	public static function calculate_quote( $departure, $adults, $child_ages, $infant_ages, $rooms ) {
		if ( ! is_object( $departure ) || ! is_array( $child_ages ) || ! is_array( $infant_ages ) ) {
			return new WP_Error( 'ote_invalid_quote_input', __( 'The booking quote could not be calculated.', 'opentrip-engine' ) );
		}

		$adults = absint( $adults );
		$rooms  = absint( $rooms );
		$children = count( $child_ages );
		$infants  = count( $infant_ages );
		if ( $adults < 1 || $adults + $children + $infants > 30 || $rooms < 1 || $rooms > 20 ) {
			return new WP_Error( 'ote_invalid_traveller_count', __( 'Check the number of travellers and rooms.', 'opentrip-engine' ) );
		}

		$child_min_age  = isset( $departure->child_min_age ) ? absint( $departure->child_min_age ) : 2;
		$child_max_age  = isset( $departure->child_max_age ) ? absint( $departure->child_max_age ) : 11;
		$infant_max_age = isset( $departure->infant_max_age ) ? absint( $departure->infant_max_age ) : 1;
		if ( $infant_max_age > 17 || $child_min_age > 17 || $child_max_age > 17 || $child_min_age > $child_max_age || $infant_max_age >= $child_min_age ) {
			return new WP_Error( 'ote_invalid_age_bands', __( 'The departure has overlapping or invalid age bands.', 'opentrip-engine' ) );
		}

		foreach ( $child_ages as $age ) {
			if ( ! is_numeric( $age ) || (int) $age < $child_min_age || (int) $age > $child_max_age ) {
				return new WP_Error(
					'ote_child_age_outside_band',
					sprintf(
						/* translators: 1: minimum child age, 2: maximum child age. */
						__( 'Child ages must be between %1$d and %2$d years.', 'opentrip-engine' ),
						$child_min_age,
						$child_max_age
					)
				);
			}
		}
		foreach ( $infant_ages as $age ) {
			if ( ! is_numeric( $age ) || (int) $age < 0 || (int) $age > $infant_max_age ) {
				return new WP_Error(
					'ote_infant_age_outside_band',
					sprintf(
						/* translators: %d: maximum infant age. */
						__( 'Infant ages must be between 0 and %d years.', 'opentrip-engine' ),
						$infant_max_age
					)
				);
			}
		}

		$legacy_price = isset( $departure->price ) ? $departure->price : 0;
		$adult_price  = self::read_rate( $departure, 'adult_price', $legacy_price );
		$child_price  = self::read_rate( $departure, 'child_price', $adult_price );
		$infant_price = self::read_rate( $departure, 'infant_price', 0 );
		$room_price   = self::read_rate( $departure, 'room_price', 0 );
		foreach ( array( $adult_price, $child_price, $infant_price, $room_price ) as $rate ) {
			if ( null === $rate ) {
				return new WP_Error( 'ote_invalid_rate', __( 'The departure contains an invalid price.', 'opentrip-engine' ) );
			}
		}

		$currency = isset( $departure->currency ) ? strtoupper( sanitize_text_field( (string) $departure->currency ) ) : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new WP_Error( 'ote_invalid_currency', __( 'The departure currency must be a three-letter ISO code.', 'opentrip-engine' ) );
		}

		$adult_subtotal  = round( $adults * $adult_price, 2, PHP_ROUND_HALF_UP );
		$child_subtotal  = round( $children * $child_price, 2, PHP_ROUND_HALF_UP );
		$infant_subtotal = round( $infants * $infant_price, 2, PHP_ROUND_HALF_UP );
		$room_subtotal   = round( $rooms * $room_price, 2, PHP_ROUND_HALF_UP );
		$total = round( $adult_subtotal + $child_subtotal + $infant_subtotal + $room_subtotal, 2, PHP_ROUND_HALF_UP );

		return array(
			'currency' => $currency,
			'adults'   => array( 'quantity' => $adults, 'unit_price' => $adult_price, 'subtotal' => $adult_subtotal ),
			'children' => array( 'quantity' => $children, 'ages' => array_values( array_map( 'intval', $child_ages ) ), 'unit_price' => $child_price, 'subtotal' => $child_subtotal ),
			'infants'  => array( 'quantity' => $infants, 'ages' => array_values( array_map( 'intval', $infant_ages ) ), 'unit_price' => $infant_price, 'subtotal' => $infant_subtotal ),
			'rooms'    => array( 'quantity' => $rooms, 'unit_price' => $room_price, 'subtotal' => $room_subtotal ),
			'age_bands' => array(
				'infant_max_age' => $infant_max_age,
				'child_min_age'  => $child_min_age,
				'child_max_age'  => $child_max_age,
				'adult_min_age'  => $child_max_age + 1,
			),
			'travellers' => $adults + $children + $infants,
			'total'      => $total,
		);
	}

	/**
	 * Read and validate one configured rate.
	 *
	 * @param object $departure Departure row.
	 * @param string $property Rate property.
	 * @param mixed  $fallback Fallback rate.
	 * @return float|null
	 */
	private static function read_rate( $departure, $property, $fallback ) {
		$value = isset( $departure->{$property} ) ? $departure->{$property} : $fallback;
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		if ( ! is_finite( $value ) || $value < 0 || $value > 9999999999.99 ) {
			return null;
		}
		return round( $value, 2, PHP_ROUND_HALF_UP );
	}
}
