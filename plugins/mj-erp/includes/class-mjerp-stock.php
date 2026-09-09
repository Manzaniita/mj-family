<?php
defined( 'ABSPATH' ) || exit;

/**
 * Purchase reception: WooCommerce stock updates, movement log, cost & price updates.
 *
 * Both public methods return either a report array or a WP_Error. They never throw:
 * every WooCommerce / repository call is wrapped and failures are written into the report.
 */
class MJERP_Stock {

	const LOCK_TTL      = 30;
	const LOCK_PREFIX   = 'mjerp_receive_lock_';
	const TYPE_RECEIVE  = 'purchase_receive';
	const TYPE_REVERT   = 'purchase_revert';
	const REF_TYPE      = 'purchase';
	const STATUS_RECV   = 'recibida';
	const STATUS_TRANS  = 'en_transito';

	/* ------------------------------------------------------------------
	 * Receive
	 * ---------------------------------------------------------------- */

	/**
	 * Mark a purchase as received: set received quantities, add WooCommerce stock, log movements,
	 * update product cost meta and (optionally) WooCommerce prices.
	 *
	 * @param array $purchase Full purchase (MJERP_Purchases_Repo::get_full()).
	 * @param array $options  items (map item_id → received_qty or list of {item_id, received_qty}), add_stock, update_cost,
	 *                        update_price, price_list, price_target, note.
	 * @return array|WP_Error Report: { items: [...], added_stock_items, cost_updated_items, price_updated_items, unlinked_items,
	 *                        skipped_items, errors, warnings, purchase_id, status }.
	 */
	public static function receive( array $purchase, array $options = array() ) {
		$purchase_id = isset( $purchase['id'] ) ? absint( $purchase['id'] ) : 0;
		if ( ! $purchase_id ) {
			return new WP_Error( 'mjerp_invalid', __( 'Compra inválida.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return new WP_Error( 'mjerp_wc_missing', __( 'WooCommerce no está disponible.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		if ( ! self::can_receive_status( $status ) ) {
			return new WP_Error( 'mjerp_invalid_status', __( 'Solo se pueden recibir compras en estado borrador o en tránsito.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$items = ( isset( $purchase['items'] ) && is_array( $purchase['items'] ) ) ? array_values( $purchase['items'] ) : array();
		if ( empty( $items ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'La compra no tiene productos para recibir.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		if ( ! self::lock( $purchase_id ) ) {
			return new WP_Error( 'mjerp_receive_locked', __( 'La recepción de esta compra ya está en curso. Esperá unos segundos e intentá de nuevo.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		try {
			// Fresh status from the database: protects against double submits that passed the lock.
			$fresh = self::fresh_row( $purchase_id );
			if ( is_array( $fresh ) && isset( $fresh['status'] ) && ! self::can_receive_status( (string) $fresh['status'] ) ) {
				return new WP_Error( 'mjerp_invalid_status', __( 'La compra ya fue recibida o su estado cambió. Actualizá la página.', 'mj-erp' ), array( 'status' => 409 ) );
			}

			return self::do_receive( $purchase, $items, $options );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mjerp_receive_failed', $e->getMessage() ? $e->getMessage() : __( 'Error inesperado al recibir la compra.', 'mj-erp' ), array( 'status' => 500 ) );
		} finally {
			self::unlock( $purchase_id );
		}
	}

	/**
	 * Receive implementation (runs under the lock).
	 *
	 * @param array $purchase Full purchase.
	 * @param array $items    Purchase items.
	 * @param array $options  Options.
	 * @return array|WP_Error
	 */
	private static function do_receive( array $purchase, array $items, array $options ) {
		$purchase_id = (int) $purchase['id'];
		$code        = isset( $purchase['code'] ) ? (string) $purchase['code'] : (string) $purchase_id;
		$settings    = self::settings();
		$user_id     = get_current_user_id();

		$add_stock    = array_key_exists( 'add_stock', $options ) && null !== $options['add_stock']
			? self::to_bool( $options['add_stock'] )
			: ( 'increase' === ( isset( $settings['stock_on_receive'] ) ? (string) $settings['stock_on_receive'] : 'increase' ) );
		$update_cost  = array_key_exists( 'update_cost', $options ) && null !== $options['update_cost']
			? self::to_bool( $options['update_cost'] )
			: ( isset( $settings['update_cost_on_receive'] ) ? self::to_bool( $settings['update_cost_on_receive'] ) : true );
		$update_price = array_key_exists( 'update_price', $options ) && null !== $options['update_price']
			? self::to_bool( $options['update_price'] )
			: ( isset( $settings['update_price_on_receive'] ) ? self::to_bool( $settings['update_price_on_receive'] ) : false );

		$enable_stock_mgmt = isset( $settings['enable_stock_management'] ) ? self::to_bool( $settings['enable_stock_management'] ) : true;
		$wc_stock_enabled  = 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );

		$price_target = isset( $options['price_target'] ) ? sanitize_key( (string) $options['price_target'] ) : '';
		if ( ! in_array( $price_target, array( 'regular_price', 'sale_price' ), true ) ) {
			$price_target = isset( $settings['price_target'] ) ? sanitize_key( (string) $settings['price_target'] ) : 'regular_price';
			if ( ! in_array( $price_target, array( 'regular_price', 'sale_price' ), true ) ) {
				$price_target = 'regular_price';
			}
		}

		$price_list_key = ! empty( $options['price_list'] ) ? sanitize_key( (string) $options['price_list'] ) : '';
		if ( '' === $price_list_key ) {
			$price_list_key = isset( $settings['price_list_for_price'] ) ? sanitize_key( (string) $settings['price_list_for_price'] ) : '';
		}

		$note = isset( $options['note'] ) ? trim( sanitize_text_field( (string) $options['note'] ) ) : '';

		$base_currency = ! empty( $purchase['base_currency'] ) ? strtoupper( (string) $purchase['base_currency'] ) : strtoupper( (string) ( isset( $settings['base_currency'] ) ? $settings['base_currency'] : 'USD' ) );
		$wc_currency   = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : $base_currency;

		$sale_rate = isset( $purchase['calc']['sale_rate'] ) ? self::to_float( $purchase['calc']['sale_rate'] ) : 0.0;
		if ( $sale_rate <= 0 ) {
			$sale_rate = isset( $settings['sale_rate'] ) ? self::to_float( $settings['sale_rate'] ) : 1.0;
		}
		if ( $sale_rate <= 0 ) {
			$sale_rate = 1.0;
		}
		$rounding = isset( $settings['price_rounding'] ) ? (int) $settings['price_rounding'] : 0;

		// USD publishing: the suggested price goes into MJ Dólar (which keeps the pesos in sync with
		// the TC) instead of being written once into the WooCommerce price field.
		$publish_mode = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::publish_mode() : 'local';
		$usd_cfg      = class_exists( 'MJERP_Costing' ) ? MJERP_Costing::usd_config_from_settings( $settings ) : null;

		$report = array(
			'items'               => array(),
			'added_stock_items'   => 0,
			'cost_updated_items'  => 0,
			'price_updated_items' => 0,
			'usd_published_items' => 0,
			'unlinked_items'      => 0,
			'skipped_items'       => 0,
			'errors'              => 0,
			'warnings'            => array(),
			'purchase_id'         => $purchase_id,
			'status'              => isset( $purchase['status'] ) ? (string) $purchase['status'] : '',
			'price_mode'          => $publish_mode,
			'base_currency'       => $base_currency,
			'store_currency'      => $wc_currency,
		);

		// Price list resolution.
		$price_list = null;
		if ( $update_price ) {
			$price_list = self::resolve_price_list( $price_list_key, $settings );
			if ( ! $price_list ) {
				$update_price         = false;
				$report['warnings'][] = __( 'No hay listas de precio configuradas: no se actualizaron precios.', 'mj-erp' );
			}
		}
		if ( $add_stock && ! $wc_stock_enabled ) {
			$report['warnings'][] = __( 'La gestión de stock está desactivada en WooCommerce (Ajustes → Productos → Inventario): no se sumó stock.', 'mj-erp' );
		}
		if ( $update_price && 'local' === $publish_mode && class_exists( 'MJERP_Dolar' ) ) {
			$blocker = MJERP_Dolar::publish_blocker();
			if ( '' !== $blocker ) {
				$report['warnings'][] = $blocker;
			}
		}
		$markup = $price_list ? self::to_float( $price_list['markup'] ) : 0.0;

		$qty_map = self::normalize_qty_map( isset( $options['items'] ) ? $options['items'] : null );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id      = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$ordered_qty  = isset( $item['quantity'] ) ? self::to_float( $item['quantity'] ) : 0.0;
			$received_qty = ( $item_id && array_key_exists( $item_id, $qty_map ) ) ? $qty_map[ $item_id ] : $ordered_qty;
			$received_qty = max( 0.0, round( $received_qty, 4 ) );

			$landed_base = isset( $item['calc']['landed_unit_base'] )
				? self::to_float( $item['calc']['landed_unit_base'] )
				: ( isset( $item['landed_unit_base'] ) ? self::to_float( $item['landed_unit_base'] ) : 0.0 );
			$landed_local = isset( $item['calc']['landed_unit_local'] )
				? self::to_float( $item['calc']['landed_unit_local'] )
				: round( $landed_base * $sale_rate, 4 );

			$entry = array(
				'item_id'       => $item_id,
				'name'          => isset( $item['name'] ) ? (string) $item['name'] : '',
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'quantity'      => $ordered_qty,
				'received_qty'  => $received_qty,
				'status'        => 'ok',
				'stock_before'  => null,
				'stock_after'   => null,
				'cost_updated'  => false,
				'price_updated' => false,
				'new_price'     => null,
				// Filled by step 5: 'usd' when the price was published through MJ Dólar (and
				// `new_price` is in dollars), 'local' when it went into the WooCommerce field.
				'price_mode'      => null,
				'price_target'    => null,
				'new_price_usd'   => null,
				'new_price_local' => null,
				'cost_usd'      => round( $landed_base, 4 ),
				'message'       => '',
			);

			// 1) Persist received quantity (before the status change locks the purchase).
			if ( $item_id ) {
				$saved = self::save_received_qty( $item_id, $received_qty );
				if ( is_wp_error( $saved ) ) {
					$entry['message'] = sprintf(
						/* translators: %s: error message */
						__( 'No se pudo guardar la cantidad recibida: %s', 'mj-erp' ),
						$saved->get_error_message()
					);
				}
			}

			// 2) Resolve the linked WooCommerce product.
			$pid_used = $variation_id ? $variation_id : $product_id;
			$product  = $pid_used ? wc_get_product( $pid_used ) : false;

			if ( ! $product instanceof WC_Product ) {
				$entry['status']  = 'unlinked';
				$entry['message'] = $pid_used
					? __( 'El producto vinculado ya no existe en WooCommerce.', 'mj-erp' )
					: __( 'Ítem sin producto vinculado: no se actualizó stock ni costo.', 'mj-erp' );
				$report['unlinked_items']++;
				$report['items'][] = $entry;
				continue;
			}

			if ( ! $entry['product_id'] ) {
				$entry['product_id'] = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
			}
			if ( '' === $entry['name'] ) {
				$entry['name'] = (string) $product->get_name();
			}

			if ( $received_qty <= 0 ) {
				$entry['status']  = 'skipped_stock';
				$entry['message'] = __( 'Sin cantidad recibida: no se modificó stock, costo ni precio.', 'mj-erp' );
				$report['skipped_items']++;
				$report['items'][] = $entry;
				continue;
			}

			try {
				// 3) Stock.
				if ( $add_stock ) {
					$stock_qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $received_qty ) : (int) $received_qty;

					if ( ! $wc_stock_enabled ) {
						$entry['status']  = 'skipped_stock';
						$entry['message'] = __( 'La gestión de stock está desactivada en WooCommerce.', 'mj-erp' );
					} elseif ( $stock_qty <= 0 ) {
						$entry['status']  = 'skipped_stock';
						$entry['message'] = __( 'La cantidad recibida es menor a una unidad de stock.', 'mj-erp' );
					} else {
						if ( ! $product->managing_stock() && $enable_stock_mgmt ) {
							$product->set_manage_stock( true );
							if ( null === $product->get_stock_quantity( 'edit' ) || '' === $product->get_stock_quantity( 'edit' ) ) {
								$product->set_stock_quantity( 0 );
							}
							$product->save();
							$reloaded = wc_get_product( $pid_used );
							if ( $reloaded instanceof WC_Product ) {
								$product = $reloaded;
							}
						}

						if ( $product->managing_stock() ) {
							$holder = MJERP_Products::stock_holder( $product );
							$before = $holder->get_stock_quantity();
							$before = ( null === $before || '' === $before ) ? 0 : $before;
							$after  = wc_update_product_stock( $product, $stock_qty, 'increase' );

							if ( false === $after || null === $after ) {
								throw new Exception( __( 'WooCommerce no pudo actualizar el stock.', 'mj-erp' ) );
							}

							$entry['stock_before'] = self::num( $before );
							$entry['stock_after']  = self::num( $after );

							self::log_movement(
								array(
									'product_id'     => (int) $entry['product_id'],
									'variation_id'   => $variation_id,
									'qty'            => (float) $stock_qty,
									'type'           => self::TYPE_RECEIVE,
									'ref_type'       => self::REF_TYPE,
									'ref_id'         => $purchase_id,
									'stock_before'   => (float) $before,
									'stock_after'    => (float) $after,
									'unit_cost_base' => round( $landed_base, 6 ),
									'note'           => '' !== $note ? $note : sprintf(
										/* translators: %s: purchase code */
										__( 'Recepción de compra %s', 'mj-erp' ),
										$code
									),
									'user_id'        => $user_id,
								)
							);

							$report['added_stock_items']++;
						} else {
							$entry['status']  = 'skipped_stock';
							$entry['message'] = __( 'El producto no gestiona stock en WooCommerce: no se sumó stock.', 'mj-erp' );
						}
					}
				}

				// 4) Cost meta.
				if ( $update_cost ) {
					$ok                    = MJERP_Products::update_cost( $product, $landed_base, $landed_local, $purchase );
					$entry['cost_updated'] = $ok;
					if ( $ok ) {
						$report['cost_updated_items']++;
					} else {
						$entry['message'] = trim( $entry['message'] . ' ' . __( 'No se pudo actualizar el costo.', 'mj-erp' ) );
					}
				}

				// 5) Published price: USD through MJ Dólar, or the WooCommerce price field.
				if ( $update_price && $price_list ) {
					$prices = self::compute_price( $landed_base, $markup, $sale_rate, $rounding, $usd_cfg );

					if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
						$entry['message'] = trim( $entry['message'] . ' ' . __( 'El producto es variable: el precio se define por variación.', 'mj-erp' ) );
					} elseif ( 'usd' === $publish_mode ) {
						$published = MJERP_Dolar::publish_usd( $product, $prices['usd'], $price_target );

						$entry['price_updated']   = $published['ok'];
						$entry['price_mode']      = 'usd';
						$entry['price_target']    = $published['target'];
						$entry['new_price']       = $published['ok'] ? $published['usd'] : null;
						$entry['new_price_usd']   = $published['ok'] ? $published['usd'] : null;
						$entry['new_price_local'] = $published['ok'] ? $published['local'] : null;

						if ( '' !== $published['message'] ) {
							$entry['message'] = trim( $entry['message'] . ' ' . $published['message'] );
						}
						if ( $published['ok'] ) {
							$report['price_updated_items']++;
							$report['usd_published_items']++;
						}
					} else {
						$new_price = ( $wc_currency === $base_currency ) ? $prices['base'] : $prices['local'];

						if ( $new_price <= 0 ) {
							$entry['message'] = trim( $entry['message'] . ' ' . __( 'El precio calculado es cero: no se actualizó.', 'mj-erp' ) );
						} else {
							$ok                       = MJERP_Products::update_price( $product, $new_price, $price_target );
							$entry['price_updated']   = $ok;
							$entry['price_mode']      = 'local';
							$entry['price_target']    = $price_target;
							$entry['new_price']       = $ok ? $new_price : null;
							$entry['new_price_usd']   = ( $ok && $wc_currency === $base_currency ) ? $prices['base'] : null;
							$entry['new_price_local'] = ( $ok && $wc_currency !== $base_currency ) ? $prices['local'] : null;
							if ( $ok ) {
								$report['price_updated_items']++;
							} else {
								$entry['message'] = trim( $entry['message'] . ' ' . __( 'No se pudo actualizar el precio.', 'mj-erp' ) );
							}
						}
					}
				}
			} catch ( Throwable $e ) {
				$entry['status']  = 'error';
				$entry['message'] = $e->getMessage() ? $e->getMessage() : __( 'Error inesperado al procesar el ítem.', 'mj-erp' );
				$report['errors']++;
			}

			if ( 'skipped_stock' === $entry['status'] ) {
				$report['skipped_items']++;
			}

			$report['items'][] = $entry;
		}

		// 6) Status + recalculation.
		$status_result = self::set_status(
			$purchase_id,
			self::STATUS_RECV,
			array(
				'received_at' => current_time( 'mysql' ),
				'received_by' => $user_id,
			)
		);

		if ( is_wp_error( $status_result ) ) {
			$status_result->add_data( array( 'status' => 500, 'report' => $report ) );
			return $status_result;
		}

		self::recalculate( $purchase_id );

		$report['status'] = self::STATUS_RECV;

		return $report;
	}

	/* ------------------------------------------------------------------
	 * Unreceive
	 * ---------------------------------------------------------------- */

	/**
	 * Revert a reception: decrease the stock previously added (net of earlier reverts), log movements,
	 * status back to en_transito. Costs and prices are NOT reverted.
	 *
	 * @param array $purchase Full purchase.
	 * @param array $options  Optional. Accepts 'note', recorded on the reverting stock movements.
	 * @return array|WP_Error Report with the same shape as receive() plus reverted_stock_items.
	 */
	public static function unreceive( array $purchase, array $options = array() ) {
		$revert_note = isset( $options['note'] ) ? sanitize_text_field( (string) $options['note'] ) : '';
		$purchase_id = isset( $purchase['id'] ) ? absint( $purchase['id'] ) : 0;
		if ( ! $purchase_id ) {
			return new WP_Error( 'mjerp_invalid', __( 'Compra inválida.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return new WP_Error( 'mjerp_wc_missing', __( 'WooCommerce no está disponible.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$settings = self::settings();
		if ( isset( $settings['allow_unreceive'] ) && ! self::to_bool( $settings['allow_unreceive'] ) ) {
			return new WP_Error( 'mjerp_unreceive_disabled', __( 'Deshacer recepciones está desactivado en la configuración.', 'mj-erp' ), array( 'status' => 403 ) );
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		if ( self::STATUS_RECV !== $status ) {
			return new WP_Error( 'mjerp_invalid_status', __( 'Solo se puede deshacer la recepción de una compra recibida.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		if ( ! self::lock( $purchase_id ) ) {
			return new WP_Error( 'mjerp_receive_locked', __( 'Hay otra operación de recepción en curso para esta compra. Esperá unos segundos e intentá de nuevo.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		try {
			$fresh = self::fresh_row( $purchase_id );
			if ( is_array( $fresh ) && isset( $fresh['status'] ) && self::STATUS_RECV !== (string) $fresh['status'] ) {
				return new WP_Error( 'mjerp_invalid_status', __( 'El estado de la compra cambió. Actualizá la página.', 'mj-erp' ), array( 'status' => 409 ) );
			}

			return self::do_unreceive( $purchase, $revert_note );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mjerp_unreceive_failed', $e->getMessage() ? $e->getMessage() : __( 'Error inesperado al deshacer la recepción.', 'mj-erp' ), array( 'status' => 500 ) );
		} finally {
			self::unlock( $purchase_id );
		}
	}

	/**
	 * Unreceive implementation (runs under the lock).
	 *
	 * @param array $purchase Full purchase.
	 * @return array|WP_Error
	 */
	private static function do_unreceive( array $purchase, $revert_note = '' ) {
		$purchase_id = (int) $purchase['id'];
		$code        = isset( $purchase['code'] ) ? (string) $purchase['code'] : (string) $purchase_id;
		$user_id     = get_current_user_id();
		$items       = ( isset( $purchase['items'] ) && is_array( $purchase['items'] ) ) ? array_values( $purchase['items'] ) : array();

		$report = array(
			'items'                => array(),
			'added_stock_items'    => 0,
			'reverted_stock_items' => 0,
			'cost_updated_items'   => 0,
			'price_updated_items'  => 0,
			'unlinked_items'       => 0,
			'skipped_items'        => 0,
			'errors'               => 0,
			'warnings'             => array( __( 'Los costos y precios actualizados en la recepción no se revierten.', 'mj-erp' ) ),
			'purchase_id'          => $purchase_id,
			'status'               => self::STATUS_RECV,
		);

		// Net stock still added by this purchase, per product/variation.
		$movements = self::movements_for_purchase( $purchase_id );
		$groups    = array();

		foreach ( $movements as $movement ) {
			if ( ! is_array( $movement ) ) {
				continue;
			}
			$type = isset( $movement['type'] ) ? (string) $movement['type'] : '';
			if ( ! in_array( $type, array( self::TYPE_RECEIVE, self::TYPE_REVERT ), true ) ) {
				continue;
			}

			$pid = isset( $movement['product_id'] ) ? (int) $movement['product_id'] : 0;
			$vid = isset( $movement['variation_id'] ) ? (int) $movement['variation_id'] : 0;
			$key = $pid . ':' . $vid;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'product_id'   => $pid,
					'variation_id' => $vid,
					'net'          => 0.0,
					'cost'         => null,
				);
			}

			$qty = isset( $movement['qty'] ) ? abs( self::to_float( $movement['qty'] ) ) : 0.0;
			if ( self::TYPE_RECEIVE === $type ) {
				$groups[ $key ]['net'] += $qty;
				if ( isset( $movement['unit_cost_base'] ) && null !== $movement['unit_cost_base'] ) {
					$groups[ $key ]['cost'] = self::to_float( $movement['unit_cost_base'] );
				}
			} else {
				$groups[ $key ]['net'] -= $qty;
			}
		}

		foreach ( $groups as $group ) {
			$net = round( (float) $group['net'], 4 );
			if ( $net <= 0 ) {
				continue;
			}

			$product_id   = (int) $group['product_id'];
			$variation_id = (int) $group['variation_id'];
			$pid_used     = $variation_id ? $variation_id : $product_id;
			$item         = self::find_item( $items, $product_id, $variation_id );

			$entry = array(
				'item_id'       => $item && isset( $item['id'] ) ? (int) $item['id'] : 0,
				'name'          => $item && isset( $item['name'] ) ? (string) $item['name'] : '',
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'quantity'      => $item && isset( $item['quantity'] ) ? self::to_float( $item['quantity'] ) : $net,
				'received_qty'  => $net,
				'status'        => 'ok',
				'stock_before'  => null,
				'stock_after'   => null,
				'cost_updated'  => false,
				'price_updated' => false,
				'new_price'     => null,
				'message'       => '',
			);

			$product = $pid_used ? wc_get_product( $pid_used ) : false;

			if ( ! $product instanceof WC_Product ) {
				$entry['status']  = 'unlinked';
				$entry['message'] = __( 'El producto ya no existe en WooCommerce: no se descontó stock.', 'mj-erp' );
				$report['unlinked_items']++;
				$report['items'][] = $entry;
				continue;
			}

			if ( '' === $entry['name'] ) {
				$entry['name'] = (string) $product->get_name();
			}

			try {
				if ( ! $product->managing_stock() ) {
					$entry['status']  = 'skipped_stock';
					$entry['message'] = __( 'El producto ya no gestiona stock: no se descontó stock.', 'mj-erp' );
					$report['skipped_items']++;
				} else {
					$stock_qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $net ) : (int) $net;
					if ( $stock_qty <= 0 ) {
						$entry['status']  = 'skipped_stock';
						$entry['message'] = __( 'La cantidad a revertir es menor a una unidad de stock.', 'mj-erp' );
						$report['skipped_items']++;
					} else {
						$holder = MJERP_Products::stock_holder( $product );
						$before = $holder->get_stock_quantity();
						$before = ( null === $before || '' === $before ) ? 0 : $before;
						$after  = wc_update_product_stock( $product, $stock_qty, 'decrease' );

						if ( false === $after || null === $after ) {
							throw new Exception( __( 'WooCommerce no pudo actualizar el stock.', 'mj-erp' ) );
						}

						$entry['stock_before'] = self::num( $before );
						$entry['stock_after']  = self::num( $after );

						self::log_movement(
							array(
								'product_id'     => $product_id ? $product_id : ( $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id() ),
								'variation_id'   => $variation_id,
								'qty'            => -1 * (float) $stock_qty,
								'type'           => self::TYPE_REVERT,
								'ref_type'       => self::REF_TYPE,
								'ref_id'         => $purchase_id,
								'stock_before'   => (float) $before,
								'stock_after'    => (float) $after,
								'unit_cost_base' => null === $group['cost'] ? null : round( (float) $group['cost'], 6 ),
								'note'           => '' !== $revert_note ? $revert_note : sprintf(
									/* translators: %s: purchase code */
									__( 'Reversión de recepción de compra %s', 'mj-erp' ),
									$code
								),
								'user_id'        => $user_id,
							)
						);

						$report['reverted_stock_items']++;
					}
				}
			} catch ( Throwable $e ) {
				$entry['status']  = 'error';
				$entry['message'] = $e->getMessage() ? $e->getMessage() : __( 'Error inesperado al revertir el stock.', 'mj-erp' );
				$report['errors']++;
			}

			$report['items'][] = $entry;
		}

		$status_result = self::set_status(
			$purchase_id,
			self::STATUS_TRANS,
			array(
				'received_at' => null,
				'received_by' => null,
			)
		);

		if ( is_wp_error( $status_result ) ) {
			$status_result->add_data( array( 'status' => 500, 'report' => $report ) );
			return $status_result;
		}

		self::recalculate( $purchase_id );

		$report['status'] = self::STATUS_TRANS;

		return $report;
	}

	/* ------------------------------------------------------------------
	 * Lock
	 * ---------------------------------------------------------------- */

	/**
	 * Acquire the per-purchase transient lock.
	 *
	 * @param int $purchase_id Purchase id.
	 * @return bool
	 */
	private static function lock( int $purchase_id ) : bool {
		$key = self::LOCK_PREFIX . $purchase_id;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::LOCK_TTL );
		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $purchase_id Purchase id.
	 * @return void
	 */
	private static function unlock( int $purchase_id ) {
		delete_transient( self::LOCK_PREFIX . $purchase_id );
	}

	/* ------------------------------------------------------------------
	 * Repository / settings bridges (defensive)
	 * ---------------------------------------------------------------- */

	/**
	 * Fresh purchase row from the repo (null when unavailable).
	 *
	 * @param int $purchase_id Purchase id.
	 * @return array|null
	 */
	private static function fresh_row( int $purchase_id ) {
		if ( class_exists( 'MJERP_Purchases_Repo' ) && method_exists( 'MJERP_Purchases_Repo', 'find' ) ) {
			$row = MJERP_Purchases_Repo::find( $purchase_id );
			return is_array( $row ) ? $row : null;
		}
		return null;
	}

	/**
	 * Persist received_qty on an item.
	 *
	 * @param int   $item_id      Item id.
	 * @param float $received_qty Quantity.
	 * @return true|WP_Error
	 */
	private static function save_received_qty( int $item_id, float $received_qty ) {
		if ( ! class_exists( 'MJERP_Purchases_Repo' ) || ! method_exists( 'MJERP_Purchases_Repo', 'update_item' ) ) {
			return new WP_Error( 'mjerp_repo_missing', __( 'Repositorio de compras no disponible.', 'mj-erp' ) );
		}

		try {
			// Defer the recalculation: do_receive() runs a single recalculate() once every item is
			// saved, so recalculating per item would repeat the whole costing pass N times.
			$result = MJERP_Purchases_Repo::update_item( $item_id, array( 'received_qty' => $received_qty ), false );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mjerp_item_update_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new WP_Error( 'mjerp_item_update_failed', __( 'La actualización del ítem fue rechazada.', 'mj-erp' ) );
		}

		return true;
	}

	/**
	 * Status change through the repo.
	 *
	 * @param int    $purchase_id Purchase id.
	 * @param string $status      New status.
	 * @param array  $extra       Extra columns.
	 * @return true|WP_Error
	 */
	private static function set_status( int $purchase_id, string $status, array $extra ) {
		if ( ! class_exists( 'MJERP_Purchases_Repo' ) || ! method_exists( 'MJERP_Purchases_Repo', 'set_status' ) ) {
			return new WP_Error( 'mjerp_repo_missing', __( 'Repositorio de compras no disponible.', 'mj-erp' ) );
		}

		try {
			$result = MJERP_Purchases_Repo::set_status( $purchase_id, $status, $extra );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mjerp_status_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new WP_Error( 'mjerp_status_failed', __( 'No se pudo cambiar el estado de la compra.', 'mj-erp' ) );
		}

		return true;
	}

	/**
	 * Recalculate cached totals (best effort).
	 *
	 * @param int $purchase_id Purchase id.
	 * @return void
	 */
	private static function recalculate( int $purchase_id ) {
		if ( class_exists( 'MJERP_Purchases_Repo' ) && method_exists( 'MJERP_Purchases_Repo', 'recalculate' ) ) {
			try {
				MJERP_Purchases_Repo::recalculate( $purchase_id );
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Insert a stock movement (best effort, never throws).
	 *
	 * @param array $data Movement row.
	 * @return int
	 */
	private static function log_movement( array $data ) : int {
		if ( ! class_exists( 'MJERP_Movements_Repo' ) || ! method_exists( 'MJERP_Movements_Repo', 'insert' ) ) {
			return 0;
		}

		if ( isset( $data['note'] ) ) {
			$data['note'] = mb_substr( (string) $data['note'], 0, 255 );
		}

		try {
			$id = MJERP_Movements_Repo::insert( $data );
			return is_numeric( $id ) ? (int) $id : 0;
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	/**
	 * All movements referencing a purchase.
	 *
	 * @param int $purchase_id Purchase id.
	 * @return array
	 */
	private static function movements_for_purchase( int $purchase_id ) : array {
		if ( ! class_exists( 'MJERP_Movements_Repo' ) || ! method_exists( 'MJERP_Movements_Repo', 'for_ref' ) ) {
			return array();
		}

		try {
			$rows = MJERP_Movements_Repo::for_ref( self::REF_TYPE, $purchase_id );
		} catch ( Throwable $e ) {
			return array();
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Settings array (defaults when the settings class is missing).
	 *
	 * @return array
	 */
	private static function settings() : array {
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'all' ) ) {
			$all = MJERP_Settings::all();
			if ( is_array( $all ) ) {
				return $all;
			}
		}

		return array(
			'base_currency'           => 'USD',
			'local_currency'          => 'ARS',
			'sale_rate'               => 1,
			'stock_on_receive'        => 'increase',
			'enable_stock_management' => true,
			'update_cost_on_receive'  => true,
			'update_price_on_receive' => false,
			'price_list_for_price'    => '',
			'price_target'            => 'regular_price',
			'price_rounding'          => 0,
			'allow_unreceive'         => true,
			'price_lists'             => array(),
		);
	}

	/**
	 * Resolve a price list definition { key, label, markup }.
	 *
	 * @param string $key      Price list key.
	 * @param array  $settings Settings.
	 * @return array|null
	 */
	private static function resolve_price_list( string $key, array $settings ) {
		$list = null;

		if ( '' !== $key && class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'price_list' ) ) {
			$candidate = MJERP_Settings::price_list( $key );
			if ( is_array( $candidate ) && isset( $candidate['markup'] ) ) {
				$list = $candidate;
			}
		}

		$lists = ( isset( $settings['price_lists'] ) && is_array( $settings['price_lists'] ) ) ? $settings['price_lists'] : array();

		if ( ! $list && '' !== $key ) {
			foreach ( $lists as $candidate ) {
				if ( is_array( $candidate ) && isset( $candidate['key'] ) && (string) $candidate['key'] === $key && isset( $candidate['markup'] ) ) {
					$list = $candidate;
					break;
				}
			}
		}

		if ( ! $list ) {
			foreach ( $lists as $candidate ) {
				if ( is_array( $candidate ) && isset( $candidate['markup'] ) ) {
					$list = $candidate;
					break;
				}
			}
		}

		if ( ! $list ) {
			return null;
		}

		return array(
			'key'    => isset( $list['key'] ) ? (string) $list['key'] : $key,
			'label'  => isset( $list['label'] ) ? (string) $list['label'] : $key,
			'markup' => self::to_float( $list['markup'] ),
		);
	}

	/**
	 * Suggested price for a landed cost (delegates to MJERP_Costing::price_for when available).
	 *
	 * @param float      $landed_base Landed unit cost (base currency).
	 * @param float      $markup_pct  Markup percentage.
	 * @param float      $sale_rate   Sale exchange rate.
	 * @param int        $rounding    Price rounding multiple (0 = none).
	 * @param array|null $usd_cfg     USD publishing context (see MJERP_Costing::usd_config()).
	 * @return array { base, local, usd, usd_local }
	 */
	private static function compute_price( float $landed_base, float $markup_pct, float $sale_rate, int $rounding, $usd_cfg = null ) : array {
		if ( class_exists( 'MJERP_Costing' ) && method_exists( 'MJERP_Costing', 'price_for' ) ) {
			try {
				$result = MJERP_Costing::price_for( $landed_base, $markup_pct, $sale_rate, $rounding, $usd_cfg );
				if ( is_array( $result ) && isset( $result['base'], $result['local'] ) ) {
					return array(
						'base'      => (float) $result['base'],
						'local'     => (float) $result['local'],
						'usd'       => isset( $result['usd'] ) ? (float) $result['usd'] : round( (float) $result['base'], 2 ),
						'usd_local' => isset( $result['usd_local'] ) ? (float) $result['usd_local'] : (float) $result['local'],
					);
				}
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}

		$base  = $landed_base * ( 1 + $markup_pct / 100 );
		$local = $base * $sale_rate;

		if ( class_exists( 'MJERP_Costing' ) && method_exists( 'MJERP_Costing', 'round_price' ) ) {
			$local = (float) MJERP_Costing::round_price( $local, $rounding );
		} else {
			$local = $rounding > 0 ? round( $local / $rounding ) * $rounding : round( $local, 2 );
		}

		return array(
			'base'      => round( $base, 2 ),
			'local'     => (float) $local,
			'usd'       => round( $base, 2 ),
			'usd_local' => (float) $local,
		);
	}

	/* ------------------------------------------------------------------
	 * Small helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Whether a status allows reception.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private static function can_receive_status( string $status ) : bool {
		return in_array( $status, array( 'borrador', self::STATUS_TRANS ), true );
	}

	/**
	 * Normalize the options.items input into [ item_id => qty ].
	 * Accepts a list of { item_id|id, received_qty|qty|quantity } or a map item_id → qty.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	private static function normalize_qty_map( $input ) : array {
		$map = array();

		if ( ! is_array( $input ) ) {
			return $map;
		}

		foreach ( $input as $key => $row ) {
			$item_id = 0;
			$qty     = null;

			if ( is_array( $row ) ) {
				if ( isset( $row['item_id'] ) ) {
					$item_id = absint( $row['item_id'] );
				} elseif ( isset( $row['id'] ) ) {
					$item_id = absint( $row['id'] );
				} else {
					$item_id = absint( $key );
				}

				if ( array_key_exists( 'received_qty', $row ) ) {
					$qty = $row['received_qty'];
				} elseif ( array_key_exists( 'qty', $row ) ) {
					$qty = $row['qty'];
				} elseif ( array_key_exists( 'quantity', $row ) ) {
					$qty = $row['quantity'];
				}
			} else {
				$item_id = absint( $key );
				$qty     = $row;
			}

			if ( ! $item_id || null === $qty || '' === $qty ) {
				continue;
			}

			$map[ $item_id ] = max( 0.0, self::to_float( $qty ) );
		}

		return $map;
	}

	/**
	 * First purchase item matching a product/variation pair.
	 *
	 * @param array $items        Items.
	 * @param int   $product_id   Product id.
	 * @param int   $variation_id Variation id.
	 * @return array|null
	 */
	private static function find_item( array $items, int $product_id, int $variation_id ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$ipid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$ivid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;

			if ( $variation_id ) {
				if ( $ivid === $variation_id ) {
					return $item;
				}
			} elseif ( $ipid === $product_id && 0 === $ivid ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Number cast keeping ints as ints.
	 *
	 * @param mixed $value Value.
	 * @return int|float
	 */
	private static function num( $value ) {
		$float = (float) $value;
		return ( floor( $float ) === $float ) ? (int) $float : $float;
	}

	/**
	 * Lenient float parser (delegates to MJERP_Helpers::to_float when available).
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
	 * Lenient boolean parser.
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
