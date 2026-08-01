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
 * Coverage ratchet.
 *
 * Reads the Clover report PHPUnit just wrote and fails when line coverage drops
 * below the floor. The floor is not a target — it is today's number, so that it
 * can only go up.
 *
 * It exists because the measurement was in the toolchain all along with nothing
 * switching it on: xdebug installed, `<source>` declared in phpunit.xml, and
 * `coverage: none` in CI. A number nobody reads is a number nobody defends.
 *
 * Usage: php tools/coverage-floor.php <clover.xml> <floor-percent>
 */

$report = $argv[1] ?? 'build/clover.xml';
$floor = (float) ($argv[2] ?? '0');

if (!is_file($report)) {
    fwrite(STDERR, "no coverage report at {$report}\n");

    exit(1);
}

$xml = simplexml_load_file($report);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "unreadable coverage report at {$report}\n");

    exit(1);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements === 0 ? 0.0 : 100 * $covered / $statements;

printf("line coverage: %.2f%% (%d/%d), floor %.2f%%%s", $percent, $covered, $statements, $floor, PHP_EOL);

if ($percent + 0.005 < $floor) {
    fwrite(STDERR, sprintf("below the floor of %.2f%%\n", $floor));

    exit(1);
}

exit(0);
