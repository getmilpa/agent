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
            new Principal('cli:impostor@cm4070'),
            'terminal-environment',
            ['principal' => 'cli:rod@cm4070', 'provenance' => 'session.question_answered', 'session' => 's1'],
            'sha256:abc',
        );

        $payload = $this->lastExecutionPayload($events);

        self::assertSame('config.set', $payload['operation'], 'the canonical identity, not a surface spelling');
        self::assertSame('cli:impostor@cm4070', $payload['executed_by']['principal']);
        self::assertSame('cli:rod@cm4070', $payload['authorized_by']['principal']);
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

        $store->recordExecution('s1', 'config.set', Principal::fromTerminal('rod', 'cm4070'), 'terminal-environment', null, 'sha256:ghi');

        self::assertFalse($this->lastExecutionPayload($events)['executed_by']['verified']);
    }
}
