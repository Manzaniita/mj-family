<?php
/** Protección del cálculo nativo, cargada cuando WooCommerce ya tiene sus métodos. */

defined('ABSPATH') || exit;

class MJE_Native_Method extends WC_Shipping_Method {

	private $original;
	private $plugin_id;

	public function __construct($original, $plugin_id) {
		$this->original  = $original;
		$this->plugin_id = $plugin_id;
		foreach (['id', 'instance_id', 'enabled', 'title', 'method_title', 'method_description', 'supports'] as $property) {
			$this->$property = $original->$property;
		}
	}

	public function supports($feature) {
		return $this->original->supports($feature);
	}

	public function get_rates_for_package($package) {
		try {
			return $this->original->get_rates_for_package($package);
		} catch (\Throwable $error) {
			MJE_Native::failed_calculation($this->plugin_id, $error);
			return [];
		}
	}
}
