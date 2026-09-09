<?php
/**
 * Comandos WP-CLI: wp mjoi optimize | stats | reset | restore
 *
 * @package MJ_Optimizador_Imagenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MJOI_CLI {

	/**
	 * Optimiza imágenes.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : IDs de adjuntos concretos.
	 *
	 * [--all]
	 * : Todas las pendientes.
	 *
	 * [--errors]
	 * : Incluir también las que fallaron.
	 *
	 * [--webp]
	 * : Forzar conversión a WebP.
	 *
	 * [--limit=<n>]
	 * : Máximo de imágenes a procesar con --all (por defecto 500).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mjoi optimize --all
	 *     wp mjoi optimize 123 456 --webp
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Asociativos.
	 */
	public function optimize( $args, $assoc_args ) {
		$webp = isset( $assoc_args['webp'] ) ? true : null;
		$ids  = array_map( 'absint', $args );

		if ( empty( $ids ) ) {
			if ( ! isset( $assoc_args['all'] ) ) {
				WP_CLI::error( 'Indica IDs o usa --all.' );
			}
			$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 500;
			$ids   = MJOI_Bulk::pending_ids( $limit, isset( $assoc_args['errors'] ) );
		}

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No hay imágenes pendientes.' );
			return;
		}

		$ok   = 0;
		$fail = 0;
		foreach ( $ids as $id ) {
			$row = MJOI_Bulk::process_one( $id, $webp );
			if ( 'done' === $row['status'] ) {
				$ok++;
				WP_CLI::log( sprintf( '#%d %s: %s -> %s (%s%%)', $id, $row['title'], size_format( $row['before'] ), size_format( $row['after'] ), $row['percent'] ) );
			} else {
				$fail++;
				WP_CLI::warning( sprintf( '#%d %s: %s', $id, $row['title'], $row['message'] ) );
			}
		}
		WP_CLI::success( sprintf( '%d optimizadas, %d con error.', $ok, $fail ) );
	}

	/**
	 * Muestra estadísticas y recuentos.
	 */
	public function stats() {
		$st = MJOI_Bulk::stats_payload();
		$c  = MJOI_Bulk::counts();
		WP_CLI::log( sprintf( 'Motor: %s | WebP: %s', MJOI_Optimizer::library_label(), MJOI_Optimizer::supports_webp() ? 'sí' : 'no' ) );
		WP_CLI::log( sprintf( 'Imágenes optimizadas: %d | Antes: %s | Ahora: %s | Ahorro: %s (%s%%)', $st['images'], $st['before_human'], $st['after_human'], $st['saved_human'], $st['percent'] ) );
		WP_CLI::log( sprintf( 'Total: %d | Hechas: %d | Pendientes: %d | Error: %d | Omitidas: %d', $c['total'], $c['done'], $c['pending'], $c['error'], $c['skipped'] ) );
	}

	/**
	 * Marca imágenes como pendientes.
	 *
	 * ## OPTIONS
	 *
	 * [--errors]
	 * : Solo las que fallaron.
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Asociativos.
	 */
	public function reset( $args, $assoc_args ) {
		$n = MJOI_Processor::reset_statuses( isset( $assoc_args['errors'] ) );
		WP_CLI::success( sprintf( '%d marcas eliminadas.', $n ) );
	}

	/**
	 * Restaura una imagen desde su copia de seguridad.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID del adjunto.
	 *
	 * @param array $args Posicionales.
	 */
	public function restore( $args ) {
		$r = MJOI_Processor::restore( absint( $args[0] ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'Imagen restaurada.' );
	}
}
