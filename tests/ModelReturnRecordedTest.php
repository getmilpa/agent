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
 * What a model call COST becomes its own fact of the stream, beside what was sent to the model.
 *
 * The cost is the half {@see \Milpa\Agent\SessionStore::recordModelCall()} cannot supply: the request
 * is written before any reply exists. These tests pin that the return lands as its own event and that
 * it does NOT move the reduced state — it is a fact for replay and for return-aware observers, read
 * straight from the stream, never folded into the session's derived shape.
 */
final class ModelReturnRecordedTest extends TestCase
{
    /** @return array{model: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int}} */
    private function aReturn(): array
    {
        return [
            'model' => 'qwen3.8-27b',
            'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 16, 'total_tokens' => 33, 'cached_tokens' => 0],
        ];
    }

    public function testTheCostOfACallIsAppendedAsItsOwnEvent(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelReturn('s1', $this->aReturn());

        $payload = null;
        foreach ($eventos->replay(SessionStore::PREFIX . 's1') as $e) {
            if ($e->type === 'session.model_returned') {
                $payload = $e->payload;
            }
        }

        self::assertNotNull($payload, 'the return is a fact of the stream');
        self::assertSame('qwen3.8-27b', $payload['model']);
        self::assertSame(33, $payload['usage']['total_tokens']);
        self::assertSame(17, $payload['usage']['prompt_tokens']);
        self::assertSame(16, $payload['usage']['completion_tokens']);
    }

    public function testTheStoreAddsNoArithmeticOfItsOwn(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        // A total the store must NOT "correct": it records what the gateway measured, verbatim.
        $almacen->recordModelReturn('s1', ['model' => 'm', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 999]]);

        $payload = null;
        foreach ($eventos->replay(SessionStore::PREFIX . 's1') as $e) {
            if ($e->type === 'session.model_returned') {
                $payload = $e->payload;
            }
        }

        self::assertSame(999, $payload['usage']['total_tokens']);
    }

    public function testTheReturnDoesNotMoveTheReducedState(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'the goal');
        $almacen->recordModelReturn('s1', $this->aReturn());

        // The exhaustive match must handle the new event WITHOUT throwing, and the fold must be the
        // same as if only `start` had happened — the cost is not a turn, a tool, or a mutation.
        $reducer = new SessionReducer();
        $session = $reducer->reduce('s1', $eventos->replay(SessionStore::PREFIX . 's1'));

        self::assertSame('the goal', $session->goal);
        self::assertSame([], $session->turns);
        self::assertSame(0, $session->toolCalls, 'no tool call was folded in');
    }
}
