<?php

/**
 * This file is part of milpa/agent.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\PrerequisiteCompletion;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ordering survives failed loads and process reconstruction, not just a happy dispatch. */
final class PrerequisiteCompletionTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function outcomes(): iterable
    {
        yield 'loaded' => [['ok' => true, 'result' => '{"ok":true,"body":"guide"}'], true];
        yield 'unknown skill' => [['ok' => true, 'result' => '{"ok":false,"error":"unknown skill"}'], false];
        yield 'dispatch failed' => [['ok' => false, 'result' => '{"ok":true}'], false];
        yield 'confirmation' => [['ok' => true, 'result' => '{"ok":true}', 'awaitingConfirmation' => true], false];
        yield 'malformed outcome' => [['ok' => true, 'result' => '{"ok":"false"}'], false];
        yield 'null outcome' => [['ok' => true, 'result' => '{"ok":null}'], false];
        yield 'known truncation' => [['ok' => true, 'result' => '{"ok":true}', 'resultChars' => 100], false];
        yield 'legacy text' => [['result' => 'done'], true];
        yield 'domain data' => [['ok' => true, 'result' => '{"data":{"ok":false}}'], true];
        yield 'complete character count' => [['ok' => true, 'result' => 'é', 'resultChars' => 1], true];
        yield 'no result' => [['ok' => true], false];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('outcomes')]
    public function testOnlyAnAdmissibleOutcomeCompletesTheObligation(array $payload, bool $expected): void
    {
        self::assertSame($expected, PrerequisiteCompletion::of($payload));
    }

    /** A new store folds the unchanged events, retaining a failed obligation until success. */
    public function testFailedLoadsRemainRequiredAcrossReloadsAndSuccessStaysSatisfied(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Load the guide first');
        $store->requireFirst('s', ['skill_load']);
        $store->recordToolCall('s', 'skill_load', [], '{"ok":false,"error":"unknown skill"}');
        $store->recordToolCall('s', 'skill_load', [], 'failed', ok: false);
        self::assertSame(['skill_load'], (new SessionStore($events))->load('s')?->runFirst);
        $failed = $store->stream('s');

        $store->recordToolCall('s', 'skill_load', [], '{"ok":true,"body":"guide"}');
        $store->recordToolCall('s', 'skill_load', [], '{"ok":false}');
        self::assertSame([], (new SessionStore($events))->load('s')?->runFirst);
        self::assertSame($failed, array_slice($store->stream('s'), 0, \count($failed)));
        $store->requireFirst('s', ['skill_load']);
        self::assertSame(['skill_load'], (new SessionStore($events))->load('s')?->runFirst);
    }
}
