<?php
/**
 * Un correo que ya tiene su propio plugin instalado en la tienda.
 *
 * MJ Envíos no le pide las credenciales de nuevo ni reimplementa su API: lee la
 * configuración que ya cargaste en el plugin del correo, se queda con la tarifa
 * que ese plugin calcula y le aplica encima las reglas de la casa (envío gratis,
 * excluidos, margen, redondeo, nombre, orden). El plugin del correo sigue siendo
 * el dueño de la conexión; MJ Envíos es el que arma la lista que ve el cliente.
 *
 * Todo adaptador es opcional: si el plugin del correo no está instalado, este
 * archivo no registra nada y MJ Envíos funciona exactamente como antes. Ningún
 * plugin de la familia MJ depende de que estos existan.
 *
 * Lo mínimo que tiene que declarar un adaptador:
 *
 *   id()          clave corta y estable ('andreani')
 *   label()       cómo se llama para el que mira el panel
 *   method_id()   el id del método de envío de WooCommerce que registra su
 *                 plugin, que es como se reconocen sus tarifas en el carrito
 *   available()   si su plugin está activo en esta tienda
 *
 * Lo demás tiene una respuesta razonable por defecto y se pisa cuando el plugin
 * del correo sabe hacer algo más (sucursales, dónde guardar la elegida,
 * seguimiento, datos de la cuenta para mostrar en el panel).
 */

defined('ABSPATH') || exit;

abstract class MJE_Native_Plugin {

	/** Clave corta y estable. Es la que se guarda en el método y en el pedido. */
	abstract public function id();

	/** Nombre para el panel. */
	abstract public function label();

	/** El id del método de envío de WooCommerce que registra su plugin. */
	abstract public function method_id();

	/** ¿Está instalado y activo el plugin del correo? */
	abstract public function available();

	/**
	 * ¿Tiene credenciales cargadas y andando?
	 *
	 * Por defecto alcanza con que el plugin esté: un adaptador que sepa mirar
	 * la conexión lo pisa para que el panel pueda avisar "falta conectarlo".
	 */
	public function connected() {
		return $this->available();
	}

	/** Link a la pantalla del plugin del correo, para no duplicar su configuración. */
	public function settings_url() {
		return '';
	}

	/**
	 * Lo que el panel muestra de la cuenta ya configurada.
	 *
	 * → [['label' => 'Titular', 'value' => 'MJ Importaciones'], ...]
	 * Nunca devuelve credenciales: son datos para confirmar de un vistazo que la
	 * cuenta conectada es la que corresponde.
	 */
	public function summary() {
		return [];
	}

	/**
	 * El servicio (modo de entrega) de una tarifa suya.
	 *
	 * Por defecto es lo que queda del id de la tarifa después del id del método,
	 * que es como lo arma la mayoría de los plugins.
	 */
	public function service_of($rate_id) {
		$rate_id = (string) $rate_id;
		$method  = $this->method_id();
		if ('' !== $method && 0 === strpos($rate_id, $method)) {
			$rate_id = substr($rate_id, strlen($method));
		}
		return trim(str_replace(['-', '_', ':'], ' ', $rate_id));
	}

	/** Cómo se lee ese servicio. Por defecto, el mismo código. */
	public function service_label($service) {
		return (string) $service;
	}

	/**
	 * ¿Ese servicio entrega en una sucursal del correo?
	 *
	 * Se pasa también la tarifa, porque varios plugins lo dicen en su propia
	 * meta y leerlo de ahí es más confiable que deducirlo del nombre.
	 */
	public function is_branch_service($service, $rate = null) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí miran la tarifa.
		$service = MJE_Settings::key_of((string) $service);
		return false !== strpos($service, 'sucursal') || false !== strpos($service, 'branch') || false !== strpos($service, 'agency');
	}

	/**
	 * ¿Esta tarifa suya es de relleno?
	 *
	 * Varios plugins, cuando NO pueden cotizar, igual emiten una tarifa de $0 con
	 * un texto de aviso en el nombre ("completá la dirección", "supera las
	 * medidas"). Para WooCommerce es una tarifa como cualquier otra, y si MJ
	 * Envíos la tomara en serio ofrecería ese envío GRATIS. Un adaptador que
	 * conoce esas tarifas las declara acá y el puente las ignora.
	 *
	 * → '' si es una tarifa real; si no, el motivo por el que no sirve.
	 */
	public function placeholder_reason($rate_id, $rate = null) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí las reconocen.
		return '';
	}

	/**
	 * Las sucursales del correo, para que el cliente elija a dónde va.
	 *
	 * → [['code','name','address','city','state','postcode','hours','lat','lon'], ...]
	 */
	public function branches($postcode = '') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí traen sucursales.
		return [];
	}

	/**
	 * Deja anotada la sucursal elegida donde su plugin la busca.
	 *
	 * Se llama ANTES de pedir las tarifas: varios correos cotizan distinto según
	 * la sucursal de destino, y algunos la leen de su propia sesión.
	 */
	public function remember_branch($code) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí la anotan.
	}

	/**
	 * Escribe en el pedido lo que su plugin necesita para reconocerlo como suyo.
	 *
	 * Sin esto, el pedido sale con el método de MJ Envíos y la pantalla del
	 * correo no lo ve: no se le puede dar de alta el envío ni imprimir el
	 * rótulo desde su propio panel.
	 */
	public function stamp_order($order, $rate_id, $service, array $branch = []) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí escriben en el pedido.
	}

	/** Lo mismo, sobre la línea de envío del pedido (la que guarda la tarifa). */
	public function stamp_item($item, $rate_id) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí escriben en la línea.
	}

	/** Link de seguimiento con el número del correo, si su plugin publica uno. */
	public function tracking_url($code) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- La firma la usan los adaptadores que sí arman el link.
		return '';
	}

	/* ------------------------------ Auxiliares --------------------------- */

	/**
	 * Las instancias de su método que hay configuradas en las zonas de envío.
	 *
	 * → [['zone' => 'Envío Todo el País', 'zone_id' => 1, 'instance_id' => 2,
	 *     'enabled' => true, 'url' => '...'], ...]
	 *
	 * Es lo que le permite al panel avisar "está conectado pero no está en
	 * ninguna zona, por eso no aparece en el checkout", que es el motivo más
	 * común de que un correo conectado no cotice.
	 */
	public function zone_instances() {
		if (!class_exists('WC_Shipping_Zones')) {
			return [];
		}
		$out   = [];
		$zones = WC_Shipping_Zones::get_zones();

		/* La zona 0 ("Resto del mundo") no viene en get_zones() y también cuenta. */
		$zones[] = ['id' => 0, 'zone_name' => '', 'shipping_methods' => []];

		foreach ($zones as $zone_data) {
			$zone_id = isset($zone_data['id']) ? (int) $zone_data['id'] : 0;
			$zone    = WC_Shipping_Zones::get_zone($zone_id);
			if (!$zone) {
				continue;
			}
			foreach ($zone->get_shipping_methods(false) as $method) {
				if (!is_object($method) || $this->method_id() !== $method->id) {
					continue;
				}
				$out[] = [
					'zone'        => $zone->get_zone_name(),
					'zone_id'     => $zone_id,
					'instance_id' => (int) $method->instance_id,
					'enabled'     => 'yes' === $method->enabled,
					'url'         => admin_url('admin.php?page=wc-settings&tab=shipping&instance_id=' . (int) $method->instance_id),
				];
			}
		}
		return $out;
	}

	/** ¿Está en alguna zona y encendido? Si no, sus tarifas no llegan nunca. */
	public function in_any_zone() {
		foreach ($this->zone_instances() as $instance) {
			if ($instance['enabled']) {
				return true;
			}
		}
		return false;
	}
}
