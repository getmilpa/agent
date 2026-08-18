<?php

/**
 * This file is part of Milpa Agent — governed agent sessions for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\Session;
use PHPUnit\Framework\TestCase;

/**
 * The window composer declares what each message is without changing the provider payload.
 */
final class WindowClassificationTest extends TestCase
{
    public function testACompactionSummaryDeclaresItsClass(): void
    {
        $session = new Session(
            's1',
            'x',
            turns: [['role' => 'user', 'content' => 'old turn', 'seq' => 1]],
            summary: 'The repository was inspected.',
            compactedThrough: 1,
        );

        self::assertSame(['summary'], array_column($session->classifiedWindow(), 'class'));
    }

    public function testAStateBriefingDeclaresItsClass(): void
    {
        $session = new Session('s1', 'x', plan: '1. Inspect 2. Change 3. Verify');

        self::assertSame(['briefing'], array_column($session->classifiedWindow(), 'class'));
    }

    public function testSummaryBriefingAndTurnsKeepTheirDistinctClassesAndRoles(): void
    {
        $session = new Session(
            's1',
            'x',
            turns: [
                ['role' => 'user', 'content' => 'covered', 'seq' => 1],
                ['role' => 'assistant', 'content' => 'recent', 'seq' => 2],
            ],
            plan: 'Continue with the recent change.',
            summary: 'The old work is complete.',
            compactedThrough: 1,
        );

        $window = $session->classifiedWindow();

        self::assertSame(['summary', 'briefing', 'turn'], array_column($window, 'class'));
        self::assertSame(['system', 'system', 'assistant'], array_column($window, 'role'));
    }

    public function testTheProviderWindowDoesNotContainTheClassificationKey(): void
    {
        $session = new Session(
            's1',
            'x',
            turns: [['role' => 'user', 'content' => 'recent', 'seq' => 2]],
            plan: 'Keep going.',
            summary: 'Earlier work.',
            compactedThrough: 1,
        );

        $providerWindow = $session->window();
        $declaredProjection = array_map(
            static fn (array $message): array => [
                'role' => $message['role'],
                'content' => $message['content'],
            ],
            $session->classifiedWindow(),
        );

        self::assertSame($declaredProjection, $providerWindow);
        self::assertSame(['system', 'system', 'user'], array_column($providerWindow, 'role'));
        foreach ($providerWindow as $message) {
            self::assertArrayNotHasKey('class', $message);
            self::assertSame(['role', 'content'], array_keys($message));
        }
    }
}
