<?php
defined( 'ABSPATH' ) || exit;

/**
 * GET|PUT /mjerp/v1/settings
 */
class MJERP_REST_Settings extends MJERP_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_settings' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_settings',
					array(
						'settings' => array(
							'description'       => __( 'Objeto parcial de configuración (opcional; también se aceptan las claves en la raíz del cuerpo).', 'mj-erp' ),
							'sanitize_callback' => array( $this, 'arg_array' ),
						),
					)
				),
			)
		);
	}

	/**
	 * GET /settings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( $request ) {
		$this->reset_settings_cache();
		return $this->ok( $this->response_payload( $this->settings() ) );
	}

	/**
	 * PUT /settings — partial update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		if ( ! class_exists( 'MJERP_Settings' ) || ! is_callable( array( 'MJERP_Settings', 'update' ) ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'El módulo de configuración no está disponible.', 'mj-erp' ), 500 );
		}

		$params = $this->params( $request );

		// Accept either { settings: {...} } or the partial object at the root.
		$input = array();
		if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
			$input = $params['settings'];
		} else {
			$input = $params;
		}

		$defaults = is_callable( array( 'MJERP_Settings', 'defaults' ) ) ? (array) MJERP_Settings::defaults() : $this->settings();
		$partial  = array();
		foreach ( (array) $input as $key => $value ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$partial[ $key ] = $this->normalise_value( $key, $value, $defaults[ $key ] );
		}

		if ( empty( $partial ) ) {
			return $this->fail( 'mjerp_invalid_settings', __( 'No se recibió ninguna opción válida para guardar.', 'mj-erp' ), 400 );
		}

		// Settings that change who can reach the ERP, where it lives or which product meta is
		// written are administrator-only: a `manage_mjerp` holder must not be able to widen its
		// own access or point the cost field at a WooCommerce core meta key. Only an actual change
		// is refused, so a non-administrator can still save the rest of the form untouched.
		$restricted = array();
		if ( ! current_user_can( 'manage_options' ) ) {
			$current = $this->settings();
			foreach ( self::admin_only_keys() as $key ) {
				if ( ! array_key_exists( $key, $partial ) ) {
					continue;
				}
				$before = isset( $current[ $key ] ) ? $current[ $key ] : null;
				if ( wp_json_encode( $this->comparable( $before ) ) !== wp_json_encode( $this->comparable( $partial[ $key ] ) ) ) {
					$restricted[] = $key;
				} else {
					unset( $partial[ $key ] );
				}
			}
		}
		if ( ! empty( $restricted ) ) {
			return $this->fail(
				'mjerp_settings_forbidden',
				sprintf(
					/* translators: %s: comma separated list of setting names. */
					__( 'Solo un administrador puede cambiar estas opciones: %s.', 'mj-erp' ),
					implode( ', ', $restricted )
				),
				403,
				array( 'keys' => $restricted )
			);
		}

		$validation = $this->validate_partial( $partial, $defaults );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$saved = MJERP_Settings::update( $partial );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->reset_settings_cache();
		$settings = is_array( $saved ) && ! empty( $saved ) ? array_merge( $this->settings(), $saved ) : $this->settings();

		/**
		 * Fires after settings were saved through the REST API.
		 *
		 * @param array $partial  Keys that were sent.
		 * @param array $settings Resulting settings.
		 */
		do_action( 'mjerp_rest_settings_updated', $partial, $settings );

		return $this->ok( $this->response_payload( $settings ) );
	}

	/**
	 * Settings only an administrator (`manage_options`) may change, because they decide who reaches
	 * the ERP, at which URL it answers, which product meta key is written and whether uninstalling
	 * destroys the data.
	 *
	 * @return string[]
	 */
	/**
	 * Normalises a value so two equivalent settings compare equal (lists sorted, scalars stringified).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected function comparable( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->comparable( $v );
			}
			if ( $out === array_values( $out ) ) {
				sort( $out );
			} else {
				ksort( $out );
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return null === $value ? '' : (string) $value;
	}

	public static function admin_only_keys() {
		/**
		 * Filters the settings restricted to administrators.
		 *
		 * @param string[] $keys Setting keys.
		 */
		return (array) apply_filters(
			'mjerp_admin_only_settings',
			array( 'allowed_roles', 'route_slug', 'cost_meta_key', 'delete_data_on_uninstall' )
		);
	}

	/**
	 * Response shape: the settings keys at the root (so `res.theme` works), plus
	 * `settings` (so `res.settings.theme` works too) and `app_url`.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	protected function response_payload( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$payload  = $settings;
		$payload['settings'] = $settings;
		$payload['app_url']  = $this->app_url();
		$payload['statuses'] = $this->statuses();
		return $payload;
	}

	/**
	 * Light per-type normalisation before handing to MJERP_Settings::update()
	 * (which performs the authoritative sanitization).
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $default Default value (used to infer the type).
	 * @return mixed
	 */
	protected function normalise_value( $key, $value, $default ) {
		if ( is_bool( $default ) ) {
			return $this->to_bool( $value, $default );
		}
		// Exchange rates are decimals even though their defaults are integers.
		if ( in_array( $key, array( 'default_rate', 'sale_rate' ), true ) ) {
			return $this->to_float( $value, $default );
		}
		if ( is_int( $default ) ) {
			if ( 'price_rounding' === $key ) {
				return max( 0, (int) round( $this->to_float( $value, 0 ) ) );
			}
			return (int) round( $this->to_float( $value, $default ) );
		}
		if ( is_float( $default ) ) {
			return $this->to_float( $value, $default );
		}
		if ( is_array( $default ) ) {
			if ( ! is_array( $value ) ) {
				// Allow comma separated strings for simple lists.
				if ( is_string( $value ) && in_array( $key, array( 'currencies', 'destinations', 'allowed_roles' ), true ) ) {
					$parts = array_map( 'trim', explode( ',', $value ) );
					return array_values( array_filter( $parts, 'strlen' ) );
				}
				return $default;
			}
			return $this->sanitize_array_value( $key, $value );
		}
		// Strings (may arrive numeric: default_rate / sale_rate defaults are ints but treat generically).
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}
		return $default;
	}

	/**
	 * Sanitize array-type settings.
	 *
	 * @param string $key   Setting key.
	 * @param array  $value Value.
	 * @return array
	 */
	protected function sanitize_array_value( $key, $value ) {
		$out = array();

		switch ( $key ) {
			case 'currencies':
				foreach ( $value as $code ) {
					$code = $this->clean_currency( $code );
					if ( '' !== $code ) {
						$out[] = $code;
					}
				}
				return array_values( array_unique( $out ) );

			case 'currency_symbols':
				foreach ( $value as $code => $symbol ) {
					$code = $this->clean_currency( $code );
					if ( '' !== $code && is_scalar( $symbol ) ) {
						$out[ $code ] = sanitize_text_field( (string) $symbol );
					}
				}
				return $out;

			case 'destinations':
			case 'allowed_roles':
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$item = 'allowed_roles' === $key ? sanitize_key( (string) $item ) : sanitize_text_field( (string) $item );
						if ( '' !== $item ) {
							$out[] = $item;
						}
					}
				}
				return array_values( array_unique( $out ) );

			case 'expense_types':
			case 'price_lists':
				foreach ( $value as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$row_key   = isset( $entry['key'] ) && is_scalar( $entry['key'] ) ? sanitize_key( (string) $entry['key'] ) : '';
					$row_label = isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '';
					if ( '' === $row_key && '' !== $row_label ) {
						$row_key = sanitize_key( sanitize_title( $row_label ) );
					}
					if ( '' === $row_key ) {
						continue;
					}
					$row = array(
						'key'   => $row_key,
						'label' => '' !== $row_label ? $row_label : $row_key,
					);
					if ( 'price_lists' === $key ) {
						$row['markup'] = isset( $entry['markup'] ) ? $this->to_float( $entry['markup'], 0 ) : 0.0;
					}
					$out[] = $row;
				}
				return $out;
		}

		// Generic array: sanitize scalars recursively.
		return $this->sanitize_generic_array( $value );
	}

	/**
	 * Recursive scalar sanitization.
	 *
	 * @param array $value Value.
	 * @return array
	 */
	protected function sanitize_generic_array( $value ) {
		$out = array();
		foreach ( (array) $value as $k => $v ) {
			$k = is_int( $k ) ? $k : sanitize_key( (string) $k );
			if ( is_array( $v ) ) {
				$out[ $k ] = $this->sanitize_generic_array( $v );
			} elseif ( is_bool( $v ) ) {
				$out[ $k ] = $v;
			} elseif ( is_scalar( $v ) ) {
				$out[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	/**
	 * Cross-field validation with Spanish messages (MJERP_Settings::sanitize() is
	 * still the last word; this only turns obvious mistakes into clear errors).
	 *
	 * @param array $partial  Partial settings.
	 * @param array $defaults Defaults.
	 * @return true|WP_Error
	 */
	protected function validate_partial( $partial, $defaults ) {
		$errors = array();

		if ( array_key_exists( 'app_title', $partial ) && '' === trim( (string) $partial['app_title'] ) ) {
			$errors['app_title'] = __( 'El título de la aplicación no puede estar vacío.', 'mj-erp' );
		}

		if ( array_key_exists( 'theme', $partial ) && ! in_array( $partial['theme'], array( 'dark', 'light' ), true ) ) {
			$errors['theme'] = __( 'El tema debe ser «dark» o «light».', 'mj-erp' );
		}

		if ( array_key_exists( 'decimals', $partial ) && ( $partial['decimals'] < 0 || $partial['decimals'] > 6 ) ) {
			$errors['decimals'] = __( 'Los decimales deben estar entre 0 y 6.', 'mj-erp' );
		}

		foreach ( array( 'base_currency', 'local_currency' ) as $ckey ) {
			if ( array_key_exists( $ckey, $partial ) && '' === $this->clean_currency( $partial[ $ckey ] ) ) {
				$errors[ $ckey ] = __( 'La moneda debe ser un código de 3 letras (ej. USD).', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'currencies', $partial ) && empty( $partial['currencies'] ) ) {
			$errors['currencies'] = __( 'Tenés que permitir al menos una moneda.', 'mj-erp' );
		}

		foreach ( array( 'default_rate', 'sale_rate' ) as $rkey ) {
			if ( array_key_exists( $rkey, $partial ) && $this->to_float( $partial[ $rkey ], 0 ) <= 0 ) {
				$errors[ $rkey ] = __( 'El tipo de cambio debe ser mayor a cero.', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'proration_method', $partial ) && ! in_array( $partial['proration_method'], array( 'fob', 'qty', 'none' ), true ) ) {
			$errors['proration_method'] = __( 'Método de prorrateo inválido.', 'mj-erp' );
		}

		if ( array_key_exists( 'code_seq_digits', $partial ) && ( $partial['code_seq_digits'] < 1 || $partial['code_seq_digits'] > 10 ) ) {
			$errors['code_seq_digits'] = __( 'Los dígitos del correlativo deben estar entre 1 y 10.', 'mj-erp' );
		}

		if ( array_key_exists( 'code_format', $partial ) && false === strpos( (string) $partial['code_format'], '{seq}' ) ) {
			$errors['code_format'] = __( 'El formato del código debe incluir el token {seq}.', 'mj-erp' );
		}

		if ( array_key_exists( 'price_lists', $partial ) ) {
			$keys = array();
			foreach ( (array) $partial['price_lists'] as $list ) {
				if ( isset( $list['key'] ) ) {
					if ( isset( $keys[ $list['key'] ] ) ) {
						$errors['price_lists'] = sprintf(
							/* translators: %s: list key */
							__( 'La clave de lista de precios «%s» está repetida.', 'mj-erp' ),
							$list['key']
						);
					}
					$keys[ $list['key'] ] = true;
				}
			}
		}

		if ( array_key_exists( 'expense_types', $partial ) ) {
			$keys = array();
			foreach ( (array) $partial['expense_types'] as $type ) {
				if ( isset( $type['key'] ) ) {
					if ( isset( $keys[ $type['key'] ] ) ) {
						$errors['expense_types'] = sprintf(
							/* translators: %s: type key */
							__( 'La clave de tipo de gasto «%s» está repetida.', 'mj-erp' ),
							$type['key']
						);
					}
					$keys[ $type['key'] ] = true;
				}
			}
		}

		if ( array_key_exists( 'stock_on_receive', $partial ) && ! in_array( $partial['stock_on_receive'], array( 'increase', 'none' ), true ) ) {
			$errors['stock_on_receive'] = __( 'Valor inválido para el stock al recibir.', 'mj-erp' );
		}

		if ( array_key_exists( 'price_target', $partial ) && ! in_array( $partial['price_target'], array( 'regular_price', 'sale_price' ), true ) ) {
			$errors['price_target'] = __( 'El precio destino debe ser regular_price o sale_price.', 'mj-erp' );
		}

		if ( array_key_exists( 'price_publish_mode', $partial ) && ! in_array( $partial['price_publish_mode'], array( 'auto', 'usd', 'local' ), true ) ) {
			$errors['price_publish_mode'] = __( 'La forma de publicar el precio debe ser auto, usd o local.', 'mj-erp' );
		}

		if ( array_key_exists( 'sale_rate_source', $partial ) && ! in_array( $partial['sale_rate_source'], array( 'mjdolar', 'manual' ), true ) ) {
			$errors['sale_rate_source'] = __( 'El origen del TC de venta debe ser mjdolar o manual.', 'mj-erp' );
		}

		if ( array_key_exists( 'usd_rounding_mode', $partial ) && ! in_array( $partial['usd_rounding_mode'], array( 'nearest', 'up' ), true ) ) {
			$errors['usd_rounding_mode'] = __( 'El modo de redondeo en dólares debe ser nearest o up.', 'mj-erp' );
		}

		if ( array_key_exists( 'usd_rounding', $partial ) ) {
			$step  = $this->to_float( $partial['usd_rounding'], -1 );
			$valid = false;
			foreach ( array_keys( MJERP_Settings::usd_rounding_choices() ) as $allowed ) {
				if ( abs( (float) $allowed - $step ) < 0.0001 ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				$errors['usd_rounding'] = __( 'El redondeo del precio en dólares no es uno de los valores permitidos.', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'new_product_status', $partial ) && ! in_array( $partial['new_product_status'], array( 'draft', 'publish' ), true ) ) {
			$errors['new_product_status'] = __( 'El estado de productos nuevos debe ser draft o publish.', 'mj-erp' );
		}

		/* --- Consignaciones ------------------------------------------------------------------ */

		if ( array_key_exists( 'consignment_stock_on_sale', $partial ) && ! in_array( $partial['consignment_stock_on_sale'], array( 'decrease', 'none' ), true ) ) {
			$errors['consignment_stock_on_sale'] = __( 'El stock al vender debe ser decrease o none.', 'mj-erp' );
		}

		if ( array_key_exists( 'consignment_approval', $partial ) && ! in_array( $partial['consignment_approval'], array( 'always', 'portal', 'never' ), true ) ) {
			$errors['consignment_approval'] = __( 'La confirmación de ventas debe ser always, portal o never.', 'mj-erp' );
		}

		if ( array_key_exists( 'consignment_allocation', $partial ) && ! in_array( $partial['consignment_allocation'], array( 'fifo', 'manual' ), true ) ) {
			$errors['consignment_allocation'] = __( 'La imputación de pagos debe ser fifo o manual.', 'mj-erp' );
		}

		if ( array_key_exists( 'consignee_role', $partial ) ) {
			$role = sanitize_key( (string) $partial['consignee_role'] );
			if ( '' === $role ) {
				$errors['consignee_role'] = __( 'El rol de reventa no puede estar vacío.', 'mj-erp' );
			} elseif ( 'administrator' === $role ) {
				$errors['consignee_role'] = __( 'No se puede usar el rol administrator para los revendedores.', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'consignment_code_format', $partial ) ) {
			$format = trim( (string) $partial['consignment_code_format'] );
			if ( '' === $format ) {
				$errors['consignment_code_format'] = __( 'El formato del código de entrega no puede estar vacío.', 'mj-erp' );
			} elseif ( false === strpos( $format, '{seq}' ) ) {
				$errors['consignment_code_format'] = __( 'El formato del código de entrega tiene que incluir {seq}.', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'consignment_payment_methods', $partial ) && is_array( $partial['consignment_payment_methods'] ) ) {
			$seen = array();
			foreach ( $partial['consignment_payment_methods'] as $method ) {
				if ( ! is_array( $method ) || ! isset( $method['key'] ) ) {
					continue;
				}
				$key = sanitize_key( (string) $method['key'] );
				if ( '' === $key ) {
					continue;
				}
				if ( isset( $seen[ $key ] ) ) {
					$errors['consignment_payment_methods'] = sprintf(
						/* translators: %s: method key */
						__( 'La clave de medio de cobro «%s» está repetida.', 'mj-erp' ),
						$key
					);
				}
				$seen[ $key ] = true;
			}
		}

		if ( array_key_exists( 'portal_slug', $partial ) ) {
			$slug = sanitize_title( (string) $partial['portal_slug'] );
			if ( '' === $slug ) {
				$errors['portal_slug'] = __( 'El slug del panel del revendedor no puede estar vacío.', 'mj-erp' );
			} else {
				$route = array_key_exists( 'route_slug', $partial )
					? sanitize_title( (string) $partial['route_slug'] )
					: sanitize_title( (string) $this->setting( 'route_slug', 'mjerp' ) );
				if ( $slug === $route ) {
					$errors['portal_slug'] = __( 'El panel del revendedor no puede usar la misma dirección que el ERP.', 'mj-erp' );
				}
			}
		}

		if ( array_key_exists( 'cost_meta_key', $partial ) ) {
			$meta_key = trim( (string) $partial['cost_meta_key'] );
			if ( '' === $meta_key || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $meta_key ) ) {
				$errors['cost_meta_key'] = __( 'La clave de meta del costo solo puede contener letras, números, guiones y guiones bajos.', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'route_slug', $partial ) ) {
			$slug = sanitize_title( (string) $partial['route_slug'] );
			if ( '' === $slug ) {
				$errors['route_slug'] = __( 'El slug de la ruta no puede estar vacío.', 'mj-erp' );
			} elseif ( in_array( $slug, array( 'wp-admin', 'wp-json', 'wp-login', 'wp-content', 'wp-includes', 'feed', 'shop', 'cart', 'checkout', 'my-account' ), true ) ) {
				$errors['route_slug'] = __( 'Ese slug está reservado por WordPress/WooCommerce; elegí otro.', 'mj-erp' );
			}
		}

		if ( array_key_exists( 'allowed_roles', $partial ) ) {
			$all_roles = wp_roles() ? array_keys( (array) wp_roles()->get_names() ) : array();
			foreach ( (array) $partial['allowed_roles'] as $role ) {
				if ( ! in_array( $role, $all_roles, true ) ) {
					$errors['allowed_roles'] = sprintf(
						/* translators: %s: role key */
						__( 'El rol «%s» no existe.', 'mj-erp' ),
						$role
					);
					break;
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return $this->fail(
				'mjerp_invalid_settings',
				reset( $errors ),
				400,
				array( 'errors' => $errors )
			);
		}

		return true;
	}

	/**
	 * SPA URL (after a slug change it reflects the new slug).
	 *
	 * @return string
	 */
	protected function app_url() {
		if ( class_exists( 'MJERP_Frontend' ) && is_callable( array( 'MJERP_Frontend', 'app_url' ) ) ) {
			return (string) MJERP_Frontend::app_url();
		}
		$slug = sanitize_title( (string) $this->setting( 'route_slug', 'mjerp' ) );
		if ( '' === $slug ) {
			$slug = 'mjerp';
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . $slug . '/' );
		}
		return home_url( '/?mjerp_app=1' );
	}
}
