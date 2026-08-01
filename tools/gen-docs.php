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
 * Generates the static API reference site for milpa/event-store.
 *
 * Thin entry over the family docs generator (`Milpa\Docs\SiteGenerator`,
 * shipped inside the milpa/core dist and pulled in here as a dev-only tool —
 * milpa/event-store has zero package dependencies at runtime): reflects over
 * `src/`, renders one `mui-api`-styled page per public type wrapped in the
 * `mui-docs` shell, a nav, a per-page table of contents, and `index.html`.
 *
 * Usage: php tools/gen-docs.php --out <dir> [--css-base <url>] [--version <v>]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Required-value long options (`name:`, not `name::`) so `--css-base /ds` with a
// space is captured; optional (`::`) only binds `--css-base=/ds`. getopt yields
// `false` for a flag it can't bind a value to, so guard with is_string, not `??`
// (which only rescues null) before falling back to the default.
$opts = getopt('', ['out:', 'css-base:', 'version:']);
$out = is_string($opts['out'] ?? null) ? $opts['out'] : 'build/docs';
$cssBase = is_string($opts['css-base'] ?? null) ? $opts['css-base'] : 'https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0';

// Version shown in the docs chrome (topbar badge, title, footer). Prefer an
// explicit --version; otherwise read the release-please manifest (present in
// the published repo); fall back to "dev" for local builds.
$version = is_string($opts['version'] ?? null) ? $opts['version'] : null;
if ($version === null) {
    $manifest = dirname(__DIR__) . '/.github/.release-please-manifest.json';
    $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $version = is_array($data) && is_string($data['.'] ?? null) ? $data['.'] : 'dev';
}

// Branding for this package's docs site — see Milpa\Docs\SiteConfig (milpa/core).
$config = new Milpa\Docs\SiteConfig(
    brand: 'Milpa Event Store',
    nsPrefix: 'Milpa\\EventStore\\',
    installCommand: 'composer require milpa/event-store',
    repoUrl: 'https://github.com/getmilpa/event-store',
    pagesUrl: 'https://getmilpa.github.io/event-store/',
    heroParagraph: 'A tiny <strong>append-only event log</strong>: append events, replay a stream, project '
        . 'state from the fold. Two interchangeable stores — <strong>file</strong> (JSONL) and '
        . '<strong>in-memory</strong> — behind one interface, with <strong>zero dependencies</strong>. The '
        . 'persistence primitive under Milpa\'s event-sourced process engine.',
    utmContent: 'event-store',
);

$count = (new Milpa\Docs\SiteGenerator(dirname(__DIR__) . '/src', $out, $cssBase, $version, $config))->generate();

echo "generated {$count} page(s) to {$out} (v{$version}, css-base: {$cssBase})\n";
exit(0);
