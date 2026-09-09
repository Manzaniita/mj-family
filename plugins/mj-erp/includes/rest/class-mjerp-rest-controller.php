<?php
defined( 'ABSPATH' ) || exit;

/**
 * Base REST controller for MJ ERP.
 *
 * Every controller of the plugin extends this class. It centralises:
 * - the capability check used as `permission_callback`,
 * - response / error helpers,
 * - request parameter merging and typed sanitization helpers,
 * - purchase loading and edit-lock enforcement.
 */
class MJERP_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mjerp/v1';

	/**
	 * Base of the routes handled by the controller (informational).
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Per-request settings cache.
	 *
	 * @var array|null
	 */
	protected static $settings_cache = null;

	/**
	 * Child controllers override this.
	 */
	public function register_routes() {}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	/**
	 * Permission callback shared by every route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_check( $request ) {
		$allowed = false;

		if ( class_exists( 'MJERP_Capabilities' ) && is_callable( array( 'MJERP_Capabilities', 'current_user_can' ) ) ) {
			$allowed = (bool) MJERP_Capabilities::current_user_can();
		} else {
			$allowed = current_user_can( 'manage_mjerp' ) || current_user_can( 'manage_options' );
		}

		if ( $allowed ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'mjerp_unauthorized',
				__( 'Tenés que iniciar sesión para usar MJ ERP.', 'mj-erp' ),
				array( 'status' => 401 )
			);
		}

		return new WP_Error(
			'mjerp_forbidden',
			__( 'No tenés permisos para usar MJ ERP.', 'mj-erp' ),
			array( 'status' => 403 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Responses
	 * ------------------------------------------------------------------ */

	/**
	 * Wrap data in a REST response.
	 *
	 * @param mixed $data   Payload (a WP_Error is passed through untouched).
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function ok( $data, $status = 200 ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$response = rest_ensure_response( $data );
		$status   = (int) $status;
		if ( $status > 0 && 200 !== $status ) {
			$response->set_status( $status );
		}
		return $response;
	}

	/**
	 * Build a WP_Error with an HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message (already translated).
	 * @param int    $status  HTTP status.
	 * @param array  $data    Extra data.
	 * @return WP_Error
	 */
	protected function fail( $code, $message, $status = 400, $data = array() ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['status'] = (int) $status;
		return new WP_Error( $code, $message, $data );
	}

	/* ---------------------------------------------------------------------
	 * Request parameters
	 * ------------------------------------------------------------------ */

	/**
	 * Merged query + body + JSON params. Route (URL) params always win.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected function params( $request ) {
		$query = $request->get_query_params();
		$body  = $request->get_body_params();
		$json  = $request->get_json_params();

		$params = array_merge(
			is_array( $query ) ? $query : array(),
			is_array( $body ) ? $body : array(),
			is_array( $json ) ? $json : array()
		);

		$url = $request->get_url_params();
		if ( is_array( $url ) ) {
			foreach ( $url as $key => $value ) {
				$params[ $key ] = $value;
			}
		}

		return $params;
	}

	/**
	 * Integer route parameter (never shadowed by the body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Parameter name.
	 * @return int
	 */
	protected function route_id( $request, $key = 'id' ) {
		$url = $request->get_url_params();
		if ( is_array( $url ) && isset( $url[ $key ] ) ) {
			return absint( $url[ $key ] );
		}
		return absint( $request->get_param( $key ) );
	}

	/**
	 * Whether a key was sent at all (null counts as "sent, clear it").
	 *
	 * @param array  $params Params.
	 * @param string $key    Key.
	 * @return bool
	 */
	protected function has( $params, $key ) {
		return is_array( $params ) && array_key_exists( $key, $params );
	}

	/**
	 * Sanitized single-line string param.
	 *
	 * @param array  $params  Params.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @param int    $max     Max length (0 = unlimited).
	 * @return string
	 */
	protected function str( $params, $key, $default = '', $max = 0 ) {
		if ( ! $this->has( $params, $key ) || ! is_scalar( $params[ $key ] ) ) {
			return $default;
		}
		$value = sanitize_text_field( (string) $params[ $key ] );
		if ( $max > 0 && function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, $max );
		} elseif ( $max > 0 ) {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	/**
	 * Sanitized multi-line string param.
	 *
	 * @param array  $params  Params.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	protected function text( $params, $key, $default = '' ) {
		if ( ! $this->has( $params, $key ) || ! is_scalar( $params[ $key ] ) ) {
			return $default;
		}
		return sanitize_textarea_field( (string) $params[ $key ] );
	}

	/**
	 * Unsigned integer param.
	 *
	 * @param array  $params  Params.
	 * @param string $key     Key.
	 * @param int    $default Default.
	 * @return int
	 */
	protected function int( $params, $key, $default = 0 ) {
		if ( ! $this->has( $params, $key ) || null === $params[ $key ] || '' === $params[ $key ] || ! is_scalar( $params[ $key ] ) ) {
			return (int) $default;
		}
		return absint( $params[ $key ] );
	}

	/**
	 * Float param (locale tolerant).
	 *
	 * @param array  $params  Params.
	 * @param string $key     Key.
	 * @param float  $default Default.
	 * @return float
	 */
	protected function float( $params, $key, $default = 0.0 ) {
		if ( ! $this->has( $params, $key ) || null === $params[ $key ] || '' === $params[ $key ] || ! is_scalar( $params[ $key ] ) ) {
			return (float) $default;
		}
		return $this->to_float( $params[ $key ], $default );
	}

	/**
	 * Boolean param.
	 *
	 * @param array  $params  Params.
	 * @param string $key     Key.
	 * @param bool   $default Default.
	 * @return bool
	 */
	protected function bool( $params, $key, $default = false ) {
		if ( ! $this->has( $params, $key ) || null === $params[ $key ] || '' === $params[ $key ] ) {
			return (bool) $default;
		}
		return $this->to_bool( $params[ $key ], $default );
	}

	/**
	 * Date param normalised to Y-m-d (or null).
	 *
	 * @param array  $params Params.
	 * @param string $key    Key.
	 * @return string|null
	 */
	protected function date( $params, $key ) {
		if ( ! $this->has( $params, $key ) ) {
			return null;
		}
		return $this->sanitize_date( $params[ $key ] );
	}

	/**
	 * Currency code param (3 uppercase letters) or default.
	 *
	 * @param array  $params  Params.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	protected function currency( $params, $key, $default = '' ) {
		if ( ! $this->has( $params, $key ) ) {
			return $default;
		}
		$value = $this->clean_currency( $params[ $key ] );
		return '' === $value ? $default : $value;
	}

	/* ---------------------------------------------------------------------
	 * Sanitization primitives (public so they work as `sanitize_callback`).
	 * ------------------------------------------------------------------ */

	/**
	 * Absolute integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public function arg_int( $value ) {
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Float.
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	public function arg_float( $value ) {
		return is_scalar( $value ) ? $this->to_float( $value ) : 0.0;
	}

	/**
	 * Single-line text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function arg_text( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Multi-line text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function arg_textarea( $value ) {
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	/**
	 * Boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function arg_bool( $value ) {
		return $this->to_bool( $value, false );
	}

	/**
	 * Date (Y-m-d) or empty string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function arg_date( $value ) {
		$date = $this->sanitize_date( $value );
		return null === $date ? '' : $date;
	}

	/**
	 * Currency code.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function arg_currency( $value ) {
		return $this->clean_currency( $value );
	}

	/**
	 * Key (lowercase, [a-z0-9_-]).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function arg_key( $value ) {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * ASC|DESC.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function arg_order( $value ) {
		$value = is_scalar( $value ) ? strtoupper( trim( (string) $value ) ) : '';
		return 'ASC' === $value ? 'ASC' : 'DESC';
	}

	/**
	 * Array passthrough (deep sanitization happens in the handler).
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	public function arg_array( $value ) {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Positive-integer validator for route ids.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_id( $value ) {
		return is_scalar( $value ) && absint( $value ) > 0;
	}

	/* ---------------------------------------------------------------------
	 * Conversion helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Locale tolerant float conversion. Delegates to MJERP_Helpers when available.
	 *
	 * @param mixed $value   Value.
	 * @param float $default Default when not parseable.
	 * @return float
	 */
	protected function to_float( $value, $default = 0.0 ) {
		if ( is_bool( $value ) ) {
			return $value ? 1.0 : 0.0;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( ! is_scalar( $value ) ) {
			return (float) $default;
		}
		if ( class_exists( 'MJERP_Helpers' ) && is_callable( array( 'MJERP_Helpers', 'to_float' ) ) ) {
			$result = MJERP_Helpers::to_float( $value );
			if ( is_numeric( $result ) ) {
				return (float) $result;
			}
			return (float) $default;
		}

		$str = trim( (string) $value );
		if ( '' === $str ) {
			return (float) $default;
		}
		$str = preg_replace( '/[^0-9,.\-]/', '', $str );
		if ( '' === $str || '-' === $str ) {
			return (float) $default;
		}
		$last_comma = strrpos( $str, ',' );
		$last_dot   = strrpos( $str, '.' );
		if ( false !== $last_comma && false !== $last_dot ) {
			if ( $last_comma > $last_dot ) {
				$str = str_replace( '.', '', $str );
				$str = str_replace( ',', '.', $str );
			} else {
				$str = str_replace( ',', '', $str );
			}
		} elseif ( false !== $last_comma ) {
			$str = str_replace( ',', '.', $str );
		}
		return is_numeric( $str ) ? (float) $str : (float) $default;
	}

	/**
	 * Tolerant boolean conversion.
	 *
	 * @param mixed $value   Value.
	 * @param bool  $default Default.
	 * @return bool
	 */
	protected function to_bool( $value, $default = false ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value ) {
			return (bool) $default;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value > 0;
		}
		if ( ! is_scalar( $value ) ) {
			return (bool) $default;
		}
		$str = strtolower( trim( (string) $value ) );
		if ( '' === $str ) {
			return (bool) $default;
		}
		if ( in_array( $str, array( '1', 'true', 'yes', 'on', 'si', 'sí', 'y', 's' ), true ) ) {
			return true;
		}
		if ( in_array( $str, array( '0', 'false', 'no', 'off', 'n', 'null' ), true ) ) {
			return false;
		}
		return (bool) $default;
	}

	/**
	 * Normalise a date to Y-m-d. Returns null when empty or invalid.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	protected function sanitize_date( $value ) {
		if ( null === $value || ! is_scalar( $value ) ) {
			return null;
		}
		$str = trim( (string) $value );
		if ( '' === $str ) {
			return null;
		}

		if ( class_exists( 'MJERP_Helpers' ) && is_callable( array( 'MJERP_Helpers', 'sanitize_date' ) ) ) {
			$result = MJERP_Helpers::sanitize_date( $str );
			if ( is_string( $result ) && '' !== $result ) {
				return $result;
			}
			if ( null === $result || '' === $result || false === $result ) {
				return null;
			}
		}

		// Y-m-d or Y-m-d H:i:s.
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $str, $m ) ) {
			if ( checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
			}
			return null;
		}
		// d/m/Y or d-m-Y (es-AR).
		if ( preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $str, $m ) ) {
			$year = (int) $m[3];
			if ( $year < 100 ) {
				$year += 2000;
			}
			if ( checkdate( (int) $m[2], (int) $m[1], $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1] );
			}
			return null;
		}
		$ts = strtotime( $str );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Currency code: 3 uppercase letters or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function clean_currency( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		return strlen( $code ) === 3 ? $code : '';
	}

	/**
	 * Loose comparison used to detect "unchanged" fields.
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	protected function same_value( $a, $b ) {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return $this->to_bool( $a ) === $this->to_bool( $b );
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 0.000001;
		}
		if ( ( null === $a || '' === $a ) && ( null === $b || '' === $b ) ) {
			return true;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return trim( (string) $a ) === trim( (string) $b );
		}
		return $a === $b;
	}

	/* ---------------------------------------------------------------------
	 * Settings / lookups
	 * ------------------------------------------------------------------ */

	/**
	 * Plugin settings (cached per request).
	 *
	 * @return array
	 */
	protected function settings() {
		if ( null === self::$settings_cache ) {
			self::$settings_cache = ( class_exists( 'MJERP_Settings' ) && is_callable( array( 'MJERP_Settings', 'all' ) ) )
				? (array) MJERP_Settings::all()
				: array();
		}
		return self::$settings_cache;
	}

	/**
	 * Clear the settings cache (after PUT /settings).
	 */
	protected function reset_settings_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	protected function setting( $key, $default = null ) {
		$settings = $this->settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Allowed currencies (uppercase).
	 *
	 * @return array
	 */
	protected function allowed_currencies() {
		$list = $this->setting( 'currencies', array( 'USD', 'ARS' ) );
		$out  = array();
		foreach ( (array) $list as $code ) {
			$code = $this->clean_currency( $code );
			if ( '' !== $code ) {
				$out[] = $code;
			}
		}
		if ( empty( $out ) ) {
			$out = array( 'USD', 'ARS' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Status definitions.
	 *
	 * @return array
	 */
	protected function statuses() {
		if ( class_exists( 'MJERP_Settings' ) && is_callable( array( 'MJERP_Settings', 'statuses' ) ) ) {
			return (array) MJERP_Settings::statuses();
		}
		return array(
			'borrador'    => array( 'label' => 'Borrador', 'color' => 'neutral' ),
			'en_transito' => array( 'label' => 'En tránsito', 'color' => 'warning' ),
			'recibida'    => array( 'label' => 'Recibida', 'color' => 'success' ),
			'cancelada'   => array( 'label' => 'Cancelada', 'color' => 'danger' ),
		);
	}

	/**
	 * Human label of a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	protected function status_label( $status ) {
		$statuses = $this->statuses();
		if ( isset( $statuses[ $status ]['label'] ) ) {
			return (string) $statuses[ $status ]['label'];
		}
		return (string) $status;
	}

	/**
	 * Display name of a user id.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	protected function user_name( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	/* ---------------------------------------------------------------------
	 * Purchases helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Load a full purchase or a 404 error.
	 *
	 * @param int $id Purchase id.
	 * @return array|WP_Error
	 */
	protected function load_purchase( $id ) {
		$id = absint( $id );
		if ( ! $id || ! class_exists( 'MJERP_Purchases_Repo' ) ) {
			return $this->fail( 'mjerp_not_found', __( 'La compra no existe.', 'mj-erp' ), 404 );
		}
		$purchase = MJERP_Purchases_Repo::get_full( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		if ( empty( $purchase ) || ! is_array( $purchase ) ) {
			return $this->fail( 'mjerp_not_found', __( 'La compra no existe.', 'mj-erp' ), 404, array( 'purchase_id' => $id ) );
		}
		return $purchase;
	}

	/**
	 * Whether a purchase can be edited (borrador / en_transito).
	 *
	 * @param array $purchase Purchase row.
	 * @return bool
	 */
	protected function is_editable( $purchase ) {
		if ( ! is_array( $purchase ) ) {
			return false;
		}
		if ( class_exists( 'MJERP_Purchases_Repo' ) && is_callable( array( 'MJERP_Purchases_Repo', 'is_editable' ) ) ) {
			return (bool) MJERP_Purchases_Repo::is_editable( $purchase );
		}
		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		return in_array( $status, array( 'borrador', 'en_transito' ), true );
	}

	/**
	 * Return true when editable, otherwise a 409 `mjerp_locked` error.
	 *
	 * @param array|WP_Error $purchase Purchase.
	 * @return true|WP_Error
	 */
	protected function require_editable( $purchase ) {
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		if ( $this->is_editable( $purchase ) ) {
			return true;
		}
		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		$label  = isset( $purchase['status_label'] ) && '' !== $purchase['status_label']
			? (string) $purchase['status_label']
			: $this->status_label( $status );
		$code   = isset( $purchase['code'] ) ? (string) $purchase['code'] : '';

		return $this->fail(
			'mjerp_locked',
			sprintf(
				/* translators: 1: purchase code, 2: status label */
				__( 'La compra %1$s está en estado «%2$s» y no se puede modificar. Solo se pueden editar las notas.', 'mj-erp' ),
				$code,
				function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label )
			),
			409,
			array(
				'purchase_id'     => isset( $purchase['id'] ) ? (int) $purchase['id'] : 0,
				'purchase_status' => $status,
			)
		);
	}

	/**
	 * Recalculate cached totals of a purchase (silently ignores errors).
	 *
	 * @param int $purchase_id Purchase id.
	 */
	protected function recalculate( $purchase_id ) {
		$purchase_id = absint( $purchase_id );
		if ( $purchase_id && class_exists( 'MJERP_Purchases_Repo' ) && is_callable( array( 'MJERP_Purchases_Repo', 'recalculate' ) ) ) {
			MJERP_Purchases_Repo::recalculate( $purchase_id );
		}
	}

	/**
	 * Common `id` route argument definition.
	 *
	 * @param string $description Description.
	 * @return array
	 */
	protected function id_arg( $description = '' ) {
		return array(
			'description'       => $description ? $description : __( 'Identificador numérico.', 'mj-erp' ),
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => array( $this, 'validate_id' ),
		);
	}

	/**
	 * Shorthand for an endpoint definition.
	 *
	 * @param string $methods  WP_REST_Server constant.
	 * @param string $callback Method name on this controller.
	 * @param array  $args     Args schema.
	 * @return array
	 */
	protected function endpoint( $methods, $callback, $args = array() ) {
		return array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => $args,
		);
	}
}
