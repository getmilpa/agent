<?php

/**
 * This file is part of Milpa Agent — the session substrate of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse decisions/0059 froze: when composition lowers a ceiling, the session records
 * the receipt as a fact, so an Audit view can paint WHY authority was not required.
 *
 * It is a channel fact, not a projection: the fold does not change, because what a session needs to
 * CONTINUE — its goal, plan, pending work — does not move because a rehearsal descended. The receipt
 * exists for the human who later asks why their agent did not have to ask them (evidence/0240).
 */
final class CompositionIsAFactTest extends TestCase
{
    private const REDUCTION = [
        'operation' => 'probe',
        'reductions' => [
            ['axis' => 'authority', 'from' => 'privileged', 'to' => 'read', 'producer' => 'policy', 'provenance' => 'lab-app@sha256:abc over sha256:def'],
        ],
    ];

    /** 1 · the composition is appended as a ceiling_composed event, carrying its reductions. */
    public function testACompositionIsRecorded(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s-1', 'goal', AutonomyMode::Ask);

        $store->recordCeilingComposition('s-1', self::REDUCTION);

        $composiciones = [];
        foreach ($eventos->replay('agent-session:s-1') as $e) {
            if ($e->type === SessionEvent::CeilingComposed->value) {
                $composiciones[] = $e->payload;
            }
        }
        self::assertCount(1, $composiciones);
        self::assertSame(self::REDUCTION, $composiciones[0]['composition']);
    }

    /** 2 · a composition with no reductions is not a fact worth recording — it is refused. */
    public function testACompositionWithoutReductionsIsRefused(): void
    {
        $store = $this->store();
        $store->start('s-1', 'goal', AutonomyMode::Ask);

        $this->expectException(\InvalidArgumentException::class);
        $store->recordCeilingComposition('s-1', ['operation' => 'probe', 'reductions' => []]);
    }

    /** 3 · a malformed composition — no operation, or reductions that are not a list — is refused. */
    public function testAMalformedCompositionIsRefused(): void
    {
        $store = $this->store();
        $store->start('s-1', 'goal', AutonomyMode::Ask);

        $this->expectException(\InvalidArgumentException::class);
        $store->recordCeilingComposition('s-1', ['reductions' => self::REDUCTION['reductions']]);
    }

    /** 4 · the event does not change what the session projects: the fold is unmoved. */
    public function testTheCompositionDoesNotChangeTheProjection(): void
    {
        $store = $this->store();
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        $antes = $store->load('s-1');

        $store->recordCeilingComposition('s-1', self::REDUCTION);
        $despues = $store->load('s-1');

        self::assertNotNull($antes);
        self::assertNotNull($despues);
        self::assertSame($antes->goal, $despues->goal);
        self::assertSame($antes->mode, $despues->mode);
        self::assertSame($antes->turns, $despues->turns);
    }

    /** 5 · isolated replay: the reduction survives read from the raw events alone. */
    public function testTheReductionSurvivesIsolatedReplay(): void
    {
        $eventos = new InMemoryEventStore();
        (new SessionStore($eventos))->start('s-1', 'goal', AutonomyMode::Ask);
        (new SessionStore($eventos))->recordCeilingComposition('s-1', self::REDUCTION);

        $crudos = [];
        foreach ($eventos->replay('agent-session:s-1') as $e) {
            if ($e->type === SessionEvent::CeilingComposed->value) {
                $crudos[] = $e->payload['composition'];
            }
        }

        self::assertCount(1, $crudos);
        self::assertSame(self::REDUCTION, $crudos[0]);
    }

    private function store(): SessionStore
    {
        return new SessionStore(new InMemoryEventStore());
    }
}
