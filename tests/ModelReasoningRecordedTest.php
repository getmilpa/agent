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

use Milpa\Agent\SessionReducer;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * What a model call REASONED becomes its own fact of the stream, beside its input and its cost.
 *
 * The reasoning is a third fact neither {@see \Milpa\Agent\SessionStore::recordModelCall()} (the
 * input) nor {@see \Milpa\Agent\SessionStore::recordModelReturn()} (the cost) carries. These tests
 * pin that it lands as its own event, verbatim, and that it does NOT move the reduced state — it is
 * deliberation for replay and for reasoning-aware observers, never folded into the session's shape.
 */
final class ModelReasoningRecordedTest extends TestCase
{
    public function testTheReasoningOfACallIsAppendedAsItsOwnEvent(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelReasoning('s1', 'Break 23 into 20 + 3; 17×20=340, 17×3=51, 340+51=391.');

        $payload = null;
        foreach ($eventos->replay(SessionStore::PREFIX . 's1') as $e) {
            if ($e->type === 'session.model_reasoned') {
                $payload = $e->payload;
            }
        }

        self::assertNotNull($payload, 'the reasoning is a fact of the stream');
        self::assertSame('Break 23 into 20 + 3; 17×20=340, 17×3=51, 340+51=391.', $payload['reasoning']);
    }

    public function testTheReasoningDoesNotMoveTheReducedState(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'the goal');
        $almacen->recordModelReasoning('s1', 'thinking about it');

        // The exhaustive match must handle the new event WITHOUT throwing, and the fold must be the
        // same as if only `start` had happened — reasoning is not a turn, a tool, or a mutation.
        $reducer = new SessionReducer();
        $session = $reducer->reduce('s1', $eventos->replay(SessionStore::PREFIX . 's1'));

        self::assertSame('the goal', $session->goal);
        self::assertSame([], $session->turns);
        self::assertSame(0, $session->toolCalls, 'no tool call was folded in');
    }
}
