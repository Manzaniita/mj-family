<?php
defined( 'ABSPATH' ) || exit;

/**
 * MJ ERP <-> MJ Dolar bridge.
 *
 * MJ Dolar owns the published price: `_mjd_usd_regular` / `_mjd_usd_sale` are the source of truth
 * and the store price in pesos is USD x TC, recomputed on every read. MJ ERP owns the cost: the
 * landed unit cost in base currency (USD) plus the markup of a price list.
 *
 * Without this bridge both plugins fight over `_regular_price`: the ERP writes a peso amount on
 * reception and MJ Dolar overwrites it on the next read from its own USD meta. With it, reception
 * publishes the suggested price *in dollars* and MJ Dolar keeps the pesos in sync with the TC, so
 * the theme (MJ Appearance and its child themes, which already render `product.usd` coming from the
 * MJ Dolar Store API extension) shows the right number without any extra integration.
 *
 * Everything degrades gracefully: with MJ Dolar inactive the ERP keeps writing peso prices exactly
 * as before.
 *
 * @package MJ_ERP
 */
class MJERP_Dolar {

	/**
	 * Registers the hooks. Safe to call when MJ Dolar is not installed.
	 *
	 * @return void
	 */
	public static function init() {
		// Read-only `dolar` block + TC taken from MJ Dolar when that is the configured source.
		add_filter( 'mjerp_settings', array( __CLASS__, 'filter_settings' ), 5 );

		// Every suggested price in the ERP moves with the TC, so the cached settings have to go.
		add_action( 'mjd_rate_updated', array( __CLASS__, 'on_rate_updated' ), 10, 3 );
	}

	/* ------------------------------------------------------------------
	 * Detection
	 * ---------------------------------------------------------------- */

	/**
	 * Whether MJ Dolar is active with the API this bridge needs.
	 *
	 * @return bool
	 */
	public static function is_active() : bool {
		return class_exists( 'MJD_Rate' )
			&& class_exists( 'MJD_Product' )
			&& is_callable( array( 'MJD_Rate', 'get' ) )
			&& is_callable( array( 'MJD_Product', 'set_usd' ) )
			&& is_callable( array( 'MJD_Product', 'apply' ) );
	}

	/**
	 * Whether MJ Dolar is active *and* has an exchange rate loaded.
	 *
	 * Publishing in USD without a TC would leave the store with no peso price at all
	 * (MJ Dolar bails out of both the write and the read filters), so this is the real gate.
	 *
	 * @return bool
	 */
	public static function is_ready() : bool {
		return self::is_active() && self::rate() > 0;
	}

	/**
	 * Exchange rate configured in MJ Dolar (pesos per dollar). 0 when unavailable.
	 *
	 * @return float
	 */
	public static function rate() : float {
		if ( ! self::is_active() ) {
			return 0.0;
		}
		try {
			$rate = (float) MJD_Rate::get();
		} catch ( Throwable $e ) {
			return 0.0;
		}
		return $rate > 0 ? $rate : 0.0;
	}

	/**
	 * Peso rounding step configured in MJ Dolar (0 = cents).
	 *
	 * @return int
	 */
	public static function store_rounding() : int {
		if ( ! class_exists( 'MJD_Settings' ) ) {
			return 0;
		}
		return max( 0, (int) MJD_Settings::get( 'rounding' ) );
	}

	/**
	 * Peso rounding mode configured in MJ Dolar: `nearest` | `up`.
	 *
	 * @return string
	 */
	public static function store_rounding_mode() : string {
		if ( ! class_exists( 'MJD_Settings' ) ) {
			return 'nearest';
		}
		return 'up' === MJD_Settings::get( 'rounding_mode' ) ? 'up' : 'nearest';
	}

	/**
	 * Admin URL of the MJ Dolar settings screen ('' when the plugin is absent).
	 *
	 * @return string
	 */
	public static function settings_url() : string {
		if ( ! self::is_active() ) {
			return '';
		}
		if ( class_exists( 'MJD_Admin' ) && is_callable( array( 'MJD_Admin', 'url' ) ) ) {
			return (string) MJD_Admin::url();
		}
		return admin_url( 'admin.php?page=mj-dolar' );
	}

	/**
	 * USD to pesos exactly as the storefront will show it (MJ Dolar rate, rounding and filters).
	 * Falls back to the ERP sale rate when MJ Dolar is absent.
	 *
	 * @param float $usd Amount in base currency.
	 * @return float
	 */
	public static function convert( $usd ) : float {
		$usd = MJERP_Helpers::to_float( $usd );

		if ( self::is_ready() ) {
			try {
				return (float) MJD_Rate::convert( $usd );
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}

		$rate = MJERP_Helpers::to_float( MJERP_Settings::get( 'sale_rate', 1 ) );
		$rate = $rate > 0 ? $rate : 1.0;

		return MJERP_Costing::round_ars( $usd * $rate, self::store_rounding(), self::store_rounding_mode() );
	}

	/* ------------------------------------------------------------------
	 * Configuration
	 * ---------------------------------------------------------------- */

	/**
	 * Where the suggested price is written on reception.
	 *
	 * - `usd`   : MJ Dolar meta (`_mjd_usd_regular` / `_mjd_usd_sale`); the pesos follow the TC.
	 * - `local` : the WooCommerce price field, in store currency (the pre-bridge behaviour).
	 *
	 * `auto` and `usd` both resolve to `usd` only when MJ Dolar is ready; without it there is
	 * nothing to publish into.
	 *
	 * @return string `usd` | `local`
	 */
	public static function publish_mode() : string {
		if ( 'local' === (string) MJERP_Settings::get( 'price_publish_mode', 'auto' ) ) {
			return 'local';
		}
		return self::is_ready() ? 'usd' : 'local';
	}

	/**
	 * Why the publish mode is not `usd`, for the UI. '' when it is (or when `local` was chosen).
	 *
	 * @return string
	 */
	public static function publish_blocker() : string {
		if ( 'local' === (string) MJERP_Settings::get( 'price_publish_mode', 'auto' ) ) {
			return '';
		}
		if ( ! self::is_active() ) {
			return __( 'MJ Dolar no esta activo: los precios se publican en la moneda de la tienda.', 'mj-erp' );
		}
		if ( self::rate() <= 0 ) {
			return __( 'MJ Dolar no tiene tipo de cambio cargado: los precios se publican en la moneda de la tienda.', 'mj-erp' );
		}
		return '';
	}

	/**
	 * USD rounding step for the published price (0 = two decimals).
	 *
	 * @return float
	 */
	public static function usd_rounding() : float {
		$step = MJERP_Helpers::to_float( MJERP_Settings::get( 'usd_rounding', 0 ) );
		return $step > 0 ? $step : 0.0;
	}

	/**
	 * USD rounding mode: `nearest` | `up`.
	 *
	 * @return string
	 */
	public static function usd_rounding_mode() : string {
		return 'up' === MJERP_Settings::get( 'usd_rounding_mode', 'nearest' ) ? 'up' : 'nearest';
	}

	/**
	 * Rounding context handed to the costing engine (PHP and its JS mirror use the same shape).
	 *
	 * @return array { rounding, mode, store_rate, store_rounding, store_mode }
	 */
	public static function usd_config() : array {
		return array(
			'rounding'       => self::usd_rounding(),
			'mode'           => self::usd_rounding_mode(),
			'store_rate'     => self::rate(),
			'store_rounding' => self::store_rounding(),
			'store_mode'     => self::store_rounding_mode(),
		);
	}

	/* ------------------------------------------------------------------
	 * Publishing
	 * ---------------------------------------------------------------- */

	/**
	 * Publishes a price in dollars through MJ Dolar.
	 *
	 * The peso price is written by MJ Dolar itself (USD x TC with its own rounding), so the ERP
	 * never touches `_regular_price` in this mode and the two plugins cannot disagree.
	 *
	 * @param WC_Product|int $product Product or variation.
	 * @param float          $usd     Price in base currency (already rounded by the caller).
	 * @param string         $target  `regular_price` | `sale_price`.
	 * @return array { ok: bool, usd: float|null, local: float|null, target: string, message: string }
	 */
	public static function publish_usd( $product, $usd, $target = 'regular_price' ) : array {
		$out = array(
			'ok'      => false,
			'usd'     => null,
			'local'   => null,
			'target'  => 'sale_price' === $target ? 'sale_price' : 'regular_price',
			'message' => '',
		);

		if ( ! self::is_ready() ) {
			$out['message'] = __( 'MJ Dolar no esta disponible para publicar el precio en dolares.', 'mj-erp' );
			return $out;
		}

		if ( ! $product instanceof WC_Product ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product ) ) : false;
		}
		if ( ! $product instanceof WC_Product ) {
			$out['message'] = __( 'El producto no existe en WooCommerce.', 'mj-erp' );
			return $out;
		}
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			$out['message'] = __( 'El producto es variable: el precio en dolares se define por variacion.', 'mj-erp' );
			return $out;
		}

		$usd = round( MJERP_Helpers::to_float( $usd ), 4 );
		if ( $usd <= 0 ) {
			$out['message'] = __( 'El precio en dolares calculado es cero: no se publico.', 'mj-erp' );
			return $out;
		}

		try {
			$current_regular = MJD_Product::usd_regular( $product );
			$current_sale    = MJD_Product::usd_sale( $product );

			if ( 'sale_price' === $out['target'] ) {
				if ( null === $current_regular ) {
					// MJ Dolar deletes the sale price when there is no USD regular price, so a
					// sale-only publication would be silently lost: write the regular instead.
					$out['target']  = 'regular_price';
					$out['message'] = __( 'El producto no tenia precio en dolares: se publico como precio normal en vez de oferta.', 'mj-erp' );
					$regular        = $usd;
					$sale           = ( null !== $current_sale && $current_sale < $usd ) ? $current_sale : '';
				} elseif ( $usd >= $current_regular ) {
					$out['message'] = __( 'El precio de oferta en dolares no es menor al precio normal: no se publico.', 'mj-erp' );
					return $out;
				} else {
					$regular = $current_regular;
					$sale    = $usd;
				}
			} else {
				$regular = $usd;
				// Preserve a running USD sale price; MJ Dolar drops it on its own when it is no
				// longer below the new regular price.
				$sale = ( null !== $current_sale ) ? $current_sale : '';
			}

			$has_usd = MJD_Product::set_usd( $product, $regular, $sale );
			if ( ! $has_usd ) {
				$out['message'] = __( 'MJ Dolar rechazo el precio en dolares.', 'mj-erp' );
				return $out;
			}

			// Writes USD x TC into the WooCommerce price fields so ordering, filters and reports see
			// real pesos. $save = false because the product is saved once, right below.
			MJD_Product::apply( $product, null, false );
			$product->save();

			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( $parent_id ) {
					MJD_Product::sync_parent_usd( $parent_id );
					if ( class_exists( 'WC_Product_Variable' ) ) {
						WC_Product_Variable::sync( $parent_id );
					}
				}
			}
		} catch ( Throwable $e ) {
			$out['message'] = $e->getMessage() ? $e->getMessage() : __( 'No se pudo publicar el precio en dolares.', 'mj-erp' );
			return $out;
		}

		$out['ok']    = true;
		$out['usd']   = $usd;
		$out['local'] = self::convert( $usd );

		/**
		 * Fires after MJ ERP published a price in dollars through MJ Dolar.
		 *
		 * @param WC_Product $product Product or variation.
		 * @param float      $usd     Published price in base currency.
		 * @param string     $target  regular_price | sale_price.
		 */
		do_action( 'mjerp_usd_price_published', $product, $usd, $out['target'] );

		return $out;
	}

	/**
	 * USD price currently published for a product, for the listings and margin columns.
	 *
	 * @param WC_Product|int $product Product.
	 * @return array { usd_regular: float|null, usd_sale: float|null, usd_price: float|null }
	 */
	public static function published_usd( $product ) : array {
		$out = array(
			'usd_regular' => null,
			'usd_sale'    => null,
			'usd_price'   => null,
		);

		if ( ! self::is_active() ) {
			return $out;
		}
		if ( ! $product instanceof WC_Product ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product ) ) : false;
		}
		if ( ! $product instanceof WC_Product ) {
			return $out;
		}

		try {
			// usd_effective() applies the same sale rules as WooCommerce (dates, sale < regular).
			$pair    = MJD_Product::usd_effective( $product );
			$regular = isset( $pair[0] ) ? $pair[0] : null;
			$sale    = isset( $pair[1] ) ? $pair[1] : null;
		} catch ( Throwable $e ) {
			return $out;
		}

		$out['usd_regular'] = ( null !== $regular ) ? round( (float) $regular, 4 ) : null;
		$out['usd_sale']    = ( null !== $sale ) ? round( (float) $sale, 4 ) : null;
		$out['usd_price']   = ( null !== $out['usd_sale'] ) ? $out['usd_sale'] : $out['usd_regular'];

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Injects the read-only `dolar` block into the settings and, when MJ Dolar is the configured
	 * exchange-rate source, overrides `sale_rate` with its TC.
	 *
	 * A single source of truth for the rate is what makes the suggested price shown in the ERP equal
	 * to the price the customer ends up seeing. The block itself is never persisted:
	 * MJERP_Settings::update() keeps only the keys present in the defaults.
	 *
	 * @param array $settings Merged settings.
	 * @return array
	 */
	public static function filter_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$active = self::is_active();
		$rate   = self::rate();

		$source = isset( $settings['sale_rate_source'] ) ? (string) $settings['sale_rate_source'] : 'mjdolar';
		if ( 'mjdolar' === $source && $rate > 0 ) {
			$settings['sale_rate'] = $rate;
		}

		$mode      = isset( $settings['price_publish_mode'] ) ? (string) $settings['price_publish_mode'] : 'auto';
		$publishes = ( 'local' !== $mode ) && $active && $rate > 0;

		$settings['dolar'] = array(
			'active'         => $active,
			'ready'          => $active && $rate > 0,
			'rate'           => $rate,
			'rounding'       => self::store_rounding(),
			'rounding_mode'  => self::store_rounding_mode(),
			'updated_at'     => ( $active && is_callable( array( 'MJD_Rate', 'updated_at' ) ) ) ? (int) MJD_Rate::updated_at() : 0,
			'show_usd_price' => ( $active && class_exists( 'MJD_Settings' ) ) ? (string) MJD_Settings::get( 'show_usd_price' ) : '',
			'settings_url'   => self::settings_url(),
			'publish_mode'   => $publishes ? 'usd' : 'local',
		);

		return $settings;
	}

	/**
	 * Drops the settings cache when the TC changes in MJ Dolar.
	 *
	 * @param float  $new  New rate.
	 * @param float  $old  Previous rate.
	 * @param string $note Note recorded with the change.
	 * @return void
	 */
	public static function on_rate_updated( $new = 0, $old = 0, $note = '' ) {
		if ( class_exists( 'MJERP_Settings' ) ) {
			MJERP_Settings::flush_cache();
		}
	}
}
