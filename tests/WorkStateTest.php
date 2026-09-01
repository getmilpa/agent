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

use Milpa\Agent\Compactor;
use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The evidence-backed work state of every artifact a session touched (P0.3).
 *
 * A measured cattle run showed the session cannot distinguish attempted from materialized from
 * verified from current, so the model carried those distinctions in its own reasoning and paid
 * tokens for each. These tests hold the projection to its contract: the lifecycle is DERIVED from
 * facts the stream already declares — never from the agent's word, and never by joining channels
 * the stream does not join.
 */
final class WorkStateTest extends TestCase
{
    /**
     * The measured wound, case (a): `make` created the file AND returned `ok:false`. The call was
     * an attempt whose own result refused to vouch for it, so the artifact is `attempted` — calling
     * it materialized would trust a receipt the producer itself withdrew.
     */
    public function testAMakeWhoseOwnResultSaysNotOkProjectsAttemptedNotMaterialized(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build the Lista screen');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":false,"files":[{"path":"src/Plugins/Demo/Screens/Lista.php","action":"created"}],"error":"postcondition failed"}',
            false,
            true,
        );

        $result = $store->facts('s1')->workStateFor('Lista');

        self::assertTrue($result['ok']);
        self::assertSame('attempted', $result['workState']['state']);
        self::assertNull($result['workState']['materialization'], 'ok:false cannot materialize');
        self::assertCount(1, $result['workState']['attempts']);
        self::assertFalse($result['workState']['attempts'][0]['succeeded'], 'the failed attempt stays visible');
    }

    /**
     * Case (b): an `implement` rejected on the first try and landed on the second. The landed call
     * materializes the artifact; the rejection remains a visible attempt instead of vanishing into
     * the agent's memory.
     */
    public function testARejectedImplementFollowedByALandedOneIsMaterializedWithTheRejectionVisible(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement Tareas');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'Tareas', 'content' => '<?php bad'],
            '{"ok":false,"error":"syntax error"}',
            false,
            true,
        );
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'Tareas', 'content' => '<?php final class Tareas {}'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/Tareas.php"}',
            true,
            true,
        );
        $landedSeq = $events->nextSeq() - 1;

        $result = $store->facts('s1')->workStateFor('Tareas');

        self::assertTrue($result['ok']);
        self::assertSame('materialized', $result['workState']['state']);
        self::assertSame($landedSeq, $result['workState']['materialization']['seq'], 'the landed call is the one cited');
        self::assertCount(2, $result['workState']['attempts']);
        self::assertFalse($result['workState']['attempts'][0]['succeeded'], 'the rejection is visible, not erased');
        self::assertTrue($result['workState']['attempts'][1]['succeeded']);
    }

    /** A producer-declared verification promotes the artifact to `verified`, citing the declaring call. */
    public function testAProducerDeclaredVerificationPromotesToVerified(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/GreeterService.php","verified":"syntax and namespace"}',
            true,
            true,
        );

        $result = $store->facts('s1')->workStateFor('GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('verified', $result['workState']['state']);
        self::assertTrue($result['workState']['verification']['verified']);
        self::assertSame('implement', $result['workState']['verification']['operation']);
    }

    /**
     * A mutating call AFTER a verification supersedes it: the stream cannot prove the verified state
     * survived the later touch, so the projection fails closed instead of presenting a stale verdict.
     */
    public function testALaterMutatingCallSupersedesAVerification(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement then edit a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            '{"ok":true,"verified":"syntax and namespace"}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'edit',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'search' => 'a', 'replace' => 'b'],
            '{"ok":true}',
            true,
            true,
        );
        $editSeq = $events->nextSeq() - 1;

        $result = $store->facts('s1')->workStateFor('GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('superseded', $result['workState']['state']);
        self::assertSame(['seq' => $editSeq, 'operation' => 'edit'], $result['workState']['supersededBy']);
    }

    /** A verification declared by the LATEST touch re-verifies the artifact: nothing mutated after it. */
    public function testAVerifyingEditAfterASupersededVerificationIsVerifiedAgain(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement, edit, verify');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            '{"ok":true,"verified":"syntax"}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'edit',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'search' => 'a', 'replace' => 'b'],
            '{"ok":true,"verified":"syntax after edit"}',
            true,
            true,
        );

        $result = $store->facts('s1')->workStateFor('GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('verified', $result['workState']['state']);
        self::assertNull($result['workState']['supersededBy']);
    }

    /**
     * A call that only ASKED — `awaitingConfirmation: true` — did not materialize anything, even
     * though the operation is mutating and the call came back ok. Counting it would repeat the
     * double-count the flag was recorded to prevent.
     */
    public function testACallThatOnlyAskedForConfirmationDoesNotMaterialize(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'ask before making');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":true,"confirm":"make Lista?"}',
            true,
            true,
            null,
            true,
        );

        $result = $store->facts('s1')->workStateFor('Lista');

        self::assertTrue($result['ok']);
        self::assertSame('attempted', $result['workState']['state']);
        self::assertNull($result['workState']['materialization']);
    }

    /** A failed producer verification stays visible as a verdict without promoting the state. */
    public function testAFailedVerificationIsVisibleWithoutPromotingTheState(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'scaffold an entity');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'entity', 'plugin' => 'Demo', 'name' => 'Order'],
            '{"ok":false,"files":[],"verify":{"ok":false,"output":"does not implement EntityInterface"}}',
            false,
            true,
        );

        $result = $store->facts('s1')->workStateFor('Order');

        self::assertTrue($result['ok']);
        self::assertSame('attempted', $result['workState']['state']);
        self::assertFalse($result['workState']['verification']['verified'], 'the failed verdict is named, not censored');
    }

    /** A todo that names an artifact nobody called yet answers `planned` — the pre-work state. */
    public function testATodoNamingAnUntouchedArtifactProjectsPlanned(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'plan the work');
        $store->setTodo('s1', new Todo('t1', 'write the GreeterService entity'));

        $result = $store->facts('s1')->workStateFor('GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('planned', $result['workState']['state']);
        self::assertSame('t1', $result['workState']['planned']['todo']);
        self::assertSame([], $result['workState']['attempts']);
    }

    /** An artifact nothing touched and nothing planned is not found — `ok:false`, never a guess. */
    public function testAnUnknownArtifactIsNotFound(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->recordToolCall('s1', 'make', ['what' => 'service', 'plugin' => 'Demo', 'name' => 'Lista'], '{"ok":true}', true, true);

        $facts = $store->facts('s1');

        self::assertFalse($facts->workStateFor('Ghost')['ok']);
        self::assertFalse($facts->workStateFor('   ')['ok']);
    }

    /** The enumeration lists every artifact the calls touched, and links the todos that name each. */
    public function testWorkStateEnumeratesArtifactsAndLinksTheTodosNamingThem(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build the Lista screen');
        $store->setTodo('s1', new Todo('t1', 'construir la pantalla Lista', TodoStatus::InProgress));
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":true,"files":[{"path":"src/Plugins/Demo/Screens/Lista.php","action":"created"}]}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'Tareas', 'content' => '<?php'],
            '{"ok":true}',
            true,
            true,
        );

        $result = $store->facts('s1')->workState();

        self::assertTrue($result['ok']);
        self::assertCount(2, $result['artifacts']);
        $lista = $result['artifacts'][0];
        self::assertSame('Lista', $lista['artifact']['value']);
        self::assertSame('materialized', $lista['state']);
        self::assertSame(['t1'], $lista['todos'], 'the todo naming the artifact is linked');
        self::assertSame('t1', $lista['planned']['todo']);
        self::assertContains(
            ['kind' => 'path', 'value' => 'src/Plugins/Demo/Screens/Lista.php'],
            $lista['identities'],
        );
        self::assertSame('Tareas', $result['artifacts'][1]['artifact']['value']);
        self::assertSame([], $result['artifacts'][1]['todos']);
        self::assertNull($result['artifacts'][1]['planned']);
    }

    /** A session with no touched artifacts enumerates an honest empty list, not an error. */
    public function testASessionWithNoArtifactsEnumeratesEmpty(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'nothing yet');

        $result = $store->facts('s1')->workState();

        self::assertTrue($result['ok']);
        self::assertSame([], $result['artifacts']);
    }

    /**
     * Two spellings of one artifact — the argument name and the returned path — fold into ONE
     * lifecycle entry: the rejected first call and the landed second are the same artifact's story.
     */
    public function testTwoSpellingsOfOneArtifactFoldIntoOneEntry(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/GreeterService.php","class":"App\\\\Plugins\\\\Demo\\\\Services\\\\GreeterService"}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'edit',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'search' => 'a', 'replace' => 'b'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/GreeterService.php"}',
            true,
            true,
        );

        $result = $store->facts('s1')->workState();

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['artifacts'], 'one artifact, two calls, one entry');
        self::assertCount(2, $result['artifacts'][0]['attempts']);
    }

    /**
     * Case (c): after a real Compactor cut, the work state survives inside the operational-facts
     * block, so the lifecycle reaches the model window without re-running anything.
     */
    public function testWorkStateSurvivesACompactionCutInsideTheFactsBlock(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build the Lista screen');
        $store->setTodo('s1', new Todo('t1', 'construir la pantalla Lista', TodoStatus::InProgress));
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":true,"files":[{"path":"src/Plugins/Demo/Screens/Lista.php","action":"created"}]}',
            true,
            true,
        );
        for ($i = 1; $i <= 5; ++$i) {
            $store->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', "filler {$i}");
        }

        self::assertNotNull((new Compactor(maxTurns: 3, keepRecent: 1))->compactIfNeeded(
            $store,
            $store->load('s1') ?? self::fail('session did not load'),
        ));

        $summary = $store->load('s1')?->summary ?? self::fail('compaction left no summary');
        $marker = 'Operational facts (JSON; calls do not prove execution): ';
        $markerAt = strpos($summary, $marker);
        self::assertNotFalse($markerAt);
        $snapshot = json_decode(substr($summary, $markerAt + strlen($marker)), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($snapshot);
        self::assertSame('Lista', $snapshot['workState'][0]['artifact']['value']);
        self::assertSame('materialized', $snapshot['workState'][0]['state']);
        self::assertSame(['t1'], $snapshot['workState'][0]['todos']);
    }

    /**
     * Case (d), the wound that stalled the run: a truncated fact must say how to recover the full
     * value. The recovery names the recorded operation and the digest identifying its arguments,
     * says the same call is on record, and leaves re-invoking to the caller — it never claims the
     * data is lost, and never urges a blind re-run.
     */
    public function testATruncatedFactCarriesTheRefetchRecovery(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'inspect a contract');
        $raw = (string) json_encode(['ok' => true, 'contract' => str_repeat('m', 1_300)]);
        $store->recordToolCall(
            's1',
            'artifact_contract',
            ['plugin' => 'Demo', 'name' => 'GreeterService'],
            $raw,
            true,
            false,
            strlen($raw),
        );
        $store->recordToolCall('s1', 'make', ['what' => 'service', 'plugin' => 'Demo', 'name' => 'Otro'], '{"ok":true}', true, true);

        $facts = $store->facts('s1')->operationalFacts(\PHP_INT_MAX);

        $truncated = $facts['calls'][0];
        self::assertTrue($truncated['resultTruncated']);
        self::assertSame('artifact_contract', $truncated['refetch']['operation']);
        self::assertTrue($truncated['refetch']['sameCallRecorded']);
        $expectedDigest = 'sha256:' . hash('sha256', '{"name":"GreeterService","plugin":"Demo"}');
        self::assertSame($expectedDigest, $truncated['refetch']['argumentsDigest'], 'the digest identifies the recorded arguments, canonically');

        self::assertArrayNotHasKey('refetch', $facts['calls'][1], 'an untruncated fact needs no recovery');
    }

    /** The narrow recovery queries carry the same refetch honesty when their answer is truncated. */
    public function testATruncatedNarrowQueryResultAlsoCarriesTheRefetch(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'inspect a contract');
        $raw = (string) json_encode(['ok' => true, 'contract' => str_repeat('m', 5_000)]);
        $store->recordToolCall(
            's1',
            'artifact_contract',
            ['plugin' => 'Demo', 'name' => 'GreeterService'],
            $raw,
            true,
            false,
            strlen($raw),
        );

        $result = $store->facts('s1')->operationResult('artifact_contract', 'GreeterService');

        self::assertTrue($result['ok']);
        self::assertTrue($result['call']['resultTruncated']);
        self::assertSame('artifact_contract', $result['call']['refetch']['operation']);
        self::assertTrue($result['call']['refetch']['sameCallRecorded']);
    }

    /** Malformed arguments still digest deterministically: a foreign stream cannot break recovery. */
    public function testMalformedArgumentsStillProduceADeterministicRefetchDigest(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'read a foreign stream');
        $events->append(new Event(
            streamId: SessionStore::PREFIX . 's1',
            type: SessionEvent::ToolCalled->value,
            payload: [
                'tool' => 'implement',
                'arguments' => 'not-an-array',
                'result' => str_repeat('x', 700),
                'ok' => true,
                'mutating' => true,
            ],
            seq: $events->nextSeq(),
        ));

        $facts = $store->facts('s1')->operationalFacts(\PHP_INT_MAX);

        self::assertTrue($facts['calls'][0]['resultTruncated']);
        self::assertSame(
            'sha256:' . hash('sha256', '"not-an-array"'),
            $facts['calls'][0]['refetch']['argumentsDigest'],
        );
    }

    /**
     * Deliverable 3: the board's todo card surfaces the derived work state of the artifact the todo
     * names, so «done but unverified» and «attempted but not materialized» are visible states.
     */
    public function testTheBoardTodoCardSurfacesTheDerivedWorkState(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build the Lista screen');
        $store->recordTurn('s1', 'user', 'haz la pantalla');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":false,"files":[{"path":"src/Plugins/Demo/Screens/Lista.php","action":"created"}]}',
            false,
            true,
        );
        // The raw done: the agent's word, with nothing behind it — visible as such on the card.
        $store->setTodo('s1', new Todo('t1', 'construir la pantalla Lista', TodoStatus::Done));

        $cards = (new SessionProjector())->boardCards($events->replay(SessionStore::PREFIX . 's1'));
        $todoCard = null;
        foreach ($cards as $card) {
            if (($card['card']['origin'] ?? null) === 'todo') {
                $todoCard = $card;
            }
        }

        self::assertNotNull($todoCard);
        self::assertSame('done', $todoCard['card']['to'], 'the agent said done');
        self::assertSame('attempted', $todoCard['card']['workState'], 'the stream says only attempted');
        self::assertSame('Lista', $todoCard['card']['workStateArtifact']);
    }

    /** A todo naming no artifact the session touched carries an honest `null`, never a guess. */
    public function testATodoNamingNoTouchedArtifactCarriesNullWorkState(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->setTodo('s1', new Todo('t1', 'think about the roadmap', TodoStatus::Done));

        $cards = (new SessionProjector())->boardCards($events->replay(SessionStore::PREFIX . 's1'));

        self::assertNotNull($cards[0]);
        self::assertNull($cards[0]['card']['workState']);
        self::assertNull($cards[0]['card']['workStateArtifact']);
    }

    /**
     * A todo naming TWO artifacts reads the WEAKEST state — fail closed: a card is only as done as
     * its least-proven artifact.
     */
    public function testATodoNamingTwoArtifactsReadsTheWeakestState(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'greeter and lista');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            '{"ok":true,"verified":"syntax"}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'],
            '{"ok":false}',
            false,
            true,
        );
        $store->setTodo('s1', new Todo('t1', 'GreeterService saluda en la pantalla Lista', TodoStatus::Done));

        $state = $store->facts('s1')->workStateForTodo('t1');

        self::assertNotNull($state);
        self::assertSame('attempted', $state['state'], 'verified + attempted reads attempted: fail closed');
        self::assertSame('Lista', $state['artifact']);
    }

    /**
     * Grouping and naming survive foreign shapes: a call whose only identity is a returned path
     * still folds with a later call naming the bare leaf, the merged entry gains the new spelling,
     * a todo naming just the leaf still links, and an empty-texted todo links nowhere.
     */
    public function testGroupingAndNamingSurviveForeignShapes(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build the Lista screen');
        // A foreign producer: no `name` argument, so the ONLY identity is the returned path.
        $events->append(new Event(
            streamId: SessionStore::PREFIX . 's1',
            type: SessionEvent::ToolCalled->value,
            payload: [
                'tool' => 'make',
                'arguments' => ['what' => 'screen', 'plugin' => 'Demo'],
                'result' => '{"ok":true,"files":[{"path":"src/Plugins/Demo/Screens/Lista.php","action":"created"}]}',
                'ok' => true,
                'mutating' => true,
            ],
            seq: $events->nextSeq(),
        ));
        $store->recordToolCall('s1', 'make', ['what' => 'screen', 'plugin' => 'Demo', 'name' => 'Lista'], '{"ok":true}', true, true);
        $store->recordToolCall('s1', 'implement', ['plugin' => 'Demo', 'class' => 'Tareas', 'content' => '<?php'], '{"ok":true}', true, true);
        $store->setTodo('s1', new Todo('t1', 'construir la pantalla Lista', TodoStatus::InProgress));
        $store->setTodo('s1', new Todo('t2', ''));

        $result = $store->facts('s1')->workState();

        self::assertTrue($result['ok']);
        self::assertCount(2, $result['artifacts'], 'path spelling and bare leaf fold into one entry');
        $lista = $result['artifacts'][0];
        self::assertSame('src/Plugins/Demo/Screens/Lista.php', $lista['artifact']['value']);
        self::assertContains(['kind' => 'name', 'value' => 'Lista'], $lista['identities'], 'the merged entry gained the new spelling');
        self::assertSame(['t1'], $lista['todos'], 'the leaf names it; the empty-texted todo links nowhere');

        $state = $store->facts('s1')->workStateForTodo('t1');
        self::assertNotNull($state);
        self::assertSame('materialized', $state['state']);
        self::assertSame('src/Plugins/Demo/Screens/Lista.php', $state['artifact']);
    }

    /** The store's facts() door answers the same projection as building it by hand. */
    public function testTheStoreDoorAndTheDirectProjectionAgree(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->recordToolCall('s1', 'make', ['what' => 'service', 'plugin' => 'Demo', 'name' => 'Lista'], '{"ok":true}', true, true);

        self::assertSame(
            SessionFacts::of($events, 's1')->workState(),
            $store->facts('s1')->workState(),
        );
    }
}
