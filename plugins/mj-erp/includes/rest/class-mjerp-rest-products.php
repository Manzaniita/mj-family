<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1/products. La libreta de clientes vive en MJERP_REST_Customers.
 *
 * Delegates to MJERP_Products / MJERP_Customers when they expose the expected
 * static methods, with self-contained WooCommerce fallbacks otherwise.
 */
class MJERP_REST_Products extends MJERP_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'products';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/products/search',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'search_products',
					array(
						'q'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/products/categories',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_categories' ),
			)
		);

		register_rest_route(
			$ns,
			'/products',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_products',
					array(
						'search'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'page'           => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'       => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'orderby'        => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'order'          => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
						'only_with_cost' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_product',
					array(
						'name'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sku'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'regular_price'  => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'status'         => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'category_ids'   => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
						'manage_stock'   => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
						'stock_quantity' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'cost'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'usd_regular'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)/movements',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del producto o variación.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::READABLE,
					'get_movements',
					array(
						'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * GET /products/search?q=&limit=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_products( $request ) {
		$wc = $this->wc_check();
		if ( is_wp_error( $wc ) ) {
			return $wc;
		}

		$p     = $this->params( $request );
		$term  = trim( $this->str( $p, 'q', '', 190 ) );
		$limit = $this->int( $p, 'limit', 20 );
		$limit = max( 1, min( 50, $limit ? $limit : 20 ) );

		if ( '' === $term ) {
			return $this->ok( array() );
		}

		$delegated = $this->delegate( 'MJERP_Products', array( 'search' ), array( $term, $limit ) );
		if ( null !== $delegated ) {
			$results = $delegated['result'];
		} else {
			$results = $this->local_search( $term, $limit );
		}

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$results = is_array( $results ) ? array_values( array_filter( $results, 'is_array' ) ) : array();
		$results = $this->exact_sku_first( $results, $term );

		return $this->ok( array_slice( $results, 0, $limit ) );
	}

	/**
	 * GET /products?search=&page=&per_page=&orderby=&order=&only_with_cost=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_products( $request ) {
		$wc = $this->wc_check();
		if ( is_wp_error( $wc ) ) {
			return $wc;
		}

		$p        = $this->params( $request );
		$page     = max( 1, $this->int( $p, 'page', 1 ) );
		$per_page = $this->int( $p, 'per_page', 20 );
		$per_page = max( 1, min( 100, $per_page ? $per_page : 20 ) );

		$orderby = sanitize_key( $this->str( $p, 'orderby', 'name' ) );
		if ( ! in_array( $orderby, array( 'name', 'title', 'date', 'id', 'price', 'modified', 'sku', 'stock' ), true ) ) {
			$orderby = 'name';
		}
		$order = strtoupper( $this->str( $p, 'order', '' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = in_array( $orderby, array( 'date', 'modified', 'id' ), true ) ? 'DESC' : 'ASC';
		}

		$args = array(
			'search'         => trim( $this->str( $p, 'search', '', 190 ) ),
			'page'           => $page,
			'per_page'       => $per_page,
			'orderby'        => $orderby,
			'order'          => $order,
			'only_with_cost' => $this->bool( $p, 'only_with_cost', false ),
		);

		$delegated = $this->delegate( 'MJERP_Products', array( 'query', 'list_products', 'get_list', 'paginate', 'list' ), array( $args ) );
		if ( null !== $delegated ) {
			$result = $delegated['result'];
		} else {
			$result = $this->local_list( $args );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		// Accept a plain list as well as the {items,total,pages,page} shape.
		if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
			$items = array_values( $result['items'] );
		} elseif ( ! isset( $result['total'] ) && ! empty( $result ) && is_array( reset( $result ) ) ) {
			$items  = array_values( $result );
			$result = array();
		} else {
			$items = array();
		}

		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );
		$pages = isset( $result['pages'] ) ? (int) $result['pages'] : (int) ceil( $total / $per_page );

		return $this->ok(
			array(
				'items'    => $items,
				'total'    => $total,
				'pages'    => max( 1, $pages ),
				'page'     => isset( $result['page'] ) ? (int) $result['page'] : $page,
				'per_page' => $per_page,
				'filters'  => $args,
			)
		);
	}

	/**
	 * POST /products
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_product( $request ) {
		$wc = $this->wc_check();
		if ( is_wp_error( $wc ) ) {
			return $wc;
		}

		$p = $this->params( $request );

		$name = $this->str( $p, 'name', '', 255 );
		if ( '' === $name ) {
			return $this->fail( 'mjerp_invalid_name', __( 'El nombre del producto es obligatorio.', 'mj-erp' ), 400, array( 'field' => 'name' ) );
		}

		$sku = $this->str( $p, 'sku', '', 100 );
		if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$existing = wc_get_product_id_by_sku( $sku );
			if ( $existing ) {
				return $this->fail(
					'mjerp_sku_exists',
					sprintf(
						/* translators: %s: SKU */
						__( 'Ya existe un producto con el SKU «%s».', 'mj-erp' ),
						$sku
					),
					409,
					array( 'field' => 'sku', 'product_id' => (int) $existing )
				);
			}
		}

		$regular_price = null;
		if ( $this->has( $p, 'regular_price' ) && null !== $p['regular_price'] && '' !== $p['regular_price'] ) {
			$regular_price = $this->float( $p, 'regular_price', 0 );
			if ( $regular_price < 0 ) {
				return $this->fail( 'mjerp_invalid_price', __( 'El precio no puede ser negativo.', 'mj-erp' ), 400, array( 'field' => 'regular_price' ) );
			}
		}

		$status = sanitize_key( $this->str( $p, 'status', '' ) );
		if ( ! in_array( $status, array( 'draft', 'publish', 'private', 'pending' ), true ) ) {
			$status = sanitize_key( (string) $this->setting( 'new_product_status', 'draft' ) );
			if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
				$status = 'draft';
			}
		}

		$category_ids = array();
		if ( $this->has( $p, 'category_ids' ) ) {
			$raw_ids = is_array( $p['category_ids'] ) ? $p['category_ids'] : array( $p['category_ids'] );
			foreach ( $raw_ids as $cat ) {
				$cat = is_scalar( $cat ) ? absint( $cat ) : 0;
				if ( $cat && term_exists( $cat, 'product_cat' ) ) {
					$category_ids[] = $cat;
				}
			}
			$category_ids = array_values( array_unique( $category_ids ) );
		} elseif ( $this->has( $p, 'category_id' ) ) {
			$cat = $this->int( $p, 'category_id', 0 );
			if ( $cat && term_exists( $cat, 'product_cat' ) ) {
				$category_ids[] = $cat;
			}
		}

		$manage_stock   = $this->bool( $p, 'manage_stock', true );
		$stock_quantity = (int) round( $this->float( $p, 'stock_quantity', 0 ) );
		if ( $stock_quantity < 0 ) {
			return $this->fail( 'mjerp_invalid_stock', __( 'El stock inicial no puede ser negativo.', 'mj-erp' ), 400, array( 'field' => 'stock_quantity' ) );
		}

		$cost = null;
		if ( $this->has( $p, 'cost' ) && null !== $p['cost'] && '' !== $p['cost'] ) {
			$cost = $this->float( $p, 'cost', 0 );
			if ( $cost < 0 ) {
				return $this->fail( 'mjerp_invalid_cost', __( 'El costo no puede ser negativo.', 'mj-erp' ), 400, array( 'field' => 'cost' ) );
			}
		}

		$usd_regular = null;
		if ( $this->has( $p, 'usd_regular' ) && null !== $p['usd_regular'] && '' !== $p['usd_regular'] ) {
			$usd_regular = $this->float( $p, 'usd_regular', 0 );
			if ( $usd_regular < 0 ) {
				return $this->fail( 'mjerp_invalid_price', __( 'El precio en dólares no puede ser negativo.', 'mj-erp' ), 400, array( 'field' => 'usd_regular' ) );
			}
		}

		$data = array(
			'name'           => $name,
			'sku'            => $sku,
			'regular_price'  => $regular_price,
			'status'         => $status,
			'category_ids'   => $category_ids,
			'manage_stock'   => $manage_stock,
			'stock_quantity' => $stock_quantity,
			'cost'           => $cost,
			'usd_regular'    => $usd_regular,
		);

		$delegated = $this->delegate( 'MJERP_Products', array( 'create' ), array( $data ) );
		if ( null !== $delegated ) {
			$result = $delegated['result'];
		} else {
			$result = $this->local_create( $data );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Delegates may return a product id, a WC_Product or a summary array.
		if ( is_numeric( $result ) ) {
			$product = wc_get_product( absint( $result ) );
			$result  = $product ? $this->summary( $product ) : null;
		} elseif ( is_object( $result ) && is_a( $result, 'WC_Product' ) ) {
			$result = $this->summary( $result );
		}

		if ( ! is_array( $result ) ) {
			return $this->fail( 'mjerp_create_failed', __( 'No se pudo crear el producto.', 'mj-erp' ), 500 );
		}

		do_action( 'mjerp_product_created', isset( $result['id'] ) ? (int) $result['id'] : 0, $result, $data );

		return $this->ok( $result, 201 );
	}

	/**
	 * GET /products/categories
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_categories( $request ) {
		$delegated = $this->delegate( 'MJERP_Products', array( 'categories', 'get_categories' ), array() );
		if ( null !== $delegated ) {
			$result = $delegated['result'];
		} else {
			$result = $this->local_categories();
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( is_array( $result ) ? array_values( $result ) : array() );
	}

	/**
	 * GET /products/{id}/movements?limit=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_movements( $request ) {
		$id    = $this->route_id( $request );
		$p     = $this->params( $request );
		$limit = $this->int( $p, 'limit', 50 );
		$limit = max( 1, min( 500, $limit ? $limit : 50 ) );

		if ( class_exists( 'MJERP_Movements_Repo' ) && is_callable( array( 'MJERP_Movements_Repo', 'for_product' ) ) ) {
			$rows = MJERP_Movements_Repo::for_product( $id, $limit );
		} else {
			$rows = $this->local_movements( $id, $limit );
		}
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$out   = array();
		$names = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$row = (array) $row;
			}
			foreach ( array( 'id', 'product_id', 'variation_id', 'ref_id', 'user_id' ) as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ];
				}
			}
			foreach ( array( 'qty', 'stock_before', 'stock_after', 'unit_cost_base' ) as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$row[ $key ] = null === $row[ $key ] ? null : (float) $row[ $key ];
				}
			}
			if ( empty( $row['user_name'] ) ) {
				$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				if ( $uid && ! isset( $names[ $uid ] ) ) {
					$names[ $uid ] = $this->user_name( $uid );
				}
				$row['user_name'] = $uid && isset( $names[ $uid ] ) ? $names[ $uid ] : '';
			}
			if ( ! isset( $row['type_label'] ) ) {
				$row['type_label'] = $this->movement_type_label( isset( $row['type'] ) ? (string) $row['type'] : '' );
			}
			$out[] = $row;
		}

		return $this->ok( $out );
	}

	/* ---------------------------------------------------------------------
	 * Delegation helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Call the first callable static method of a class.
	 *
	 * @param string $class   Class.
	 * @param array  $methods Candidate method names.
	 * @param array  $args    Arguments.
	 * @return array|null  ['result' => mixed] or null when nothing was callable.
	 */
	protected function delegate( $class, $methods, $args ) {
		if ( ! class_exists( $class ) ) {
			return null;
		}
		foreach ( (array) $methods as $method ) {
			if ( is_callable( array( $class, $method ) ) ) {
				return array( 'result' => call_user_func_array( array( $class, $method ), $args ) );
			}
		}
		return null;
	}

	/**
	 * WooCommerce availability.
	 *
	 * @return true|WP_Error
	 */
	protected function wc_check() {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_products' ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'WooCommerce no está disponible.', 'mj-erp' ), 500 );
		}
		return true;
	}

	/**
	 * Product summary (MJERP_Products::summary when available, local fallback otherwise).
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	protected function summary( $product ) {
		if ( class_exists( 'MJERP_Products' ) && is_callable( array( 'MJERP_Products', 'summary' ) ) ) {
			$summary = MJERP_Products::summary( $product );
			if ( is_array( $summary ) ) {
				return $summary;
			}
		}
		return $this->local_summary( $product );
	}

	/**
	 * Move the exact SKU match to the top of a result list.
	 *
	 * @param array  $results Summaries.
	 * @param string $term    Search term.
	 * @return array
	 */
	protected function exact_sku_first( $results, $term ) {
		$needle = strtolower( trim( $term ) );
		if ( '' === $needle ) {
			return $results;
		}
		foreach ( $results as $index => $row ) {
			if ( isset( $row['sku'] ) && strtolower( trim( (string) $row['sku'] ) ) === $needle ) {
				if ( 0 !== $index ) {
					unset( $results[ $index ] );
					array_unshift( $results, $row );
				}
				return array_values( $results );
			}
		}
		return array_values( $results );
	}

	/**
	 * Movement type label.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	protected function movement_type_label( $type ) {
		switch ( $type ) {
			case 'purchase_receive':
				return __( 'Recepción de compra', 'mj-erp' );
			case 'purchase_revert':
				return __( 'Recepción deshecha', 'mj-erp' );
			case 'adjustment':
				return __( 'Ajuste', 'mj-erp' );
			default:
				return $type;
		}
	}

	/* ---------------------------------------------------------------------
	 * Local fallbacks (used when MJERP_Products / MJERP_Customers do not expose
	 * the expected methods).
	 * ------------------------------------------------------------------ */

	/**
	 * Search products by name / SKU (includes variations, all statuses).
	 *
	 * @param string $term  Term.
	 * @param int    $limit Limit.
	 * @return array
	 */
	protected function local_search( $term, $limit ) {
		if ( ! class_exists( 'WC_Data_Store' ) ) {
			return array();
		}

		$ids = array();
		try {
			$store = WC_Data_Store::load( 'product' );
			$found = $store->search_products( $term, '', true, true, $limit * 3 );
			$ids   = array_values( array_filter( array_map( 'absint', (array) $found ) ) );
		} catch ( Exception $e ) {
			$ids = array();
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku_id = absint( wc_get_product_id_by_sku( $term ) );
			if ( $sku_id ) {
				array_unshift( $ids, $sku_id );
			}
		}

		$seen = array();
		$out  = array();
		foreach ( $ids as $id ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( (array) $product->get_children() as $child_id ) {
					if ( count( $out ) >= $limit ) {
						break;
					}
					$child_id = absint( $child_id );
					if ( isset( $seen[ $child_id ] ) ) {
						continue;
					}
					$seen[ $child_id ] = true;
					$child             = wc_get_product( $child_id );
					if ( $child ) {
						$out[] = $this->summary( $child );
					}
				}
				continue;
			}
			$out[] = $this->summary( $product );
		}

		return $out;
	}

	/**
	 * Paginated product list (variable products expand their variations).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected function local_list( $args ) {
		$orderby_map = array(
			'name'     => 'title',
			'title'    => 'title',
			'sku'      => 'title',
			'stock'    => 'title',
			'date'     => 'date',
			'modified' => 'modified',
			'id'       => 'ID',
			'price'    => 'price',
		);

		$query = array(
			'status'   => array( 'publish', 'draft', 'private', 'pending' ),
			'type'     => array( 'simple', 'variable' ),
			'paginate' => true,
			'limit'    => $args['per_page'],
			'page'     => $args['page'],
			'orderby'  => isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'title',
			'order'    => $args['order'],
			'return'   => 'objects',
		);
		if ( '' !== $args['search'] ) {
			$query['s'] = $args['search'];
		}

		$result = wc_get_products( $query );
		if ( ! is_object( $result ) ) {
			return array( 'items' => array(), 'total' => 0, 'pages' => 1, 'page' => $args['page'] );
		}

		$products = isset( $result->products ) ? (array) $result->products : array();
		$total    = isset( $result->total ) ? (int) $result->total : count( $products );
		$pages    = isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

		$items = array();
		$seen  = array();

		// Exact SKU match goes first on the first page (WP search does not look at SKUs).
		if ( 1 === (int) $args['page'] && '' !== $args['search'] && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku_id = absint( wc_get_product_id_by_sku( $args['search'] ) );
			if ( $sku_id ) {
				$sku_product = wc_get_product( $sku_id );
				if ( $sku_product && ! $sku_product->is_type( 'variable' ) ) {
					$items[]           = $this->summary( $sku_product );
					$seen[ $sku_id ]   = true;
				}
			}
		}

		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}
			$pid = $product->get_id();
			if ( ! isset( $seen[ $pid ] ) ) {
				$seen[ $pid ] = true;
				$items[]      = $this->summary( $product );
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( (array) $product->get_children() as $child_id ) {
					$child_id = absint( $child_id );
					if ( isset( $seen[ $child_id ] ) ) {
						continue;
					}
					$child = wc_get_product( $child_id );
					if ( $child ) {
						$seen[ $child_id ] = true;
						$items[]           = $this->summary( $child );
					}
				}
			}
		}

		if ( ! empty( $args['only_with_cost'] ) ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $row ) {
						return isset( $row['cost_base'] ) && null !== $row['cost_base'];
					}
				)
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, $pages ),
			'page'  => (int) $args['page'],
		);
	}

	/**
	 * Create a simple product.
	 *
	 * @param array $data Data (already validated).
	 * @return array|WP_Error Product summary.
	 */
	protected function local_create( $data ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'WooCommerce no está disponible.', 'mj-erp' ), 500 );
		}

		$product = new WC_Product_Simple();
		$product->set_name( $data['name'] );

		if ( '' !== $data['sku'] ) {
			try {
				$product->set_sku( $data['sku'] );
			} catch ( Exception $e ) {
				return $this->fail(
					'mjerp_sku_exists',
					sprintf(
						/* translators: %s: SKU */
						__( 'El SKU «%s» no es válido o ya está en uso.', 'mj-erp' ),
						$data['sku']
					),
					409,
					array( 'field' => 'sku' )
				);
			}
		}

		if ( null !== $data['regular_price'] ) {
			$product->set_regular_price( function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $data['regular_price'] ) : (string) $data['regular_price'] );
		}

		$product->set_status( $data['status'] );

		if ( ! empty( $data['category_ids'] ) ) {
			$product->set_category_ids( $data['category_ids'] );
		}

		$product->set_manage_stock( (bool) $data['manage_stock'] );
		if ( $data['manage_stock'] ) {
			$product->set_stock_quantity( (int) $data['stock_quantity'] );
			$product->set_stock_status( (int) $data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
		} else {
			$product->set_stock_status( 'instock' );
		}

		try {
			$id = $product->save();
		} catch ( Exception $e ) {
			return $this->fail(
				'mjerp_create_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'No se pudo crear el producto: %s', 'mj-erp' ),
					$e->getMessage()
				),
				500
			);
		}

		$id = absint( $id );
		if ( ! $id ) {
			return $this->fail( 'mjerp_create_failed', __( 'No se pudo crear el producto.', 'mj-erp' ), 500 );
		}

		if ( null !== $data['cost'] ) {
			$this->write_cost_meta( $id, (float) $data['cost'] );
		}

		// Publishing in dollars overwrites the WooCommerce price with USD × TC, so it runs last.
		if ( ! empty( $data['usd_regular'] ) && class_exists( 'MJERP_Dolar' ) && MJERP_Dolar::is_ready() ) {
			MJERP_Dolar::publish_usd( $id, (float) $data['usd_regular'], 'regular_price' );
		}

		$saved = wc_get_product( $id );
		return $saved ? $this->summary( $saved ) : $this->fail( 'mjerp_create_failed', __( 'No se pudo cargar el producto creado.', 'mj-erp' ), 500 );
	}

	/**
	 * Write the cost meta keys defined by the spec.
	 *
	 * @param int   $product_id Product (or variation) id.
	 * @param float $cost_base  Cost in base currency.
	 */
	protected function write_cost_meta( $product_id, $cost_base ) {
		$meta_key = (string) $this->setting( 'cost_meta_key', '_mjerp_cost' );
		if ( '' === $meta_key ) {
			$meta_key = '_mjerp_cost';
		}
		$base_currency = $this->clean_currency( $this->setting( 'base_currency', 'USD' ) );
		$cost_local    = $this->cost_to_local( $cost_base );

		update_post_meta( $product_id, $meta_key, round( $cost_base, 4 ) );
		update_post_meta( $product_id, '_mjerp_cost_local', round( $cost_local, 4 ) );
		update_post_meta( $product_id, '_mjerp_cost_currency', $base_currency );
		update_post_meta( $product_id, '_mjerp_cost_updated_at', current_time( 'mysql' ) );
	}

	/**
	 * Convert a base-currency cost to the store (local) currency.
	 *
	 * @param float $cost_base Cost.
	 * @return float
	 */
	protected function cost_to_local( $cost_base ) {
		$wc_currency    = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$base_currency  = $this->clean_currency( $this->setting( 'base_currency', 'USD' ) );
		$sale_rate      = $this->to_float( $this->setting( 'sale_rate', 1 ), 1 );
		if ( $wc_currency === $base_currency ) {
			return (float) $cost_base;
		}
		return (float) $cost_base * ( $sale_rate > 0 ? $sale_rate : 1 );
	}

	/**
	 * Product categories.
	 *
	 * @return array
	 */
	protected function local_categories() {
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
			$out[] = array(
				'id'     => (int) $term->term_id,
				'name'   => (string) $term->name,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}
		return $out;
	}

	/**
	 * Stock movements of a product straight from the table.
	 *
	 * @param int $product_id Product or variation id.
	 * @param int $limit      Limit.
	 * @return array
	 */
	protected function local_movements( $product_id, $limit ) {
		global $wpdb;

		if ( class_exists( 'MJERP_Install' ) && is_callable( array( 'MJERP_Install', 'table' ) ) ) {
			$table = MJERP_Install::table( 'stock_movements' );
		} else {
			$table = $wpdb->prefix . 'mjerp_stock_movements';
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d OR variation_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $product_id ),
				absint( $product_id ),
				absint( $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Local product summary following the spec shape.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	protected function local_summary( $product ) {
		$is_variation = $product->is_type( 'variation' );
		$id           = $product->get_id();
		$parent_id    = $is_variation ? $product->get_parent_id() : $id;
		$parent       = $is_variation ? wc_get_product( $parent_id ) : null;
		$parent_name  = $parent ? $parent->get_name() : '';

		$name = $product->get_name();
		if ( $is_variation ) {
			$attrs = function_exists( 'wc_get_formatted_variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) ) : '';
			$name  = trim( ( '' !== $parent_name ? $parent_name : $name ) . ( '' !== $attrs ? ' - ' . $attrs : '' ) );
		}

		$price         = $product->get_price();
		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();

		$meta_key      = (string) $this->setting( 'cost_meta_key', '_mjerp_cost' );
		$meta_key      = '' === $meta_key ? '_mjerp_cost' : $meta_key;
		$cost_raw      = get_post_meta( $id, $meta_key, true );
		$cost_base     = ( '' === $cost_raw || null === $cost_raw || false === $cost_raw ) ? null : (float) $cost_raw;
		$cost_local    = null;
		if ( null !== $cost_base ) {
			$local_raw  = get_post_meta( $id, '_mjerp_cost_local', true );
			$cost_local = ( '' === $local_raw || null === $local_raw || false === $local_raw ) ? $this->cost_to_local( $cost_base ) : (float) $local_raw;
		}
		$price_f = ( '' === $price || null === $price ) ? null : (float) $price;

		$margin_pct = null;
		$markup_pct = null;
		if ( null !== $price_f && null !== $cost_local ) {
			if ( $price_f > 0 ) {
				$margin_pct = round( ( $price_f - $cost_local ) / $price_f * 100, 2 );
			}
			if ( $cost_local > 0 ) {
				$markup_pct = round( ( $price_f - $cost_local ) / $cost_local * 100, 2 );
			}
		}

		$image_id = $product->get_image_id();
		if ( ! $image_id && $parent ) {
			$image_id = $parent->get_image_id();
		}
		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : null;

		$cost_currency = get_post_meta( $id, '_mjerp_cost_currency', true );

		// MJ Dólar: price published in dollars and the margin measured against it (which, unlike the
		// peso margin, does not move when the exchange rate does).
		$usd            = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::published_usd( $product ) : array( 'usd_regular' => null, 'usd_sale' => null, 'usd_price' => null );
		$margin_usd_pct = null;
		$markup_usd_pct = null;
		if ( null !== $usd['usd_price'] && null !== $cost_base ) {
			if ( $usd['usd_price'] > 0 ) {
				$margin_usd_pct = round( ( $usd['usd_price'] - $cost_base ) / $usd['usd_price'] * 100, 2 );
			}
			if ( $cost_base > 0 ) {
				$markup_usd_pct = round( ( $usd['usd_price'] - $cost_base ) / $cost_base * 100, 2 );
			}
		}

		return array(
			'id'                 => (int) $id,
			'product_id'         => (int) $parent_id,
			'variation_id'       => $is_variation ? (int) $id : 0,
			'type'               => $product->get_type(),
			'name'               => $name,
			'sku'                => (string) $product->get_sku(),
			'status'             => $product->get_status(),
			'price'              => $price_f,
			'regular_price'      => ( '' === $regular_price || null === $regular_price ) ? null : (float) $regular_price,
			'sale_price'         => ( '' === $sale_price || null === $sale_price ) ? null : (float) $sale_price,
			'manage_stock'       => (bool) $product->managing_stock(),
			'stock_quantity'     => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'stock_status'       => $product->get_stock_status(),
			'cost_base'          => $cost_base,
			'cost_local'         => $cost_local,
			'cost_currency'      => '' !== $cost_currency ? (string) $cost_currency : $this->clean_currency( $this->setting( 'base_currency', 'USD' ) ),
			'cost_updated_at'    => (string) get_post_meta( $id, '_mjerp_cost_updated_at', true ),
			'last_purchase_code' => (string) get_post_meta( $id, '_mjerp_last_purchase_code', true ),
			'image'              => $image ? $image : null,
			'permalink'          => get_permalink( $id ),
			'edit_link'          => admin_url( 'post.php?post=' . (int) $parent_id . '&action=edit' ),
			'margin_pct'         => $margin_pct,
			'markup_pct'         => $markup_pct,
			'price_usd'          => $usd['usd_price'],
			'usd_regular'        => $usd['usd_regular'],
			'usd_sale'           => $usd['usd_sale'],
			'margin_usd_pct'     => $margin_usd_pct,
			'markup_usd_pct'     => $markup_usd_pct,
			'on_consignment'     => class_exists( 'MJERP_Consignments_Repo' )
				? MJERP_Consignments_Repo::on_hand_for_product( (int) $id )
				: 0.0,
			'is_variation'       => $is_variation,
			'parent_name'        => $parent_name,
		);
	}
}
