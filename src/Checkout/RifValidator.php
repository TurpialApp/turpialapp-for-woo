<?php

namespace Cachicamo\WooCommerce\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * Same check-digit algorithm as the core's H.VenezuelaGetValidRif (helpers/utils.go:598), so a
 * document accepted here is guaranteed to be accepted when the invoice reaches the core.
 */
class RifValidator {

	const TYPE_WEIGHTS = array(
		'V' => 1,
		'E' => 2,
		'J' => 3,
		'C' => 3,
		'P' => 4,
		'G' => 5,
	);

	const DIGIT_WEIGHTS = array( 4, 3, 2, 7, 6, 5, 4, 3, 2 );

	/**
	 * @param string $value Raw checkout input.
	 * @return string|null Normalized document (e.g. "V123456789") or null if invalid.
	 */
	public static function normalize( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		$value = preg_replace( '/[^VEJGPC0-9]/', '', $value );

		if ( ! preg_match( '/^([VEJGPC])(\d{5,9})$/', $value, $matches ) ) {
			return null;
		}

		$type   = $matches[1];
		$digits = $matches[2];

		if ( ! isset( self::TYPE_WEIGHTS[ $type ] ) ) {
			return null;
		}

		// A cedula (V/E) with fewer than 9 digits carries no check digit: it is accepted as a
		// natural person's identity number, not a RIF.
		if ( in_array( $type, array( 'V', 'E' ), true ) && strlen( $digits ) < 9 ) {
			return $type . $digits;
		}

		if ( self::check_digit( $type, $digits ) ) {
			return $type . $digits;
		}

		return null;
	}

	public static function is_valid( $value ) {
		return null !== self::normalize( $value );
	}

	private static function check_digit( $type, $digits ) {
		$count_digits = strlen( $digits );
		if ( 9 === $count_digits ) {
			$count_digits--;
		}

		$sum   = self::TYPE_WEIGHTS[ $type ] * 4;
		$index = count( self::DIGIT_WEIGHTS ) - 1;

		for ( $i = $count_digits - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];
			$sum  += $digit * self::DIGIT_WEIGHTS[ $index ];
			$index--;
		}

		$final_digit = $sum % 11;
		if ( $final_digit > 1 ) {
			$final_digit = 11 - $final_digit;
		}

		if ( 9 === strlen( $digits ) ) {
			$final_digit_legal = (int) $digits[8];
			return $final_digit_legal === $final_digit || 0 === $final_digit_legal;
		}

		return true;
	}
}
