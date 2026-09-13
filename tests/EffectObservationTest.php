<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\EffectObservation;
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
final class EffectObservationTest extends TestCase
{
    public function testWitnessesDistinguishArtifactsEvidenceRepetitionAndLegacy(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'test', AutonomyMode::Auto);
        $source = hash('sha256', 'proposal/file/content');
        $applied = hash('sha256', 'applied/file/content');
        $proof = hash('sha256', 'proof/content');
        $from = 0;
        foreach ([
            [null, 1, 0, 'advancing'],
            [new EffectObservation('files', true), 0, 0, 'stalled'],
            [new EffectObservation('files', true, [$source]), 1, 0, 'advancing'],
            [new EffectObservation('files', true, [$source]), 0, 0, 'stalled'],
            [new EffectObservation('files', true, [$applied]), 1, 0, 'advancing'],
            [new EffectObservation('tests', true, [], [$proof]), 0, 1, 'advancing'],
            [new EffectObservation('tests', true, [], [$proof]), 0, 0, 'stalled'],
            [new EffectObservation('files', false), 0, 0, 'unknown'],
        ] as [$witness, $artifacts, $evidence, $progress]) {
            $seq = $witness === null ? null : $store->recordEffectObservation('s', 'make', ['name' => 'X'], $witness);
            $to = $store->recordToolCall('s', 'make', ['name' => 'X'], '{"ok":true}', mutating: true, effectObservationSeq: $seq);
            $r = ProgressReceipt::of($store->stream('s'), $from, $to);
            self::assertSame([$artifacts, $evidence, $progress], [$r->newArtifacts, $r->newEvidence, $r->progress]);
            $from = $to;
        }
    }

    public function testFailureAndConfirmationCannotLendTheirWitnessToAnotherCall(): void
    {
        foreach (['failure', 'confirmation'] as $kind) {
            $store = new SessionStore(new InMemoryEventStore());
            $store->start('s', 'test', AutonomyMode::Auto);
            $seq = $store->recordEffectObservation('s', 'make', [], new EffectObservation('files', true, [hash('sha256', 'x')]));
            $first = $store->recordToolCall(
                's',
                'make',
                [],
                '{}',
                ok: $kind !== 'failure',
                mutating: true,
                awaitingConfirmation: $kind === 'confirmation',
                effectObservationSeq: $seq
            );
            self::assertSame('stalled', ProgressReceipt::of($store->stream('s'), 0, $first)->progress);
            $last = $store->recordToolCall('s', 'make', [], '{}', mutating: true, effectObservationSeq: $seq);
            self::assertSame('unknown', ProgressReceipt::of($store->stream('s'), $first, $last)->progress);
        }
    }

    public function testMissingOrMismatchedWitnessIsUnknownAndPositiveEvidenceStillWins(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'test', AutonomyMode::Auto);
        $seq = $store->recordEffectObservation('s', 'make', ['name' => 'A'], new EffectObservation('files', true));
        $to = $store->recordToolCall('s', 'make', ['name' => 'B'], '{}', mutating: true, effectObservationSeq: $seq);
        self::assertSame('unknown', ProgressReceipt::of($store->stream('s'), 0, $to)->progress);
        $to = $store->recordToolCall('s', 'make', [], '{}', mutating: true, effectObservationSeq: 99999);
        self::assertSame('unknown', ProgressReceipt::of($store->stream('s'), 0, $to)->progress);
        $seq = $store->recordEffectObservation('s', 'test', [], new EffectObservation('tests', true, [], [hash('sha256', 'proof')]));
        $to = $store->recordToolCall('s', 'test', [], '{}', effectObservationSeq: $seq);
        self::assertSame('advancing', ProgressReceipt::of($store->stream('s'), 0, $to)->progress);
    }

    public function testWireValidationAndArgumentCorrelation(): void
    {
        $o = new EffectObservation('files', true, [hash('sha256', 'a')]);
        self::assertEquals($o, EffectObservation::fromArray($o->toArray()));
        foreach ([null, [], ['schema' => 'future'], array_replace($o->toArray(), ['artifacts' => ['bad']])] as $bad) {
            self::assertFalse(EffectObservation::fromArray($bad)->known);
        }
        self::assertSame(EffectObservation::argumentsDigest(['b' => [2, 1], 'a' => 3]), EffectObservation::argumentsDigest(['a' => 3, 'b' => [2, 1]]));
        self::assertNotSame(EffectObservation::argumentsDigest(['b' => [2, 1]]), EffectObservation::argumentsDigest(['b' => [1, 2]]));
    }

    #[DataProvider('invalidObservations')]
    public function testInvalidObservationsAreRefused(string $producer, bool $known, array $ids): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EffectObservation($producer, $known, $ids);
    }

    public static function invalidObservations(): array
    {
        return [['', true, []], ['files', false, [hash('sha256', 'a')]], ['files', true, ['key' => hash('sha256', 'a')]], ['files', true, ['bad']]];
    }
}
