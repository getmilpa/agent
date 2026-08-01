<?php

/**
 * This file is part of milpa/event-store — the append-only event-log primitive
 * of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/event-store
 */
declare(strict_types=1);

/**
 * Verifica que ESTE paquete conserve su atribución.
 *
 * Apache-2.0 §4(d) obliga a arrastrar el NOTICE si existe, pero no obliga a
 * crearlo. Sin NOTICE, la atribución viaja sólo en vectores que un fork puede
 * perder sin violar la licencia: los headers se reescriben, el composer.json se
 * sustituye, el LICENSE se reemplaza por el genérico. El NOTICE es el único que
 * la licencia obliga a conservar — por eso las cuatro invariantes se verifican
 * juntas y no una sola.
 *
 * El hermano de este archivo, `verify-attribution.sh` en milpa/devtools, hace el
 * barrido de la familia entera. Éste mira un solo paquete, que es lo que un CI
 * de repo puede ver: un verificador que nadie corre no es un gate, es un
 * archivo.
 *
 * Uso:  php tools/verify-attribution.php          → reporta y sale 1 si hay violaciones
 *       php tools/verify-attribution.php --quiet  → sólo el veredicto
 */

$nombre = getenv('ATTRIBUTION_NAME') ?: 'Rodrigo Vicente - TeamX Agency';
$silencioso = in_array('--quiet', $argv, true);
$raiz = \dirname(__DIR__);

$decir = static function (string $linea) use ($silencioso): void {
    if (!$silencioso) {
        echo $linea, PHP_EOL;
    }
};

$fallos = [];

// 1 · NOTICE presente y con el nombre canónico.
$notice = $raiz . '/NOTICE';
if (!is_file($notice)) {
    $fallos[] = 'sin NOTICE';
} elseif (!str_contains((string) file_get_contents($notice), $nombre)) {
    $fallos[] = 'NOTICE sin el nombre canónico';
}

// 2 · composer.json declara authors con el nombre canónico.
$composer = json_decode((string) @file_get_contents($raiz . '/composer.json'), true);
$autores = [];
foreach ((is_array($composer) ? ($composer['authors'] ?? []) : []) as $autor) {
    if (is_array($autor) && isset($autor['name'])) {
        $autores[] = (string) $autor['name'];
    }
}
if ($autores === []) {
    $fallos[] = 'composer.json sin authors';
} elseif (!in_array($nombre, $autores, true)) {
    $fallos[] = 'authors sin el nombre canónico';
}

// 3 · Todo archivo fuente lleva el header de atribución.
$sinHeader = [];
$src = $raiz . '/src';
if (is_dir($src)) {
    $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($archivos as $archivo) {
        $ruta = (string) $archivo;
        if (!str_ends_with($ruta, '.php')) {
            continue;
        }
        // Basta con leer el encabezado: el header vive en las primeras líneas y
        // leer el archivo entero de cada fuente es trabajo que nadie necesita.
        $cabeza = (string) file_get_contents($ruta, false, null, 0, 2048);
        if (!str_contains($cabeza, '(c) ' . $nombre)) {
            $sinHeader[] = substr($ruta, strlen($raiz) + 1);
        }
    }
}
if ($sinHeader !== []) {
    $fallos[] = count($sinHeader) . ' archivo(s) sin header';
}

// 4 · LICENSE presente con línea de copyright.
$license = $raiz . '/LICENSE';
if (!is_file($license)) {
    $fallos[] = 'sin LICENSE';
} elseif (preg_match('/^ *Copyright \d{4}/mi', (string) file_get_contents($license)) !== 1) {
    $fallos[] = 'LICENSE sin línea de copyright';
}

$paquete = is_array($composer) ? (string) ($composer['name'] ?? basename($raiz)) : basename($raiz);

if ($fallos === []) {
    echo "atribución OK: {$paquete} conserva las cuatro invariantes (nombre canónico: \"{$nombre}\")", PHP_EOL;

    exit(0);
}

$decir("atribución: {$paquete} pierde la atribución");
foreach ($fallos as $fallo) {
    $decir('  · ' . $fallo);
}
foreach (array_slice($sinHeader, 0, 10) as $archivo) {
    $decir('      ' . $archivo);
}
if (count($sinHeader) > 10) {
    $decir('      … y ' . (count($sinHeader) - 10) . ' más');
}

fwrite(STDERR, "ATRIBUCIÓN FAIL: {$paquete}\n");

exit(1);
