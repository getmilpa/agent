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

use Milpa\Agent\Evidence;
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Semantic progress per model call, derived from the stream and never from the agent's word
 * (greenhouse decisions/0185).
 *
 * The measured wound: a fifth live run burned 811k tokens over 42 calls to materialize 8
 * artifacts, thousands of them spent reasoning about how to write a test instead of writing it
 * (the stalled run is frozen at greenhouse `evidence/fixtures/corrida5-work-mthqbzu6`). These
 * falsifiers hold the receipt to its contract: a philosophize phase MUST derive `stalled`, a
 * build phase MUST derive `advancing`, and nothing outside the measured range may ever count.
 */
final class ProgressReceiptTest extends TestCase
{
    /**
     * The philosophize phase of the frozen run, synthesized: model calls, reasoning, and read-only
     * tool calls — no evidence, no mutation, no closed todo. The receipt MUST say `stalled`;
     * an `advancing` here would bless exactly the behavior the primitive exists to end.
     */
    public function testAPhilosophizePhaseDerivesStalled(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'close the judge-target deadlock');
        $from = $events->nextSeq() - 1;

        for ($i = 0; $i < 3; ++$i) {
            $this->modelCalled($events, 's1');
            $events->append(new Event(
                SessionStore::PREFIX . 's1',
                'session.model_reasoned',
                ['reasoning' => 'maybe the test could be written differently…'],
                $events->nextSeq(),
            ));
            $store->recordToolCall('s1', 'observe', ['target' => 'Judge'], '{"ok":true,"state":"unchanged"}');
        }
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame('stalled', $receipt->progress, 'reading and reasoning are not progress');
        self::assertSame(3, $receipt->calls);
        self::assertSame(3, $receipt->newFacts, 'the read-only ok calls remain visible as facts');
        self::assertSame(0, $receipt->newArtifacts);
        self::assertSame(0, $receipt->newEvidence);
        self::assertSame(0, $receipt->closedTodos);
    }

    /**
     * A build phase — mutating succeeded calls and recorded evidence — MUST derive `advancing`.
     * If this failed, the forced choice would fire on a session doing real work, which is the
     * false positive that kills a guard within a week.
     */
    public function testABuildPhaseDerivesAdvancing(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement the receipt');
        $from = $events->nextSeq() - 1;

        $this->modelCalled($events, 's1');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'Receipt', 'content' => '<?php final class Receipt {}'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/Receipt.php"}',
            true,
            true,
        );
        $store->recordEvidence('s1', Evidence::artifact('e1', 'src/Plugins/Demo/Services/Receipt.php'));
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame('advancing', $receipt->progress);
        self::assertSame(1, $receipt->calls);
        self::assertSame(1, $receipt->newArtifacts, 'the succeeded mutation is the materialization proxy');
        self::assertSame(1, $receipt->newEvidence);
    }

    /** A closed todo alone is growth: the board moved on evidence, so the window advanced. */
    public function testAClosedTodoAloneDerivesAdvancing(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'finish the card');
        $store->setTodo('s1', new Todo('t1', 'write the receipt'));
        $from = $events->nextSeq() - 1;

        $this->modelCalled($events, 's1');
        $store->completeTodo('s1', 't1', Evidence::testPassed('e1', 'vendor/bin/phpunit --filter Receipt', 't1'));
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame('advancing', $receipt->progress);
        self::assertSame(1, $receipt->closedTodos);
        // completeTodo records its evidence first, so the same window carries it too.
        self::assertSame(1, $receipt->newEvidence);
    }

    /** An empty range is `stalled` with zeros — never a crash, never an invented `advancing`. */
    public function testAnEmptyRangeDerivesStalledWithZeros(): void
    {
        $receipt = ProgressReceipt::of([], 7, 7);

        self::assertSame('stalled', $receipt->progress);
        self::assertSame(7, $receipt->fromSeq);
        self::assertSame(7, $receipt->toSeq);
        self::assertSame(0, $receipt->calls);
        self::assertSame(0, $receipt->newFacts);
        self::assertSame(0, $receipt->newArtifacts);
        self::assertSame(0, $receipt->newEvidence);
        self::assertSame(0, $receipt->closedTodos);
        self::assertSame(0, $receipt->newHouseDebt);
    }

    /**
     * Events at or before `fromSeq` NEVER count: the receipt measures the window since the
     * checkpoint, and letting earlier work leak in would let one early materialization excuse
     * an arbitrary number of later philosophize calls.
     */
    public function testEventsBeforeTheCheckpointNeverCount(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'two phases');

        // Phase one: real progress.
        $this->modelCalled($events, 's1');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'Early', 'content' => '<?php'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/Early.php"}',
            true,
            true,
        );
        $store->recordEvidence('s1', Evidence::artifact('e1', 'src/Plugins/Demo/Services/Early.php'));
        $from = $events->nextSeq() - 1;

        // Phase two, after the checkpoint: philosophy only.
        $this->modelCalled($events, 's1');
        $store->recordToolCall('s1', 'observe', ['target' => 'Early'], '{"ok":true}');
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame('stalled', $receipt->progress, 'phase-one progress cannot excuse phase-two philosophy');
        self::assertSame(1, $receipt->calls, 'only the post-checkpoint call counts');
        self::assertSame(0, $receipt->newArtifacts);
        self::assertSame(0, $receipt->newEvidence);
    }

    /** Events after `toSeq` do not count either: the window has two edges, not one. */
    public function testEventsAfterTheWindowDoNotCount(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'a bounded window');
        $from = $events->nextSeq() - 1;
        $this->modelCalled($events, 's1');
        $to = $events->nextSeq() - 1;
        $store->recordEvidence('s1', Evidence::artifact('e1', 'src/After.php'));

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame('stalled', $receipt->progress);
        self::assertSame(0, $receipt->newEvidence, 'evidence recorded after toSeq belongs to the next window');
    }

    /**
     * A mutating call whose own result says `ok:false` is an attempt, not an artifact — the
     * measured `make` wound: the call mutated AND refused to vouch for itself. Counting it would
     * let failed mutations read as materialization.
     */
    public function testAFailedMutationIsAFactButNeverAnArtifact(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'a refused make');
        $from = $events->nextSeq() - 1;
        $this->modelCalled($events, 's1');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":false,"error":"postcondition failed"}',
            false,
            true,
        );
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame(0, $receipt->newFacts, 'a failed call is not a new fact');
        self::assertSame(0, $receipt->newArtifacts);
        self::assertSame('stalled', $receipt->progress);
    }

    /**
     * A mutating call that only ASKED for confirmation did not materialize anything: counting it
     * would repeat the double-count `awaitingConfirmation` was recorded to prevent
     * (greenhouse evidence/0200).
     */
    public function testACallThatOnlyAskedForConfirmationIsNotAnArtifact(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'a confirmation ask');
        $from = $events->nextSeq() - 1;
        $this->modelCalled($events, 's1');
        $store->recordToolCall(
            's1',
            'plugins_disable',
            ['name' => 'Demo'],
            '{"ok":true,"requires_confirmation":true}',
            true,
            true,
            null,
            true,
        );
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame(0, $receipt->newArtifacts, 'asking is not doing');
        self::assertSame('stalled', $receipt->progress);
    }

    /** House debt is counted from the additive event the house emits beside the session's own. */
    public function testHouseDebtSignalsAreCounted(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'a signaled run');
        $from = $events->nextSeq() - 1;
        $this->modelCalled($events, 's1');
        $events->append(new Event(
            SessionStore::PREFIX . 's1',
            'session.debt_signaled',
            ['signal' => 'framework_gap', 'context' => ['digest' => 'judge-target deadlock']],
            $events->nextSeq(),
        ));
        $to = $events->nextSeq() - 1;

        $receipt = ProgressReceipt::of($events->replay(SessionStore::PREFIX . 's1'), $from, $to);

        self::assertSame(1, $receipt->newHouseDebt);
        // Debt named is honesty, not growth: it does not flip the window to advancing by itself.
        self::assertSame('stalled', $receipt->progress);
    }

    /** The telemetry projection carries every field, so a caller can surface it without re-deriving. */
    public function testToArrayCarriesEveryField(): void
    {
        $receipt = ProgressReceipt::of([], 3, 9);

        self::assertSame([
            'fromSeq' => 3,
            'toSeq' => 9,
            'calls' => 0,
            'newFacts' => 0,
            'newArtifacts' => 0,
            'newEvidence' => 0,
            'closedTodos' => 0,
            'newHouseDebt' => 0,
            'progress' => 'stalled',
        ], $receipt->toArray());
    }

    /** Appends one minimal `session.model_called` fact, the unit the receipt counts calls by. */
    private function modelCalled(InMemoryEventStore $events, string $session): void
    {
        $events->append(new Event(
            SessionStore::PREFIX . $session,
            'session.model_called',
            ['model' => 'qwen3.8-27b', 'endpoint' => 'http://llama.local:11438'],
            $events->nextSeq(),
        ));
    }
}
