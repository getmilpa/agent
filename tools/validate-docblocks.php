<?php

/**
 * This file is part of Milpa Event Store — the append-only event-log primitive of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/event-store
 */

declare(strict_types=1);

/**
 * DocBlock coverage gate for the public core (D14).
 *
 * Fails if any PUBLIC symbol under the scanned path lacks a DocBlock with a
 * non-empty summary line. Rule: every type (class/interface/enum/trait) and
 * every OWN public method, EXCEPT magic methods, trivial accessors, and the
 * enum-synthetic cases()/from()/tryFrom(). Reflection-based, so it needs the
 * package autoloader (run after `composer install`).
 *
 * Exit 0 = fully documented; exit 1 = one or more public symbols undocumented.
 *
 * Usage: php tools/validate-docblocks.php [path]   (path defaults to src)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$root = $argv[1] ?? 'src';
if (!is_dir($root)) {
    fwrite(STDERR, "no such path: {$root}\n");
    exit(1);
}

$enumSynthetic = ['cases', 'from', 'tryFrom'];

/** True when a DocBlock has at least one non-empty, non-@tag text line. */
$hasSummary = static function (string|false $doc): bool {
    if ($doc === false) {
        return false;
    }
    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        $text = trim($line, " \t*/");
        if ($text === '') {
            continue;
        }
        return $text[0] !== '@';
    }
    return false;
};

/** True when the method body is a single `return $this->x;` or `$this->x = …;`. */
$isTrivialAccessor = static function (ReflectionMethod $m): bool {
    if ($m->isAbstract() || $m->getFileName() === false) {
        return false;
    }
    $lines = file($m->getFileName()) ?: [];
    $body = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

    $brace = strpos($body, '{');
    if ($brace === false) {
        return false;
    }
    $inner = trim(substr($body, $brace));

    return (bool) (preg_match('/^\{\s*return\s+\$this->\w+;\s*}$/s', $inner)
        || preg_match('/^\{\s*\$this->\w+\s*=[^;]+;\s*}$/s', $inner));
};

$repoRoot = dirname(__DIR__) . '/';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$violations = [];
$typeCount = 0;
$methodCount = 0;

foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $src = file_get_contents($file->getPathname());
    if ($src === false
        || !preg_match('/namespace\s+([^;]+);/', $src, $ns)
        || !preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $src, $cm)) {
        continue;
    }
    $fqcn = trim($ns[1]) . '\\' . $cm[1];
    if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn) && !enum_exists($fqcn)) {
        continue;
    }

    $rc = new ReflectionClass($fqcn);
    $typeCount++;
    $rel = str_replace($repoRoot, '', $file->getPathname());
    if (!$hasSummary($rc->getDocComment())) {
        $violations[] = "{$fqcn} — {$rel}:{$rc->getStartLine()}";
    }

    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== $fqcn) {
            continue; // inherited — belongs to the declaring type
        }
        $name = $m->getName();
        if (str_starts_with($name, '__')
            || ($rc->isEnum() && in_array($name, $enumSynthetic, true))
            || $isTrivialAccessor($m)) {
            continue;
        }
        $methodCount++;
        if (!$hasSummary($m->getDocComment())) {
            $violations[] = "{$fqcn}::{$name}() — {$rel}:{$m->getStartLine()}";
        }
    }
}

echo "== docblocks ({$typeCount} type(s), {$methodCount} public method(s), {$root}) ==\n";

if ($violations !== []) {
    echo "\nUNDOCUMENTED PUBLIC SYMBOLS:\n";
    foreach ($violations as $v) {
        echo "  x {$v}\n";
    }
    echo "\n" . count($violations) . " undocumented public symbol(s).\n";
    exit(1);
}

echo "\nOK: every public symbol has a DocBlock.\n";
exit(0);
