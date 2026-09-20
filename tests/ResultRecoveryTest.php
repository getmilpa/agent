<?php

/**
 * This file is part of Milpa Agent.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A new invocation cannot stand in for the immutable result of an earlier call. */
final class ResultRecoveryTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function effects(): iterable
    {
        yield 'mutation' => [['mutating' => true], 'mutating'];
        yield 'missing effect' => [[], 'unknown'];
        yield 'null effect' => [['mutating' => null], 'unknown'];
        yield 'string effect' => [['mutating' => 'false'], 'unknown'];
        yield 'read' => [['mutating' => false], 'read'];
    }

    /** @param array<string, mixed> $effect */
    #[DataProvider('effects')]
    public function testRecoveryNamesOnlyTheEffectActuallyRecorded(array $effect, string $kind): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'recover one recorded result');
        $raw = json_encode(['value' => str_repeat('🌽', 5_000)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $event = new Event(
            streamId: SessionStore::PREFIX . 's1',
            type: SessionEvent::ToolCalled->value,
            payload: [
                'tool' => 'producer',
                'arguments' => ['name' => 'demo'],
                'result' => $raw,
                'resultChars' => mb_strlen($raw),
                'ok' => true,
                ...$effect,
            ],
            seq: $events->nextSeq(),
        );
        $events->append($event);
        $before = $store->stream('s1');
        $facts = $store->facts('s1');
        $block = $facts->operationalFacts(\PHP_INT_MAX);
        $answer = $facts->operationResult('producer');
        $compact = $block['calls'][0];
        $query = $answer['call'];

        self::assertSame([
            'refetch' => 'new_read_not_recorded_result',
            'reinvoke' => 'not_result_recovery',
            'admission' => 'not_evaluated',
        ], $block['resultRecovery']);
        self::assertSame($block['resultRecovery'], $answer['resultRecovery']);

        self::assertSame(600, $compact['resultSummaryChars']);
        self::assertSame(4_000, $query['resultReturnedChars']);
        self::assertSame(mb_substr($raw, 0, 600), $compact['resultSummary']);
        self::assertSame(mb_substr($raw, 0, 4_000), $query['result']);
        foreach ([$compact, $query] as $call) {
            self::assertSame($event->seq, $call['seq']);
            self::assertTrue($call['resultTruncated']);
            self::assertSame(mb_strlen($raw), $call['resultStoredChars']);
            $digest = 'sha256:' . hash('sha256', '{"name":"demo"}');
            if ($kind === 'read') {
                self::assertArrayNotHasKey('recovery', $call);
                self::assertSame('producer', $call['refetch']['operation']);
                self::assertSame($digest, $call['refetch']['argumentsDigest']);
                self::assertTrue($call['refetch']['sameCallRecorded']);
            } else {
                self::assertArrayNotHasKey('refetch', $call);
                self::assertSame([
                    'source' => 'recorded_call',
                    'argumentsDigest' => $digest,
                    'producer' => $kind,
                ], $call['recovery']);
            }
        }
        self::assertSame($before, $store->stream('s1'), 'Projecting recovery must not append or invoke anything');
        self::assertSame($raw, $store->stream('s1')[1]->payload['result']);
    }

    public function testRecoveryDoesNotClaimThatTruncatedStorageIsComplete(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'recover a partially retained result');
        $store->recordToolCall('s1', 'producer', [], 'retained fragment', true, true, 5_000);

        $result = $store->facts('s1')->operationResult('producer');

        self::assertTrue($result['ok']);
        self::assertTrue($result['call']['resultTruncated']);
        self::assertSame(5_000, $result['call']['resultChars']);
        self::assertSame(17, $result['call']['resultStoredChars']);
        self::assertSame('retained fragment', $result['call']['result']);
        self::assertArrayNotHasKey('refetch', $result['call']);
        self::assertSame('not_result_recovery', $result['resultRecovery']['reinvoke']);
        self::assertFalse($store->facts('s1')->operationResult('missing')['ok']);
    }

    public function testACompleteResultNeedsNoRecoveryHint(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'read a complete result');
        $store->recordToolCall('s1', 'producer', [], '{"ok":true}', true, true, 11);

        self::assertArrayNotHasKey('resultRecovery', $store->facts('s1')->operationalFacts(\PHP_INT_MAX));
        self::assertArrayNotHasKey('resultRecovery', $store->facts('s1')->operationResult('producer'));

        foreach ([$store->facts('s1')->operationalFacts(\PHP_INT_MAX)['calls'][0],
            $store->facts('s1')->operationResult('producer')['call']] as $call) {
            self::assertFalse($call['resultTruncated']);
            self::assertArrayNotHasKey('refetch', $call);
            self::assertArrayNotHasKey('recovery', $call);
        }
    }
}
