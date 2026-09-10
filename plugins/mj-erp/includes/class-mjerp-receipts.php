<?php
/**
 * Comprobantes de los pagos de reventa, sobre la bóveda de MJ Medios de Pago.
 *
 * El ERP ya tenía el circuito: la reventa declara un pago desde el portal y el
 * pago no mueve el saldo hasta que el dueño lo confirma. Lo único que faltaba
 * era el archivo. Así que esto no inventa otro flujo: registra el contexto
 * 'erp_payment' en MJ Medios de Pago y conecta las dos decisiones.
 *
 *   aprobar el comprobante  →  MJERP_Consignment_Payments_Repo::confirm()
 *                              (el pago baja el saldo y se imputa a las ventas)
 *   rechazar el comprobante →  el pago queda declarado y el motivo se guarda
 *                              en su nota, visible en el ERP
 *
 * QUÉ SE LE PIDE
 *   Sale del permiso `payment_receipt` del revendedor (ajustes → por rol → ficha).
 *   En "heredar" usa la regla del gateway de WooCommerce equivalente según las
 *   equivalencias de MJ Medios de Pago, así el comprobante se configura una vez
 *   y vale en la tienda y en las reventas.
 *
 * Sin MJ Medios de Pago activo, nada de esto existe y el ERP sigue funcionando
 * igual que antes.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Receipts
 */
class MJERP_Receipts {

	/** Clave del contexto dentro de MJ Medios de Pago. */
	const CONTEXT = 'erp_payment';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'mjpm_receipt_contexts', array( __CLASS__, 'register_context' ) );
	}

	/**
	 * ¿Está MJ Medios de Pago con los comprobantes disponibles?
	 *
	 * @return bool
	 */
	public static function available() : bool {
		return class_exists( 'MJPM_Receipts' )
			&& is_callable( array( 'MJPM_Receipts', 'enabled' ) )
			&& MJPM_Receipts::enabled();
	}

	/* ------------------------------------------------------------------
	 * Contexto
	 * ---------------------------------------------------------------- */

	/**
	 * Registra el contexto de los pagos de reventa.
	 *
	 * @param array $contexts Registro de MJ Medios de Pago.
	 * @return array
	 */
	public static function register_context( $contexts ) {
		if ( ! is_array( $contexts ) ) {
			return $contexts;
		}
		$label = class_exists( 'MJERP_Settings' )
			? sprintf(
				/* translators: %s: reseller role label, e.g. "Reventa". */
				__( 'Pagos de %s', 'mj-erp' ),
				(string) MJERP_Settings::get( 'consignee_role_label', 'Reventa' )
			)
			: __( 'Pagos de reventa', 'mj-erp' );

		$contexts[ self::CONTEXT ] = array(
			'label'       => $label,
			'singular'    => __( 'pago', 'mj-erp' ),
			'object'      => array( __CLASS__, 'object' ),
			'requirement' => array( __CLASS__, 'requirement' ),
			'can_upload'  => array( __CLASS__, 'can_upload' ),
			'can_review'  => array( __CLASS__, 'can_review' ),
			'state'       => array( __CLASS__, 'state' ),
			'set_state'   => array( __CLASS__, 'set_state' ),
			'link'        => array( __CLASS__, 'link' ),
		);
		return $contexts;
	}

	/**
	 * Ficha del pago para la bandeja y los mails.
	 *
	 * @param int $payment_id Payment id.
	 * @return array|null
	 */
	public static function object( $payment_id ) {
		$payment = MJERP_Consignment_Payments_Repo::find( (int) $payment_id );
		if ( ! $payment ) {
			return null;
		}
		$consignee = MJERP_Consignees::find( (int) $payment['consignee_id'] );
		$user      = $consignee && (int) $consignee['user_id'] > 0 ? get_userdata( (int) $consignee['user_id'] ) : null;

		$name = $consignee && '' !== (string) $consignee['name'] ? (string) $consignee['name'] : __( 'Revendedor', 'mj-erp' );
		$mail = $consignee && ! empty( $consignee['email'] ) ? (string) $consignee['email'] : ( $user ? (string) $user->user_email : '' );
		$phone = $consignee && ! empty( $consignee['phone'] ) ? (string) $consignee['phone'] : '';

		return array(
			'id'           => (int) $payment['id'],
			/* translators: %d: payment id. */
			'title'        => sprintf( __( 'Pago #%d', 'mj-erp' ), (int) $payment['id'] ),
			'url'          => self::link( $payment_id ),
			'admin_url'    => self::admin_url( (int) $payment['consignee_id'] ),
			'customer'     => $name,
			'email'        => $mail,
			'phone'        => $phone,
			'total'        => (float) $payment['amount'],
			'currency'     => (string) $payment['currency'],
			'status_label' => (string) $payment['status_label'],
			'method'       => (string) $payment['method'],
			'method_label' => (string) $payment['method_label'],
			'date'         => (string) ( $payment['created_at'] ? $payment['created_at'] : $payment['payment_date'] ),
		);
	}

	/**
	 * Qué se le pide a ese pago.
	 *
	 * El permiso del revendedor manda; en "heredar" decide el gateway equivalente.
	 *
	 * @param int $payment_id Payment id.
	 * @return array
	 */
	public static function requirement( $payment_id ) {
		$payment = MJERP_Consignment_Payments_Repo::find( (int) $payment_id );
		if ( ! $payment ) {
			return array( 'mode' => 'no' );
		}
		/* Los ajustes de cuenta no son un pago: no hay nada que comprobar. */
		if ( MJERP_Consignment_Payments_Repo::KIND_PAYMENT !== (string) $payment['kind'] ) {
			return array( 'mode' => 'no' );
		}

		$consignee = MJERP_Consignees::find( (int) $payment['consignee_id'] );
		$user_id   = $consignee ? (int) $consignee['user_id'] : 0;
		$mode      = (string) MJERP_Permissions::value( 'payment_receipt', $user_id, $consignee, 'heredar' );

		$base = class_exists( 'MJPM_Mapping' )
			? MJPM_Mapping::requirement_for_erp( (string) $payment['method'] )
			: array();

		if ( 'heredar' !== $mode && '' !== $mode ) {
			$base['mode'] = $mode;
		}

		/* El estado del pedido no aplica acá: lo que mueve el pago es confirm(). */
		$base['on_ok']     = '';
		$base['on_reject'] = '';
		$base['attach']    = 0;

		/**
		 * Regla del comprobante para un pago de reventa.
		 *
		 * @param array $base    Regla resuelta.
		 * @param array $payment Pago.
		 */
		return (array) apply_filters( 'mjerp_payment_receipt_requirement', $base, $payment );
	}

	/**
	 * El pago tiene que ser del revendedor que está subiendo.
	 *
	 * @param int $payment_id Payment id.
	 * @param int $user_id    User id.
	 * @return bool
	 */
	public static function can_upload( $payment_id, $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$payment = MJERP_Consignment_Payments_Repo::find( (int) $payment_id );
		if ( ! $payment ) {
			return false;
		}
		$consignee = MJERP_Consignees::find_by_user( $user_id );
		return $consignee && (int) $consignee['id'] === (int) $payment['consignee_id'];
	}

	/**
	 * Quién revisa.
	 *
	 * Los pagos de reventa son del ERP, así que alcanza con administrar el ERP;
	 * el ajuste "quien administra MJ ERP" de MJ Medios de Pago decide si esa
	 * misma persona ve además los comprobantes de la tienda.
	 *
	 * @return bool
	 */
	public static function can_review() {
		if ( class_exists( 'MJERP_Capabilities' ) && is_callable( array( 'MJERP_Capabilities', 'current_user_can' ) ) ) {
			if ( MJERP_Capabilities::current_user_can() ) {
				return true;
			}
		}
		return class_exists( 'MJPM_Receipts' ) && MJPM_Receipts::can_review();
	}

	/**
	 * Estado de la compra, derivado de lo que ya sabe el ERP.
	 *
	 * Un pago confirmado es un comprobante aprobado: no hace falta una columna
	 * nueva para decir dos veces lo mismo. El rechazo sí sale de los archivos,
	 * porque el pago se queda declarado esperando otro.
	 *
	 * @param int $payment_id Payment id.
	 * @return string
	 */
	public static function state( $payment_id ) {
		$payment = MJERP_Consignment_Payments_Repo::find( (int) $payment_id );
		if ( ! $payment ) {
			return '';
		}
		if ( MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED === (string) $payment['status'] ) {
			return MJPM_Receipts::STATUS_OK;
		}

		$files    = MJPM_Receipts::for_object( self::CONTEXT, (int) $payment_id );
		$rejected = false;
		foreach ( $files as $file ) {
			if ( MJPM_Receipts::STATUS_PENDING === $file['status'] ) {
				return MJPM_Receipts::STATUS_PENDING;
			}
			if ( MJPM_Receipts::STATUS_NO === $file['status'] ) {
				$rejected = true;
			}
		}
		return $rejected ? MJPM_Receipts::STATUS_NO : MJPM_Receipts::STATUS_PENDING;
	}

	/**
	 * La decisión del comprobante es la decisión del pago.
	 *
	 * @param int    $payment_id Payment id.
	 * @param string $decision   aprobado | rechazado | pendiente.
	 * @param string $reason     Motivo.
	 * @return void
	 */
	public static function set_state( $payment_id, $decision, $reason = '' ) {
		$payment = MJERP_Consignment_Payments_Repo::find( (int) $payment_id );
		if ( ! $payment ) {
			return;
		}

		if ( MJPM_Receipts::STATUS_OK === $decision ) {
			MJERP_Consignment_Payments_Repo::confirm( (int) $payment_id );
			return;
		}

		if ( MJPM_Receipts::STATUS_NO === $decision && '' !== trim( (string) $reason ) ) {
			/* El pago sigue declarado; el motivo queda escrito donde se lo ve. */
			$note = trim( (string) $payment['note'] );
			$mark = sprintf(
				/* translators: %s: rejection reason. */
				__( 'Comprobante rechazado: %s', 'mj-erp' ),
				trim( (string) $reason )
			);
			if ( false === strpos( $note, $mark ) ) {
				$note = '' !== $note ? $note . ' · ' . $mark : $mark;
			}
			MJERP_Consignment_Payments_Repo::update( (int) $payment_id, array( 'note' => mb_substr( $note, 0, 255 ) ) );
		}
	}

	/**
	 * Dónde lo sube el revendedor: su portal.
	 *
	 * @param int $payment_id Payment id.
	 * @return string
	 */
	public static function link( $payment_id ) {
		unset( $payment_id );
		return class_exists( 'MJERP_Portal' ) ? MJERP_Portal::url() : '';
	}

	/**
	 * Link profundo a la ficha del revendedor en el ERP.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return string
	 */
	private static function admin_url( $consignee_id ) {
		if ( ! class_exists( 'MJERP_Frontend' ) || (int) $consignee_id <= 0 ) {
			return '';
		}
		return MJERP_Frontend::app_url() . '#/consignaciones/revendedor/' . (int) $consignee_id;
	}

	/* ------------------------------------------------------------------
	 * Para la REST del portal y del ERP
	 * ---------------------------------------------------------------- */

	/**
	 * Bloque de comprobante de un pago, para las respuestas de la API.
	 *
	 * @param array $payment Payment row (shaped).
	 * @return array|null null cuando ese pago no pide comprobante.
	 */
	public static function payload( array $payment ) {
		if ( ! self::available() || empty( $payment['id'] ) ) {
			return null;
		}
		$req = MJPM_Receipts::requirement( self::CONTEXT, (int) $payment['id'] );
		if ( empty( $req['asks'] ) ) {
			return null;
		}
		$files = MJPM_Receipts::for_object( self::CONTEXT, (int) $payment['id'] );
		$state = self::state( (int) $payment['id'] );

		return array(
			'context'     => self::CONTEXT,
			'object_id'   => (int) $payment['id'],
			'noun'        => MJPM_Receipts::noun(),
			'noun_title'  => MJPM_Receipts::noun( false, true ),
			'required'    => ! empty( $req['required'] ),
			'text'        => (string) $req['text'],
			'count'       => count( $files ),
			'max_files'   => (int) $req['files'],
			'state'       => $state,
			'state_label' => '' !== $state ? MJPM_Receipts::status_label( $state ) : '',
			'reason'      => self::last_reason( $files ),
			/* Solo para quien revisa: el link a la bandeja con ese pago filtrado. */
			'review_url'  => self::can_review() ? self::review_url( (int) $payment['id'] ) : '',
		);
	}

	/**
	 * Link a la bandeja de comprobantes, ya filtrada en ese pago.
	 *
	 * @param int $payment_id Payment id.
	 * @return string
	 */
	public static function review_url( $payment_id ) {
		if ( ! class_exists( 'MJPM_Receipts_Admin' ) ) {
			return '';
		}
		return MJPM_Receipts_Admin::page_url(
			array(
				'estado'   => 'todos',
				'contexto' => self::CONTEXT,
				's'        => (int) $payment_id,
			)
		);
	}

	/**
	 * ¿Este método de pago exige comprobante para este revendedor?
	 *
	 * Lo usa la REST del portal para no aceptar un pago obligatorio sin archivo.
	 *
	 * @param string     $method    Método del ERP.
	 * @param int        $user_id   User id.
	 * @param array|null $consignee Consignee row.
	 * @return bool
	 */
	public static function required_for( $method, $user_id = 0, $consignee = null ) : bool {
		if ( ! self::available() ) {
			return false;
		}
		$mode = (string) MJERP_Permissions::value( 'payment_receipt', $user_id, $consignee, 'heredar' );
		if ( 'heredar' !== $mode && '' !== $mode ) {
			return 'obligatorio' === $mode;
		}
		if ( ! class_exists( 'MJPM_Mapping' ) ) {
			return false;
		}
		$req = MJPM_Mapping::requirement_for_erp( (string) $method );
		return ! empty( $req['required'] );
	}

	/**
	 * Último motivo de rechazo de una lista de comprobantes.
	 *
	 * @param array $files Comprobantes.
	 * @return string
	 */
	private static function last_reason( array $files ) : string {
		$reason = '';
		foreach ( $files as $file ) {
			if ( MJPM_Receipts::STATUS_NO === $file['status'] && '' !== (string) $file['reason'] ) {
				$reason = (string) $file['reason'];
			}
		}
		return $reason;
	}
}
