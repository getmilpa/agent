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
 * The system prompt is a FACT appended when it changes, not a field copied on every call.
 *
 * Decided in greenhouse decisions/0039 after three measurements: `evidence/0223` counted ONE distinct
 * system per session across 32 sessions, `evidence/0224` proved a session read in isolation
 * reconstructs identically under this shape, and `evidence/0225` proved a fact appended before a real
 * compaction still reconstructs afterwards.
 *
 * What these tests pin is the property, not the saving: a reader with ONLY this session's events can
 * always say which system was in force on each call.
 */
final class SystemIsAFactTest extends TestCase
{
    private const STREAM = SessionStore::PREFIX . 's1';

    private function intake(string $system, string $user = 'hola'): ModelCallIntake
    {
        return ModelCallIntake::fromChannelPayload('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'tools' => [['name' => 'plugins_list']],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function payloads(InMemoryEventStore $eventos, string $tipo): array
    {
        $out = [];
        foreach ($eventos->replay(self::STREAM) as $e) {
            if ($e->type === $tipo) {
                $out[] = $e->payload;
            }
        }

        return $out;
    }

    public function testAnUnchangedSystemIsAppendedOnceAndReferencedAfterwards(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->recordModelCall('s1', $this->intake('eres un agente'));
        $almacen->recordModelCall('s1', $this->intake('eres un agente'));
        $almacen->recordModelCall('s1', $this->intake('eres un agente'));

        self::assertCount(1, $this->payloads($eventos, 'session.system_set'));

        $llamadas = $this->payloads($eventos, 'session.model_called');
        self::assertCount(3, $llamadas);
        foreach ($llamadas as $llamada) {
            self::assertArrayNotHasKey('system', $llamada, 'the text must not travel on every call');
            self::assertIsString($llamada['system_ref']);
        }
    }

    public function testAChangedSystemIsAppendedAgainAndTheCallsPointAtDifferentFacts(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->recordModelCall('s1', $this->intake('eres un agente'));
        $almacen->recordModelCall('s1', $this->intake('ahora eres OTRO agente'));

        self::assertCount(2, $this->payloads($eventos, 'session.system_set'));

        $llamadas = $this->payloads($eventos, 'session.model_called');
        self::assertNotSame(
            $llamadas[0]['system_ref'],
            $llamadas[1]['system_ref'],
            'a session that changes its prompt mid-run must not collapse into one reference',
        );
    }

    /**
     * The property `decisions/0035` demands: a reader of THIS session's events, and nothing else,
     * recovers the text. If it needed an index or another stream, the view would know less than its
     * channel.
     */
    public function testTheTextIsRecoverableFromThisSessionsEventsAlone(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake('eres un agente'));
        $almacen->recordModelCall('s1', $this->intake('ahora eres OTRO agente'));

        $porRef = [];
        $vigentes = [];
        foreach ($eventos->replay(self::STREAM) as $e) {
            if ($e->type === 'session.system_set') {
                $porRef[(string) $e->payload['ref']] = (string) $e->payload['system'];
            }
            if ($e->type === 'session.model_called') {
                $vigentes[] = $porRef[(string) $e->payload['system_ref']] ?? null;
            }
        }

        self::assertSame(['eres un agente', 'ahora eres OTRO agente'], $vigentes);
    }

    /**
     * Two sessions sharing one store must not lean on each other: the second one appends its own fact
     * even when the text is identical, or reading it alone would resolve to nothing.
     */
    public function testASecondSessionAppendsItsOwnFactForTheSameText(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->start('s2', 'y');

        $almacen->recordModelCall('s1', $this->intake('eres un agente'));
        $almacen->recordModelCall('s2', $this->intake('eres un agente'));

        $enS2 = 0;
        foreach ($eventos->replay(SessionStore::PREFIX . 's2') as $e) {
            if ($e->type === 'session.system_set') {
                ++$enS2;
            }
        }

        self::assertSame(1, $enS2);
    }

    /**
     * `decisions/0039` point 2: the messages are recorded with their CONTENT, not their size. A shape
     * makes «what did the agent receive» unanswerable, and everything built on top inherits that.
     */
    public function testTheMessagesAreRecordedWithTheirContent(): void
    {
        $intake = $this->intake('eres un agente', 'arregla el bug del login');

        self::assertSame(
            [['role' => 'user', 'content' => 'arregla el bug del login']],
            $intake->messages,
        );
    }
}
