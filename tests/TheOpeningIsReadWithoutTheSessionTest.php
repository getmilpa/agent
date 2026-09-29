<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FirstEventInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Who opened a session is read without reading the session (greenhouse decisions/0517, evidence/1045 §3).
 *
 * A session of 293 MB died inside the check that only wanted its opener: the check built the whole stream,
 * and the leg died before it could record that it died.
 *
 * @guards `opening()` answers the `session.started` event — the same one the stream holds — and, over a store
 *         that can read one event, never replays the session
 *
 * @refuses an opening for a session that was never opened
 *
 * @subject-in milpa/agent
 */
final class TheOpeningIsReadWithoutTheSessionTest extends TestCase
{
    public function testTheOpeningIsTheStartedEventWithWhoOpenedIt(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->recordTurn('s', 'user', 'a turn before the start'); // not an opening, whatever comes first
        $store->start('s', 'build the blog', AutonomyMode::Auto, null, new Principal('key:ABC', true));
        $store->recordTurn('s', 'assistant', 'on it');

        $opening = $store->opening('s');

        self::assertNotNull($opening);
        self::assertSame('session.started', $opening->type);
        self::assertSame('build the blog', $opening->payload['goal']);
        self::assertSame(['id' => 'key:ABC', 'verified' => true], $opening->payload['by']);
        self::assertEquals(
            array_values(array_filter($store->stream('s'), static fn (Event $e): bool => $e->type === 'session.started'))[0],
            $opening,
            'the same event the stream holds',
        );
        self::assertNull($store->opening('never-opened'));
    }

    public function testAStoreThatCanReadOneEventIsNeverAskedForTheSession(): void
    {
        $inner = new InMemoryEventStore();
        (new SessionStore($inner))->start('s', 'x', by: new Principal('passkey:p', true));
        $events = new class ($inner) implements EventStoreInterface, FirstEventInterface {
            public function __construct(private InMemoryEventStore $inner)
            {
            }

            public function first(string $streamId, string $type): ?Event
            {
                return $this->inner->first($streamId, $type);
            }

            public function append(Event $event): void
            {
                $this->inner->append($event);
            }

            public function replay(string $streamId): array
            {
                throw new \LogicException('the session was replayed to read its opening');
            }

            public function nextSeq(): int
            {
                return $this->inner->nextSeq();
            }

            public function streams(): array
            {
                return $this->inner->streams();
            }

            public function replayAll(): array
            {
                return $this->inner->replayAll();
            }
        };

        self::assertSame('passkey:p', (new SessionStore($events))->opening('s')?->payload['by']['id']);
    }

    public function testAStoreThatCannotReadOneEventAnswersTheSameFromTheStream(): void
    {
        $inner = new InMemoryEventStore();
        (new SessionStore($inner))->message('s', 'another-session', 'a message before the start'); // not an opening
        (new SessionStore($inner))->start('s', 'x', by: new Principal('passkey:p', true));
        $events = new class ($inner) implements EventStoreInterface {
            public function __construct(private InMemoryEventStore $inner)
            {
            }

            public function append(Event $event): void
            {
                $this->inner->append($event);
            }

            public function replay(string $streamId): array
            {
                return $this->inner->replay($streamId);
            }

            public function nextSeq(): int
            {
                return $this->inner->nextSeq();
            }

            public function streams(): array
            {
                return $this->inner->streams();
            }

            public function replayAll(): array
            {
                return $this->inner->replayAll();
            }
        };
        $store = new SessionStore($events);

        self::assertEquals((new SessionStore($inner))->opening('s'), $store->opening('s'));
        self::assertSame('session.started', $store->opening('s')?->type);
        self::assertNull($store->opening('never-opened'));
    }
}
