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
     * WHAT WAS WITHHELD IS READ FROM ITS DECLARATION, NEVER SUBTRACTED.
     *
     * The withdrawal is already a fact of the stream: whoever withdraws an option records it with a
     * stable reason code at the moment of withdrawing. Reading that is not the same as computing it —
     * asking a registry how many tools exist and subtracting would produce a number the channel never
     * gave, which is exactly the falsifier this class exists under.
     */
    public function testWhatWasWithheldIsReadFromItsDeclaration(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake(['config_set']));
        $almacen->removeOption('s1', 'plugins_list', 'denied-by-operator', 'whoever ran this agent excluded it');

        $o = SessionObservation::of($eventos, 's1');

        self::assertTrue($o->answers['omitted']['answered']);
        self::assertSame(
            [['tool' => 'plugins_list', 'code' => 'denied-by-operator']],
            array_map(
                static fn (array $w): array => ['tool' => $w['tool'], 'code' => $w['code']],
                $o->answers['omitted']['value']['withdrawn'],
            ),
        );
    }

    /**
     * NOTHING WITHDRAWN IS AN ANSWER, not a silence.
     *
     * Reading every declaration in the session and finding none is a measurement with a result. What
     * it does NOT claim is that nothing was withheld — a filter that withdraws without declaring is
     * invisible here by construction, and that boundary is inherited from the channel rather than
     * introduced by this class.
     */
    public function testNoWithdrawalIsAnAnsweredQuestion(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());

        $o = SessionObservation::of($eventos, 's1');

        self::assertTrue($o->answers['omitted']['answered']);
        self::assertSame([], $o->answers['omitted']['value']['withdrawn']);
    }

    /**
     * THE EDGE THAT WOULD MAKE THE VIEW LIE THE OTHER WAY.
     *
     * `record-only` records the withdrawal and keeps offering the tool. Against it, «every
     * option_removed is an omission» would report something as withheld that actually travelled — so
     * the declaration is crossed against what travelled, and the two cases are named apart.
     */
    public function testSomethingRecordedButStillOfferedIsNotReportedAsWithheld(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake(['plugins_list', 'config_set']));
        $almacen->removeOption('s1', 'plugins_list', 'refused-by-gate', 'the gate refused it');

        $o = SessionObservation::of($eventos, 's1');
        $v = $o->answers['omitted']['value'];

        self::assertSame([], $v['withdrawn'], 'it travelled, so it was not withheld');
        self::assertSame(['plugins_list'], array_column($v['recordedButStillOffered'], 'tool'));
    }

    /**
     * THE CONTROL THAT DECIDES.
     *
     * If the omission were computed by subtracting what was offered from some catalogue, changing
     * what the agent was offered would move the answer. It must not: the withdrawals are read from
     * their declarations, and nothing else feeds them.
     */
    public function testChangingWhatWasOfferedDoesNotMoveTheOmission(): void
    {
        $conMuchas = new InMemoryEventStore();
        $a = $this->store($conMuchas);
        $a->start('s1', 'x');
        $a->recordModelCall('s1', $this->intake(['config_set', 'plan', 'todo', 'agent_spawn']));
        $a->removeOption('s1', 'plugins_list', 'denied-by-operator', 'x');

        $conPocas = new InMemoryEventStore();
        $b = $this->store($conPocas);
        $b->start('s1', 'x');
        $b->recordModelCall('s1', $this->intake(['config_set']));
        $b->removeOption('s1', 'plugins_list', 'denied-by-operator', 'x');

        self::assertSame(
            SessionObservation::of($conMuchas, 's1')->answers['omitted'],
            SessionObservation::of($conPocas, 's1')->answers['omitted'],
        );
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

    /**
     * The store knows how to observe itself, so a surface never has to reach past it for the raw
     * event store. Handing out the stream to build a view is how a second reader of the same facts
     * appears, and two readers drift.
     */
    public function testTheStoreOffersTheObservationWithoutHandingOutTheStream(): void
    {
        $almacen = $this->store($eventos = new InMemoryEventStore());
        $almacen->start('s1', 'x');
        $almacen->recordModelCall('s1', $this->intake());

        self::assertSame(
            SessionObservation::of($eventos, 's1')->toArray(),
            $almacen->observation('s1')->toArray(),
        );
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
        self::assertSame([], $json['cannotSay']);
    }
}
