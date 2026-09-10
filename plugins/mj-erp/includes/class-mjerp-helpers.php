<?php
/**
 * Numeric, date and currency helpers.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Helpers
 */
class MJERP_Helpers {

	/**
	 * Converts a loosely formatted number into a float.
	 *
	 * Accepts ints/floats, "1234.56", "1.234,56", "1,234.56", "1234,56",
	 * "US$ 1.234,56", "1.234.567" (repeated separator = thousands), negatives.
	 * When both separators are present the last one is the decimal separator.
	 *
	 * @param mixed $value   Raw value.
	 * @param float $default Value returned when the input cannot be parsed.
	 * @return float
	 */
	/**
	 * A quantity written the short way: 3 instead of 3.0000, 2.5 instead of 2.5000.
	 *
	 * Las cantidades se guardan con cuatro decimales porque hay rubros que venden por peso, pero
	 * al leerlo en pantalla «quedan 1 unidades» tiene que decir 1 y no 1.0000.
	 *
	 * @param float $value    Quantity.
	 * @param int   $decimals Maximum decimals to keep.
	 * @return string
	 */
	/**
	 * Un importe en moneda base escrito como se lee: "US$ 14,01".
	 *
	 * Se usa en los textos que van a la bitácora del revendedor, que se guardan ya redactados:
	 * el importe de ese día tiene que quedar congelado en la frase, no recalcularse después.
	 *
	 * @param float $value Importe en moneda base.
	 * @return string
	 */
	public static function money_base( $value ) {
		$currency = class_exists( 'MJERP_Settings' ) ? strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ) : 'USD';
		$symbols  = class_exists( 'MJERP_Settings' ) ? (array) MJERP_Settings::get( 'currency_symbols', array() ) : array();
		$symbol   = isset( $symbols[ $currency ] ) && '' !== (string) $symbols[ $currency ] ? (string) $symbols[ $currency ] : $currency;
		$amount   = (float) $value;
		$decimals = ( abs( $amount - round( $amount ) ) < 0.005 ) ? 0 : 2;

		return $symbol . ' ' . number_format_i18n( $amount, $decimals );
	}

	public static function trim_number( $value, $decimals = 4 ) {
		$decimals  = max( 0, min( 8, (int) $decimals ) );
		$formatted = number_format( (float) $value, $decimals, '.', '' );
		if ( $decimals > 0 && false !== strpos( $formatted, '.' ) ) {
			$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		}
		return '' === $formatted ? '0' : $formatted;
	}

	public static function to_float( $value, $default = 0.0 ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 1.0 : 0.0;
		}
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return (float) $default;
		}

		$s = trim( (string) $value );
		if ( '' === $s ) {
			return (float) $default;
		}
		if ( is_numeric( $s ) ) {
			return (float) $s;
		}

		// Keep digits, separators and minus sign only (drops currency symbols, spaces, etc.).
		$s = preg_replace( '/[^0-9,.\-]/', '', $s );
		if ( '' === $s || null === $s ) {
			return (float) $default;
		}

		$negative = ( false !== strpos( $s, '-' ) );
		$s        = str_replace( '-', '', $s );
		if ( '' === $s ) {
			return (float) $default;
		}

		$last_comma = strrpos( $s, ',' );
		$last_dot   = strrpos( $s, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			if ( $last_comma > $last_dot ) {
				// "1.234,56" → comma is decimal.
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				// "1,234.56" → dot is decimal.
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $last_comma ) {
			if ( substr_count( $s, ',' ) > 1 ) {
				$s = str_replace( ',', '', $s );
			} else {
				$s = str_replace( ',', '.', $s );
			}
		} elseif ( false !== $last_dot ) {
			if ( substr_count( $s, '.' ) > 1 ) {
				$s = str_replace( '.', '', $s );
			}
		}

		if ( ! is_numeric( $s ) ) {
			return (float) $default;
		}
		$f = (float) $s;
		return $negative ? -$f : $f;
	}

	/**
	 * Converts a value into an integer (accepting formatted numbers).
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $default Fallback.
	 * @return int
	 */
	public static function to_int( $value, $default = 0 ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return (int) $default;
		}
		return (int) round( self::to_float( $value, (float) $default ) );
	}

	/**
	 * Converts an amount expressed in $currency into the base currency.
	 *
	 * Rate = units of the line currency per 1 unit of base currency (e.g. 1583 ARS per USD).
	 *
	 * @param mixed  $amount        Amount in the line currency.
	 * @param string $currency      Line currency.
	 * @param mixed  $rate          Exchange rate.
	 * @param string $base_currency Base currency.
	 * @return float
	 */
	public static function to_base( $amount, $currency, $rate, $base_currency ) {
		$amount   = self::to_float( $amount );
		$rate     = self::to_float( $rate );
		$currency = strtoupper( trim( (string) $currency ) );
		$base     = strtoupper( trim( (string) $base_currency ) );

		if ( '' === $currency || $currency === $base ) {
			return $amount;
		}
		return $rate > 0 ? $amount / $rate : 0.0;
	}

	/**
	 * Converts a base-currency amount into the local equivalent.
	 *
	 * @param mixed $base_amount Amount in base currency.
	 * @param mixed $rate        Exchange rate.
	 * @return float
	 */
	public static function to_local( $base_amount, $rate ) {
		return self::to_float( $base_amount ) * self::to_float( $rate );
	}

	/**
	 * Rounds to 4 decimals (stored values).
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	public static function round4( $value ) {
		return round( self::to_float( $value ), 4 );
	}

	/**
	 * Rounds to the display decimals (settings `decimals`, default 2).
	 *
	 * @param mixed    $value    Value.
	 * @param int|null $decimals Decimals; null = settings.
	 * @return float
	 */
	public static function round_money( $value, $decimals = null ) {
		if ( null === $decimals ) {
			$decimals = class_exists( 'MJERP_Settings' ) ? (int) MJERP_Settings::get( 'decimals', 2 ) : 2;
		}
		$decimals = max( 0, min( 6, (int) $decimals ) );
		return round( self::to_float( $value ), $decimals );
	}

	/**
	 * Current site time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Current site date (Y-m-d).
	 *
	 * @return string
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Sanitizes a date into Y-m-d (accepts Y-m-d, Y-m-d H:i:s, d/m/Y, d-m-Y, or strtotime-able strings).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function sanitize_date( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$s = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $s || '0000-00-00' === $s ) {
			return null;
		}

		$y = 0;
		$m = 0;
		$d = 0;
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $mt ) ) {
			$y = (int) $mt[1];
			$m = (int) $mt[2];
			$d = (int) $mt[3];
		} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $mt ) ) {
			$d = (int) $mt[1];
			$m = (int) $mt[2];
			$y = (int) $mt[3];
		} elseif ( preg_match( '/^(\d{1,2})-(\d{1,2})-(\d{4})/', $s, $mt ) ) {
			$d = (int) $mt[1];
			$m = (int) $mt[2];
			$y = (int) $mt[3];
		} else {
			$ts = strtotime( $s );
			if ( false === $ts ) {
				return null;
			}
			return gmdate( 'Y-m-d', $ts );
		}

		if ( ! checkdate( $m, $d, $y ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * Sanitizes a datetime into Y-m-d H:i:s.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function sanitize_datetime( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$s = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $s || 0 === strpos( $s, '0000-00-00' ) ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $s, $mt ) ) {
			if ( ! checkdate( (int) $mt[2], (int) $mt[3], (int) $mt[1] ) ) {
				return null;
			}
			$sec = isset( $mt[6] ) ? (int) $mt[6] : 0;
			return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $mt[1], $mt[2], $mt[3], min( 23, (int) $mt[4] ), min( 59, (int) $mt[5] ), min( 59, $sec ) );
		}
		$date = self::sanitize_date( $s );
		return null === $date ? null : $date . ' 00:00:00';
	}

	/**
	 * Sanitizes a currency code (3 uppercase letters). Falls back to the base currency.
	 *
	 * @param mixed      $value   Raw value.
	 * @param array|null $allowed Allowed codes; null = settings `currencies`.
	 * @return string
	 */
	public static function sanitize_currency( $value, $allowed = null ) {
		$base = class_exists( 'MJERP_Settings' ) ? strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ) : 'USD';
		if ( ! preg_match( '/^[A-Z]{3}$/', $base ) ) {
			$base = 'USD';
		}

		if ( null === $allowed && class_exists( 'MJERP_Settings' ) ) {
			$allowed = MJERP_Settings::get( 'currencies', array() );
		}

		$code = is_scalar( $value ) ? strtoupper( trim( (string) $value ) ) : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return $base;
		}
		if ( is_array( $allowed ) && ! empty( $allowed ) ) {
			$allowed = array_map( 'strtoupper', array_map( 'strval', $allowed ) );
			if ( ! in_array( $code, $allowed, true ) ) {
				return $base;
			}
		}
		return $code;
	}

	/**
	 * Loose boolean conversion (true, 1, "1", "true", "yes", "on", "si", "sí").
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
		}
		if ( null === $value ) {
			return false;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		$s = strtolower( trim( (string) $value ) );
		return in_array( $s, array( '1', 'true', 'yes', 'on', 'si', 'sí', 'y', 's' ), true );
	}

	/**
	 * Display name of a user (empty string when the user does not exist).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_display_name( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = (string) $user->user_login;
		}
		return $name;
	}

	/**
	 * Safe array getter with dot-notation support ("calc.totals.total").
	 *
	 * @param mixed  $arr     Array (or object).
	 * @param string $key     Key or dotted path.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function array_get( $arr, $key, $default = null ) {
		if ( is_object( $arr ) ) {
			$arr = get_object_vars( $arr );
		}
		if ( ! is_array( $arr ) ) {
			return $default;
		}
		$key = (string) $key;
		if ( array_key_exists( $key, $arr ) ) {
			return $arr[ $key ];
		}
		if ( false === strpos( $key, '.' ) ) {
			return $default;
		}
		$current = $arr;
		foreach ( explode( '.', $key ) as $segment ) {
			if ( is_object( $current ) ) {
				$current = get_object_vars( $current );
			}
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $default;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	/**
	 * Clamps a number between two bounds.
	 *
	 * @param mixed $value Value.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return float
	 */
	public static function clamp( $value, $min, $max ) {
		return max( (float) $min, min( (float) $max, self::to_float( $value ) ) );
	}

	/**
	 * Sanitizes a plain text value, trimmed and truncated.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length (0 = unlimited).
	 * @return string
	 */
	public static function text( $value, $max_length = 0 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$s = trim( sanitize_text_field( (string) $value ) );
		if ( $max_length > 0 && function_exists( 'mb_substr' ) ) {
			$s = mb_substr( $s, 0, $max_length );
		} elseif ( $max_length > 0 ) {
			$s = substr( $s, 0, $max_length );
		}
		return $s;
	}
}
