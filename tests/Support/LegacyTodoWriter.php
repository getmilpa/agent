<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests\Support;

use Milpa\Agent\Session;
use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoOrigin;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * Appends a `session.todo_changed` event the way the store wrote it BEFORE the WorkProtocol
 * graduation (greenhouse decisions/0183) — including a bare `done` with nothing behind it.
 *
 * HISTORY IS TOLERATED, NEW WRITES ARE GOVERNED: old streams carrying unevidenced dones exist and
 * must keep loading, but the write door no longer produces them. A test that needs one simulates
 * it as history through the raw event store — which is legitimate, because history exists. The
 * payload mirrors the pre-graduation `SessionStore::setTodo()` field for field, so folds and
 * goldens read exactly what an old stream would hold.
 */
final class LegacyTodoWriter
{
    /** Append one todo event exactly as the pre-graduation store would have written it. */
    public static function write(EventStoreInterface $events, string $id, Todo $todo): void
    {
        $session = (new SessionStore($events))->load($id);
        $previo = null;
        foreach ($session instanceof Session ? $session->todos : [] as $t) {
            if ($t->id === $todo->id) {
                $previo = $t;

                break;
            }
        }

        $cambio = $previo === null
            || $previo->status !== $todo->status
            || $previo->text !== $todo->text;
        $version = $previo === null ? 1 : ($cambio ? $previo->version + 1 : $previo->version);

        $events->append(new Event(
            streamId: SessionStore::PREFIX . $id,
            type: SessionEvent::TodoChanged->value,
            payload: [
                ...$todo->toArray(),
                'version' => $version,
                'mutationsAt' => $session instanceof Session ? $session->mutations : 0,
                'origin' => $previo === null
                    ? TodoOrigin::derive($todo->status, $session instanceof Session ? $session->toolCalls : 0)->value
                    : null,
                'planVersion' => $session instanceof Session ? $session->planVersion : 0,
                'bornInPlan' => $previo === null
                    ? ($session instanceof Session ? $session->planVersion : 0)
                    : $previo->bornInPlan,
                'supersedes' => $cambio && $previo !== null ? $previo->version : null,
                'from' => $cambio && $previo !== null ? $previo->status->value : null,
                'evidenced' => $todo->status === TodoStatus::Done
                    ? ($session instanceof Session && $session->evidenceFor($todo->id) !== [])
                    : null,
            ],
            seq: $events->nextSeq(),
        ));
    }
}
