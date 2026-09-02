<?php

/**
 * This file is part of Milpa Agent — long-running coding sessions for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Tests\Support\LegacyTodoWriter;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Agent\WorkSnapshot;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * D-06 (greenhouse decisions/0187): where the WORK stands, derived once from the stream.
 *
 * `house:context` inventories the house's structure; this answers the question a resuming agent
 * keeps re-deriving by re-reading files — objective, what is materialized, what is verified, what is
 * blocked, what cannot be closed, what to do next, and the house's own debt. Every field is proven
 * by the stream, never asserted; these cases hold it to that.
 */
final class WorkSnapshotTest extends TestCase
{
    public function testObjectiveIsThePlanOfRecord(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'goal');
        $store->setPlan('s1', 'ship the Tareas plugin');
        $store->setPlan('s1', 'ship the Tareas plugin, narrowed to the list screen');

        $snap = $this->snapshot($events, 's1');

        self::assertSame('ship the Tareas plugin, narrowed to the list screen', $snap->objective, 'the latest plan wins');
    }

    public function testAVerifiedArtifactIsBothMaterializedAndVerified(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/GreeterService.php","verified":"syntax and namespace"}',
            true,
            true,
        );

        $snap = $this->snapshot($events, 's1');

        self::assertContains('GreeterService', $snap->materialized);
        self::assertContains('GreeterService', $snap->verified);
    }

    /** A verified artifact touched again is materialized but NOT still verified — the stale verdict is refused. */
    public function testASupersededArtifactIsMaterializedButNotVerifiedAndNeedsReverification(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $store->recordToolCall('s1', 'implement', ['plugin' => 'Demo', 'class' => 'Tareas'], '{"ok":true,"verified":"syntax"}', true, true);
        $store->recordToolCall('s1', 'implement', ['plugin' => 'Demo', 'class' => 'Tareas'], '{"ok":true}', true, true);

        $snap = $this->snapshot($events, 's1');

        self::assertContains('Tareas', $snap->materialized);
        self::assertNotContains('Tareas', $snap->verified, 'a superseded verdict is not presented as current');
        self::assertContains('re-verify Tareas (touched after its last verdict)', $snap->nextExecutableActions);
    }

    /** An attempt whose own result said ok:false is not materialized — it is the next action. */
    public function testAnAttemptedArtifactIsNotMaterializedAndIsNextWork(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $store->recordToolCall('s1', 'make', ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'], '{"ok":false,"error":"postcondition failed"}', false, true);

        $snap = $this->snapshot($events, 's1');

        self::assertNotContains('Lista', $snap->materialized);
        self::assertContains('finish materializing Lista (attempted, not proven)', $snap->nextExecutableActions);
    }

    /** A pending question blocks; answering it clears the block. */
    public function testAPendingQuestionBlocksAndAnAnswerClearsIt(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $store->ask('s1', new PendingQuestion('q1', 'Install the external mailer package?', ['yes', 'no'], null, null, 'permission'));

        $blockedSnap = $this->snapshot($events, 's1');
        self::assertSame([['kind' => 'awaiting_human', 'detail' => 'Install the external mailer package?']], $blockedSnap->blocked);

        $store->answer('s1', 'q1', 'no', null, null);
        $clearedSnap = $this->snapshot($events, 's1');
        self::assertSame([], $clearedSnap->blocked, 'an answered question is no longer pending');
    }

    /** A todo claimed done whose named artifact is only attempted is unclosable — the gate would reject it. */
    public function testADoneTodoOverAnUnprovenArtifactIsUnclosable(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $store->recordToolCall('s1', 'make', ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'], '{"ok":false,"error":"boom"}', false, true);
        LegacyTodoWriter::write($events, 's1', new Todo('t1', 'build the Lista screen', TodoStatus::Done));

        $snap = $this->snapshot($events, 's1');

        self::assertCount(1, $snap->unclosable);
        self::assertSame('build the Lista screen', $snap->unclosable[0]['todo']);
        self::assertSame('attempted', $snap->unclosable[0]['state']);
    }

    /** A blocked todo surfaces as a block. */
    public function testABlockedTodoSurfacesAsBlocked(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $store->setTodo('s1', new Todo('t1', 'wait on the DBA', TodoStatus::Blocked));

        $snap = $this->snapshot($events, 's1');

        self::assertContains(['kind' => 'todo_blocked', 'detail' => 'wait on the DBA'], $snap->blocked);
    }

    /** The house's own debt signals are surfaced, read by the fact's literal type (the DebtSignal doctrine). */
    public function testHouseDebtIsSurfaced(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');
        $events->append(new Event(
            SessionStore::PREFIX . 's1',
            'session.debt_signaled',
            ['kind' => 'framework_gap', 'summary' => 'no operation exposes the migration status'],
            $events->nextSeq(),
        ));

        $snap = $this->snapshot($events, 's1');

        self::assertSame([['kind' => 'framework_gap', 'summary' => 'no operation exposes the migration status']], $snap->houseDebt);
    }

    /** An empty session is an honest empty snapshot, not an error. */
    public function testAnEmptySessionIsAnEmptySnapshot(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build');

        $snap = $this->snapshot($events, 's1');

        self::assertSame('', $snap->objective);
        self::assertSame([], $snap->materialized);
        self::assertSame([], $snap->verified);
        self::assertSame([], $snap->blocked);
        self::assertSame([], $snap->unclosable);
        self::assertSame([], $snap->houseDebt);
    }

    private function snapshot(InMemoryEventStore $events, string $session): WorkSnapshot
    {
        return WorkSnapshot::fromEvents($session, $events->replay(SessionStore::PREFIX . $session));
    }
}
