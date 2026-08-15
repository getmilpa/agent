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
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionObservation;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The common fact a developer needs, before any surface paints it.
 *
 * The role is transversal: a human fills it sometimes and an agent others, and what defines it is
 * needing BOTH the human's view and the agent's at once. So this is one source, and the projections
 * are errands on top of it — never two implementations that can disagree.
 *
 * The property that governs every test here: it may not know more than the stream said. When the
 * stream is silent this must say so, because a partial view that does not declare itself partial is
 * more dangerous than a small one.
 */
final class SessionObservationTest extends TestCase
{
    private function store(InMemoryEventStore $eventos): SessionStore
    {
        return new SessionStore($eventos);
    }

    private function intake(array $tools = ['plugins_list', 'config_set']): ModelCallIntake
    {
        return ModelCallIntake::fromChannelPayload('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'system' => 'eres un agente de milpa',
            'messages' => [['role' => 'user', 'content' => 'hola']],
            'tools' => array_map(static fn (string $t): array => ['name' => $t], $tools),
        ]);
    }

    public function testItAnswersWhatTheAgentWasOfferedFromTheStream(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'listar plugins');
        $almacen->recordModelCall('s1', $this->intake());

        $o = SessionObservation::of($eventos, 's1');

        self::assertTrue($o->answers['tools_offered']['answered']);
        self::assertSame(['plugins_list', 'config_set'], $o->answers['tools_offered']['value']);
    }

    public function testItAnswersWhatItCalledAndWhatCameBack(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());
        $almacen->recordToolCall('s1', 'plugins_list', [], 'ok: dos plugins', true, false);

        $o = SessionObservation::of($eventos, 's1');

        self::assertTrue($o->answers['called']['answered']);
        self::assertSame(['plugins_list'], array_column($o->answers['called']['value'], 'tool'));
        self::assertTrue($o->answers['returned']['answered']);
    }

    public function testItAnswersWhichGateIntervened(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion(
            id: 'perm:config:set',
            question: '¿lo dejo?',
            options: ['CONFIRMAR', 'CANCELAR'],
            reason: 'permission',
        ));

        $o = SessionObservation::of($eventos, 's1');

        self::assertTrue($o->answers['gate']['answered']);
        self::assertSame('perm:config:set', $o->answers['gate']['value'][0]['id']);
    }

    /**
     * THE ONE THAT DECIDES EVERYTHING.
     *
     * Nobody declared an omission, so this says nobody declared one. It does not go and ask the tool
     * registry how many tools exist in order to subtract. The moment it did, it would know something
     * the stream never said — and a view that knows more than its channel is not a view of the
     * system, it is a second opinion about it.
     */
    public function testAnUndeclaredOmissionIsSaidToBeUNKNOWNRatherThanCalculated(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());

        $o = SessionObservation::of($eventos, 's1');

        self::assertFalse($o->answers['omitted']['answered']);
        self::assertNotSame('', $o->answers['omitted']['because']);
    }

    /**
     * A session whose intake nobody recorded must not look like a session that was offered nothing.
     * This is the same distinction as `toolsUnknown`, one layer up, and it is the failure this whole
     * slice exists to prevent: reading `0` and concluding the app is broken when the truth is that
     * the recorder was not wired.
     */
    public function testASessionWithoutIntakeSaysSoInsteadOfShowingZero(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordToolCall('s1', 'plugins_list', [], 'ok', true, false);

        $o = SessionObservation::of($eventos, 's1');

        self::assertFalse($o->answers['tools_offered']['answered']);
        self::assertFalse($o->answers['context_received']['answered']);
        self::assertTrue($o->answers['called']['answered'], 'what it did IS recorded');
    }

    /** Every one of the seven is present as a key, answered or not — that is what makes it auditable. */
    public function testTheSevenQuestionsAreAlwaysAllThere(): void
    {
        $eventos = new InMemoryEventStore();
        $this->store($eventos)->start('s1', 'x');

        $o = SessionObservation::of($eventos, 's1');

        self::assertSame([
            'tools_offered',
            'context_received',
            'omitted',
            'called',
            'returned',
            'gate',
            'between_turns',
        ], array_keys($o->answers));
    }

    public function testAnUnknownSessionIsNotAnEmptyObservation(): void
    {
        $o = SessionObservation::of(new InMemoryEventStore(), 'no-existe');

        self::assertFalse($o->exists);
    }

    /**
     * The machine projection carries the same facts as the human one and no more. If the two ever
     * diverge there are two truths again, which is exactly what the role does not need.
     */
    public function testTheMachineProjectionIsTheSameFacts(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());

        $o = SessionObservation::of($eventos, 's1');
        $json = $o->toArray();

        self::assertSame($o->answers, $json['answers']);
        self::assertSame(['omitted'], $json['cannotSay']);
    }
}
