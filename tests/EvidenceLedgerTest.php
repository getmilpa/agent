<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\Evidence;
use Milpa\Agent\EvidenceKind;
use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Tests\Support\LegacyTodoWriter;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A per-session evidence ledger, and todos whose `done` is grounded in it (D2, backlog).
 *
 * A todo used to reach `done` on the agent's word alone — a real audit caught the agent claiming
 * progress it had not grounded. These tests hold the fix to its contract: evidence is an append-only
 * ledger folded from the same stream as everything else; the sanctioned completion path REQUIRES a
 * verifiable piece and fails closed without one; and a raw `done` with nothing behind it is NAMED
 * unevidenced, not censored — the same doctrine {@see \Milpa\Agent\TodoOrigin::Unsupported} applies at
 * birth. What closed a todo is queryable from the fold, never inferred.
 */
final class EvidenceLedgerTest extends TestCase
{
    private function store(): SessionStore
    {
        return new SessionStore(new InMemoryEventStore());
    }

    /**
     * THE SANCTIONED PATH: a todo closed through {@see SessionStore::completeTodo()} lands `done`
     * AND carries the verifiable evidence that closed it, tied to the card.
     */
    public function testCompletingATodoWithEvidenceMarksItDoneAndVerified(): void
    {
        $store = $this->store();
        $store->start('s1', 'write the entity');
        $store->setTodo('s1', new Todo('t1', 'write the entity', TodoStatus::Pending));

        $store->completeTodo('s1', 't1', Evidence::testPassed('e1', 'vendor/bin/phpunit'));

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertSame(TodoStatus::Done, $session->todos[0]->status, 'the card moved to done');
        self::assertTrue($session->isDoneVerified('t1'), 'and the ledger vouches for it');

        $evidence = $store->evidenceFor('s1', 't1');
        self::assertCount(1, $evidence, 'the evidence that closed it is queryable from the todo');
        self::assertSame(EvidenceKind::TestPassed, $evidence[0]->kind);
        self::assertSame('vendor/bin/phpunit', $evidence[0]->reference, 'the reference is what a reader re-runs');
        self::assertSame('t1', $evidence[0]->todo, 'it is tied to the todo it closed');
    }

    /**
     * THE GRADUATION (greenhouse decisions/0183): the transition to `done` without evidence CEASES
     * TO EXIST. What record-and-flag tolerated — a bare `setTodo(Done)` landing stamped
     * `evidenced: false` — is now refused at the door, and NOTHING moves. This test held the old
     * compat; it flips to hold the new law, exactly as the ruling licenses.
     */
    public function testARawDoneWithNoEvidenceIsRefusedAndNothingMoves(): void
    {
        $store = $this->store();
        $store->start('s1', 'ship it');
        $store->setTodo('s1', new Todo('t1', 'ship it', TodoStatus::Pending));

        try {
            $store->setTodo('s1', new Todo('t1', 'ship it', TodoStatus::Done));
            self::fail('a done without evidence does not exist: setTodo must refuse the transition');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('completeTodo', $refusal->getMessage(), 'the refusal names the door');
        }

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertSame(TodoStatus::Pending, $session->todos[0]->status, 'the card did not move');
        self::assertSame([], $session->unverifiedDones(), 'and no unverified done was minted');
    }

    /** Creating a card ALREADY done is the same refused transition: born-done was a birth, not work. */
    public function testCreatingATodoAsDoneIsRefused(): void
    {
        $store = $this->store();
        $store->start('s1', 'ship it');

        try {
            $store->setTodo('s1', new Todo('t1', 'ship it', TodoStatus::Done));
            self::fail('a card cannot be born done through setTodo');
        } catch (\LogicException) {
            // expected: the transition does not exist
        }

        self::assertSame([], $store->load('s1')?->todos ?? ['sentinel'], 'nothing was written');
    }

    /** A blocked card cannot jump to done through the raw door either: the law is by target, not by source. */
    public function testMovingABlockedTodoToDoneIsRefused(): void
    {
        $store = $this->store();
        $store->start('s1', 'unblock it');
        $store->setTodo('s1', new Todo('t1', 'unblock it', TodoStatus::Blocked));

        $this->expectException(\LogicException::class);
        $store->setTodo('s1', new Todo('t1', 'unblock it', TodoStatus::Done));
    }

    /**
     * THE GATE FAILS CLOSED on unverifiable evidence: a piece that points at nothing cannot re-check,
     * so it cannot close a todo, and NOTHING moves — not the card, not the ledger.
     */
    public function testCompletingWithUnverifiableEvidenceIsRefusedAndNothingMoves(): void
    {
        $store = $this->store();
        $store->start('s1', 'do the thing');
        $store->setTodo('s1', new Todo('t1', 'do the thing', TodoStatus::Pending));

        try {
            $store->completeTodo('s1', 't1', Evidence::artifact('e1', '   '));
            self::fail('a todo must not close on evidence that points at nothing');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertSame(TodoStatus::Pending, $session->todos[0]->status, 'the card did not move');
        self::assertSame([], $store->evidenceFor('s1', 't1'), 'and no evidence was recorded');
    }

    /** Evidence cannot close a todo that does not exist: it is refused before anything is written. */
    public function testCompletingATodoThatDoesNotExistIsRefused(): void
    {
        $store = $this->store();
        $store->start('s1', 'a goal');

        $this->expectException(\InvalidArgumentException::class);
        $store->completeTodo('s1', 'ghost', Evidence::testPassed('e1', 'phpunit'));
    }

    /** {@see SessionStore::recordEvidence()} refuses a piece with no reference: the ledger holds evidence, not claims. */
    public function testRecordingEvidenceWithoutAReferenceIsRefused(): void
    {
        $store = $this->store();
        $store->start('s1', 'a goal');

        $this->expectException(\InvalidArgumentException::class);
        $store->recordEvidence('s1', Evidence::operationOk('e1', ''));
    }

    /**
     * A todo can be closed by MORE THAN ONE piece of evidence — the ledger accumulates, it does not
     * overwrite. Both the standalone {@see SessionStore::recordEvidence()} and {@see SessionStore::completeTodo()}
     * feed the same ledger.
     */
    public function testEvidenceAccumulatesForATodo(): void
    {
        $store = $this->store();
        $store->start('s1', 'build the feature');
        $store->setTodo('s1', new Todo('t1', 'build the feature', TodoStatus::Pending));

        $store->recordEvidence('s1', Evidence::artifact('e1', 'src/Feature.php', 't1'));
        $store->completeTodo('s1', 't1', Evidence::testPassed('e2', 'vendor/bin/phpunit --filter Feature'));

        $evidence = $store->evidenceFor('s1', 't1');
        self::assertCount(2, $evidence, 'both pieces are kept');
        self::assertSame(['e1', 'e2'], array_map(static fn (Evidence $e): string => $e->id, $evidence));
    }

    /**
     * The refusal is by TRANSITION, not by ledger state: even with covering evidence already
     * recorded, the raw {@see SessionStore::setTodo()} does not own the move to `done`. The claim
     * travels through {@see SessionStore::completeTodo()} — one door, so the house always judges
     * the evidence against the claim instead of trusting that somebody else already did.
     */
    public function testEvenWithEvidenceInTheLedgerTheRawDoorRefusesDone(): void
    {
        $store = $this->store();
        $store->start('s1', 'a goal');
        $store->setTodo('s1', new Todo('t1', 'a task', TodoStatus::Pending));
        $store->recordEvidence('s1', Evidence::operationOk('e1', 'config.set sha256:abc', 't1'));

        try {
            $store->setTodo('s1', new Todo('t1', 'a task', TodoStatus::Done));
            self::fail('the raw door must refuse done even when the ledger could vouch for it');
        } catch (\LogicException) {
            // expected: completeTodo is the only path to done
        }

        // And through the sanctioned door the same claim lands, verified.
        $store->completeTodo('s1', 't1', Evidence::operationOk('e2', 'config.set sha256:abc'));
        self::assertTrue($store->load('s1')?->isDoneVerified('t1'));
    }

    /**
     * HISTORY IS TOLERATED, NEW WRITES ARE GOVERNED: a stream carrying an old bare `done` — written
     * before the graduation, simulated here as raw history because the door no longer produces it —
     * still loads, still projects `evidenced: false`, and {@see Session::unverifiedDones()} still
     * names it. The law changed the WRITE door, never the fold.
     */
    public function testAStreamCarryingOldBareDonesStillLoadsAndNamesThem(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'legacy');
        LegacyTodoWriter::write($events, 's1', new Todo('t1', 'done long ago', TodoStatus::Done));

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertSame(TodoStatus::Done, $session->todos[0]->status, 'the old done loads');
        self::assertFalse($session->isDoneVerified('t1'), 'no ledger means it cannot be vouched for');
        self::assertSame(['t1'], array_map(static fn (Todo $t): string => $t->id, $session->unverifiedDones()));

        $cards = (new SessionProjector())->projectAll($events->replay('agent-session:s1'));
        $done = array_values(array_filter(
            $cards,
            static fn (array $c): bool => $c['kind'] === 'card' && ($c['card']['to'] ?? '') === 'done',
        ));
        self::assertCount(1, $done, 'the historical done still projects');
        self::assertFalse($done[0]['card']['evidenced'], 'named unevidenced, not censored');
    }

    /** The projector translates an evidence event so a surface can paint what closed a card. */
    public function testTheProjectorRendersRecordedEvidence(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'a goal');
        $store->setTodo('s1', new Todo('t1', 'a task', TodoStatus::Pending));
        $store->completeTodo('s1', 't1', Evidence::artifact('e1', 'docs/report.md', null, 'the written report'));

        $cards = (new SessionProjector())->projectAll(iterator_to_array($events->replay('agent-session:s1')));

        $evidenceCards = array_values(array_filter($cards, static fn (array $c): bool => $c['kind'] === 'evidence'));
        self::assertCount(1, $evidenceCards, 'the evidence surfaced as its own kind');
        self::assertSame('docs/report.md', $evidenceCards[0]['card']['reference']);
        self::assertSame('t1', $evidenceCards[0]['card']['todo']);
        self::assertSame('artifact_created', $evidenceCards[0]['card']['evidenceKind']);
    }

    /** The done card carries the derived `evidenced` flag so a stateless surface can flag it. */
    public function testTheDoneCardCarriesTheEvidencedFlag(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'a goal');
        $store->setTodo('s1', new Todo('t1', 'a task', TodoStatus::Pending));
        $store->completeTodo('s1', 't1', Evidence::testPassed('e1', 'phpunit'));

        $projector = new SessionProjector();
        $doneCard = null;
        foreach ($events->replay('agent-session:s1') as $event) {
            $projected = $projector->project($event);
            if ($projected !== null && $projected['kind'] === 'card' && ($projected['card']['to'] ?? '') === 'done') {
                $doneCard = $projected;
            }
        }

        self::assertNotNull($doneCard, 'the done transition projected a card');
        self::assertTrue($doneCard['card']['evidenced'], 'and it is flagged as evidenced');
    }
}
