<?php
/**
 * Gateway offline para cada método propio (uno por definición en mjpm_custom).
 * Funciona como "Transferencia bancaria": no cobra online, deja el pedido en
 * el estado elegido y muestra instrucciones en la página de gracias y el email.
 *
 * Solo "Activar" se guarda en la opción de WooCommerce del gateway (así el
 * interruptor de WooCommerce → Ajustes → Pagos funciona); el resto vive en
 * MJ Medios de Pago.
 */

defined('ABSPATH') || exit;

class MJPM_Gateway extends WC_Payment_Gateway {

	/** @var array Definición del método propio. */
	public $mjpm = [];

	/** @var string Instrucciones para el cliente. */
	public $instructions = '';

	public function __construct(array $def) {
		$this->mjpm               = $def;
		$this->id                 = (string) $def['id'];
		$this->has_fields         = false;
		$this->method_title       = (string) $def['title'];
		$this->method_description = 'Método propio creado con MJ Medios de Pago. Título, descripción, instrucciones, estado del pedido y ajuste de precio se editan en MJ → Medios de pago.';
		$this->supports           = ['products'];

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = (string) $def['title'];
		$this->description  = (string) $def['description'];
		$this->instructions = (string) $def['instructions'];
		$this->enabled      = $this->get_option('enabled', 'yes');

		add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
		add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'   => 'Activar',
				'type'    => 'checkbox',
				'label'   => 'Habilitar este medio de pago',
				'default' => 'yes',
			],
		];
	}

	public function admin_options() {
		echo '<h2>' . esc_html($this->method_title) . '</h2>';
		echo '<p>' . esc_html($this->method_description) . ' <a href="' . esc_url(admin_url('admin.php?page=mj-medios-pago')) . '">Ir a MJ Medios de Pago</a>.</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	public function process_payment($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			return ['result' => 'failure'];
		}
		$status = isset($this->mjpm['status']) ? (string) $this->mjpm['status'] : 'on-hold';
		$note   = 'Pedido con ' . $this->title . ' (MJ Medios de Pago).';

		if ($order->get_total() <= 0) {
			$order->payment_complete();
		} elseif ('processing' === $status || 'completed' === $status) {
			$order->payment_complete();
			if ('completed' === $status && 'completed' !== $order->get_status()) {
				$order->update_status('completed', $note);
			}
		} else {
			$order->update_status($status, $note);
		}

		if (function_exists('WC') && WC()->cart) {
			WC()->cart->empty_cart();
		}

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url($order),
		];
	}

	/** Página de gracias (checkout clásico). */
	public function thankyou_page($order_id) {
		$order = wc_get_order($order_id);
		if ('' !== $this->instructions) {
			echo wp_kses_post(wpautop(wptexturize($this->instructions)));
		}
		if ($order && class_exists('MJPM_Orders')) {
			$usd = MJPM_Orders::usd_total_text($order);
			if ('' !== $usd) {
				echo '<p><strong>' . esc_html($usd) . '</strong></p>';
			}
		}
	}

	/** Email al cliente (pedidos en espera, pendientes o procesando). */
	public function email_instructions($order, $sent_to_admin, $plain_text = false) {
		if (!$order instanceof WC_Order || $sent_to_admin || $this->id !== $order->get_payment_method()) {
			return;
		}
		if (!in_array($order->get_status(), ['on-hold', 'pending', 'processing'], true)) {
			return;
		}
		$usd = class_exists('MJPM_Orders') ? MJPM_Orders::usd_total_text($order) : '';
		if ('' === $this->instructions && '' === $usd) {
			return;
		}
		if ($plain_text) {
			if ('' !== $this->instructions) {
				echo esc_html(wp_strip_all_tags(wptexturize($this->instructions))) . "\n\n";
			}
			if ('' !== $usd) {
				echo esc_html($usd) . "\n\n";
			}
			return;
		}
		if ('' !== $this->instructions) {
			echo wp_kses_post(wpautop(wptexturize($this->instructions)));
		}
		if ('' !== $usd) {
			echo '<p><strong>' . esc_html($usd) . '</strong></p>';
		}
	}
}
