<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\Evidence;
use Milpa\Agent\EvidenceKind;
use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
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
     * A `done` reached the RAW way, with nothing behind it, is not rejected — it is recorded and
     * FLAGGED. The system records what happened; it names the unevidenced done rather than hiding or
     * censoring it, exactly as it names an unsupported birth.
     */
    public function testARawDoneWithNoEvidenceIsRecordedButFlaggedUnverified(): void
    {
        $store = $this->store();
        $store->start('s1', 'ship it');
        $store->setTodo('s1', new Todo('t1', 'ship it', TodoStatus::Pending));
        $store->setTodo('s1', new Todo('t1', 'ship it', TodoStatus::Done));

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertSame(TodoStatus::Done, $session->todos[0]->status, 'nothing is censored: the done lands');
        self::assertFalse($session->isDoneVerified('t1'), 'but the ledger cannot vouch for it');
        self::assertSame([], $store->evidenceFor('s1', 't1'), 'and there is nothing to show as closing it');

        $unverified = $session->unverifiedDones();
        self::assertCount(1, $unverified, 'it is named, so a verifier can count it');
        self::assertSame('t1', $unverified[0]->id);
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
     * The stamp on the `done` transition is DERIVED from the ledger, not the caller's word: recording
     * the evidence before the transition makes a plain {@see SessionStore::setTodo()} done read as
     * verified, because the store reads the ledger it just grew.
     */
    public function testTheDoneStampIsDerivedFromTheLedger(): void
    {
        $store = $this->store();
        $store->start('s1', 'a goal');
        $store->setTodo('s1', new Todo('t1', 'a task', TodoStatus::Pending));
        $store->recordEvidence('s1', Evidence::operationOk('e1', 'config.set sha256:abc', 't1'));

        // A raw setTodo — not completeTodo — still reads as verified, because the evidence is already
        // in the ledger and the stamp is observed, not declared.
        $store->setTodo('s1', new Todo('t1', 'a task', TodoStatus::Done));

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertTrue($session->isDoneVerified('t1'));
    }

    /**
     * A STALE STREAM STILL LOADS: an old `done` written before this feature existed carries no stamp
     * and no ledger, and it must reconstruct as an unverified done rather than break — backward
     * compatibility is what an append-only log is for.
     */
    public function testAStreamWithNoEvidenceStillProjectsAsAnUnverifiedDone(): void
    {
        $store = $this->store();
        $store->start('s1', 'legacy');
        $store->setTodo('s1', new Todo('t1', 'done long ago', TodoStatus::Done));

        $session = $store->load('s1');
        self::assertNotNull($session);
        self::assertSame(TodoStatus::Done, $session->todos[0]->status);
        self::assertFalse($session->isDoneVerified('t1'), 'no ledger means it cannot be vouched for');
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
