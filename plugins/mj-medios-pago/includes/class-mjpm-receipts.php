<?php
/**
 * Comprobantes de pago: el registro, la regla de cada medio de pago, la
 * decisión de aprobar o rechazar, y los contextos que pueden pedir uno.
 *
 * CÓMO SE ORDENA
 *   Un comprobante es UN archivo. Una transferencia partida en dos son dos
 *   comprobantes y cada uno se aprueba o rechaza por separado.
 *   Que la COMPRA quede aprobada es otra decisión, siempre explícita de quien
 *   revisa: nunca se deduce de que haya un archivo aprobado, porque un
 *   comprobante por la mitad del monto no debería dar una compra por cobrada.
 *
 * PAGOS PARCIALES
 *   En los contextos que llevan saldo (los pedidos), al aprobar se dice cuánto
 *   entró: todo lo que falta, o una parte. Con todo, la compra queda "aprobado"
 *   y el pedido pasa al estado de pago confirmado. Con una parte, queda
 *   "parcial": se anota el importe, el cliente ve cuánto falta abonar y puede
 *   subir el próximo comprobante. Cada pago acreditado queda en el historial
 *   del pedido con su importe, su fecha y el mensaje para el cliente.
 *
 * CONTEXTOS
 *   Este plugin trae el contexto 'order' (pedidos de WooCommerce). Los demás
 *   plugins de la familia registran el suyo con el filtro 'mjpm_receipt_contexts'
 *   y así reusan bóveda, bandeja, mails, retención y permisos:
 *
 *     add_filter('mjpm_receipt_contexts', function ($ctx) {
 *         $ctx['mi_contexto'] = [
 *             'label'       => 'Lo mío',
 *             'object'      => fn($id) => [...],       // ficha normalizada
 *             'requirement' => fn($id) => [...],       // qué se pide para ese objeto
 *             'can_upload'  => fn($id, $user) => bool, // ¿es suyo?
 *             'can_review'  => fn() => bool,
 *             'state'       => fn($id) => 'pendiente', // estado de la compra
 *             'set_state'   => fn($id, $decision, $motivo) => null,
 *             'link'        => fn($id) => 'https://…', // dónde lo sube el cliente
 *         ];
 *         return $ctx;
 *     });
 */

defined('ABSPATH') || exit;

class MJPM_Receipts {

	/* Estado de revisión de un archivo y también de la compra. */
	const STATUS_PENDING = 'pendiente';
	const STATUS_OK      = 'aprobado';
	const STATUS_NO      = 'rechazado';

	/* Solo de la compra, nunca de un archivo: entró plata pero no alcanza. */
	const STATUS_PARTIAL = 'parcial';

	/** Meta del pedido donde vive la decisión a nivel compra. */
	const META_STATE  = '_mjpm_receipt_state';
	const META_REASON = '_mjpm_receipt_reason';
	const META_BY     = '_mjpm_receipt_by';
	const META_AT     = '_mjpm_receipt_at';
	const META_ASKED  = '_mjpm_receipt_asked';   // Cuándo se le pidió (para el plazo).
	const META_REMIND = '_mjpm_receipt_reminded';
	const META_PAID     = '_mjpm_paid';           // Total acreditado del pedido.
	const META_PAYMENTS = '_mjpm_payments';       // Historial: importe, tipo, mensaje, fecha y quién.

	private static $contexts = null;

	public static function init() {
		add_action(MJPM_Install::CRON_PURGE, [__CLASS__, 'purge']);
		add_action(MJPM_Install::CRON_REMIND, [__CLASS__, 'remind']);
		add_action(MJPM_Install::CRON_EXPIRE, [__CLASS__, 'expire']);
	}

	/* ------------------------------ Ajustes ------------------------------- */

	public static function enabled() {
		return (bool) MJPM_Settings::receipts('enabled') && MJPM_Install::table_exists();
	}

	/** Cómo se llama en la interfaz ("comprobante" por defecto, configurable). */
	public static function noun($plural = false, $capital = false) {
		$word = (string) MJPM_Settings::receipts($plural ? 'noun_plural' : 'noun');
		if ('' === $word) {
			$word = $plural ? 'comprobantes' : 'comprobante';
		}
		return $capital ? self::ucfirst($word) : $word;
	}

	private static function ucfirst($text) {
		return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
	}

	/**
	 * Importe con formato.
	 *
	 * Pasa por WooCommerce cuando está, y si no (una tienda de turnos sin
	 * WooCommerce) formatea a mano: los comprobantes funcionan igual sin tienda.
	 */
	public static function money($amount) {
		if (function_exists('wc_price')) {
			return MJPM_Methods::money($amount);
		}
		return '$ ' . number_format((float) $amount, 2, ',', '.');
	}

	/** ¿El usuario actual puede ver y decidir sobre comprobantes? */
	public static function can_review() {
		if (current_user_can('manage_options')) {
			return true;
		}
		if (MJPM_Settings::receipts('cap_shop') && current_user_can('manage_woocommerce')) {
			return true;
		}
		if (MJPM_Settings::receipts('cap_erp') && current_user_can('manage_mjerp')) {
			return true;
		}
		return false;
	}

	/** Estados de un archivo, con los nombres del panel (Comprobantes → Ajustes → Textos). */
	public static function statuses() {
		return [
			self::STATUS_PENDING => MJPM_Settings::receipt_text('statusPending'),
			self::STATUS_OK      => MJPM_Settings::receipt_text('statusOk'),
			self::STATUS_NO      => MJPM_Settings::receipt_text('statusNo'),
		];
	}

	/** Estados de la COMPRA: los de un archivo más el pago parcial. */
	public static function object_statuses() {
		return self::statuses() + [self::STATUS_PARTIAL => MJPM_Settings::receipt_text('statusPartial')];
	}

	public static function status_label($status) {
		$all = self::object_statuses();
		return isset($all[$status]) ? $all[$status] : (string) $status;
	}

	/* ----------------------------- Contextos ------------------------------ */

	/** Todos los contextos registrados. */
	public static function contexts() {
		if (null === self::$contexts) {
			$contexts = [
				'order' => [
					'label'       => 'Pedidos de la tienda',
					'singular'    => 'pedido',
					'object'      => [__CLASS__, 'order_object'],
					'requirement' => [__CLASS__, 'order_requirement'],
					'can_upload'  => [__CLASS__, 'order_can_upload'],
					'can_review'  => [__CLASS__, 'can_review'],
					'state'       => [__CLASS__, 'order_state'],
					'set_state'   => [__CLASS__, 'order_set_state'],
					'link'        => [__CLASS__, 'order_link'],
					'balance'     => [__CLASS__, 'order_balance'],
					'reason'      => [__CLASS__, 'order_reason'],
				],
			];
			if (!function_exists('wc_get_order')) {
				unset($contexts['order']);
			}

			/**
			 * Contextos que pueden pedir un comprobante.
			 *
			 * @param array $contexts Registro.
			 */
			$filtered       = apply_filters('mjpm_receipt_contexts', $contexts);
			self::$contexts = is_array($filtered) ? $filtered : $contexts;
		}
		return self::$contexts;
	}

	public static function context($key) {
		$all = self::contexts();
		$key = sanitize_key((string) $key);
		return isset($all[$key]) ? $all[$key] : null;
	}

	public static function context_label($key) {
		$ctx = self::context($key);
		return $ctx && isset($ctx['label']) ? (string) $ctx['label'] : (string) $key;
	}

	/** Cómo se llama UNA cosa de ese contexto ("pedido", "pago", "turno"). */
	public static function singular($key) {
		$ctx = self::context($key);
		return $ctx && !empty($ctx['singular']) ? (string) $ctx['singular'] : 'compra';
	}

	/** 'e' u 'a' para concordar con el singular del contexto ("este pedido" / "esta venta"). */
	public static function singular_gender($key) {
		$ctx = self::context($key);
		$word = $ctx && !empty($ctx['singular']) ? (string) $ctx['singular'] : 'compra';
		if (!empty($ctx['femenino'])) {
			return 'a';
		}
		return 'a' === mb_substr($word, -1) ? 'a' : 'e';
	}

	/** Llama a un callback del contexto, con valor por defecto si no existe. */
	private static function call($context, $hook, array $args = [], $fallback = null) {
		$ctx = self::context($context);
		if (!$ctx || !isset($ctx[$hook]) || !is_callable($ctx[$hook])) {
			return $fallback;
		}
		return call_user_func_array($ctx[$hook], $args);
	}

	/** Ficha normalizada del objeto al que está pegado el comprobante. */
	public static function object($context, $object_id) {
		$object = self::call($context, 'object', [(int) $object_id], null);
		if (!is_array($object)) {
			return null;
		}
		return wp_parse_args($object, [
			'id'           => (int) $object_id,
			'title'        => '',
			'url'          => '',
			'admin_url'    => '',
			'customer'     => '',
			'email'        => '',
			'phone'        => '',
			'total'        => 0.0,
			'currency'     => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'ARS',
			'status_label' => '',
			'method'       => '',
			'method_label' => '',
			'date'         => '',
		]);
	}

	public static function can_upload($context, $object_id, $user_id = null) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return (bool) self::call($context, 'can_upload', [(int) $object_id, $user_id], false);
	}

	public static function can_review_context($context) {
		return (bool) self::call($context, 'can_review', [], false);
	}

	/** Estado de la COMPRA ('' = todavía no se pidió nada). */
	public static function state($context, $object_id) {
		$state = (string) self::call($context, 'state', [(int) $object_id], '');
		return isset(self::object_statuses()[$state]) ? $state : '';
	}

	/** El mensaje o motivo de la última decisión sobre la compra ('' si no hay). */
	public static function state_reason($context, $object_id) {
		return (string) self::call($context, 'reason', [(int) $object_id], '');
	}

	/** ¿El contexto lleva saldo? Solo esos aceptan pagos parciales. */
	public static function supports_amounts($context) {
		$ctx = self::context($context);
		return $ctx && isset($ctx['balance']) && is_callable($ctx['balance']);
	}

	/**
	 * Cuánto se pagó y cuánto falta, con los textos listos para mostrar.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @return array|null { total, paid, due, totalText, paidText, dueText, history } o null sin saldo.
	 */
	public static function balance($context, $object_id) {
		if (!self::supports_amounts($context)) {
			return null;
		}
		$raw = self::call($context, 'balance', [(int) $object_id], null);
		if (!is_array($raw)) {
			return null;
		}
		$total = max(0.0, (float) (isset($raw['total']) ? $raw['total'] : 0));
		$paid  = max(0.0, (float) (isset($raw['paid']) ? $raw['paid'] : 0));
		$due   = max(0.0, round($total - $paid, 2));

		$history = [];
		foreach (isset($raw['history']) && is_array($raw['history']) ? $raw['history'] : [] as $entry) {
			if (!is_array($entry) || empty($entry['amount'])) {
				continue;
			}
			$history[] = [
				'amount'     => (float) $entry['amount'],
				'amountText' => self::money((float) $entry['amount']),
				'kind'       => isset($entry['kind']) ? (string) $entry['kind'] : '',
				'kindLabel'  => MJPM_Settings::receipt_text(self::STATUS_OK === (isset($entry['kind']) ? $entry['kind'] : '') ? 'kindTotal' : 'kindPartial'),
				'note'       => isset($entry['note']) ? (string) $entry['note'] : '',
				'date'       => !empty($entry['at']) ? mysql2date('c', (string) $entry['at']) : '',
			];
		}

		return [
			'total'     => $total,
			'paid'      => $paid,
			'due'       => $due,
			'totalText' => self::money($total),
			'paidText'  => self::money($paid),
			'dueText'   => self::money($due),
			'history'   => $history,
			/* Cómo se llama cada cosa en la tienda. */
			'labels'    => [
				'total'      => MJPM_Settings::receipt_text('total'),
				'paid'       => MJPM_Settings::receipt_text('paid'),
				'due'        => MJPM_Settings::receipt_text('due'),
				'paidInFull' => MJPM_Settings::receipt_text('paidInFull'),
				'history'    => MJPM_Settings::receipt_text('history'),
			],
		];
	}

	/** Lo que declaró el cliente en los comprobantes que falta revisar. */
	public static function declared_pending($context, $object_id) {
		$sum = 0.0;
		foreach (self::for_object($context, $object_id) as $row) {
			if (self::STATUS_PENDING === $row['status']) {
				$sum += (float) $row['amount'];
			}
		}
		return round($sum, 2);
	}

	/** Dónde sube el cliente el comprobante de ese objeto. */
	public static function link($context, $object_id) {
		$link = (string) self::call($context, 'link', [(int) $object_id], '');
		return '' !== $link ? $link : self::upload_url($context, $object_id);
	}

	/* ------------------------- Regla por método --------------------------- */

	/**
	 * Qué se le pide a un medio de pago, resolviendo los "heredar".
	 *
	 * @param string $method Id de gateway de WooCommerce.
	 * @return array { mode, text, files, hours, on_ok, on_reject, attach }
	 */
	public static function method_requirement($method) {
		$g   = MJPM_Settings::receipts();
		$cfg = MJPM_Settings::method(sanitize_key((string) $method));

		$mode = isset($cfg['receipt']) ? (string) $cfg['receipt'] : 'heredar';
		if ('heredar' === $mode || !array_key_exists($mode, MJPM_Settings::receipt_modes())) {
			$mode = (string) $g['mode'];
		}

		$text  = isset($cfg['receipt_text']) ? trim((string) $cfg['receipt_text']) : '';
		$files = isset($cfg['receipt_files']) ? (int) $cfg['receipt_files'] : 0;
		$hours = isset($cfg['receipt_hours']) ? (int) $cfg['receipt_hours'] : -1;

		$on_ok = isset($cfg['receipt_on_ok']) ? (string) $cfg['receipt_on_ok'] : 'heredar';
		if ('heredar' === $on_ok) {
			$on_ok = (string) $g['on_ok'];
		}

		$attach = isset($cfg['receipt_attach']) ? (int) $cfg['receipt_attach'] : -1;

		return self::fill_requirement([
			'mode'      => $mode,
			'text'      => $text,
			'files'     => $files,
			'hours'     => $hours,
			'on_ok'     => $on_ok,
			'on_reject' => (string) $g['on_reject'],
			'attach'    => $attach < 0 ? (int) $g['attach'] : $attach,
		]);
	}

	/** Completa una regla parcial con los ajustes generales. */
	public static function fill_requirement(array $in) {
		$g    = MJPM_Settings::receipts();
		$mode = isset($in['mode']) ? (string) $in['mode'] : 'no';
		if (!array_key_exists($mode, MJPM_Settings::receipt_modes())) {
			$mode = (string) $g['mode'];
		}
		$files = isset($in['files']) ? (int) $in['files'] : 0;
		$hours = isset($in['hours']) ? (int) $in['hours'] : -1;
		$text  = isset($in['text']) ? trim((string) $in['text']) : '';

		return [
			'mode'      => $mode,
			'required'  => 'obligatorio' === $mode,
			'asks'      => 'no' !== $mode,
			'text'      => '' !== $text ? $text : (string) $g['text'],
			'files'     => $files > 0 ? $files : max(1, (int) $g['max_files']),
			'hours'     => $hours >= 0 ? $hours : max(0, (int) $g['hours']),
			'on_ok'     => isset($in['on_ok']) ? (string) $in['on_ok'] : (string) $g['on_ok'],
			'on_reject' => isset($in['on_reject']) ? (string) $in['on_reject'] : (string) $g['on_reject'],
			'on_partial' => isset($in['on_partial']) ? (string) $in['on_partial'] : (string) $g['on_partial'],
			'attach'    => isset($in['attach']) ? (int) $in['attach'] : (int) $g['attach'],
			'max_bytes' => MJPM_Vault::max_bytes(),
			'types'     => MJPM_Vault::allowed_types(),
			'accept'    => MJPM_Vault::accept_attr(),
			'ask_amount'    => (bool) $g['ask_amount'],
			'ask_reference' => (bool) $g['ask_reference'],
			'ask_note'      => (bool) $g['ask_note'],
		];
	}

	/** Regla vigente para un objeto concreto. */
	public static function requirement($context, $object_id) {
		if (!self::enabled()) {
			return self::fill_requirement(['mode' => 'no']);
		}
		$req = self::call($context, 'requirement', [(int) $object_id], null);
		return is_array($req) ? self::fill_requirement($req) : self::fill_requirement(['mode' => 'no']);
	}

	/* --------------------------- Contexto 'order' -------------------------- */

	public static function order_object($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return null;
		}
		$name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		$date = $order->get_date_created();

		return [
			'id'           => $order->get_id(),
			'title'        => '#' . $order->get_order_number(),
			'url'          => $order->get_view_order_url(),
			'admin_url'    => $order->get_edit_order_url(),
			'customer'     => '' !== $name ? $name : ($order->get_billing_email() ? $order->get_billing_email() : 'Cliente'),
			'email'        => $order->get_billing_email(),
			'phone'        => $order->get_billing_phone(),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'status_label' => wc_get_order_status_name($order->get_status()),
			'method'       => $order->get_payment_method(),
			'method_label' => $order->get_payment_method_title(),
			'date'         => $date ? $date->date('c') : '',
		];
	}

	public static function order_requirement($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return ['mode' => 'no'];
		}
		$req = self::method_requirement($order->get_payment_method());

		/**
		 * Regla del comprobante para un pedido concreto.
		 *
		 * @param array    $req   Regla resuelta.
		 * @param WC_Order $order Pedido.
		 */
		return (array) apply_filters('mjpm_order_receipt_requirement', $req, $order);
	}

	/** El pedido es del usuario, o es de invitado y el link firmado lo habilita. */
	public static function order_can_upload($order_id, $user_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return false;
		}
		if ((int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === (int) $user_id) {
			return true;
		}
		return false;
	}

	public static function order_state($order_id) {
		$order = wc_get_order((int) $order_id);
		return $order instanceof WC_Order ? (string) $order->get_meta(self::META_STATE) : '';
	}

	/**
	 * Anota la decisión en el pedido y, si entró plata, la suma al saldo.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $decision aprobado | parcial | rechazado | pendiente.
	 * @param string $reason   Mensaje o motivo para el cliente.
	 * @param float  $amount   Lo que se acredita con esta decisión (aprobado o parcial).
	 */
	public static function order_set_state($order_id, $decision, $reason = '', $amount = 0.0) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return;
		}
		$reason = (string) $reason;
		$order->update_meta_data(self::META_STATE, $decision);
		$order->update_meta_data(self::META_REASON, $reason);
		$order->update_meta_data(self::META_BY, (string) get_current_user_id());
		$order->update_meta_data(self::META_AT, current_time('mysql'));

		$paying   = self::STATUS_OK === $decision || self::STATUS_PARTIAL === $decision;
		$credited = $paying ? max(0.0, round((float) $amount, 2)) : 0.0;
		if ($credited > 0) {
			$history   = $order->get_meta(self::META_PAYMENTS);
			$history   = is_array($history) ? $history : [];
			$history[] = [
				'amount' => $credited,
				'kind'   => $decision,
				'note'   => $reason,
				'at'     => current_time('mysql'),
				'by'     => get_current_user_id(),
			];
			$order->update_meta_data(self::META_PAYMENTS, $history);
			$order->update_meta_data(self::META_PAID, round((float) $order->get_meta(self::META_PAID) + $credited, 2));
		}
		$order->save();

		/*
		 * "A revisar" no es una decisión: pasa cuando entra un archivo nuevo. Ahí
		 * solo se anota el estado, nunca se mueve el pedido ni se deja una nota que
		 * diga que lo rechazaste.
		 */
		if (!$paying && self::STATUS_NO !== $decision) {
			return;
		}

		$req     = self::requirement('order', $order_id);
		$balance = self::balance('order', $order_id);
		if (self::STATUS_OK === $decision) {
			$target = (string) $req['on_ok'];
			$what   = sprintf('Pago aprobado: %s acreditados, pedido pagado completo.', self::money($credited));
		} elseif (self::STATUS_PARTIAL === $decision) {
			$target = (string) $req['on_partial'];
			$what   = sprintf(
				'Pago parcial aprobado: %s acreditados. Pagado %s de %s, falta abonar %s.',
				self::money($credited),
				$balance ? $balance['paidText'] : '',
				$balance ? $balance['totalText'] : '',
				$balance ? $balance['dueText'] : ''
			);
		} else {
			$target = (string) $req['on_reject'];
			$what   = sprintf('%s rechazado.', self::ucfirst(self::noun()));
		}
		if ('' !== $reason) {
			$what .= sprintf(' %s: %s', self::STATUS_NO === $decision ? 'Motivo' : 'Mensaje al cliente', $reason);
		}

		if ('' !== $target && $order->get_status() !== $target) {
			$order->update_status($target, $what);
			return;
		}
		$order->add_order_note($what);
	}

	/** Saldo de un pedido: el total (descontando reintegros) y lo acreditado. */
	public static function order_balance($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return null;
		}
		return [
			'total'   => (float) $order->get_total() - (float) $order->get_total_refunded(),
			'paid'    => (float) $order->get_meta(self::META_PAID),
			'history' => $order->get_meta(self::META_PAYMENTS),
		];
	}

	public static function order_reason($order_id) {
		$order = wc_get_order((int) $order_id);
		return $order instanceof WC_Order ? (string) $order->get_meta(self::META_REASON) : '';
	}

	public static function order_link($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return '';
		}
		/* Con cuenta: el detalle del pedido. Sin cuenta: el link firmado. */
		if ((int) $order->get_customer_id() > 0) {
			return $order->get_view_order_url();
		}
		return self::upload_url('order', $order_id);
	}

	/** Marca que a este pedido se le pidió un comprobante (arranca el plazo). */
	public static function mark_asked($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$req = self::requirement('order', $order->get_id());
		if (!$req['asks']) {
			return;
		}
		if ('' === (string) $order->get_meta(self::META_STATE)) {
			$order->update_meta_data(self::META_STATE, self::STATUS_PENDING);
		}
		if ('' === (string) $order->get_meta(self::META_ASKED)) {
			$order->update_meta_data(self::META_ASKED, current_time('mysql'));
		}
	}

	/* ------------------------------ Registro ------------------------------- */

	/**
	 * Guarda un comprobante ya validado por la bóveda.
	 *
	 * @param array $data Datos de la fila.
	 * @return int|WP_Error Id nuevo.
	 */
	public static function create(array $data) {
		global $wpdb;

		$now  = current_time('mysql');
		$row  = [
			'context'     => sanitize_key(isset($data['context']) ? $data['context'] : 'order'),
			'object_id'   => max(0, (int) (isset($data['object_id']) ? $data['object_id'] : 0)),
			'method'      => isset($data['method']) ? substr(sanitize_text_field((string) $data['method']), 0, 60) : '',
			'amount'      => isset($data['amount']) ? max(0, (float) $data['amount']) : 0,
			'currency'    => strtoupper(substr(isset($data['currency']) ? (string) $data['currency'] : 'ARS', 0, 3)),
			'reference'   => isset($data['reference']) ? substr(sanitize_text_field((string) $data['reference']), 0, 190) : '',
			'note'        => isset($data['note']) ? substr(sanitize_text_field((string) $data['note']), 0, 255) : '',
			'status'      => self::STATUS_PENDING,
			'reason'      => '',
			'file_name'   => isset($data['name']) ? substr((string) $data['name'], 0, 255) : '',
			'file_path'   => isset($data['path']) ? substr((string) $data['path'], 0, 255) : '',
			'file_type'   => isset($data['mime']) ? substr((string) $data['mime'], 0, 100) : '',
			'file_size'   => max(0, (int) (isset($data['size']) ? $data['size'] : 0)),
			'file_hash'   => isset($data['hash']) ? substr((string) $data['hash'], 0, 64) : '',
			'purged'      => 0,
			'uploaded_by' => max(0, (int) (isset($data['uploaded_by']) ? $data['uploaded_by'] : get_current_user_id())),
			'uploaded_ip' => substr(self::ip(), 0, 100),
			'reviewed_by' => 0,
			'reviewed_at' => null,
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		$ok = $wpdb->insert(MJPM_Install::table(), $row); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if (!$ok) {
			/* El cliente ya subió el archivo y ve un error genérico. Sin esto,
			   del lado de acá no queda nada para saber por qué. */
			MJ_Log::error('medios-pago', 'No se pudo registrar un comprobante subido', [
				'contexto' => isset($row['context']) ? $row['context'] : '',
				'objeto'   => isset($row['object_id']) ? (int) $row['object_id'] : 0,
				'archivo'  => isset($row['file_name']) ? $row['file_name'] : '',
				'motivo'   => $wpdb->last_error,
			]);
			return new WP_Error('mjpm_db_error', 'No se pudo registrar el comprobante.');
		}
		$id = (int) $wpdb->insert_id;

		/**
		 * Comprobante nuevo.
		 *
		 * @param int   $id  Id.
		 * @param array $row Fila guardada.
		 */
		do_action('mjpm_receipt_created', $id, $row);

		return $id;
	}

	public static function find($id) {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . MJPM_Install::table() . ' WHERE id = %d', (int) $id), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape($row) : null;
	}

	/**
	 * Listado con filtros.
	 *
	 * @param array $args context, object_id, status, search, page, per_page, orderby, order.
	 * @return array { rows, total, pages }
	 */
	public static function query(array $args = []) {
		global $wpdb;

		$args = wp_parse_args($args, [
			'context'   => '',
			'object_id' => 0,
			'status'    => '',
			'search'    => '',
			'page'      => 1,
			'per_page'  => 25,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		]);

		$where = ['1=1'];
		$prep  = [];

		if ('' !== (string) $args['context']) {
			$where[] = 'context = %s';
			$prep[]  = sanitize_key($args['context']);
		}
		if ((int) $args['object_id'] > 0) {
			$where[] = 'object_id = %d';
			$prep[]  = (int) $args['object_id'];
		}
		if ('' !== (string) $args['status'] && isset(self::statuses()[$args['status']])) {
			$where[] = 'status = %s';
			$prep[]  = (string) $args['status'];
		}
		if ('' !== trim((string) $args['search'])) {
			$like    = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
			$where[] = '(reference LIKE %s OR note LIKE %s OR file_name LIKE %s OR object_id = %d)';
			$prep[]  = $like;
			$prep[]  = $like;
			$prep[]  = $like;
			$prep[]  = (int) preg_replace('/\D+/', '', (string) $args['search']);
		}

		$allowed_orderby = ['id', 'created_at', 'status', 'amount', 'object_id'];
		$orderby         = in_array((string) $args['orderby'], $allowed_orderby, true) ? (string) $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper((string) $args['order']) ? 'ASC' : 'DESC';

		$per_page = max(1, min(200, (int) $args['per_page']));
		$page     = max(1, (int) $args['page']);
		$offset   = ($page - 1) * $per_page;

		$table = MJPM_Install::table();
		$sql   = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$query = $wpdb->prepare($sql, array_merge($prep, [$per_page, $offset])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode(' AND ', $where);
		$total     = (int) $wpdb->get_var($prep ? $wpdb->prepare($count_sql, $prep) : $count_sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return [
			'rows'  => array_map([__CLASS__, 'shape'], is_array($rows) ? $rows : []),
			'total' => $total,
			'pages' => (int) ceil($total / $per_page),
		];
	}

	/** Comprobantes de un objeto, del más viejo al más nuevo. */
	public static function for_object($context, $object_id) {
		$result = self::query([
			'context'   => $context,
			'object_id' => $object_id,
			'per_page'  => 200,
			'orderby'   => 'id',
			'order'     => 'ASC',
		]);
		return $result['rows'];
	}

	/** Cuántos hay a revisar (para el contador del menú). */
	public static function count_pending($context = '') {
		global $wpdb;
		$table = MJPM_Install::table();
		if ('' !== (string) $context) {
			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND context = %s", self::STATUS_PENDING, sanitize_key($context))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Cuántos por estado, para las pestañas de la bandeja. */
	public static function summary($context = '') {
		global $wpdb;
		$table = MJPM_Install::table();
		$sql   = "SELECT status, COUNT(*) AS total FROM {$table}";
		$prep  = [];
		if ('' !== (string) $context) {
			$sql   .= ' WHERE context = %s';
			$prep[] = sanitize_key($context);
		}
		$sql .= ' GROUP BY status';

		$rows = $wpdb->get_results($prep ? $wpdb->prepare($sql, $prep) : $sql, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = ['todos' => 0];
		foreach (array_keys(self::statuses()) as $status) {
			$out[$status] = 0;
		}
		foreach (is_array($rows) ? $rows : [] as $row) {
			$status = (string) $row['status'];
			if (isset($out[$status])) {
				$out[$status] = (int) $row['total'];
			}
			$out['todos'] += (int) $row['total'];
		}
		return $out;
	}

	/* ------------------------------ Decisión ------------------------------- */

	/**
	 * Aprueba o rechaza UN archivo.
	 *
	 * @param int    $id       Id del comprobante.
	 * @param string $decision aprobado | rechazado | pendiente.
	 * @param string $reason   Motivo (se le muestra al cliente si rechazás).
	 * @return array|WP_Error La fila actualizada.
	 */
	public static function review($id, $decision, $reason = '') {
		global $wpdb;

		$row = self::find($id);
		if (!$row) {
			return new WP_Error('mjpm_not_found', 'Ese comprobante no existe.');
		}
		if (!self::can_review_context($row['context'])) {
			return new WP_Error('mjpm_forbidden', 'No tenés permiso para revisar comprobantes.', ['status' => 403]);
		}
		$decision = (string) $decision;
		if (!isset(self::statuses()[$decision])) {
			return new WP_Error('mjpm_bad_decision', 'Decisión desconocida.');
		}

		$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MJPM_Install::table(),
			[
				'status'      => $decision,
				'reason'      => substr(sanitize_text_field((string) $reason), 0, 255),
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => self::STATUS_PENDING === $decision ? null : current_time('mysql'),
				'updated_at'  => current_time('mysql'),
			],
			['id' => (int) $row['id']]
		);
		if (false === $ok) {
			/* Aprobaste o rechazaste y no se guardó: el pedido queda como
			   estaba y es fácil creer que sí se guardó. */
			MJ_Log::error('medios-pago', 'No se pudo guardar la decisión sobre un comprobante', [
				'comprobante' => (int) $row['id'],
				'decision'    => $decision,
				'motivo'      => $wpdb->last_error,
			]);
			return new WP_Error('mjpm_db_error', 'No se pudo guardar la decisión.');
		}

		$fresh = self::find($row['id']);

		/**
		 * Se decidió sobre un comprobante.
		 *
		 * @param int    $id       Id.
		 * @param string $decision Decisión.
		 * @param array  $receipt  Fila.
		 */
		do_action('mjpm_receipt_reviewed', (int) $row['id'], $decision, $fresh);

		return $fresh;
	}

	/**
	 * Aprueba o rechaza la COMPRA. Es la decisión que mueve el pedido, manda el
	 * mail y cierra el circuito; los archivos se revisan aparte.
	 *
	 * En los contextos con saldo, aprobar lleva importe: sin importe (o si cubre
	 * lo que falta) es el pago total; con uno menor, la compra queda "parcial".
	 *
	 * @param string     $context    Contexto.
	 * @param int        $object_id  Objeto.
	 * @param string     $decision   aprobado | parcial | rechazado | pendiente.
	 * @param string     $reason     Motivo del rechazo o mensaje para el cliente.
	 * @param bool       $cascade    Aplicar la decisión a los archivos a revisar.
	 * @param float|null $amount     Lo que entró con este pago (null = todo lo que falta).
	 * @return bool|WP_Error
	 */
	public static function review_object($context, $object_id, $decision, $reason = '', $cascade = true, $amount = null) {
		if (!self::can_review_context($context)) {
			return new WP_Error('mjpm_forbidden', 'No tenés permiso para revisar comprobantes.', ['status' => 403]);
		}
		if (!isset(self::object_statuses()[$decision])) {
			return new WP_Error('mjpm_bad_decision', 'Decisión desconocida.');
		}
		$object = self::object($context, $object_id);
		if (!$object) {
			return new WP_Error('mjpm_not_found', 'No encontramos eso que querés revisar.');
		}

		$credited = 0.0;
		if (self::STATUS_OK === $decision || self::STATUS_PARTIAL === $decision) {
			$balance = self::balance($context, $object_id);
			if (!$balance) {
				if (self::STATUS_PARTIAL === $decision) {
					return new WP_Error('mjpm_no_partial', 'Acá no se pueden registrar pagos parciales.');
				}
			} else {
				if (self::STATUS_PARTIAL === $decision && (null === $amount || (float) $amount <= 0)) {
					return new WP_Error('mjpm_no_amount', 'Para un pago parcial poné cuánto entró.');
				}
				$credited = null === $amount ? $balance['due'] : max(0.0, round((float) $amount, 2));
				/* Lo que cubre el saldo es pago total, aunque se haya marcado como parte. */
				$decision = $credited + 0.005 >= $balance['due'] ? self::STATUS_OK : self::STATUS_PARTIAL;
			}
		}

		if ($cascade) {
			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MJPM_Install::table(),
				[
					/* Un archivo no queda "parcial": el comprobante es válido aunque no alcance. */
					'status'      => self::STATUS_PARTIAL === $decision ? self::STATUS_OK : $decision,
					'reason'      => substr(sanitize_text_field((string) $reason), 0, 255),
					'reviewed_by' => get_current_user_id(),
					'reviewed_at' => self::STATUS_PENDING === $decision ? null : current_time('mysql'),
					'updated_at'  => current_time('mysql'),
				],
				[
					'context'   => sanitize_key($context),
					'object_id' => (int) $object_id,
					'status'    => self::STATUS_PENDING,
				]
			);
		}

		self::call($context, 'set_state', [(int) $object_id, $decision, (string) $reason, $credited]);

		if (self::STATUS_PENDING !== $decision) {
			MJPM_Receipts_Mail::notify_customer($context, $object_id, $decision, $reason, $credited);
		}

		/**
		 * Se decidió sobre la compra entera.
		 *
		 * @param string $context   Contexto.
		 * @param int    $object_id Objeto.
		 * @param string $decision  Decisión (aprobado | parcial | rechazado | pendiente).
		 * @param string $reason    Motivo o mensaje.
		 * @param float  $credited  Lo acreditado con esta decisión (0 si no entró plata).
		 */
		do_action('mjpm_receipt_object_reviewed', $context, (int) $object_id, $decision, (string) $reason, $credited);

		return true;
	}

	/**
	 * Vuelve a poner la compra "a revisar" porque entró un archivo nuevo.
	 *
	 * No pasa por review_object() a propósito: eso es una decisión de quien
	 * revisa, y acá el que dispara es el cliente subiendo algo. Sin chequeo de
	 * permiso, sin mails y sin tocar los archivos ya revisados.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @return void
	 */
	public static function mark_pending($context, $object_id) {
		if (self::STATUS_OK === self::state($context, $object_id)) {
			return;
		}
		self::call($context, 'set_state', [(int) $object_id, self::STATUS_PENDING, '']);
	}

	/** Borra un comprobante (el archivo y la fila). */
	public static function delete($id) {
		global $wpdb;
		$row = self::find($id);
		if (!$row) {
			return new WP_Error('mjpm_not_found', 'Ese comprobante no existe.');
		}
		MJPM_Vault::delete($row['file_path']);
		$wpdb->delete(MJPM_Install::table(), ['id' => (int) $row['id']]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		do_action('mjpm_receipt_deleted', (int) $row['id'], $row);
		return true;
	}

	/** Se van los comprobantes cuando se borra el pedido al que pertenecen. */
	public static function delete_for_object($context, $object_id) {
		foreach (self::for_object($context, $object_id) as $row) {
			MJPM_Vault::delete($row['file_path']);
		}
		global $wpdb;
		$wpdb->delete(MJPM_Install::table(), ['context' => sanitize_key($context), 'object_id' => (int) $object_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------- Tareas -------------------------------- */

	/**
	 * Retención: pasados los días configurados se borra el ARCHIVO y queda el
	 * registro (importe, fecha, quién aprobó). Con 0 días no se borra nada.
	 */
	public static function purge() {
		global $wpdb;

		$days = (int) MJPM_Settings::receipts('keep_days');
		if ($days <= 0 || !MJPM_Install::table_exists()) {
			return 0;
		}
		$limit = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		$table = MJPM_Install::table();

		$rows = $wpdb->get_results($wpdb->prepare("SELECT id, file_path FROM {$table} WHERE purged = 0 AND created_at IS NOT NULL AND created_at < %s LIMIT 200", $limit), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done = 0;
		foreach (is_array($rows) ? $rows : [] as $row) {
			MJPM_Vault::delete($row['file_path']);
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				['purged' => 1, 'file_path' => '', 'updated_at' => current_time('mysql')],
				['id' => (int) $row['id']]
			);
			$done++;
		}
		return $done;
	}

	/**
	 * Recordatorio: a las X horas de pedirle el comprobante, si el pedido sigue
	 * sin ninguno, se le manda un mail. Con 0 horas no se manda nada.
	 */
	public static function remind() {
		$hours = (int) MJPM_Settings::receipts('remind_hours');
		if ($hours <= 0 || !self::enabled() || !function_exists('wc_get_orders')) {
			return 0;
		}

		$orders = wc_get_orders([
			'limit'      => 30,
			'meta_query' => [
				[
					'key'   => self::META_STATE,
					'value' => self::STATUS_PENDING,
				],
				[
					'key'     => self::META_REMIND,
					'compare' => 'NOT EXISTS',
				],
			],
			'date_created' => '<' . (time() - ($hours * HOUR_IN_SECONDS)),
		]);

		$sent = 0;
		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) {
				continue;
			}
			if (self::for_object('order', $order->get_id())) {
				continue; // Ya subió algo: no se le insiste.
			}
			MJPM_Receipts_Mail::remind_customer('order', $order->get_id());
			$order->update_meta_data(self::META_REMIND, current_time('mysql'));
			$order->save();
			$sent++;
		}
		return $sent;
	}

	/**
	 * Vencimiento: con "Al vencer el plazo → cancelar" prendido, los pedidos que
	 * esperan comprobante, pasado su plazo y sin ningún archivo subido, se
	 * cancelan (WooCommerce devuelve el stock solo) y el cliente recibe la nota.
	 * Un pedido con algo subido no se toca: lo decide quien revisa.
	 */
	public static function expire() {
		if (!(int) MJPM_Settings::receipts('expire') || !self::enabled() || !function_exists('wc_get_orders')) {
			return 0;
		}
		$since = (int) MJPM_Settings::receipts('expire_since');
		if ($since <= 0) {
			return 0;
		}
		$note  = str_replace('{comprobante}', self::noun(), (string) MJPM_Settings::receipts('expire_note'));
		$count = 0;
		$batch = max(1, (int) apply_filters('mjpm_expire_batch', 30));
		/*
		 * Solo pedidos creados desde que se prendió la opción, y recorriendo
		 * páginas: los que ya tienen algo subido (esperando revisión) no pueden
		 * tapar a los vencidos que vienen después.
		 */
		for ($page = 1; $page <= 10; $page++) {
			$orders = wc_get_orders([
				'limit'        => $batch,
				'paged'        => $page,
				'status'       => (array) apply_filters('mjpm_expire_statuses', ['on-hold', 'pending']),
				'date_created' => '>' . $since,
				'meta_query'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- acotado por estado, fecha y tope.
					[
						'key'   => self::META_STATE,
						'value' => self::STATUS_PENDING,
					],
				],
				'orderby'      => 'date',
				'order'        => 'ASC',
			]);
			if (!$orders) {
				break;
			}
			$count += self::expire_batch($orders, $note);
			if (count($orders) < $batch) {
				break;
			}
		}
		return $count;
	}

	/** Cancela los vencidos de un lote. → cuántos. */
	private static function expire_batch(array $orders, $note) {
		$count = 0;
		foreach ($orders as $order) {
			if (!$order instanceof WC_Order || self::for_object('order', $order->get_id())) {
				continue; // Ya subió algo: lo decide quien revisa.
			}
			$deadline = self::deadline('order', $order->get_id());
			if ('' === $deadline || strtotime($deadline) > time()) {
				continue;
			}
			if (!apply_filters('mjpm_expire_order', true, $order)) {
				continue;
			}
			$order->update_status('cancelled', 'Venció el plazo para subir el ' . self::noun() . '.');
			if ('' !== trim($note)) {
				$order->add_order_note($note, 1); // Nota para el cliente: WooCommerce se la manda por mail.
			}
			$count++;
			do_action('mjpm_order_expired', $order);
		}
		return $count;
	}

	/** Fecha límite para subirlo, o '' si no hay plazo. */
	public static function deadline($context, $object_id) {
		$req = self::requirement($context, $object_id);
		if ((int) $req['hours'] <= 0) {
			return '';
		}
		$object = self::object($context, $object_id);
		$from   = $object && '' !== (string) $object['date'] ? strtotime((string) $object['date']) : 0;
		if ($from <= 0) {
			return '';
		}
		return gmdate('c', $from + ((int) $req['hours'] * HOUR_IN_SECONDS));
	}

	/* ------------------------- Links firmados ----------------------------- */

	/**
	 * Token autocontenido para que alguien sin cuenta suba el comprobante de su
	 * compra: contexto, objeto y vencimiento, firmados con la sal del sitio. No
	 * se guarda nada, y cambiar la sal invalida todos los links de golpe.
	 */
	public static function upload_token($context, $object_id, $days = null) {
		$days    = null === $days ? (int) MJPM_Settings::receipts('link_days') : (int) $days;
		$expires = time() + (max(1, $days) * DAY_IN_SECONDS);
		$payload = sanitize_key($context) . '|' . (int) $object_id . '|' . $expires;
		return self::b64($payload . '|' . self::signature($payload));
	}

	/** Devuelve ['context','object_id'] si el token es válido y no venció. */
	public static function verify_token($token) {
		$raw = self::unb64((string) $token);
		if ('' === $raw) {
			return null;
		}
		$parts = explode('|', $raw);
		if (4 !== count($parts)) {
			return null;
		}
		list($context, $object_id, $expires, $signature) = $parts;
		$payload = $context . '|' . $object_id . '|' . $expires;
		if (!hash_equals(self::signature($payload), (string) $signature)) {
			return null;
		}
		if ((int) $expires < time()) {
			return null;
		}
		if (!self::context($context)) {
			return null;
		}
		return ['context' => sanitize_key($context), 'object_id' => (int) $object_id];
	}

	private static function signature($payload) {
		return hash_hmac('sha256', 'mjpm_receipt|' . $payload, wp_salt('mjpm_receipts'));
	}

	private static function b64($raw) {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private static function unb64($encoded) {
		$encoded = strtr(preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $encoded), '-_', '+/');
		$decoded = base64_decode($encoded, true);
		return false === $decoded ? '' : $decoded;
	}

	/** URL pública donde se sube con el link firmado. */
	public static function upload_url($context, $object_id) {
		return add_query_arg('mjpm_comprobante', self::upload_token($context, $object_id), home_url('/'));
	}

	/* ------------------------------ Helpers ------------------------------- */

	/**
	 * URL del archivo (pasa por el endpoint que valida permiso).
	 *
	 * Con sesión lleva el nonce de la REST: un link o un <img> no mandan la
	 * cabecera X-WP-Nonce, y sin nonce WordPress atiende a la REST como si no
	 * hubiera nadie logueado, así que el permiso fallaría siempre.
	 *
	 * @param array  $row      Comprobante.
	 * @param bool   $download Descargar en vez de ver.
	 * @param string $token    Token del link firmado (quien compró sin cuenta).
	 * @return string
	 */
	public static function file_url($row, $download = false, $token = '') {
		if (!is_array($row) || empty($row['id'])) {
			return '';
		}
		$args = ['id' => (int) $row['id']];
		if ($download) {
			$args['descargar'] = 1;
		}
		if ('' !== (string) $token) {
			$args['token'] = rawurlencode((string) $token);
		} elseif (is_user_logged_in()) {
			$args['_wpnonce'] = wp_create_nonce('wp_rest');
		}
		return add_query_arg($args, rest_url(MJPM_Receipts_REST::NS . '/comprobantes/archivo'));
	}

	/** Fila cruda → fila normalizada para la interfaz. */
	public static function shape(array $row) {
		$status = isset($row['status']) ? (string) $row['status'] : self::STATUS_PENDING;
		$amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;

		return [
			'id'            => (int) $row['id'],
			'context'       => (string) $row['context'],
			'context_label' => self::context_label($row['context']),
			'object_id'     => (int) $row['object_id'],
			'method'        => (string) $row['method'],
			'amount'        => $amount,
			'currency'      => (string) $row['currency'],
			'amount_text'   => $amount > 0 ? MJPM_Receipts::money($amount) : '',
			'reference'     => (string) $row['reference'],
			'note'          => (string) $row['note'],
			'status'        => $status,
			'status_label'  => self::status_label($status),
			'reason'        => (string) $row['reason'],
			'file_name'     => (string) $row['file_name'],
			'file_path'     => (string) $row['file_path'],
			'file_type'     => (string) $row['file_type'],
			'file_size'     => (int) $row['file_size'],
			'file_hash'     => (string) $row['file_hash'],
			'size_text'     => (int) $row['file_size'] > 0 ? size_format((int) $row['file_size'], 1) : '',
			'is_image'      => MJPM_Vault::is_viewable_image($row['file_type']),
			'purged'        => !empty($row['purged']),
			'available'     => empty($row['purged']) && MJPM_Vault::exists($row['file_path']),
			'uploaded_by'   => (int) $row['uploaded_by'],
			'reviewed_by'   => (int) $row['reviewed_by'],
			'reviewed_at'   => !empty($row['reviewed_at']) ? mysql2date('c', $row['reviewed_at']) : '',
			'created_at'    => !empty($row['created_at']) ? mysql2date('c', $row['created_at']) : '',
		];
	}

	private static function ip() {
		$keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
		foreach ($keys as $key) {
			if (empty($_SERVER[$key])) {
				continue;
			}
			$raw = explode(',', sanitize_text_field(wp_unslash($_SERVER[$key])));
			$ip  = filter_var(trim($raw[0]), FILTER_VALIDATE_IP);
			if ($ip) {
				return (string) $ip;
			}
		}
		return '';
	}
}
