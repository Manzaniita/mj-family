<?php
/**
 * Comprueba la documentación de desarrollo sin dependencias ni escrituras.
 * Uso: php tools/verificar-documentacion.php [raíz de una copia para pruebas]
 *
 * Admite enlaces Markdown inline sin títulos, encabezados ATX y anclas HTML.
 * No consulta enlaces externos ni interpreta todo el estándar CommonMark.
 */

$raiz = realpath(isset($argv[1]) ? $argv[1] : dirname(__DIR__));
if (false === $raiz || !is_dir($raiz)) {
    fwrite(STDERR, "No existe la raíz a verificar.\n");
    exit(1);
}

$errores = array();
$documentos = array();
$omitidas = array('.git', '.claude', '.codex', '.codex-log', 'vendor', 'node_modules', 'dist', 'releases');
$recorrer = function ($carpeta) use (&$recorrer, &$documentos, $omitidas) {
    foreach (new DirectoryIterator($carpeta) as $entrada) {
        if ($entrada->isDot() || $entrada->isLink()) {
            continue;
        }
        if ($entrada->isDir()) {
            if (!in_array($entrada->getFilename(), $omitidas, true)) {
                $recorrer($entrada->getPathname());
            }
        } elseif ('md' === strtolower($entrada->getExtension()) && 'AGENTS.override.md' !== $entrada->getFilename()) {
            $documentos[] = $entrada->getPathname();
        }
    }
};
$recorrer($raiz);
sort($documentos);

foreach (array('AGENTS.md', 'README.md', 'PUBLICAR.md', 'CONFIGURACION.md', 'TIENDAS.md', 'PRUEBAS-EN-LA-TIENDA.md', 'AUDITORIA.md') as $nombre) {
    if (!is_file($raiz . '/' . $nombre)) {
        $errores[] = 'Falta el documento de entrada: ' . $nombre;
    }
}

// Conservar líneas para informar ubicaciones; ignorar ejemplos en bloques cercados.
function lineas_documento($archivo) {
    $lineas = preg_split('/\r\n|\n|\r/', file_get_contents($archivo));
    $cerca = '';
    $largo = 0;
    foreach ($lineas as &$linea) {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})(.*)$/', $linea, $coincidencia)) {
            if ('' === $cerca) {
                $cerca = $coincidencia[1][0];
                $largo = strlen($coincidencia[1]);
            } elseif ($cerca === $coincidencia[1][0] && strlen($coincidencia[1]) >= $largo && '' === trim($coincidencia[2])) {
                $cerca = '';
            }
            $linea = '';
        } elseif ('' !== $cerca) {
            $linea = '';
        }
    }
    unset($linea);
    return $lineas;
}

function anclas_documento($archivo) {
    static $cache = array();
    if (isset($cache[$archivo])) {
        return $cache[$archivo];
    }
    $anclas = array();
    $usadas = array();
    foreach (lineas_documento($archivo) as $linea) {
        if (preg_match_all('/<(?:a|h[1-6])\b[^>]*\bid=["\']([^"\']+)["\']/i', $linea, $ids)) {
            $anclas = array_merge($anclas, $ids[1]);
        }
        if (!preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $linea, $titulo)) {
            continue;
        }
        $texto = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $titulo[1]);
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8');
        $texto = strtolower(strtr($texto, array('Á'=>'á', 'É'=>'é', 'Í'=>'í', 'Ó'=>'ó', 'Ú'=>'ú', 'Ü'=>'ü', 'Ñ'=>'ñ')));
        $base = str_replace(' ', '-', preg_replace('/[^\p{L}\p{M}\p{N}_\- ]/u', '', $texto));
        $id = $base;
        $sufijo = 0;
        while (isset($usadas[$id])) {
            $id = $base . '-' . (++$sufijo);
        }
        $usadas[$id] = true;
        $anclas[] = $id;
    }
    $cache[$archivo] = $anclas;
    return $anclas;
}

$enlaces = 0;
$guias = 0;
$prefijo = rtrim(str_replace('\\', '/', $raiz), '/') . '/';
foreach ($documentos as $archivo) {
    $relativo = substr(str_replace('\\', '/', $archivo), strlen($prefijo));
    if ('AGENTS.md' === basename($archivo)) {
        $guias++;
        $bytes = filesize($archivo);
        if (0 === $bytes || $bytes > 8192) {
            $errores[] = $relativo . ': mantener la guía entre 1 y 8192 bytes; detalles en documentos enlazados.';
        }
        $total = 0;
        $carpeta = dirname($archivo);
        do {
            $guia = $carpeta . '/AGENTS.md';
            $total += is_file($guia) ? filesize($guia) : 0;
            if ($carpeta === $raiz) {
                break;
            }
            $carpeta = dirname($carpeta);
        } while (strlen($carpeta) >= strlen($raiz));
        if ($total > 24576) {
            $errores[] = $relativo . ': la cadena de guías supera 24 KiB; reducir para dejar margen al contexto global.';
        }
    }
    foreach (lineas_documento($archivo) as $numero => $linea) {
        // El código inline puede contener ejemplos que no son enlaces navegables.
        $linea = preg_replace('/(`+).*?\1/', '', $linea);
        preg_match_all('/\[[^\]]*\]\((<[^>]+>|[^\s)]+)\)/', $linea, $coincidencias);
        foreach ($coincidencias[1] as $enlace) {
            $enlace = trim($enlace, '<>');
            if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $enlace)) {
                continue;
            }
            $enlaces++;
            $partes = explode('#', $enlace, 2);
            $ruta = rawurldecode(explode('?', $partes[0], 2)[0]);
            $destino = '' === $ruta ? $archivo : realpath(dirname($archivo) . '/' . $ruta);
            $lugar = $relativo . ':' . ($numero + 1);
            if (false === $destino) {
                $errores[] = $lugar . ': no existe ' . $enlace;
                continue;
            }
            $normal = str_replace('\\', '/', $destino);
            if ($normal !== rtrim($prefijo, '/') && 0 !== strpos($normal, $prefijo)) {
                $errores[] = $lugar . ': enlace fuera del repositorio: ' . $enlace;
                continue;
            }
            if (isset($partes[1]) && '' !== $partes[1] && is_file($destino) && 'md' === strtolower(pathinfo($destino, PATHINFO_EXTENSION))) {
                if (!in_array(rawurldecode($partes[1]), anclas_documento($destino), true)) {
                    $errores[] = $lugar . ': no existe el ancla de ' . $enlace;
                }
            }
        }
    }
}

$configuracion = $raiz . '/tools/releases.config.json';
$config = is_file($configuracion) ? json_decode(file_get_contents($configuracion), true) : null;
if (!is_array($config) || !isset($config['exclude']) || !is_array($config['exclude'])) {
    $errores[] = 'Falta una configuración válida de exclusiones en tools/releases.config.json.';
} else {
    foreach (array('AGENTS.md', 'AGENTS.override.md', 'CLAUDE.md', '.codex', '.codex-log', '.claude') as $excluido) {
        if (!in_array($excluido, $config['exclude'], true)) {
            $errores[] = 'Excluir del ZIP las instrucciones/configuración de agentes: ' . $excluido;
        }
    }
}

foreach ($errores as $error) {
    fwrite(STDERR, 'ERROR ' . $error . "\n");
}
echo count($documentos) . ' documentos, ' . $guias . ' guías, ' . $enlaces . " enlaces locales revisados.\n";
echo empty($errores) ? "DOCUMENTACIÓN EN ORDEN\n" : "DOCUMENTACIÓN CON ERRORES\n";
exit(empty($errores) ? 0 : 1);
