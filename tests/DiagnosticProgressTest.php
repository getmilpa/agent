<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\EffectObservation;
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Diagnostic novelty can advance a repair without certifying it (greenhouse0751).
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
final class DiagnosticProgressTest extends TestCase
{
    public function testNewFailureAdvancesOnceAcrossCheckpointsWithoutPositiveEvidence(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'repair');
        $args = ['path' => 'tests/X.php'];
        $diagnostic = hash('sha256', 'scope-and-inputs');
        $from = 0;
        foreach ([1, 0] as $novelty) {
            $store = new SessionStore($events);
            $seq = $store->recordEffectObservation(
                's',
                'test',
                $args,
                new EffectObservation('tests', true, [hash('sha256', 'untrusted-artifact')], [hash('sha256', 'untrusted-proof')], [$diagnostic])
            );
            $to = $store->recordToolCall('s', 'test', $args, '{"ok":false,"ran":true}', ok: false, mutating: true, effectObservationSeq: $seq);
            $r = ProgressReceipt::of($store->stream('s'), $from, $to);
            self::assertSame($novelty, $r->newDiagnostics);
            self::assertSame([0, 0, 0, 0], [$r->newFacts, $r->newArtifacts, $r->newEvidence, $r->closedTodos]);
            self::assertSame($novelty === 1 ? 'advancing' : 'stalled', $r->progress);
            $from = $to;
        }
    }

    public function testDiagnosticDoesNotCreatePositiveVerification(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'repair');
        $args = ['path' => 'tests/X.php'];
        $seq = $store->recordEffectObservation('s', 'test', $args, new EffectObservation('tests', true, diagnostics: [hash('sha256', 'failure')]));
        $store->recordToolCall('s', 'test', $args, '{"ok":false,"ran":true}', ok: false, effectObservationSeq: $seq);
        self::assertFalse($store->facts('s')->evidenceByPredicate('test-passed', 'tests/X.php')['ok']);
        self::assertFalse($store->facts('s')->lastVerificationOf('tests/X.php')['ok']);
        self::assertSame([], $store->load('s')->todos);

    }

    #[DataProvider('damagedLinks')]
    public function testDiagnosticNeedsItsOwnBoundObservation(string $damage): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'repair');
        $store->start('other', 'other');
        $args = ['path' => 'tests/X.php'];
        $observation = new EffectObservation('tests', true, diagnostics: [hash('sha256', 'failure')]);
        $seq = $store->recordEffectObservation($damage === 'session' ? 'other' : 's', 'test', $args, $observation);
        if ($damage === 'used') {
            $store->recordToolCall('s', 'read', [], '{}');
        }
        $to = $store->recordToolCall(
            's',
            $damage === 'tool' ? 'edit' : 'test',
            $damage === 'arguments' ? ['path' => 'tests/Y.php'] : $args,
            '{"ok":false}',
            ok: false,
            mutating: true,
            awaitingConfirmation: $damage === 'confirmation',
            effectObservationSeq: $damage === 'future' ? $seq + 100 : $seq
        );
        $r = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's'), 0, $to);
        self::assertSame(0, $r->newDiagnostics);
        self::assertSame('stalled', $r->progress);
    }

    /** @return list<array{string}> */
    public static function damagedLinks(): array
    {
        return [['session'], ['tool'], ['arguments'], ['confirmation'], ['future'], ['used']];
    }

    public function testVersionedWirePreservesLegacyAndRejectsMalformedDiagnostics(): void
    {
        $plain = new EffectObservation('tests', true);
        self::assertSame('milpa.agent.effect-observation/v1', $plain->toArray()['schema']);
        self::assertEquals($plain, EffectObservation::fromArray($plain->toArray()));
        $diagnostic = new EffectObservation('tests', true, diagnostics: [hash('sha256', 'failure')]);
        $wire = $diagnostic->toArray();
        self::assertSame('milpa.agent.effect-observation/v2', $wire['schema']);
        self::assertEquals($diagnostic, EffectObservation::fromArray($wire));
        foreach ([['schema' => 'milpa.agent.effect-observation/v1'], ['schema' => 'future'], ['diagnostics' => null],
            ['diagnostics' => ['bad']], ['diagnostics' => ['key' => hash('sha256', 'failure')]], ['known' => false]] as $patch) {
            self::assertFalse(EffectObservation::fromArray(array_replace($wire, $patch))->known);
        }
        unset($wire['diagnostics']);
        self::assertFalse(EffectObservation::fromArray($wire)->known);
    }

    public function testDiagnosticOutsideTheWindowDoesNotExcuseLaterReads(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'repair');
        $seq = $store->recordEffectObservation('s', 'test', [], new EffectObservation('tests', true, diagnostics: [hash('sha256', 'failure')]));
        $to = $store->recordToolCall('s', 'test', [], '{"ok":false}', ok: false, effectObservationSeq: $seq);
        self::assertSame(0, ProgressReceipt::of($store->stream('s'), 0, $seq - 1)->newDiagnostics);
        $last = $store->recordToolCall('s', 'read', [], '{"ok":true}');
        self::assertSame('stalled', ProgressReceipt::of($store->stream('s'), $to, $last)->progress);
    }
}
