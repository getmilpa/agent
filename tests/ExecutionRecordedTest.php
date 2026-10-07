<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The fact of an execution, declared rather than inferred (greenhouse decisions/0037, H-ATTRIBUTION-1).
 *
 * A tool call is a call whether or not it produced an effect, and one operation can emit two of them:
 * one that only asks for confirmation and one that executes. Both come back successful, so counting
 * effects from `session.tool_called` counts two where there was one, and hanging an executor on it
 * would attribute an ATTEMPT with the face of a FACT (greenhouse evidence/0210).
 *
 * This event exists to say the thing no other event says: the effect happened, here is the authority
 * that permitted it, and here is the principal that was observed materialising it. They are two
 * identities and they are kept apart, because the moment pause and resume exist they stop coinciding
 * (greenhouse evidence/0209).
 */
final class ExecutionRecordedTest extends TestCase
{
    private function store(): SessionStore
    {
        return new SessionStore(new InMemoryEventStore());
    }

    /** @return array<string, mixed> */
    private function lastExecutionPayload(InMemoryEventStore $events): array
    {
        $found = null;
        foreach ($events->replay('agent-session:s1') as $event) {
            if ($event->type === 'session.operation_executed') {
                $found = $event->payload;
            }
        }

        self::assertNotNull($found, 'the execution left a durable fact of its own');

        return (array) $found;
    }

    /**
     * WHO AUTHORISED IT AND WHO RAN IT ARE TWO FIELDS, and a record that cannot tell them apart says
     * "rod authorised it" about an effect somebody else materialised — true, and the wrong truth.
     */
    public function testAuthorityAndExecutorAreKeptApart(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'set a key');

        $store->recordExecution(
            's1',
            'config.set',
            new Principal('cli:impostor@workstation'),
            'terminal-environment',
            ['principal' => 'cli:operator@workstation', 'provenance' => 'session.question_answered', 'session' => 's1'],
            'sha256:abc',
        );

        $payload = $this->lastExecutionPayload($events);

        self::assertSame('config.set', $payload['operation'], 'the canonical identity, not a surface spelling');
        self::assertSame('cli:impostor@workstation', $payload['executed_by']['principal']);
        self::assertSame('cli:operator@workstation', $payload['authorized_by']['principal']);
        self::assertSame('terminal-environment', $payload['executed_by']['source'], 'an observation says where it came from');
        self::assertFalse($payload['executed_by']['verified'], 'observing an actor never verifies them');
        self::assertSame('sha256:abc', $payload['arguments_digest'], 'the arguments are referenced, not copied');
    }

    /**
     * NO OBSERVABLE EXECUTOR IS `unknown`, NEVER A RECONSTRUCTED PRINCIPAL.
     *
     * An honest gap is worth something; a principal invented at write time is false evidence with
     * better typography.
     */
    public function testAnUnobservableExecutorIsDeclaredUnknown(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'refresh the index');

        $store->recordExecution('s1', 'capabilities.refresh', null, 'unknown', null, 'sha256:def');

        $payload = $this->lastExecutionPayload($events);

        self::assertNull($payload['executed_by']['principal'], 'nobody is invented');
        self::assertSame('unknown', $payload['executed_by']['source']);
        self::assertFalse($payload['executed_by']['verified']);
        self::assertNull($payload['authorized_by'], 'an effect no consent covered says so, and does not stay silent');
    }

    /** An already unverified principal is not promoted by the act of executing. */
    public function testExecutingNeverRaisesVerification(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'set a key');

        $store->recordExecution('s1', 'config.set', Principal::fromTerminal('operator', 'workstation'), 'terminal-environment', null, 'sha256:ghi');

        self::assertFalse($this->lastExecutionPayload($events)['executed_by']['verified']);
    }

    /**
     * WHERE IT RAN, AND WHAT IT LEFT (greenhouse decisions/0588).
     *
     * «It executed» was true of a rehearsal in a disposable copy and of an act in the house alike, and the fact
     * could not tell them apart. An execution in the house says so, and carries the house's own account of the
     * state it touched: each path, and its digest before and after.
     */
    public function testAnExecutionInTheHouseSaysWhatItLeft(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'lend the drill');
        $state = [['path' => 'var/herramientas.json', 'before' => 'sha256:aaa', 'after' => 'sha256:bbb']];

        $store->recordExecution('s1', 'herramientas.prestar', null, 'agent', null, 'sha256:abc', [
            'environment' => 'house', 'confined' => true, 'state' => $state, 'changed' => true, 'pre_image' => 'k0123',
        ]);

        $payload = $this->lastExecutionPayload($events);
        self::assertSame('house', $payload['environment']);
        self::assertTrue($payload['confined']);
        self::assertSame($state, $payload['state']);
        self::assertTrue($payload['changed']);
        self::assertSame('k0123', $payload['pre_image']);
        self::assertSame('herramientas.prestar', $payload['operation'], 'beside what the fact already said');
        self::assertSame('sha256:abc', $payload['arguments_digest']);
    }

    /** The same digest before and after is «it did not change» — and a path that was not there is null, not a digest. */
    public function testEqualDigestsAreAnExecutionThatLeftTheHouseAsItWas(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'add a tool');

        $store->recordExecution('s1', 'herramientas.agregar', null, 'agent', null, 'sha256:abc', [
            'environment' => 'house', 'confined' => true, 'changed' => false, 'pre_image' => null,
            'state' => [['path' => 'var/herramientas.json', 'before' => null, 'after' => null]],
        ]);

        $payload = $this->lastExecutionPayload($events);
        self::assertFalse($payload['changed']);
        self::assertNull($payload['pre_image']);
        self::assertSame([['path' => 'var/herramientas.json', 'before' => null, 'after' => null]], $payload['state']);
    }

    /** An execution nobody said anything about carries none of it: the fact is the one it was, key for key. */
    public function testAnExecutionThatDoesNotSayWhereItRanIsTheFactItWas(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'set a key');

        $store->recordExecution('s1', 'config.set', null, 'agent', null, 'sha256:abc');

        self::assertSame(['operation', 'executed_by', 'authorized_by', 'arguments_digest'], array_keys($this->lastExecutionPayload($events)));
    }

    /**
     * A receipt is written by the house, and a malformed one is a defect of the house: it is refused, never kept
     * half-read.
     *
     * @param array<string, mixed> $landed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testAMalformedAccountIsRefused(array $landed): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'add a tool');

        try {
            $store->recordExecution('s1', 'herramientas.agregar', null, 'agent', null, 'sha256:abc', $landed);
            self::fail('a malformed account was kept');
        } catch (\InvalidArgumentException) {
            foreach ($events->replay('agent-session:s1') as $event) {
                self::assertNotSame('session.operation_executed', $event->type, 'nothing was written');
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function malformed(): iterable
    {
        $good = ['environment' => 'house', 'confined' => true, 'changed' => true, 'pre_image' => null, 'state' => [['path' => 'var/a.json', 'before' => 'sha256:a', 'after' => 'sha256:b']]];
        yield 'no environment' => [array_diff_key($good, ['environment' => 1])];
        yield 'an environment that is not a word' => [['environment' => ['house']] + $good];
        yield 'an empty environment' => [['environment' => ''] + $good];
        yield 'confined is not a yes or a no' => [['confined' => 'yes'] + $good];
        yield 'changed is not a yes or a no' => [['changed' => 1] + $good];
        yield 'no state' => [array_diff_key($good, ['state' => 1])];
        yield 'a state that is not a list' => [['state' => ['path' => 'var/a.json']] + $good];
        yield 'a path that is not a word' => [['state' => [['path' => 7, 'before' => null, 'after' => null]]] + $good];
        yield 'a digest that is not a word' => [['state' => [['path' => 'var/a.json', 'before' => 7, 'after' => null]]] + $good];
        yield 'a state entry without its after' => [['state' => [['path' => 'var/a.json', 'before' => null]]] + $good];
        yield 'a pre-image that is not a word' => [['pre_image' => 7] + $good];
        yield 'something nobody asked for' => [$good + ['note' => 'trust me']];
    }
}
