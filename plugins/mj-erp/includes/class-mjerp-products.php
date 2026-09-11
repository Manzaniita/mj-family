<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bridge between MJ ERP and WooCommerce products.
 *
 * Search, summaries, creation, listing (with variations expanded), cost meta and price updates.
 * All public methods are static and never throw: WooCommerce exceptions are caught and converted
 * into null / false / WP_Error results.
 */
class MJERP_Products {

	const META_COST_LOCAL         = '_mjerp_cost_local';
	const META_COST_CURRENCY      = '_mjerp_cost_currency';
	const META_COST_UPDATED_AT    = '_mjerp_cost_updated_at';
	const META_LAST_PURCHASE_ID   = '_mjerp_last_purchase_id';
	const META_LAST_PURCHASE_CODE = '_mjerp_last_purchase_code';

	const DEFAULT_COST_META_KEY = '_mjerp_cost';

	/**
	 * Meta key that stores the landed unit cost (base currency). Configurable via settings.
	 *
	 * @return string
	 */
	public static function cost_meta_key() : string {
		$key = self::setting( 'cost_meta_key', self::DEFAULT_COST_META_KEY );
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key ) {
			$key = self::DEFAULT_COST_META_KEY;
		}
		return $key;
	}

	/* ------------------------------------------------------------------
	 * Search
	 * ---------------------------------------------------------------- */

	/**
	 * Search products (simple, variable and variations, all statuses) by name / SKU.
	 * An exact SKU match is always returned first.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Max results.
	 * @return array List of product summaries.
	 */
	public static function search( $term, $limit = 20 ) : array {
		$term  = trim( sanitize_text_field( (string) $term ) );
		$limit = max( 1, min( 100, (int) $limit ) );

		if ( '' === $term || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$ids = self::search_ids( $term, $limit );

		// Exact SKU match goes first.
		$sku_id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $term ) : 0;
		if ( $sku_id > 0 ) {
			$ids = array_values( array_diff( $ids, array( $sku_id ) ) );
			array_unshift( $ids, $sku_id );
		}

		$ids   = array_slice( $ids, 0, $limit + 5 );
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// A variable parent cannot be purchased: stock and cost live on its variations, which
			// the same search already returns. Offering it would only lead to a rejected item.
			if ( $product->is_type( 'variable' ) && ! empty( $product->get_children() ) ) {
				continue;
			}

			$summary = self::summary( $product );
			if ( $summary ) {
				$items[] = $summary;
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Raw product id search through the WooCommerce data store.
	 *
	 * @param string $term  Term.
	 * @param int    $limit 0 = no limit.
	 * @return int[]
	 */
	private static function search_ids( string $term, int $limit = 0 ) : array {
		$ids = array();

		try {
			if ( class_exists( 'WC_Data_Store' ) ) {
				$store = WC_Data_Store::load( 'product' );
				$ids   = $store->search_products( $term, '', true, true, $limit > 0 ? $limit : null );
			}
		} catch ( Exception $e ) {
			$ids = array();
		}

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			// Fallback: plain WP title search.
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					's'              => $term,
					'fields'         => 'ids',
					'posts_per_page' => $limit > 0 ? $limit : -1,
					'no_found_rows'  => true,
				)
			);
		}

		$ids = array_map( 'absint', (array) $ids );
		$ids = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}

	/* ------------------------------------------------------------------
	 * Summaries
	 * ---------------------------------------------------------------- */

	/**
	 * Build the product summary used everywhere in the ERP.
	 *
	 * @param WC_Product|int $product Product object or id.
	 * @return array|null
	 */
	public static function summary( $product ) {
		if ( ! $product instanceof WC_Product ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return null;
			}
			$product = $product ? wc_get_product( absint( $product ) ) : false;
		}
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $is_variation ? (int) $product->get_id() : 0;
		$parent       = ( $is_variation && $parent_id ) ? wc_get_product( $parent_id ) : null;
		$parent_name  = $parent instanceof WC_Product ? self::clean_name( $parent->get_name() ) : null;

		if ( $is_variation ) {
			$attributes = function_exists( 'wc_get_formatted_variation' ) ? wc_get_formatted_variation( $product, true, false, false ) : '';
			$attributes = is_string( $attributes ) ? trim( wp_strip_all_tags( $attributes ) ) : '';
			if ( '' !== $attributes ) {
				$name = trim( (string) $parent_name . ' - ' . $attributes, " -\t\n\r" );
			} else {
				$name = self::clean_name( $product->get_name() );
			}
		} else {
			$name = self::clean_name( $product->get_name() );
		}

		$holder   = self::stock_holder( $product );
		$stock    = $holder->get_stock_quantity();
		$stock    = ( null === $stock || '' === $stock ) ? null : self::int_or_float( $stock );
		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $parent instanceof WC_Product ) {
			$image_id = (int) $parent->get_image_id();
		}
		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : false;

		$cost = self::cost_fields( $product );

		return array(
			'id'                 => (int) $product->get_id(),
			'product_id'         => $parent_id,
			'variation_id'       => $variation_id,
			'type'               => $product->get_type(),
			'name'               => $name,
			'sku'                => (string) $product->get_sku(),
			'status'             => $product->get_status(),
			'price'              => $cost['price'],
			'regular_price'      => self::price_or_null( $product->get_regular_price() ),
			'sale_price'         => self::price_or_null( $product->get_sale_price() ),
			'manage_stock'       => (bool) $product->managing_stock(),
			'stock_quantity'     => $stock,
			'stock_status'       => $product->get_stock_status(),
			'cost_base'          => $cost['cost_base'],
			'cost_local'         => $cost['cost_local'],
			'cost_currency'      => $cost['cost_currency'],
			'cost_updated_at'    => $cost['cost_updated_at'],
			'last_purchase_id'   => $cost['last_purchase_id'],
			'last_purchase_code' => $cost['last_purchase_code'],
			'image'              => $image ? $image : null,
			'permalink'          => $product->get_permalink(),
			'edit_link'          => admin_url( 'post.php?post=' . $parent_id . '&action=edit' ),
			'margin_pct'         => $cost['margin_pct'],
			'markup_pct'         => $cost['markup_pct'],
			// MJ Dólar: price published in dollars and the margin measured against it.
			'price_usd'          => $cost['price_usd'],
			'usd_regular'        => $cost['usd_regular'],
			'usd_sale'           => $cost['usd_sale'],
			'margin_usd_pct'     => $cost['margin_usd_pct'],
			'markup_usd_pct'     => $cost['markup_usd_pct'],
			// Unidades en poder de revendedores. El stock de WooCommerce todavía las cuenta (salen
			// al venderse), así que sin este dato no se sabe qué queda realmente disponible.
			'on_consignment'     => class_exists( 'MJERP_Consignments_Repo' )
				? MJERP_Consignments_Repo::on_hand_for_product( (int) $product->get_id() )
				: 0.0,
			'is_variation'       => $is_variation,
			'parent_name'        => $parent_name,
		);
	}

	/**
	 * Summary from the ids stored on a purchase item.
	 *
	 * @param int $product_id   Parent/simple product id.
	 * @param int $variation_id Variation id (0 if none).
	 * @return array|null
	 */
	public static function summary_by_ids( $product_id, $variation_id = 0 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$id           = $variation_id ? $variation_id : $product_id;

		if ( ! $id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return self::summary( $product );
	}

	/**
	 * Find a product (simple or variation) by exact SKU.
	 *
	 * @param string $sku SKU.
	 * @return array|null
	 */
	public static function find_by_sku( $sku ) {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_clean' ) ) {
			return null;
		}
		$sku = trim( wc_clean( (string) $sku ) );
		if ( '' === $sku ) {
			return null;
		}

		$id = (int) wc_get_product_id_by_sku( $sku );
		if ( ! $id ) {
			return null;
		}

		$product = wc_get_product( $id );
		return $product instanceof WC_Product ? self::summary( $product ) : null;
	}

	/**
	 * Find a product by exact title (simple, variable or variation post title).
	 * Stock-holding types (simple / variation) win over variable parents on ties.
	 *
	 * @param string $name Title.
	 * @return array|null
	 */
	public static function find_by_name( $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'title'                  => $name,
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => 'any',
				'posts_per_page'         => 5,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = is_array( $query->posts ) ? array_map( 'absint', $query->posts ) : array();
		if ( empty( $ids ) ) {
			return null;
		}

		$first = null;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( null === $first ) {
				$first = $product;
			}
			if ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) {
				return self::summary( $product );
			}
		}

		return $first ? self::summary( $first ) : null;
	}

	/* ------------------------------------------------------------------
	 * Create
	 * ---------------------------------------------------------------- */

	/**
	 * Create a simple product.
	 *
	 * @param array $data name (required), sku, regular_price, status, category_ids, manage_stock, stock_quantity, cost,
	 *                    description, short_description.
	 * @return array|WP_Error Product summary or error.
	 */
	public static function create( array $data ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'mjerp_wc_missing', __( 'WooCommerce no está disponible.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$name = isset( $data['name'] ) ? trim( sanitize_text_field( (string) $data['name'] ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'mjerp_invalid', __( 'El nombre del producto es obligatorio.', 'mj-erp' ), array( 'status' => 400, 'field' => 'name' ) );
		}

		$sku = isset( $data['sku'] ) ? trim( wc_clean( (string) $data['sku'] ) ) : '';
		if ( '' !== $sku ) {
			$existing = (int) wc_get_product_id_by_sku( $sku );
			if ( $existing ) {
				return new WP_Error(
					'mjerp_sku_exists',
					/* translators: %s: SKU */
					sprintf( __( 'Ya existe un producto con el SKU «%s».', 'mj-erp' ), $sku ),
					array( 'status' => 409, 'field' => 'sku', 'product_id' => $existing )
				);
			}
		}

		$allowed_status = array( 'draft', 'publish', 'private', 'pending' );
		$status         = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = sanitize_key( (string) self::setting( 'new_product_status', 'draft' ) );
			if ( ! in_array( $status, $allowed_status, true ) ) {
				$status = 'draft';
			}
		}

		$regular_price = null;
		if ( isset( $data['regular_price'] ) && '' !== $data['regular_price'] && null !== $data['regular_price'] ) {
			$regular_price = self::to_float( $data['regular_price'] );
		} elseif ( isset( $data['price'] ) && '' !== $data['price'] && null !== $data['price'] ) {
			$regular_price = self::to_float( $data['price'] );
		}
		if ( null !== $regular_price && $regular_price < 0 ) {
			return new WP_Error( 'mjerp_invalid', __( 'El precio no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'regular_price' ) );
		}

		$manage_stock = array_key_exists( 'manage_stock', $data ) ? self::to_bool( $data['manage_stock'] ) : true;
		$stock_qty    = isset( $data['stock_quantity'] ) ? self::to_float( $data['stock_quantity'] ) : 0;
		$stock_qty    = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $stock_qty ) : (int) $stock_qty;
		if ( $stock_qty < 0 ) {
			return new WP_Error( 'mjerp_invalid', __( 'El stock inicial no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'stock_quantity' ) );
		}

		$category_ids = array();
		if ( ! empty( $data['category_ids'] ) ) {
			$raw_ids = is_array( $data['category_ids'] ) ? $data['category_ids'] : explode( ',', (string) $data['category_ids'] );
			foreach ( $raw_ids as $cat_id ) {
				$cat_id = absint( $cat_id );
				if ( $cat_id && term_exists( $cat_id, 'product_cat' ) ) {
					$category_ids[] = $cat_id;
				}
			}
			$category_ids = array_values( array_unique( $category_ids ) );
		} elseif ( ! empty( $data['category_id'] ) ) {
			$cat_id = absint( $data['category_id'] );
			if ( $cat_id && term_exists( $cat_id, 'product_cat' ) ) {
				$category_ids[] = $cat_id;
			}
		}

		$cost = null;
		if ( isset( $data['cost'] ) && '' !== $data['cost'] && null !== $data['cost'] ) {
			$cost = self::to_float( $data['cost'] );
			if ( $cost < 0 ) {
				return new WP_Error( 'mjerp_invalid', __( 'El costo no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'cost' ) );
			}
		}

		// Sale price in base currency, published through MJ Dólar (which then owns the peso price).
		$usd_regular = null;
		if ( isset( $data['usd_regular'] ) && '' !== $data['usd_regular'] && null !== $data['usd_regular'] ) {
			$usd_regular = self::to_float( $data['usd_regular'] );
			if ( $usd_regular < 0 ) {
				return new WP_Error( 'mjerp_invalid', __( 'El precio en dólares no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'usd_regular' ) );
			}
		}

		$description       = isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '';
		$short_description = isset( $data['short_description'] ) ? wp_kses_post( (string) $data['short_description'] ) : '';

		$id = 0;
		try {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( $status );
			if ( '' !== $sku ) {
				$product->set_sku( $sku );
			}
			if ( null !== $regular_price ) {
				$product->set_regular_price( wc_format_decimal( $regular_price ) );
			}
			if ( ! empty( $category_ids ) ) {
				$product->set_category_ids( $category_ids );
			}
			$product->set_manage_stock( $manage_stock );
			if ( $manage_stock ) {
				$product->set_stock_quantity( $stock_qty );
			}
			if ( '' !== $description ) {
				$product->set_description( $description );
			}
			if ( '' !== $short_description ) {
				$product->set_short_description( $short_description );
			}

			$id = (int) $product->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'mjerp_create_failed', $e->getMessage() ? $e->getMessage() : __( 'No se pudo crear el producto.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		if ( ! $id ) {
			return new WP_Error( 'mjerp_create_failed', __( 'No se pudo crear el producto en WooCommerce.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		if ( null !== $cost ) {
			$sale_rate = self::to_float( self::setting( 'sale_rate', 1 ) );
			self::update_cost( $id, $cost, $cost * ( $sale_rate > 0 ? $sale_rate : 1 ), array() );
		}

		// Publishing in dollars overwrites the WooCommerce price with USD × TC, so it runs last.
		if ( null !== $usd_regular && $usd_regular > 0 && class_exists( 'MJERP_Dolar' ) && MJERP_Dolar::is_ready() ) {
			MJERP_Dolar::publish_usd( $id, $usd_regular, 'regular_price' );
		}

		$created = wc_get_product( $id );
		$summary = $created instanceof WC_Product ? self::summary( $created ) : null;

		if ( ! $summary ) {
			return new WP_Error( 'mjerp_create_failed', __( 'El producto se creó pero no se pudo leer.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return $summary;
	}

	/* ------------------------------------------------------------------
	 * Listing (Productos page)
	 * ---------------------------------------------------------------- */

	/**
	 * Paginated product list with variations expanded and margin fields.
	 *
	 * @param array $args search, page, per_page, orderby (title|name|date|id|price|modified|menu_order), order, only_with_cost, category.
	 * @return array { items, total, pages, page, per_page }
	 */
	public static function query( array $args ) : array {
		$args = wp_parse_args(
			$args,
			array(
				'search'         => '',
				'page'           => 1,
				'per_page'       => 20,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'only_with_cost' => false,
				'category'       => '',
				'only_in_stock'  => false,
			)
		);

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$search   = trim( sanitize_text_field( (string) $args['search'] ) );
		$order    = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';

		$orderby_map = array(
			'title'      => 'title',
			'name'       => 'title',
			'date'       => 'date',
			'id'         => 'ID',
			'price'      => 'price',
			'modified'   => 'modified',
			'menu_order' => 'menu_order',
			'sku'        => 'title',
		);
		$orderby_key = strtolower( sanitize_key( (string) $args['orderby'] ) );
		$orderby     = isset( $orderby_map[ $orderby_key ] ) ? $orderby_map[ $orderby_key ] : 'title';

		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'pages'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
		);

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $empty;
		}

		$include = null;

		if ( '' !== $search ) {
			$include = self::search_ids( $search, 0 );
			if ( empty( $include ) ) {
				return $empty;
			}
		}

		if ( self::to_bool( $args['only_with_cost'] ) ) {
			$with_cost = self::ids_with_cost();
			if ( empty( $with_cost ) ) {
				return $empty;
			}
			$include = null === $include ? $with_cost : array_values( array_intersect( $include, $with_cost ) );
			if ( empty( $include ) ) {
				return $empty;
			}
		}

		$query_args = array(
			'status'   => array( 'publish', 'draft', 'private' ),
			'type'     => array( 'simple', 'variable' ),
			'paginate' => true,
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
			'return'   => 'objects',
		);

		if ( null !== $include ) {
			$query_args['include'] = $include;
		}

		/*
		 * "Solo lo que hay": en el mostrador no sirve ver lo agotado. Filtra por el estado de stock
		 * del producto, así que un variable entra si alguna de sus variaciones está disponible.
		 */
		if ( self::to_bool( $args['only_in_stock'] ) ) {
			$query_args['stock_status'] = 'instock';
		}

		$category = $args['category'];
		if ( '' !== $category && null !== $category ) {
			$term = is_numeric( $category ) ? get_term( absint( $category ), 'product_cat' ) : get_term_by( 'slug', sanitize_title( (string) $category ), 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$query_args['category'] = array( $term->slug );
			} else {
				return $empty;
			}
		}

		try {
			$result = wc_get_products( $query_args );
		} catch ( Exception $e ) {
			return $empty;
		}

		$products = array();
		$total    = 0;
		$pages    = 0;

		if ( is_object( $result ) && isset( $result->products ) ) {
			$products = (array) $result->products;
			$total    = isset( $result->total ) ? (int) $result->total : count( $products );
			$pages    = isset( $result->max_num_pages ) ? (int) $result->max_num_pages : ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 1 );
		} elseif ( is_array( $result ) ) {
			$products = $result;
			$total    = count( $products );
			$pages    = 1;
		}

		$items = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				$product = wc_get_product( absint( $product ) );
			}
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$summary = self::summary( $product );
			if ( $summary ) {
				$items[] = $summary;
			}

			if ( $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				foreach ( (array) $children as $child_id ) {
					$child = wc_get_product( absint( $child_id ) );
					if ( ! $child instanceof WC_Product ) {
						continue;
					}
					$child_summary = self::summary( $child );
					if ( $child_summary ) {
						$items[] = $child_summary;
					}
				}
			}
		}

		// `ids_with_cost()` matches a variable parent when any of its variations has a cost, so the
		// expanded rows can still contain entries without one (the parent row itself, or sibling
		// variations that were never purchased). Drop them so the filter means what it says.
		if ( self::to_bool( $args['only_with_cost'] ) ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $row ) {
						return isset( $row['cost_base'] ) && null !== $row['cost_base'] && (float) $row['cost_base'] > 0;
					}
				)
			);
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => $pages,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Parent product ids that have a cost meta (directly or through one of their variations).
	 *
	 * @return int[]
	 */
	private static function ids_with_cost() : array {
		global $wpdb;

		$key = self::cost_meta_key();

		$sql = $wpdb->prepare(
			"SELECT DISTINCT CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AS pid
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type IN ('product','product_variation')",
			$key
		);

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_map( 'absint', (array) $ids );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Product categories (flat list).
	 *
	 * @return array [ { id, name, parent, count } ]
	 */
	public static function categories() : array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $term->term_id,
				'name'   => self::clean_name( $term->name ),
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Cost & price updates
	 * ---------------------------------------------------------------- */

	/**
	 * Write the cost meta on a product/variation.
	 *
	 * @param int|WC_Product $product_id   Product (or variation) id used for the purchase item.
	 * @param float          $landed_base  Landed unit cost in base currency.
	 * @param float          $landed_local Landed unit cost in local currency.
	 * @param array          $purchase     Purchase row (uses id, code, base_currency when present).
	 * @return bool
	 */
	public static function update_cost( $product_id, $landed_base, $landed_local, array $purchase ) : bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = $product_id instanceof WC_Product ? $product_id : wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$landed_base  = round( self::to_float( $landed_base ), 4 );
		$landed_local = round( self::to_float( $landed_local ), 4 );
		if ( $landed_base < 0 || $landed_local < 0 ) {
			return false;
		}

		$currency = '';
		if ( ! empty( $purchase['base_currency'] ) ) {
			$currency = strtoupper( sanitize_text_field( (string) $purchase['base_currency'] ) );
		}
		if ( '' === $currency ) {
			$currency = strtoupper( (string) self::setting( 'base_currency', 'USD' ) );
		}

		try {
			$product->update_meta_data( self::cost_meta_key(), wc_format_decimal( $landed_base, 4 ) );
			$product->update_meta_data( self::META_COST_LOCAL, wc_format_decimal( $landed_local, 4 ) );
			$product->update_meta_data( self::META_COST_CURRENCY, $currency );
			$product->update_meta_data( self::META_COST_UPDATED_AT, current_time( 'mysql' ) );

			if ( ! empty( $purchase['id'] ) ) {
				$product->update_meta_data( self::META_LAST_PURCHASE_ID, (int) $purchase['id'] );
			}
			if ( ! empty( $purchase['code'] ) ) {
				$product->update_meta_data( self::META_LAST_PURCHASE_CODE, sanitize_text_field( (string) $purchase['code'] ) );
			}

			$product->save_meta_data();
		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Set the regular or sale price of a product/variation.
	 *
	 * @param WC_Product|int $product Product.
	 * @param float          $price   New price (store currency).
	 * @param string         $target  regular_price|sale_price.
	 * @return bool
	 */
	public static function update_price( $product, $price, $target ) : bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// Variable / grouped parents have no price of their own.
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return false;
		}

		$price = self::to_float( $price );
		if ( $price <= 0 ) {
			return false;
		}

		$target   = 'sale_price' === $target ? 'sale_price' : 'regular_price';
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$value    = wc_format_decimal( round( $price, $decimals ) );

		try {
			if ( 'sale_price' === $target ) {
				$product->set_sale_price( $value );
			} else {
				$product->set_regular_price( $value );
			}
			$product->save();

			if ( $product->is_type( 'variation' ) && $product->get_parent_id() && class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $product->get_parent_id() );
			}
		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Cost / margin fields for a product per the §9 rule.
	 *
	 * @param WC_Product|int $product Product.
	 * @return array { price, price_usd, usd_regular, usd_sale, cost_base, cost_local, cost_currency,
	 *                 cost_updated_at, last_purchase_id, last_purchase_code, margin_pct, markup_pct,
	 *                 margin_usd_pct, markup_usd_pct }
	 */
	public static function cost_fields( $product ) : array {
		$out = array(
			'price'              => null,
			'price_usd'          => null,
			'usd_regular'        => null,
			'usd_sale'           => null,
			'cost_base'          => null,
			'cost_local'         => null,
			'cost_currency'      => null,
			'cost_updated_at'    => null,
			'last_purchase_id'   => null,
			'last_purchase_code' => null,
			'margin_pct'         => null,
			'markup_pct'         => null,
			'margin_usd_pct'     => null,
			'markup_usd_pct'     => null,
		);

		if ( ! $product instanceof WC_Product ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return $out;
			}
			$product = $product ? wc_get_product( absint( $product ) ) : false;
		}
		if ( ! $product instanceof WC_Product ) {
			return $out;
		}

		$out['price'] = self::price_or_null( $product->get_price() );

		// Price published in dollars through MJ Dólar (null when that plugin is not in play).
		if ( class_exists( 'MJERP_Dolar' ) ) {
			$usd                = MJERP_Dolar::published_usd( $product );
			$out['usd_regular'] = $usd['usd_regular'];
			$out['usd_sale']    = $usd['usd_sale'];
			$out['price_usd']   = $usd['usd_price'];
		}

		$raw = $product->get_meta( self::cost_meta_key(), true, 'edit' );
		if ( is_array( $raw ) || is_object( $raw ) ) {
			$raw = '';
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return $out;
		}

		$cost_base   = round( (float) $raw, 4 );
		$wc_currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$base_cur    = strtoupper( (string) self::setting( 'base_currency', 'USD' ) );
		$local_cur   = strtoupper( (string) self::setting( 'local_currency', 'ARS' ) );
		$sale_rate   = self::to_float( self::setting( 'sale_rate', 1 ) );

		if ( $wc_currency === $base_cur ) {
			$cost_local = $cost_base;
		} elseif ( $wc_currency === $local_cur ) {
			$cost_local = $cost_base * ( $sale_rate > 0 ? $sale_rate : 1 );
		} else {
			$stored = $product->get_meta( self::META_COST_LOCAL, true, 'edit' );
			$stored = is_scalar( $stored ) ? trim( (string) $stored ) : '';
			$cost_local = ( '' !== $stored && is_numeric( $stored ) ) ? (float) $stored : $cost_base * ( $sale_rate > 0 ? $sale_rate : 1 );
		}
		$cost_local = round( $cost_local, 4 );

		$currency_meta = $product->get_meta( self::META_COST_CURRENCY, true, 'edit' );
		$updated_meta  = $product->get_meta( self::META_COST_UPDATED_AT, true, 'edit' );
		$last_id_meta  = $product->get_meta( self::META_LAST_PURCHASE_ID, true, 'edit' );
		$last_code     = $product->get_meta( self::META_LAST_PURCHASE_CODE, true, 'edit' );

		$out['cost_base']          = $cost_base;
		$out['cost_local']         = $cost_local;
		$out['cost_currency']      = ( is_string( $currency_meta ) && '' !== $currency_meta ) ? $currency_meta : $base_cur;
		$out['cost_updated_at']    = ( is_string( $updated_meta ) && '' !== $updated_meta ) ? $updated_meta : null;
		$out['last_purchase_id']   = ( is_scalar( $last_id_meta ) && '' !== (string) $last_id_meta ) ? (int) $last_id_meta : null;
		$out['last_purchase_code'] = ( is_string( $last_code ) && '' !== $last_code ) ? $last_code : null;

		$price = $out['price'];
		if ( null !== $price && $price > 0 ) {
			$out['margin_pct'] = round( ( $price - $cost_local ) / $price * 100, 2 );
		}
		if ( null !== $price && $cost_local > 0 ) {
			$out['markup_pct'] = round( ( $price - $cost_local ) / $cost_local * 100, 2 );
		}

		// Margin in dollars: cost and price are both in base currency, so it does not move when the
		// exchange rate does. For an importer this is the number that actually stays true.
		$price_usd = $out['price_usd'];
		if ( null !== $price_usd && $price_usd > 0 ) {
			$out['margin_usd_pct'] = round( ( $price_usd - $cost_base ) / $price_usd * 100, 2 );
		}
		if ( null !== $price_usd && $cost_base > 0 ) {
			$out['markup_usd_pct'] = round( ( $price_usd - $cost_base ) / $cost_base * 100, 2 );
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * The product object that actually holds the stock (a variation may be managed by its parent).
	 *
	 * @param WC_Product $product Product.
	 * @return WC_Product
	 */
	public static function stock_holder( WC_Product $product ) : WC_Product {
		try {
			$holder_id = (int) $product->get_stock_managed_by_id();
			if ( $holder_id && $holder_id !== (int) $product->get_id() ) {
				$holder = wc_get_product( $holder_id );
				if ( $holder instanceof WC_Product ) {
					return $holder;
				}
			}
		} catch ( Exception $e ) {
			// Fall through to the product itself.
			unset( $e );
		}

		return $product;
	}

	/**
	 * Settings accessor with a safe fallback when the settings class is not loaded.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function setting( string $key, $default = null ) {
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'get' ) ) {
			$value = MJERP_Settings::get( $key, $default );
			return ( null === $value ) ? $default : $value;
		}
		return $default;
	}

	/**
	 * Decode HTML entities from titles so the JS layer escapes exactly once.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function clean_name( $name ) : string {
		return trim( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * '' / null → null, otherwise float.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function price_or_null( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Integer when the number has no decimals, float otherwise.
	 *
	 * @param mixed $value Value.
	 * @return int|float
	 */
	private static function int_or_float( $value ) {
		$float = (float) $value;
		return ( floor( $float ) === $float ) ? (int) $float : $float;
	}

	/**
	 * Lenient float parser (accepts "1.234,56", "1,234.56", "1234,56", "1234.56").
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private static function to_float( $value ) : float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( is_bool( $value ) || null === $value ) {
			return $value ? 1.0 : 0.0;
		}
		if ( class_exists( 'MJERP_Helpers' ) && method_exists( 'MJERP_Helpers', 'to_float' ) ) {
			return (float) MJERP_Helpers::to_float( $value );
		}

		$s = trim( (string) $value );
		if ( '' === $s ) {
			return 0.0;
		}
		$s = preg_replace( '/[^\d,.\-]/', '', $s );
		if ( null === $s || '' === $s ) {
			return 0.0;
		}

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
			$s = ( substr_count( $s, ',' ) > 1 ) ? str_replace( ',', '', $s ) : str_replace( ',', '.', $s );
		} elseif ( false !== $last_dot && substr_count( $s, '.' ) > 1 ) {
			$s = str_replace( '.', '', $s );
		}

		return is_numeric( $s ) ? (float) $s : 0.0;
	}

	/**
	 * Lenient boolean parser ("1", "true", "yes", "on", "si", "sí").
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) : bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return 0.0 !== (float) $value;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return in_array( $value, array( '1', 'true', 'yes', 'on', 'si', 'sí' ), true );
		}
		return ! empty( $value );
	}
}
