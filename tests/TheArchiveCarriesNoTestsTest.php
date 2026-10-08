<?php

/**
 * This file is part of Milpa Agent — the session and consent core of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use PHPUnit\Framework\TestCase;

/**
 * WHAT A HOUSE INSTALLS OF THIS PACKAGE CARRIES NO TESTS.
 *
 * Composer installs the archive of a tag, and git leaves out of an archive whatever `.gitattributes` marks
 * `export-ignore`. Until that line existed, a house that required this package received its tests too — as
 * many files as the code it runs, never run there, and with them whatever a test had been written with. One
 * line keeps them out, and nothing in the package held that line.
 *
 * @guards the tests being marked out of the archive, and the archive git builds carrying none
 *
 * @refuses nothing — it holds a line of `.gitattributes`
 */
final class TheArchiveCarriesNoTestsTest extends TestCase
{
    public function testTheTestsAreMarkedOutOfTheArchive(): void
    {
        $marked = array_map('trim', file(\dirname(__DIR__) . '/.gitattributes', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: []);

        self::assertContains('/tests export-ignore', $marked, 'the tests travel to every house that installs this package');
    }

    /** By execution: the archive git builds of this very commit, listed. */
    public function testTheArchiveGitBuildsOfThisCommitCarriesNone(): void
    {
        $root = \dirname(__DIR__);
        if (!file_exists($root . '/.git')) {
            self::markTestSkipped('not a git checkout: there is no archive to build here');
        }

        exec('git -C ' . escapeshellarg($root) . ' archive HEAD 2>/dev/null | tar -t 2>/dev/null', $entries, $exit);
        if ($exit !== 0 || $entries === []) {
            self::markTestSkipped('git could not build the archive of HEAD here');
        }

        self::assertSame([], array_values(array_filter($entries, static fn (string $entry): bool => str_starts_with($entry, 'tests/'))));
        self::assertNotSame([], array_filter($entries, static fn (string $entry): bool => str_starts_with($entry, 'src/')), 'the control: the archive was read, and it carries the code');
    }
}
