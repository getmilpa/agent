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

use Milpa\Agent\ModelCallIntake;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The intake becomes a fact of the stream, next to what the agent did with it.
 */
final class ModelCallRecordedTest extends TestCase
{
    private function intake(): ModelCallIntake
    {
        return ModelCallIntake::fromChannelPayload('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'messages' => [['role' => 'system', 'content' => 'eres un agente'], ['role' => 'user', 'content' => 'hola']],
            'tools' => [['name' => 'plugins_list'], ['name' => 'config_set']],
        ]);
    }

    public function testWhatWasSentToTheModelIsAppendedToTheStream(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());

        $tipos = array_map(
            static fn (object $e): string => $e->type,
            $eventos->replay(SessionStore::PREFIX . 's1'),
        );

        self::assertContains('session.model_called', $tipos);
    }

    public function testTheRecordedFactCarriesTheToolsThatTravelled(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());

        $payload = null;
        foreach ($eventos->replay(SessionStore::PREFIX . 's1') as $e) {
            if ($e->type === 'session.model_called') {
                $payload = $e->payload;
            }
        }

        self::assertNotNull($payload);
        self::assertSame(['plugins_list', 'config_set'], $payload['tools']);
        self::assertSame('qwen3-coder:30b', $payload['model']);
        self::assertSame('eres un agente', $payload['system']);
        self::assertSame([['role' => 'user', 'chars' => 4]], $payload['messages']);
    }

    /**
     * Observing a channel may not change it. The intake is a sibling fact of the conversation, not a
     * turn in it: if recording what the model was asked also fed the model an extra turn, every
     * observed run would diverge from the same run unobserved — and a measurement that alters what it
     * measures is not evidence of anything.
     */
    public function testRecordingTheIntakeDoesNotAddATurnToTheConversation(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x');
        $almacen->recordTurn('s1', 'user', 'hola');

        $antes = $almacen->load('s1')?->turns ?? [];
        $almacen->recordModelCall('s1', $this->intake());
        $despues = $almacen->load('s1')?->turns ?? [];

        self::assertSame($antes, $despues);
    }
}
