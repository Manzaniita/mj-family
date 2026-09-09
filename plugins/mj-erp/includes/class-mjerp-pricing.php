<?php
/**
 * The price a reseller sees while logged in on the shop.
 *
 * Off by default. When `shop_price_mode` (or a reseller's own override) says `lista`, the catalogue
 * is priced from the landed cost plus that list's markup — the same ladder the ERP uses to publish
 * shelf prices, so a wholesale price never contradicts a retail one.
 *
 * Everything here runs on the storefront only. WooCommerce caches variation prices per request and
 * in a transient keyed by a hash, so the resolved list takes part in that hash: without it, the
 * first visitor of the day would freeze their prices for everyone else.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Pricing
 */
class MJERP_Pricing {

	/**
	 * Price list resolved for the current visitor. `null` = not resolved yet, '' = public price.
	 *
	 * @var string|null
	 */
	private static $list = null;

	/**
	 * Per-request cache of computed prices, keyed by "product id|list".
	 *
	 * @var array
	 */
	private static $prices = array();

	/**
	 * Hooks the price filters.
	 *
	 * @return void
	 */
	public static function init() {
		// Cheap enough to always register: every filter returns early when no list applies.
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 20, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'filter_variation_price' ), 20, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'filter_variation_price' ), 20, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'filter_prices_hash' ), 20, 1 );
	}

	/**
	 * Price list that applies to the current visitor ('' when none does).
	 *
	 * @return string
	 */
	public static function current_list() : string {
		if ( null !== self::$list ) {
			return self::$list;
		}

		self::$list = '';

		if ( is_admin() && ! wp_doing_ajax() ) {
			// The shop owner has to keep seeing the real catalogue price while working.
			return self::$list;
		}
		if ( ! is_user_logged_in() ) {
			return self::$list;
		}

		$consignee = MJERP_Consignees::current();
		if ( ! $consignee || empty( $consignee['is_active'] ) ) {
			return self::$list;
		}

		self::$list = MJERP_Permissions::shop_price_list( (int) $consignee['user_id'], $consignee );

		return self::$list;
	}

	/**
	 * Whether the current visitor is being priced from a list.
	 *
	 * @return bool
	 */
	public static function is_active() : bool {
		return '' !== self::current_list();
	}

	/**
	 * Replaces the price of a product.
	 *
	 * @param mixed      $price   Original price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_price( $price, $product ) {
		$list = self::current_list();
		if ( '' === $list || ! $product instanceof WC_Product ) {
			return $price;
		}

		$computed = self::price_for( $product->get_id(), $list );

		return null === $computed ? $price : $computed;
	}

	/**
	 * Clears the sale price: a list price is already the final one, and leaving a sale price behind
	 * would show a "discount" against a number the reseller never sees.
	 *
	 * @param mixed      $price   Original sale price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_sale_price( $price, $product ) {
		$list = self::current_list();
		if ( '' === $list || ! $product instanceof WC_Product ) {
			return $price;
		}
		return null === self::price_for( $product->get_id(), $list ) ? $price : '';
	}

	/**
	 * Replaces a variation price inside the parent's price range.
	 *
	 * @param mixed      $price     Original price.
	 * @param WC_Product $variation Variation.
	 * @param WC_Product $product   Parent product.
	 * @return mixed
	 */
	public static function filter_variation_price( $price, $variation, $product ) {
		unset( $product );
		return self::filter_price( $price, $variation );
	}

	/**
	 * Makes the resolved list part of the variation price cache key.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public static function filter_prices_hash( $hash ) {
		$list = self::current_list();
		if ( '' !== $list ) {
			$hash['mjerp_price_list'] = $list;
		}
		return is_array( $hash ) ? $hash : array();
	}

	/**
	 * Price of a product under a list, in the shop currency (null when it cannot be computed).
	 *
	 * A product that was never costed keeps its catalogue price: inventing a wholesale price out of
	 * nothing would be worse than showing the public one.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $list_key   Price list key.
	 * @return float|null
	 */
	public static function price_for( $product_id, string $list_key ) : ?float {
		$product_id = absint( $product_id );
		if ( ! $product_id || '' === $list_key ) {
			return null;
		}

		$cache_key = $product_id . '|' . $list_key;
		if ( array_key_exists( $cache_key, self::$prices ) ) {
			return self::$prices[ $cache_key ];
		}

		self::$prices[ $cache_key ] = null;

		$list = MJERP_Settings::price_list( $list_key );
		if ( ! $list ) {
			return null;
		}

		$fields = MJERP_Products::cost_fields( $product_id );
		$cost   = isset( $fields['cost_base'] ) ? $fields['cost_base'] : null;
		if ( null === $cost || $cost <= 0 ) {
			return null;
		}

		$rate    = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::rate() : MJERP_Helpers::to_float( MJERP_Settings::get( 'sale_rate', 1 ) );
		$usd_cfg = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::usd_config() : null;
		$price   = MJERP_Costing::price_for(
			(float) $cost,
			(float) $list['markup'],
			$rate,
			(int) MJERP_Settings::get( 'price_rounding', 0 ),
			$usd_cfg
		);

		$shop_currency = MJERP_Sales_Repo::shop_currency();
		$base_currency = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );

		if ( $shop_currency === $base_currency ) {
			$value = (float) $price['usd'];
		} else {
			// `usd_local` is what the shop would actually charge for that USD price once MJ Dólar
			// converted and rounded it, so a list price lands on the same steps as the shelf price.
			$value = 'usd' === (string) MJERP_Settings::get( 'price_publish_mode', 'auto' )
				? (float) $price['usd_local']
				: (float) $price['local'];
		}

		/**
		 * Filters the price a reseller is charged for a product.
		 *
		 * @param float  $value      Price in the shop currency.
		 * @param int    $product_id Product id.
		 * @param string $list_key   Price list key.
		 */
		$value = (float) apply_filters( 'mjerp_reseller_price', $value, $product_id, $list_key );

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		self::$prices[ $cache_key ] = $value > 0 ? round( $value, $decimals ) : null;

		return self::$prices[ $cache_key ];
	}

	/**
	 * Clears the per-request caches (used by the tests and after a settings change).
	 *
	 * @return void
	 */
	public static function flush_cache() : void {
		self::$list   = null;
		self::$prices = array();
	}
}
