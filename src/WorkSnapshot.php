<?php

/**
 * This file is part of milpa/agent — long-running coding sessions for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent;

use Milpa\EventStore\Event;

/**
 * Where the session STANDS, derived once from its own stream (greenhouse decisions/0187, D-06).
 *
 * `house:context` (decisions/0450) answers «what is this house» — an INVENTORY of structure. This
 * answers the other question a resuming agent keeps paying to reconstruct: «where does the WORK
 * stand». The pattern decisions/0187 measured, «¿qué existe? → leo → razono → compacto → ¿qué
 * existía? → releo → reconstruyo», spent a large share of a 3.46M-token run re-deriving state the
 * stream already holds. This VO derives it in one read so the model stops re-reading eight files to
 * know its own position.
 *
 * Every field is PROVED by the stream, never asserted — the same fail-closed doctrine as
 * {@see SessionFacts::workState()}, which this reuses for the artifact lifecycle:
 *
 *  - `objective`   the plan of record (the latest `plan_set`), the criterion the rest is judged by.
 *  - `materialized` artifacts a mutating call's own `ok:true` proved into being (incl. verified and
 *                  superseded — they exist; whether they are still verified is the next field's job).
 *  - `verified`    artifacts whose latest producer-declared verdict is still positive — NOT the
 *                  superseded, which WERE verified and were touched again (a stale verdict is the
 *                  lie the WorkProtocol exists to refuse).
 *  - `blocked`     what a human must decide before the work moves: the pending question, and any
 *                  todo the session itself marked blocked.
 *  - `unclosable`  todos claimed `done` whose weakest named artifact is not yet verified — the
 *                  claim the completion gate would reject, surfaced BEFORE the model re-claims it.
 *  - `nextExecutableActions` the artifacts that still need work (planned, attempted-not-materialized,
 *                  or superseded → re-verify) and the todos still open — what recovery may act on.
 *  - `houseDebt`   the framework gaps the house itself signalled this session ({@see \Milpa\AppRuntime\Agent\DebtSignal}).
 *
 * It reaches nothing and changes nothing: a read of the stream the session already wrote.
 */
final readonly class WorkSnapshot
{
    /**
     * @param string                                                     $objective             the plan of record, or '' when none was set
     * @param list<string>                                               $materialized          artifact identities proven materialized
     * @param list<string>                                               $verified              artifact identities still verified
     * @param list<array{kind: string, detail: string}>                  $blocked               what a human must decide
     * @param list<array{todo: string, artifact: string, state: string}> $unclosable            done-claims the gate would reject
     * @param list<string>                                               $nextExecutableActions concise actions recovery may take
     * @param list<array{kind: string, summary: string}>                 $houseDebt             framework gaps the house signalled
     */
    public function __construct(
        public string $session,
        public string $objective,
        public array $materialized,
        public array $verified,
        public array $blocked,
        public array $unclosable,
        public array $nextExecutableActions,
        public array $houseDebt,
    ) {
    }

    /**
     * Derive the snapshot from a session's events in stream order.
     *
     * @param list<Event> $events
     */
    public static function fromEvents(string $session, array $events): self
    {
        $facts = SessionFacts::fromEvents($session, $events);
        $work = $facts->workState();
        $artifacts = \is_array($work['artifacts'] ?? null) ? $work['artifacts'] : [];

        $materialized = [];
        $verified = [];
        $needWork = [];
        foreach ($artifacts as $entry) {
            $state = \is_string($entry['state'] ?? null) ? $entry['state'] : '';
            $name = self::artifactName($entry);
            if ($name === '') {
                continue;
            }
            if (\in_array($state, ['materialized', 'verified', 'superseded'], true)) {
                $materialized[] = $name;
            }
            if ($state === 'verified') {
                $verified[] = $name;
            }
            if ($state === 'planned') {
                $needWork[] = "materialize {$name}";
            } elseif ($state === 'attempted') {
                $needWork[] = "finish materializing {$name} (attempted, not proven)";
            } elseif ($state === 'superseded') {
                $needWork[] = "re-verify {$name} (touched after its last verdict)";
            } elseif ($state === 'materialized') {
                $needWork[] = "verify {$name}";
            }
        }

        [$objective, $todos, $blocked, $houseDebt] = self::foldStream($events);

        $unclosable = [];
        foreach ($todos as $todo) {
            if ($todo['status'] === 'blocked') {
                $blocked[] = ['kind' => 'todo_blocked', 'detail' => $todo['text']];
            }
            if (\in_array($todo['status'], ['pending', 'in_progress'], true)) {
                $needWork[] = "todo: {$todo['text']}";
            }
            if ($todo['status'] !== 'done') {
                continue;
            }
            // A done claim is only as proven as its weakest named artifact (SessionFacts fails
            // closed the same way): below `verified`, the completion gate would reject it, so it is
            // surfaced here BEFORE the model re-claims it as finished.
            $weakest = $facts->workStateForTodo($todo['id']);
            if ($weakest !== null && $weakest['state'] !== 'verified') {
                $unclosable[] = ['todo' => $todo['text'], 'artifact' => $weakest['artifact'], 'state' => $weakest['state']];
            }
        }

        return new self(
            session: $session,
            objective: $objective,
            materialized: array_values(array_unique($materialized)),
            verified: array_values(array_unique($verified)),
            blocked: $blocked,
            unclosable: $unclosable,
            nextExecutableActions: array_values(array_unique($needWork)),
            houseDebt: $houseDebt,
        );
    }

    /**
     * One pass for the fields the raw stream owns: the plan of record, the latest state of each
     * todo, the pending question, and the debt the house signalled.
     *
     * @param list<Event> $events
     *
     * @return array{0: string, 1: list<array{id: string, text: string, status: string}>, 2: list<array{kind: string, detail: string}>, 3: list<array{kind: string, summary: string}>}
     */
    private static function foldStream(array $events): array
    {
        $objective = '';
        /** @var array<string, array{id: string, text: string, status: string}> $todos */
        $todos = [];
        $houseDebt = [];
        $pendingQuestion = null;

        foreach ($events as $event) {
            $p = $event->payload;
            switch ($event->type) {
                case SessionEvent::PlanSet->value:
                    if (\is_string($p['plan'] ?? null)) {
                        $objective = $p['plan'];
                    }
                    break;
                case SessionEvent::TodoChanged->value:
                    $id = \is_string($p['id'] ?? null) ? $p['id'] : '';
                    if ($id !== '') {
                        $todos[$id] = [
                            'id' => $id,
                            'text' => \is_string($p['text'] ?? null) ? $p['text'] : '',
                            'status' => \is_string($p['status'] ?? null) ? $p['status'] : 'pending',
                        ];
                    }
                    break;
                case SessionEvent::QuestionAsked->value:
                    // A later answer clears it; tracked as the latest-asked so the closing scan is
                    // one pass, not a lookahead per question.
                    $pendingQuestion = \is_string($p['question'] ?? null) ? $p['question'] : '';
                    break;
                case SessionEvent::QuestionAnswered->value:
                case SessionEvent::AnswerWindowClosed->value:
                    // Answered OR its window closed: either way nothing is pending in THIS session.
                    $pendingQuestion = null;
                    break;
                case 'session.debt_signaled':
                    // By its literal string, the DebtSignal doctrine: this VO lives in milpa/agent
                    // and the emitter in app-runtime, so the reader reads the fact, never the class.
                    $houseDebt[] = [
                        'kind' => \is_string($p['kind'] ?? null) ? $p['kind'] : 'unknown',
                        'summary' => \is_string($p['summary'] ?? null) ? $p['summary'] : '',
                    ];
                    break;
            }
        }

        $blocked = [];
        if ($pendingQuestion !== null && $pendingQuestion !== '') {
            $blocked[] = ['kind' => 'awaiting_human', 'detail' => $pendingQuestion];
        }

        return [$objective, array_values($todos), $blocked, $houseDebt];
    }

    /** @param array<string, mixed> $entry a {@see SessionFacts::workState()} artifact entry */
    private static function artifactName(array $entry): string
    {
        $artifact = $entry['artifact'] ?? null;
        if (\is_array($artifact) && \is_string($artifact['value'] ?? null)) {
            return $artifact['value'];
        }

        return \is_string($artifact) ? $artifact : '';
    }

    /**
     * The serializable form a surface or operation projects — every field flattened to plain data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => true,
            'session' => $this->session,
            'objective' => $this->objective,
            'materialized' => $this->materialized,
            'verified' => $this->verified,
            'blocked' => $this->blocked,
            'unclosable' => $this->unclosable,
            'nextExecutableActions' => $this->nextExecutableActions,
            'houseDebt' => $this->houseDebt,
        ];
    }
}
