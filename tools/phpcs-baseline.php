<?php
/**
 * Compara la salida de PHPCS contra la foto de referencia (el "baseline").
 *
 * POR QUÉ EXISTE ESTO
 *
 * PHPCS revisa el código con las reglas de WordPress, pero no entiende el
 * contexto. Marca, por ejemplo, "falta nonce" cada vez que se lee $_GET,
 * aunque se esté leyendo para mostrar un cartel y no para guardar nada. En
 * este repositorio eso da 512 avisos, y la enorme mayoría son de ese tipo.
 *
 * Si el CI cortara con esos 512, estaría en rojo desde el primer día y nadie
 * lo miraría más: una verificación que siempre falla no verifica nada.
 *
 * Entonces se guarda una foto de lo que hay hoy (tools/phpcs-baseline.json) y
 * el CI corta solo si aparece algo NUEVO. Lo viejo queda anotado como deuda,
 * a la vista, sin frenar el trabajo; lo nuevo no entra.
 *
 * CÓMO SE USA
 *
 *   php tools/phpcs-baseline.php comparar informe.json
 *       Corta con código 1 si hay avisos nuevos. Es lo que corre el CI.
 *
 *   php tools/phpcs-baseline.php actualizar informe.json
 *       Vuelve a sacar la foto. Se hace cuando se arregló deuda de verdad,
 *       nunca para tapar un aviso nuevo.
 *
 * CÓMO CUENTA
 *
 * La clave es archivo + regla, no el número de línea: si se agregan diez
 * líneas arriba, el mismo aviso se corre de lugar y no debería contar como
 * nuevo. Lo que se compara es cuántas veces aparece cada regla en cada
 * archivo. Si sube, hay algo nuevo; si baja, se arregló algo.
 */

if ('cli' !== PHP_SAPI) {
	exit(1);
}

$baseline_file = __DIR__ . '/phpcs-baseline.json';
$accion        = isset($argv[1]) ? $argv[1] : '';
$informe_file  = isset($argv[2]) ? $argv[2] : '';

if (!in_array($accion, ['comparar', 'actualizar'], true) || '' === $informe_file) {
	fwrite(STDERR, "Uso: php tools/phpcs-baseline.php [comparar|actualizar] informe.json\n");
	exit(2);
}

if (!is_readable($informe_file)) {
	fwrite(STDERR, "No se puede leer el informe: {$informe_file}\n");
	exit(2);
}

$informe = json_decode((string) file_get_contents($informe_file), true);
if (!is_array($informe) || !isset($informe['files'])) {
	fwrite(STDERR, "El informe no tiene el formato JSON de PHPCS.\n");
	exit(2);
}

/**
 * Convierte el informe de PHPCS en un mapa "archivo|regla" => cantidad.
 *
 * Las rutas se normalizan a barras normales y relativas a la raíz del
 * repositorio, para que la foto sirva igual en Windows y en el CI (Linux).
 *
 * @param array $informe Informe decodificado de PHPCS.
 * @return array<string,int>
 */
function mj_contar($informe) {
	$raiz   = str_replace('\\', '/', dirname(__DIR__)) . '/';
	$conteo = [];

	foreach ($informe['files'] as $ruta => $datos) {
		$ruta = str_replace('\\', '/', $ruta);
		if (0 === strpos($ruta, $raiz)) {
			$ruta = substr($ruta, strlen($raiz));
		}
		if (empty($datos['messages'])) {
			continue;
		}
		foreach ($datos['messages'] as $aviso) {
			$regla = isset($aviso['source']) ? $aviso['source'] : 'desconocida';
			$clave = $ruta . '|' . $regla;
			$conteo[$clave] = isset($conteo[$clave]) ? $conteo[$clave] + 1 : 1;
		}
	}

	ksort($conteo);
	return $conteo;
}

$actual = mj_contar($informe);

if ('actualizar' === $accion) {
	$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	file_put_contents($baseline_file, $json . "\n");
	$total = array_sum($actual);
	echo "Foto actualizada: {$total} avisos en " . count($actual) . " combinaciones de archivo y regla.\n";
	exit(0);
}

/* ------------------------------- Comparar ------------------------------- */

if (!is_readable($baseline_file)) {
	fwrite(STDERR, "Falta la foto de referencia. Generala con:\n");
	fwrite(STDERR, "  php tools/phpcs-baseline.php actualizar informe.json\n");
	exit(2);
}

$baseline = json_decode((string) file_get_contents($baseline_file), true);
if (!is_array($baseline)) {
	fwrite(STDERR, "La foto de referencia está rota.\n");
	exit(2);
}

$nuevos     = [];
$arreglados = [];

foreach ($actual as $clave => $cantidad) {
	$antes = isset($baseline[$clave]) ? (int) $baseline[$clave] : 0;
	if ($cantidad > $antes) {
		$nuevos[$clave] = $cantidad - $antes;
	} elseif ($cantidad < $antes) {
		$arreglados[$clave] = $antes - $cantidad;
	}
}

foreach ($baseline as $clave => $cantidad) {
	if (!isset($actual[$clave])) {
		$arreglados[$clave] = (int) $cantidad;
	}
}

if ($arreglados) {
	echo "Se arreglaron " . array_sum($arreglados) . " avisos que estaban anotados:\n";
	foreach ($arreglados as $clave => $cantidad) {
		list($archivo, $regla) = explode('|', $clave, 2);
		echo "  -{$cantidad}  {$archivo}\n      {$regla}\n";
	}
	echo "\n";
	echo "Si esto es correcto, actualizá la foto para que no vuelvan a colarse:\n";
	echo "  composer verificar:foto\n\n";
}

if (!$nuevos) {
	$total = array_sum($actual);
	echo "Sin avisos nuevos. Quedan {$total} anotados como deuda conocida.\n";
	exit(0);
}

echo "AVISOS NUEVOS (" . array_sum($nuevos) . "):\n\n";
foreach ($nuevos as $clave => $cantidad) {
	list($archivo, $regla) = explode('|', $clave, 2);
	echo "  +{$cantidad}  {$archivo}\n      {$regla}\n";
}
echo "\n";
echo "Estos no estaban antes. Revisalos: si son reales, arreglalos; si son\n";
echo "falsos positivos, dejá un phpcs:ignore con el motivo al lado.\n";
exit(1);
