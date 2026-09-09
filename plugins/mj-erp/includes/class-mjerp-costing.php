<?php
defined( 'ABSPATH' ) || exit;

/**
 * MJ ERP — pure costing engine (spec §7).
 *
 * No database access and no WordPress API calls: every method is static and side-effect free so the
 * class can be exercised from the CLI. The same algorithm is mirrored in assets/js/calc.js (MJ.calc);
 * both implementations must return the same numbers and the same shape for the same input.
 *
 * Conventions:
 * - Money math is done in floats; returned money values are rounded to 4 decimals (display uses 2).
 * - A rate is "units of the line currency per 1 unit of the base currency" (e.g. 1583 ARS per USD).
 * - Items are returned keyed by their index in the input array (so previews without ids work).
 *
 * @package MJ_ERP
 */
class MJERP_Costing {

	/**
	 * Decimals used for stored / returned money values.
	 */
	const DECIMALS = 4;

	/**
	 * Valid proration methods.
	 */
	const METHODS = array( 'fob', 'qty', 'none' );

	/**
	 * Runs the full costing of a purchase.
	 *
	 * @param array $purchase  Purchase row: base_currency, proration_method, default_rate.
	 * @param array $items     Item rows: id (nullable), quantity, unit_cost, currency, rate.
	 * @param array $expenses  Expense rows: id (nullable), amount, currency, rate, is_prorated.
	 * @param array $settings  Plugin settings: price_lists, price_rounding, sale_rate, local_currency, …
	 *
	 * @return array {
	 *     @type array $items    Per-item results keyed by input index.
	 *     @type array $expenses Per-expense results keyed by input index (amount_base, amount_local, is_prorated).
	 *     @type array $totals   Purchase totals.
	 * }
	 */
	public static function calculate( array $purchase, array $items, array $expenses, array $settings ) : array {
		$decimals = self::DECIMALS;

		// ---- Context -------------------------------------------------------------------------------
		$base_currency = self::currency_code( self::pick( $purchase, 'base_currency' ) );
		if ( '' === $base_currency ) {
			$base_currency = self::currency_code( self::pick( $settings, 'base_currency' ) );
		}
		if ( '' === $base_currency ) {
			$base_currency = 'USD';
		}

		$local_currency = self::currency_code( self::pick( $settings, 'local_currency' ) );
		if ( '' === $local_currency ) {
			$local_currency = 'ARS';
		}

		$default_rate = self::to_float( self::pick( $purchase, 'default_rate' ) );
		if ( $default_rate <= 0 ) {
			$default_rate = self::to_float( self::pick( $settings, 'default_rate' ) );
		}
		if ( $default_rate <= 0 ) {
			$default_rate = 1.0;
		}

		$sale_rate = self::to_float( self::pick( $settings, 'sale_rate' ) );
		if ( $sale_rate <= 0 ) {
			$sale_rate = $default_rate;
		}

		$method = self::method_code( self::pick( $purchase, 'proration_method' ) );
		if ( '' === $method ) {
			$method = self::method_code( self::pick( $settings, 'proration_method' ) );
		}
		if ( '' === $method ) {
			$method = 'fob';
		}

		$price_rounding = (int) self::to_float( self::pick( $settings, 'price_rounding' ) );
		$price_lists    = self::price_lists( self::pick( $settings, 'price_lists' ) );
		$usd_cfg        = self::usd_config_from_settings( $settings );

		// ---- Step 1: items (FOB) -------------------------------------------------------------------
		$rows      = array();
		$fob_total = 0.0;
		$total_qty = 0.0;
		$max_index = null;
		$max_fob   = null;

		foreach ( $items as $index => $item ) {
			$item = self::row( $item );

			$quantity  = self::to_float( self::pick( $item, 'quantity' ) );
			$unit_cost = self::to_float( self::pick( $item, 'unit_cost' ) );
			$currency  = self::currency_code( self::pick( $item, 'currency' ) );
			if ( '' === $currency ) {
				$currency = $base_currency;
			}
			$rate = self::to_float( self::pick( $item, 'rate' ) );
			if ( $rate <= 0 ) {
				$rate = $default_rate;
			}

			$unit_cost_base = self::to_base( $unit_cost, $currency, $rate, $base_currency );
			$fob            = $quantity * $unit_cost_base;

			$rows[ $index ] = array(
				'item_id'        => self::id_value( $item, 'id', 'item_id' ),
				'quantity'       => $quantity,
				'rate'           => $rate,
				'unit_cost_base' => $unit_cost_base,
				'fob'            => $fob,
				'share'          => 0.0,
			);

			$fob_total += $fob;
			$total_qty += $quantity;

			// Largest FOB (first one on ties) receives the cents rounding adjustment.
			if ( null === $max_index || $fob > $max_fob ) {
				$max_index = $index;
				$max_fob   = $fob;
			}
		}

		$items_count = count( $rows );

		// ---- Step 2: expenses ----------------------------------------------------------------------
		$expense_rows       = array();
		$prorated_total     = 0.0;
		$non_prorated_total = 0.0;

		foreach ( $expenses as $index => $expense ) {
			$expense = self::row( $expense );

			$amount   = self::to_float( self::pick( $expense, 'amount' ) );
			$currency = self::currency_code( self::pick( $expense, 'currency' ) );
			if ( '' === $currency ) {
				$currency = $local_currency;
			}
			$rate = self::to_float( self::pick( $expense, 'rate' ) );
			if ( $rate <= 0 ) {
				$rate = $default_rate;
			}
			$is_prorated = self::to_bool( self::pick( $expense, 'is_prorated' ), true );

			$amount_base = self::to_base( $amount, $currency, $rate, $base_currency );

			if ( $is_prorated ) {
				$prorated_total += $amount_base;
			} else {
				$non_prorated_total += $amount_base;
			}

			$expense_rows[ $index ] = array(
				'expense_id'   => self::id_value( $expense, 'id', 'expense_id' ),
				'amount_base'  => round( $amount_base, $decimals ),
				'amount_local' => round( $amount_base * $rate, $decimals ),
				'is_prorated'  => $is_prorated,
			);
		}

		$fob_total_r          = round( $fob_total, $decimals );
		$prorated_total_r     = round( $prorated_total, $decimals );
		$non_prorated_total_r = round( $non_prorated_total, $decimals );
		$expenses_total_r     = round( $prorated_total_r + $non_prorated_total_r, $decimals );

		// ---- Step 3: shares ------------------------------------------------------------------------
		$method_used         = $method;
		$rounding_adjustment = 0.0;
		$rounding_index      = null;
		$rounding_item_id    = null;

		if ( 'none' !== $method && $prorated_total_r > 0 && $items_count > 0 ) {
			if ( 'fob' === $method_used && $fob_total <= 0 ) {
				$method_used = 'qty';
			}
			$denominator = ( 'fob' === $method_used ) ? $fob_total : $total_qty;

			if ( $denominator > 0 ) {
				$share_sum = 0.0;
				foreach ( $rows as $index => $row ) {
					$weight = ( 'fob' === $method_used ) ? $row['fob'] : $row['quantity'];
					$share  = round( $prorated_total_r * $weight / $denominator, $decimals );

					$rows[ $index ]['share'] = $share;
					$share_sum              += $share;
				}

				$rounding_adjustment = round( $prorated_total_r - $share_sum, $decimals );
				$rounding_index      = $max_index;
				$rounding_item_id    = $rows[ $max_index ]['item_id'];

				if ( 0.0 !== $rounding_adjustment ) {
					$rows[ $max_index ]['share'] = round( $rows[ $max_index ]['share'] + $rounding_adjustment, $decimals );
				}
			}
		}

		// ---- Steps 4 & 6: per-item landed cost and price lists ------------------------------------
		$out_items = array();

		foreach ( $rows as $index => $row ) {
			$fob          = $row['fob'];
			$share        = $row['share'];
			$landed_total = $fob + $share;
			$landed_unit  = ( $row['quantity'] > 0 ) ? round( $landed_total / $row['quantity'], $decimals ) : 0.0;

			$prices = array();
			foreach ( $price_lists as $key => $markup ) {
				$prices[ $key ] = self::price_for( $landed_unit, $markup, $sale_rate, $price_rounding, $usd_cfg );
			}

			$out_items[ $index ] = array(
				'item_id'           => $row['item_id'],
				'fob_base'          => round( $fob, $decimals ),
				'fob_local'         => round( $fob * $row['rate'], $decimals ),
				'unit_cost_base'    => round( $row['unit_cost_base'], $decimals ),
				'share_base'        => round( $share, $decimals ),
				'share_pct'         => ( $fob > 0 ) ? round( $share / $fob * 100, $decimals ) : 0.0,
				'landed_total_base' => round( $landed_total, $decimals ),
				'landed_unit_base'  => $landed_unit,
				'landed_unit_local' => round( $landed_unit * $sale_rate, $decimals ),
				'prices'            => $prices,
			);
		}

		// ---- Steps 5 & 7: totals -------------------------------------------------------------------
		$landed_total  = round( $fob_total_r + $prorated_total_r, $decimals );
		$total         = round( $fob_total_r + $expenses_total_r, $decimals );
		$proration_pct = ( $fob_total_r > 0 ) ? round( $prorated_total_r / $fob_total_r * 100, $decimals ) : 0.0;

		$totals = array(
			'base_currency'              => $base_currency,
			'local_currency'             => $local_currency,
			'sale_rate'                  => $sale_rate,
			'default_rate'               => $default_rate,
			'fob_total'                  => $fob_total_r,
			'prorated_total'             => $prorated_total_r,
			'non_prorated_total'         => $non_prorated_total_r,
			'expenses_total'             => $expenses_total_r,
			'landed_total'               => $landed_total,
			'total'                      => $total,
			'total_local'                => round( $total * $default_rate, $decimals ),
			'total_qty'                  => round( $total_qty, $decimals ),
			'items_count'                => $items_count,
			'proration_pct'              => $proration_pct,
			'rounding_adjustment'        => $rounding_adjustment,
			'rounding_item_id'           => $rounding_item_id,
			'rounding_item_index'        => $rounding_index,
			'proration_method'           => $method,
			'proration_method_effective' => ( null !== $rounding_index ) ? $method_used : $method,
			'usd_rounding'               => $usd_cfg['rounding'],
			'usd_rounding_mode'          => $usd_cfg['mode'],
			'store_rate'                 => $usd_cfg['store_rate'],
		);

		return array(
			'items'    => $out_items,
			'expenses' => $expense_rows,
			'totals'   => $totals,
		);
	}

	/**
	 * Rounds a local price according to the configured rounding step.
	 *
	 * Contract: round_price( float $price, int $rounding ) : float
	 * - $rounding <= 0  → round( $price, 2 )
	 * - otherwise       → round( $price / $rounding ) * $rounding (nearest multiple, half away from zero)
	 *
	 * Parameters are accepted untyped (and cast internally) so numeric strings / nulls coming from
	 * settings never raise a TypeError.
	 *
	 * @param float|int|string $price    Price to round.
	 * @param int|string       $rounding Rounding step (0 = none; 10, 50, 100, 500, 1000…).
	 * @return float
	 */
	public static function round_price( $price, $rounding = 0 ) : float {
		$price    = self::to_float( $price );
		$rounding = (int) self::to_float( $rounding );

		if ( $rounding <= 0 ) {
			return round( $price, 2 );
		}

		return (float) ( round( $price / $rounding ) * $rounding );
	}

	/**
	 * Rounds an amount in base currency to a step, mirroring the peso rounding of MJ Dólar so the
	 * published USD price is a round number too ("US$ 129", not "US$ 128,74").
	 *
	 * Contract: round_usd( float $usd, float $step, string $mode ) : float
	 * - $step <= 0 → round( $usd, 2 )
	 * - nearest    → round( $usd / $step ) * $step
	 * - up         → ceil( $usd / $step - 1e-9 ) * $step   (the epsilon keeps a value already on
	 *                the step from climbing to the next one after a float artifact)
	 *
	 * @param float|string $usd  Amount in base currency.
	 * @param float|string $step Rounding step (0 = cents; 0.5, 1, 5, 10…).
	 * @param string       $mode `nearest` | `up`.
	 * @return float
	 */
	public static function round_usd( $usd, $step = 0, $mode = 'nearest' ) : float {
		$usd  = self::to_float( $usd );
		$step = self::to_float( $step );

		if ( $step <= 0 ) {
			return round( $usd, 2 );
		}

		$q = $usd / $step;
		$q = ( 'up' === $mode ) ? ceil( $q - 1e-9 ) : round( $q );

		// The step can be fractional (0.5), so the product needs its own rounding.
		return round( (float) ( $q * $step ), self::DECIMALS );
	}

	/**
	 * Rounds an amount in local currency the way MJ Dólar does (MJD_Rate::round), so the peso price
	 * previewed in the ERP is exactly the one the storefront will print.
	 *
	 * @param float|string $amount Amount in local currency.
	 * @param int|string   $step   Rounding step (0 = cents).
	 * @param string       $mode   `nearest` | `up`.
	 * @return float
	 */
	public static function round_ars( $amount, $step = 0, $mode = 'nearest' ) : float {
		$amount = self::to_float( $amount );
		$step   = (int) self::to_float( $step );

		if ( $step <= 0 ) {
			return round( $amount, 2 );
		}

		$q = $amount / $step;
		$q = ( 'up' === $mode ) ? ceil( $q - 1e-9 ) : round( $q );

		return (float) ( $q * $step );
	}

	/**
	 * Suggested sale price for a landed unit cost.
	 *
	 * base  = landed_unit_base × (1 + markup / 100), 4 decimals
	 * local = round_price( base × sale_rate, rounding )
	 *
	 * When $usd_cfg is given, two more fields describe the price as MJ Dólar would publish it:
	 *
	 * usd       = round_usd( base, usd_cfg.rounding, usd_cfg.mode )
	 * usd_local = round_ars( usd × usd_cfg.store_rate, usd_cfg.store_rounding, usd_cfg.store_mode )
	 *
	 * `usd_local` derives from the *rounded* USD price, not from `base`: in USD publishing mode the
	 * dollar price is the source of truth and the pesos follow it, which is exactly what the
	 * storefront computes. Without a store rate it falls back to the ERP sale rate and rounding, so
	 * the field is always populated.
	 *
	 * @param float|string $landed_unit_base Landed unit cost in base currency.
	 * @param float|string $markup_pct       Markup percentage (70 → +70%).
	 * @param float|string $sale_rate        Local units per 1 base unit (<= 0 → 1, local equals base).
	 * @param int|string   $rounding         Rounding step for the local price (0 = cents).
	 * @param array|null   $usd_cfg          { rounding, mode, store_rate, store_rounding, store_mode }.
	 * @return array { 'base' => float, 'local' => float, 'usd' => float, 'usd_local' => float }
	 */
	public static function price_for( $landed_unit_base, $markup_pct, $sale_rate, $rounding = 0, $usd_cfg = null ) : array {
		$landed = self::to_float( $landed_unit_base );
		$markup = self::to_float( $markup_pct );
		$rate   = self::to_float( $sale_rate );
		if ( $rate <= 0 ) {
			$rate = 1.0;
		}

		$base  = round( $landed * ( 1 + $markup / 100 ), self::DECIMALS );
		$local = self::round_price( $base * $rate, $rounding );

		$cfg      = self::usd_config( $usd_cfg );
		$usd      = self::round_usd( $base, $cfg['rounding'], $cfg['mode'] );
		$store_rt = $cfg['store_rate'] > 0 ? $cfg['store_rate'] : $rate;

		$usd_local = $cfg['store_rate'] > 0
			? self::round_ars( $usd * $store_rt, $cfg['store_rounding'], $cfg['store_mode'] )
			: self::round_price( $usd * $store_rt, $rounding );

		return array(
			'base'      => $base,
			'local'     => $local,
			'usd'       => $usd,
			'usd_local' => $usd_local,
		);
	}

	/**
	 * Builds the USD publishing context out of a settings array.
	 *
	 * The ERP keys (`usd_rounding`, `usd_rounding_mode`) come from the plugin settings; the store
	 * keys come from the read-only `dolar` block that MJERP_Dolar injects into them. Reading both
	 * from the same array is what lets the JS mirror produce identical numbers: the browser gets
	 * that array verbatim in `window.MJERP.settings`.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function usd_config_from_settings( $settings ) : array {
		$settings = self::row( $settings );
		$dolar    = self::row( self::pick( $settings, 'dolar' ) );

		return self::usd_config(
			array(
				'rounding'       => self::pick( $settings, 'usd_rounding' ),
				'mode'           => self::pick( $settings, 'usd_rounding_mode' ),
				'store_rate'     => self::pick( $dolar, 'rate' ),
				'store_rounding' => self::pick( $dolar, 'rounding' ),
				'store_mode'     => self::pick( $dolar, 'rounding_mode' ),
			)
		);
	}

	/**
	 * Normalizes the USD publishing context, filling every key with a safe default.
	 *
	 * @param mixed $value Raw config (array or null).
	 * @return array { rounding: float, mode: string, store_rate: float, store_rounding: int, store_mode: string }
	 */
	public static function usd_config( $value ) : array {
		$cfg = self::row( $value );

		$rounding = self::to_float( self::pick( $cfg, 'rounding' ) );
		$rate     = self::to_float( self::pick( $cfg, 'store_rate' ) );
		$mode     = self::pick( $cfg, 'mode' );
		$store    = self::pick( $cfg, 'store_mode' );

		return array(
			'rounding'       => $rounding > 0 ? $rounding : 0.0,
			'mode'           => ( is_string( $mode ) && 'up' === strtolower( trim( $mode ) ) ) ? 'up' : 'nearest',
			'store_rate'     => $rate > 0 ? $rate : 0.0,
			'store_rounding' => max( 0, (int) self::to_float( self::pick( $cfg, 'store_rounding' ) ) ),
			'store_mode'     => ( is_string( $store ) && 'up' === strtolower( trim( $store ) ) ) ? 'up' : 'nearest',
		);
	}

	/**
	 * Converts an amount expressed in $currency to the base currency.
	 *
	 * - same currency (or empty currency) → amount unchanged
	 * - otherwise → rate > 0 ? amount / rate : 0
	 *
	 * @param float|string $amount        Amount in the line currency.
	 * @param string       $currency      Line currency code.
	 * @param float|string $rate          Units of line currency per 1 unit of base currency.
	 * @param string       $base_currency Base currency code.
	 * @return float
	 */
	public static function to_base( $amount, $currency, $rate, $base_currency ) : float {
		$amount   = self::to_float( $amount );
		$currency = self::currency_code( $currency );
		$base     = self::currency_code( $base_currency );

		if ( '' === $currency || $currency === $base ) {
			return $amount;
		}

		$rate = self::to_float( $rate );

		return ( $rate > 0 ) ? $amount / $rate : 0.0;
	}

	/**
	 * Casts any scalar (including es-AR formatted strings) to float. Never throws, never returns NAN/INF.
	 *
	 * Accepts "1234.56", "1234,56", "1.234,56", "1,234.56" (when both separators are present the last
	 * one is the decimal separator), currency symbols and spaces are ignored. Anything unparseable → 0.
	 *
	 * @param mixed $value Value to cast.
	 * @return float
	 */
	public static function to_float( $value ) : float {
		if ( is_int( $value ) ) {
			return (float) $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : 0.0;
		}
		if ( null === $value || is_bool( $value ) ) {
			return $value ? 1.0 : 0.0;
		}
		if ( ! is_string( $value ) ) {
			if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
				$value = (string) $value;
			} else {
				return 0.0;
			}
		}

		$s = trim( $value );
		if ( '' === $s ) {
			return 0.0;
		}
		if ( is_numeric( $s ) ) {
			$f = (float) $s;
			return is_finite( $f ) ? $f : 0.0;
		}

		// Strip everything but digits, separators and the sign.
		$s = preg_replace( '/[^0-9,.\-]/', '', $s );
		if ( null === $s || '' === $s || '-' === $s ) {
			return 0.0;
		}
		// Keep a single leading minus.
		$negative = ( '-' === $s[0] );
		$s        = str_replace( '-', '', $s );

		$last_comma = strrpos( $s, ',' );
		$last_dot   = strrpos( $s, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			if ( $last_comma > $last_dot ) {
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $last_comma ) {
			if ( substr_count( $s, ',' ) > 1 ) {
				$s = str_replace( ',', '', $s );
			} else {
				$s = str_replace( ',', '.', $s );
			}
		} elseif ( false !== $last_dot && substr_count( $s, '.' ) > 1 ) {
			$s = str_replace( '.', '', $s );
		}

		if ( ! is_numeric( $s ) ) {
			return 0.0;
		}

		$f = (float) $s;
		if ( ! is_finite( $f ) ) {
			return 0.0;
		}

		return $negative ? -$f : $f;
	}

	/* ------------------------------------------------------------------------------------------------
	 * Private helpers
	 * ---------------------------------------------------------------------------------------------- */

	/**
	 * Reads a key from an array, null when missing.
	 *
	 * @param array  $data Row.
	 * @param string $key  Key.
	 * @return mixed|null
	 */
	private static function pick( array $data, string $key ) {
		return array_key_exists( $key, $data ) ? $data[ $key ] : null;
	}

	/**
	 * Normalizes a row (array or object) to an associative array.
	 *
	 * @param mixed $value Row.
	 * @return array
	 */
	private static function row( $value ) : array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return array();
	}

	/**
	 * Normalizes a currency code: trimmed, uppercased; '' when empty / not a string.
	 *
	 * @param mixed $value Code.
	 * @return string
	 */
	private static function currency_code( $value ) : string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		return strtoupper( trim( (string) $value ) );
	}

	/**
	 * Normalizes a proration method; '' when unknown so the caller can fall back.
	 *
	 * @param mixed $value Method.
	 * @return string
	 */
	private static function method_code( $value ) : string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::METHODS, true ) ? $value : '';
	}

	/**
	 * Boolean cast that understands "0", "false", "no", "off", "" as false.
	 *
	 * @param mixed $value   Value.
	 * @param bool  $default Used when the value is null.
	 * @return bool
	 */
	private static function to_bool( $value, bool $default ) : bool {
		if ( null === $value ) {
			return $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons -- numeric compare intended.
		}
		if ( is_string( $value ) ) {
			$s = strtolower( trim( $value ) );
			return ! in_array( $s, array( '', '0', 'false', 'no', 'off', 'null' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Reads the row id: int when numeric, string when a non-empty temporary id, null otherwise.
	 *
	 * @param array  $row     Row.
	 * @param string $key     Primary key name ('id').
	 * @param string $alt_key Alternative key name ('item_id' / 'expense_id').
	 * @return int|string|null
	 */
	private static function id_value( array $row, string $key, string $alt_key ) {
		$id = self::pick( $row, $key );
		if ( null === $id || '' === $id || false === $id ) {
			$id = self::pick( $row, $alt_key );
		}
		if ( null === $id || '' === $id || false === $id ) {
			return null;
		}
		if ( is_int( $id ) ) {
			return $id;
		}
		if ( is_numeric( $id ) ) {
			return (int) $id;
		}
		if ( is_string( $id ) ) {
			return trim( $id );
		}
		return null;
	}

	/**
	 * Normalizes settings price lists to [ key => markup ].
	 *
	 * Accepts the canonical list of { key, label, markup } rows and, for robustness, a map
	 * key => markup or key => { markup }.
	 *
	 * @param mixed $value Settings value.
	 * @return array
	 */
	private static function price_lists( $value ) : array {
		$lists = array();
		if ( ! is_array( $value ) ) {
			return $lists;
		}

		foreach ( $value as $index => $entry ) {
			$entry_row = self::row( $entry );

			if ( is_array( $entry ) || is_object( $entry ) ) {
				$key = self::pick( $entry_row, 'key' );
				if ( ( null === $key || '' === $key ) && is_string( $index ) ) {
					$key = $index;
				}
				$markup = self::pick( $entry_row, 'markup' );
			} else {
				// Map form: key => markup.
				$key    = is_string( $index ) ? $index : null;
				$markup = $entry;
			}

			if ( null === $key || ( ! is_string( $key ) && ! is_numeric( $key ) ) ) {
				continue;
			}
			$key = trim( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$lists[ $key ] = self::to_float( $markup );
		}

		return $lists;
	}
}
