<?php
/**
 * WP-CLI: wp mjd rate [<valor>] [--note=<nota>] · wp mjd sync
 */

defined('ABSPATH') || exit;

class MJD_CLI {

	/**
	 * Muestra o cambia el tipo de cambio.
	 *
	 * ## OPTIONS
	 *
	 * [<valor>]
	 * : Nuevo TC (pesos por dólar). Sin valor, muestra el vigente.
	 *
	 * [--note=<nota>]
	 * : Nota para el historial.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mjd rate
	 *     wp mjd rate 1450 --note="blue venta"
	 */
	public function rate($args, $assoc_args) {
		if (empty($args)) {
			if (!MJD_Rate::is_set()) {
				WP_CLI::warning('No hay tipo de cambio cargado.');
				return;
			}
			$at = MJD_Rate::updated_at();
			WP_CLI::line(MJD_Rate::format_rate() . ($at ? ' (actualizado ' . wp_date('Y-m-d H:i', $at) . ')' : ''));
			return;
		}
		$note   = isset($assoc_args['note']) ? (string) $assoc_args['note'] : 'wp-cli';
		$result = MJD_Rate::update($args[0], $note, 0);
		if (is_wp_error($result)) {
			WP_CLI::error($result->get_error_message());
		}
		WP_CLI::success('Tipo de cambio guardado: ' . MJD_Rate::format_rate($result) . '. Recalculando precios…');
		$this->sync([], []);
	}

	/**
	 * Recalcula los precios en pesos de todos los productos con USD.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mjd sync
	 */
	public function sync($args, $assoc_args) {
		if (!MJD_Rate::is_set()) {
			WP_CLI::error('No hay tipo de cambio cargado.');
		}
		$state = MJD_Sync::start('cli');
		if ('running' !== $state['status']) {
			WP_CLI::success('No hay productos con precio USD.');
			return;
		}
		$progress = \WP_CLI\Utils\make_progress_bar('Recalculando', (int) $state['total']);
		while ('running' === $state['status']) {
			$before = (int) $state['done'];
			$state  = MJD_Sync::step(50);
			if (!empty($state['locked'])) {
				sleep(1);
				continue;
			}
			$progress->tick(max(0, (int) $state['done'] - $before));
		}
		$progress->finish();
		WP_CLI::success('Precios actualizados: ' . (int) $state['total'] . ' productos/variaciones.');
	}
}
