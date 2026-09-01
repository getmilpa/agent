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
use Milpa\Agent\Principal;
use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Narrow, read-only projections for ordinary session recovery.
 *
 * The projection reads the same append-only stream as the full observation, but returns only the
 * requested fact. In particular, an implementation's complete source argument must not ride back in
 * a query whose answer is the landing result.
 */
final class SessionFactsTest extends TestCase
{
    public function testLastCallForAnArtifactReturnsOnlyNarrowFields(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            [
                'plugin' => 'Demo',
                'class' => 'GreeterService',
                'content' => str_repeat('source-that-must-not-return-', 2_000),
            ],
            (string) json_encode([
                'ok' => true,
                'file' => 'src/Plugins/Demo/Services/GreeterService.php',
                'class' => 'App\\Plugins\\Demo\\Services\\GreeterService',
                'verified' => 'syntax, strict_types, class and namespace',
            ]),
            true,
            true,
            awaitingConfirmation: false,
        );
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'OtherService'],
            '{"ok":true}',
            true,
            true,
        );

        $result = SessionFacts::of($events, 's1')->lastCallForArtifact('GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('implement', $result['call']['operation']);
        self::assertArrayNotHasKey('content', $result['call']['target']);
        self::assertSame(
            ['plugin' => 'Demo', 'class' => 'GreeterService'],
            $result['call']['target'],
        );
        self::assertTrue($result['call']['succeeded']);
        self::assertFalse($result['call']['awaitingConfirmation']);
        self::assertLessThan(
            1_000,
            strlen((string) json_encode($result)),
            'a 58K source argument must not ride back in a narrow result query',
        );
    }

    public function testOperationResultSelectsTheLastSpecificMake(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'scaffold services');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":true,"files":[{"path":"first.php","action":"created"}]}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'OtherService'],
            '{"ok":true,"files":[{"path":"other.php","action":"created"}]}',
            true,
            true,
        );
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":false,"files":[],"error":"already exists"}',
            false,
            true,
        );

        $result = SessionFacts::of($events, 's1')->operationResult('make', 'GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('make', $result['call']['operation']);
        self::assertFalse($result['call']['succeeded'], 'the query succeeded; the selected make did not');
        self::assertSame('already exists', $result['call']['result']['error']);
    }

    public function testALargeResultIsBoundedAndDeclaresTheProjectionCut(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'scaffold a service');
        $raw = (string) json_encode(['ok' => true, 'output' => str_repeat('x', 20_000)]);
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'GreeterService'],
            $raw,
            true,
            true,
            strlen($raw),
        );

        $result = SessionFacts::of($events, 's1')->operationResult('make', 'GreeterService');

        self::assertTrue($result['ok']);
        self::assertTrue($result['call']['resultTruncated']);
        self::assertSame(strlen($raw), $result['call']['resultChars']);
        self::assertSame(4_000, $result['call']['resultReturnedChars']);
        self::assertIsString($result['call']['result'], 'a cut JSON value is an honest fragment, not fabricated structure');
        self::assertLessThan(5_000, strlen((string) json_encode($result)));
    }

    public function testAnArtifactKindIsNotMistakenForItsIdentity(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'scaffold a service');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":true,"postconditions":{"checks":[{"name":"service","ok":true}]}}',
            true,
            true,
        );

        self::assertFalse(SessionFacts::of($events, 's1')->lastCallForArtifact('service')['ok']);
    }

    public function testAQualifiedIdentityIsNotInferredFromABareName(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            'ok',
            true,
            true,
        );

        $facts = SessionFacts::of($events, 's1');

        self::assertTrue($facts->lastCallForArtifact('GreeterService')['ok']);
        self::assertFalse($facts->lastCallForArtifact('App\\Plugins\\Demo\\Services\\GreeterService')['ok']);
    }

    public function testAnArtifactCanBeFoundByReturnedPathOrQualifiedClass(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            (string) json_encode([
                'ok' => true,
                'file' => 'src/Plugins/Demo/Services/GreeterService.php',
                'class' => 'App\\Plugins\\Demo\\Services\\GreeterService',
                'verified' => 'syntax and namespace',
            ]),
            true,
            true,
        );

        $facts = SessionFacts::of($events, 's1');

        self::assertTrue($facts->lastCallForArtifact('src/Plugins/Demo/Services/GreeterService.php')['ok']);
        self::assertTrue($facts->lastCallForArtifact('App\\Plugins\\Demo\\Services\\GreeterService')['ok']);
    }

    public function testLastExplicitVerificationReturnsItsVerdictAndEvidence(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            (string) json_encode([
                'ok' => true,
                'file' => 'src/Plugins/Demo/Services/GreeterService.php',
                'class' => 'App\\Plugins\\Demo\\Services\\GreeterService',
                'verified' => 'syntax, strict_types, class, namespace and static conformance',
            ]),
            true,
            true,
        );
        $verificationSeq = $events->nextSeq() - 1;
        $store->recordToolCall(
            's1',
            'artifact_contract',
            ['plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":true,"kind":"class"}',
            true,
            false,
        );

        $result = SessionFacts::of($events, 's1')->lastVerificationOf('GreeterService');

        self::assertTrue($result['ok']);
        self::assertTrue($result['verification']['verified']);
        self::assertSame('implement', $result['verification']['operation']);
        self::assertSame('syntax, strict_types, class, namespace and static conformance', $result['verification']['detail']);
        self::assertSame(
            ['event' => 'session.tool_called', 'seq' => $verificationSeq],
            $result['verification']['evidence'],
        );
    }

    public function testAFailedMakeVerificationIsReturnedAsFalse(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'scaffold an entity');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'entity', 'plugin' => 'Demo', 'name' => 'Order'],
            (string) json_encode([
                'ok' => false,
                'files' => [['path' => 'src/Plugins/Demo/Entities/Order.php', 'action' => 'rolled-back']],
                'verify' => ['ok' => false, 'output' => 'class does not implement EntityInterface'],
            ]),
            false,
            true,
        );

        $result = SessionFacts::of($events, 's1')->lastVerificationOf('Order');

        self::assertTrue($result['ok']);
        self::assertFalse($result['verification']['verified']);
        self::assertSame(
            ['ok' => false, 'output' => 'class does not implement EntityInterface'],
            $result['verification']['detail'],
        );
    }

    public function testALargeVerificationDetailIsAlsoBounded(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'scaffold an entity');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'entity', 'plugin' => 'Demo', 'name' => 'Order'],
            (string) json_encode([
                'ok' => true,
                'files' => [['path' => 'src/Plugins/Demo/Entities/Order.php', 'action' => 'created']],
                'verify' => ['ok' => true, 'output' => str_repeat('finding ', 2_000)],
            ]),
            true,
            true,
        );

        $result = SessionFacts::of($events, 's1')->lastVerificationOf('Order');

        self::assertTrue($result['ok']);
        self::assertTrue($result['verification']['verified']);
        self::assertTrue($result['verification']['detailTruncated']);
        self::assertGreaterThan(2_000, $result['verification']['detailChars']);
        self::assertSame(2_000, mb_strlen($result['verification']['detail']));
    }

    public function testAnUnrelatedToolCannotInventAVerificationFromAnUntypedField(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'inspect a greeter');
        $store->recordToolCall(
            's1',
            'artifact_contract',
            ['plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":true,"verified":"false"}',
            true,
            false,
        );

        self::assertFalse(SessionFacts::of($events, 's1')->lastVerificationOf('GreeterService')['ok']);
    }

    public function testLastDecisionCarriesTheQuestionAnswerAttributionAndEvidence(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'make a service');
        $store->ask('s1', new PendingQuestion('q1', 'Use sqlite?'));
        $store->answer('s1', 'q1', 'yes');
        $store->ask('s1', new PendingQuestion(
            'perm:implement',
            'May implement GreeterService?',
            ['yes', 'no'],
            '{"operation":"implement","arguments":{"class":"GreeterService"}}',
            reason: 'permission',
        ));
        $questionSeq = $events->nextSeq() - 1;
        $store->answer(
            's1',
            'perm:implement',
            'yes',
            new Principal('actor:rod', verified: true),
            'tui:desktop',
        );
        $answerSeq = $events->nextSeq() - 1;

        $result = SessionFacts::of($events, 's1')->lastDecision();

        self::assertTrue($result['ok']);
        self::assertSame('perm:implement', $result['decision']['id']);
        self::assertSame('May implement GreeterService?', $result['decision']['question']);
        self::assertSame('yes', $result['decision']['answer']);
        self::assertSame('permission', $result['decision']['reason']);
        self::assertSame(
            '{"operation":"implement","arguments":{"class":"GreeterService"}}',
            $result['decision']['why'],
        );
        self::assertSame(['id' => 'actor:rod', 'verified' => true], $result['decision']['by']);
        self::assertSame('tui:desktop', $result['decision']['executor']);
        self::assertSame(
            [
                'question' => ['event' => 'session.question_asked', 'seq' => $questionSeq],
                'answer' => ['event' => 'session.question_answered', 'seq' => $answerSeq],
            ],
            $result['decision']['evidence'],
        );
    }

    public function testDecisionPairingFollowsTheOpenQuestionLikeTheCanonicalReducer(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->ask('s1', new PendingQuestion('q1', 'First question?'));
        $store->ask('s1', new PendingQuestion('q2', 'Question currently open?'));
        $store->answer('s1', 'q1', 'yes');

        $result = SessionFacts::of($events, 's1')->lastDecision();

        self::assertTrue($result['ok']);
        self::assertSame('q1', $result['decision']['id'], 'the answer event keeps the ID it carried');
        self::assertSame('q2', $result['decision']['questionId'], 'the fold pairs with the question that was open');
        self::assertSame('Question currently open?', $result['decision']['question']);
        self::assertSame(
            $store->load('s1')?->decisions[0]['question'],
            $result['decision']['question'],
            'the narrow projection and canonical session fold must not create two histories',
        );
        self::assertFalse($result['decision']['idMatches'], 'the disagreement is exposed, not repaired on read');
    }

    public function testAnUnansweredQuestionIsNotADecision(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->ask('s1', new PendingQuestion('q1', 'Still waiting?'));

        self::assertFalse(SessionFacts::of($events, 's1')->lastDecision()['ok']);
    }

    public function testEveryNotFoundQueryReturnsOkFalseWithoutThrowing(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('known', 'x');
        $known = SessionFacts::of($events, 'known');
        $unknown = SessionFacts::of($events, 'unknown');

        foreach ([$known, $unknown] as $facts) {
            self::assertFalse($facts->lastCallForArtifact('MissingClass')['ok']);
            self::assertFalse($facts->operationResult('implement', 'MissingClass')['ok']);
            self::assertFalse($facts->lastVerificationOf('MissingClass')['ok']);
            self::assertFalse($facts->lastDecision()['ok']);
        }

        self::assertFalse($known->lastCallForArtifact('')['ok']);
        self::assertFalse($known->operationResult('')['ok']);
        self::assertFalse($known->operationResult('implement', '   ')['ok']);
        self::assertFalse($known->lastVerificationOf('   ')['ok']);
    }

    public function testOperationResultSkipsNonCallEventsAndOtherTools(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'recover one make');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":true}',
        );
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'OtherService'],
            '{"ok":true}',
        );
        $store->recordToolCall('s1', 'validate', ['target' => 'OtherPlugin'], '{"ok":true,"checks":[]}');
        $store->recordTurn('s1', 'assistant', 'done');

        $result = SessionFacts::of($events, 's1')->operationResult('make', 'GreeterService');

        self::assertTrue($result['ok']);
        self::assertSame('make', $result['call']['operation']);
    }

    public function testAClosedAnswerWindowClearsTheQuestionWithoutInventingADecision(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'wait for authority');
        $store->ask('s1', new PendingQuestion(
            'q1',
            'Still authorised?',
            expiresAt: '2026-08-01T10:00:00+00:00',
        ));

        self::assertTrue($store->expireIfDue('s1', new \DateTimeImmutable('2026-08-01T10:00:01+00:00')));
        self::assertFalse(SessionFacts::of($events, 's1')->lastDecision()['ok']);
    }

    public function testAnAnswerWithoutAnOpenQuestionPreservesTheGap(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'read malformed history');
        $store->answer('s1', 'orphan-answer', 'yes');

        $result = SessionFacts::of($events, 's1')->lastDecision();

        self::assertTrue($result['ok']);
        self::assertNull($result['decision']['questionId']);
        self::assertNull($result['decision']['idMatches']);
        self::assertNull($result['decision']['question']);
        self::assertNull($result['decision']['evidence']['question']);
    }

    public function testMalformedToolPayloadsFailSoftlyInTheProjection(): void
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
                'result' => ['ok' => true, 'verified' => 'foreign structured result'],
                'ok' => true,
                'mutating' => true,
            ],
            seq: $events->nextSeq(),
        ));

        $result = SessionFacts::of($events, 's1')->operationResult('implement');

        self::assertTrue($result['ok']);
        self::assertSame([], $result['call']['target']);
        self::assertSame(['ok' => true, 'verified' => 'foreign structured result'], $result['call']['result']);
        self::assertGreaterThan(0, $result['call']['resultStoredChars']);
    }

    public function testValidateAndArtifactContractSpellingsUseDeclaredIdentitySchemas(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'recover validation facts');
        $store->recordToolCall(
            's1',
            'validate',
            ['target' => 'HealthyPlugin'],
            (string) json_encode([
                'ok' => true,
                'target' => 'HealthyPlugin',
                'manifest' => 'plugins/HealthyPlugin/milpa.json',
                'checks' => ['manifest' => ['ok' => true, 'findings' => []]],
            ]),
        );
        $store->recordToolCall('s1', 'artifact:contract', ['name' => 'Alpha'], '{"ok":true}');
        $store->recordToolCall('s1', 'artifact.contract', ['name' => 'Beta'], '{"ok":true}');

        $facts = SessionFacts::of($events, 's1');

        self::assertTrue($facts->lastCallForArtifact('plugins/HealthyPlugin/milpa.json')['ok']);
        self::assertTrue($facts->lastCallForArtifact('Alpha')['ok']);
        self::assertTrue($facts->lastCallForArtifact('Beta')['ok']);
        self::assertTrue($facts->lastVerificationOf('HealthyPlugin')['verification']['verified']);
    }

    public function testMalformedVerificationContractsAreNeverPromoted(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'read malformed verification facts');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'TextResult'],
            'not-json',
        );
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'MissingVerdict'],
            '{"ok":true,"verified":""}',
        );
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'entity', 'plugin' => 'Demo', 'name' => 'MissingVerify'],
            '{"ok":true,"verify":null}',
        );
        $store->recordToolCall(
            's1',
            'validate',
            ['target' => 'MalformedChecks'],
            '{"ok":true,"checks":"not-an-array"}',
        );

        $facts = SessionFacts::of($events, 's1');

        self::assertFalse($facts->lastVerificationOf('TextResult')['ok']);
        self::assertFalse($facts->lastVerificationOf('MissingVerdict')['ok']);
        self::assertFalse($facts->lastVerificationOf('MissingVerify')['ok']);
        self::assertFalse($facts->lastVerificationOf('MalformedChecks')['ok']);
    }

    /**
     * Compaction currentness is scoped to the recorded channel, and a later call on the same artifact
     * makes an older covered call stale even when the later call remains on the recent side of the cut.
     */
    public function testOperationalFactsBoundResultsAndDoNotCallAnOlderArtifactFactCurrent(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement and then edit a greeter');
        $large = (string) json_encode([
            'ok' => true,
            'file' => 'src/Plugins/Demo/Services/GreeterService.php',
            'class' => 'App\\Plugins\\Demo\\Services\\GreeterService',
            'verified' => 'syntax and namespace',
            'output' => str_repeat('x', 5_000),
        ]);
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php'],
            $large,
            true,
            true,
            strlen($large),
            false,
        );
        $throughSeq = $events->nextSeq() - 1;
        $store->recordToolCall(
            's1',
            'edit',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'search' => 'old', 'replace' => 'new'],
            '{"ok":true,"class":"GreeterService","verified":"syntax"}',
            true,
            true,
            awaitingConfirmation: false,
        );
        $latestSeq = $events->nextSeq() - 1;

        $facts = $store->facts('s1')->operationalFacts($throughSeq);

        self::assertSame($latestSeq, $facts['asOfSeq']);
        self::assertCount(2, $facts['calls'], 'facts after the turn cut still need a structured route into the window');
        self::assertTrue($facts['calls'][0]['coveredByCompaction']);
        self::assertFalse($facts['calls'][1]['coveredByCompaction']);
        self::assertFalse($facts['calls'][0]['stillCurrent'], 'the later edit names the same artifact');
        self::assertSame('latest_recorded_call', $facts['calls'][0]['currentScope']);
        self::assertTrue($facts['calls'][0]['resultTruncated']);
        self::assertSame(600, $facts['calls'][0]['resultSummaryChars']);
        self::assertIsString($facts['calls'][0]['resultSummary'], 'a cut JSON result remains an honest fragment');
        self::assertSame(strlen($large), $facts['calls'][0]['resultChars']);
    }

    public function testTheStoreExposesTheProjectionWithoutHandingOutTheStream(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->recordToolCall(
            's1',
            'make',
            ['what' => 'service', 'plugin' => 'Demo', 'name' => 'GreeterService'],
            '{"ok":true}',
        );

        self::assertSame(
            SessionFacts::of($events, 's1')->operationResult('make', 'GreeterService'),
            $store->facts('s1')->operationResult('make', 'GreeterService'),
        );
    }
}
