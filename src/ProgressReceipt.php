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

namespace Milpa\Agent;

use Milpa\EventStore\Event;

/**
 * Semantic progress between two positions of one session stream, derived PURELY from recorded
 * events — never from the agent's word (greenhouse decisions/0185).
 *
 * ── THE MEASURED WOUND ──────────────────────────────────────────────────────────────────────────
 *
 * The fifth live run burned 811k tokens over 42 model calls to materialize 8 artifacts: the agent
 * turned a mechanical task into an architectural expedition, and when it hit framework-owned
 * plumbing it kept reasoning escape routes for thousands of tokens per call (the stalled run is
 * frozen at greenhouse `evidence/fixtures/corrida5-work-mthqbzu6`). Tokens per call measure COST;
 * they cannot distinguish a call that read three files and wrote one from a call that reasoned
 * eight thousand tokens about how to write a test. This receipt measures what the stream can
 * PROVE grew: evidence, materializations, closed todos.
 *
 * ── WHAT COUNTS, AND WHAT DELIBERATELY DOES NOT ─────────────────────────────────────────────────
 *
 * `newArtifacts` counts tool calls that were both mutating AND succeeded — honestly a PROXY: the
 * stream sees calls, not filesystems, and a succeeded mutation is the closest fact it holds to «an
 * artifact materialized» ({@see SessionFacts} uses the same fact for its `materialized` state). A
 * call whose own result said `ok:false`, and a call that only ASKED for confirmation, never count —
 * the first refused to vouch for itself, the second did not do anything yet (the double-count
 * `awaitingConfirmation` exists to prevent, greenhouse evidence/0200). `newFacts` counts succeeded
 * calls of ANY kind — reading is a fact, it is just not growth. `newHouseDebt` counts the additive
 * `session.debt_signaled` events the HOUSE emits beside the session's own; naming debt is honesty,
 * not growth, so it does not make a window `advancing`.
 *
 * The window is half-open on purpose: `fromSeq` is the checkpoint (the last already-counted
 * position) and never counts; `toSeq` is the window's edge and does. Letting the checkpoint leak
 * in would let one early materialization excuse an arbitrary number of later philosophize calls.
 */
final readonly class ProgressReceipt
{
    /** The progress verdict of a window in which something PROVABLE grew. */
    public const ADVANCING = 'advancing';

    /** The progress verdict of a window that produced no evidence, no materialization, no closed todo. */
    public const STALLED = 'stalled';

    /**
     * @param int    $fromSeq      the checkpoint: the last stream position already counted (exclusive)
     * @param int    $toSeq        the window's edge: the last position this receipt covers (inclusive)
     * @param int    $calls        `session.model_called` events in the window
     * @param int    $newFacts     succeeded tool calls of any kind — reads included, because a fact
     *                             is a fact even when it is not growth
     * @param int    $newArtifacts succeeded MUTATING calls that actually did (not merely asked) —
     *                             the materialization proxy the stream can see
     * @param int    $newEvidence  `session.evidence_recorded` events in the window
     * @param int    $closedTodos  `session.todo_changed` events reaching status `done`
     * @param int    $newHouseDebt additive `session.debt_signaled` events the house emitted
     * @param string $progress     {@see self::ADVANCING} when evidence + artifacts + closed todos
     *                             grew; {@see self::STALLED} otherwise
     */
    public function __construct(
        public int $fromSeq,
        public int $toSeq,
        public int $calls,
        public int $newFacts,
        public int $newArtifacts,
        public int $newEvidence,
        public int $closedTodos,
        public int $newHouseDebt,
        public string $progress,
    ) {
    }

    /**
     * Derives the receipt over an already-replayed stream, counting only events with
     * `fromSeq < seq <= toSeq` — the same handed-the-events door {@see SessionFacts::fromEvents}
     * opens, so every reader derives from ONE replay instead of growing a second one.
     *
     * @param list<Event> $events  the session's events in stream order
     * @param int         $fromSeq the checkpoint: the last position already counted (exclusive)
     * @param int         $toSeq   the window's edge (inclusive)
     */
    public static function of(array $events, int $fromSeq, int $toSeq): self
    {
        $calls = 0;
        $newFacts = 0;
        $newArtifacts = 0;
        $newEvidence = 0;
        $closedTodos = 0;
        $newHouseDebt = 0;

        foreach ($events as $event) {
            if ($event->seq <= $fromSeq || $event->seq > $toSeq) {
                continue;
            }

            switch ($event->type) {
                case SessionEvent::ModelCalled->value:
                    ++$calls;

                    break;

                case SessionEvent::ToolCalled->value:
                    if (!self::callSucceeded($event->payload)) {
                        break;
                    }
                    ++$newFacts;
                    if (($event->payload['mutating'] ?? false) === true
                        && ($event->payload['awaitingConfirmation'] ?? null) !== true
                    ) {
                        ++$newArtifacts;
                    }

                    break;

                case SessionEvent::EvidenceRecorded->value:
                    ++$newEvidence;

                    break;

                case SessionEvent::TodoChanged->value:
                    if (($event->payload['status'] ?? null) === TodoStatus::Done->value) {
                        ++$closedTodos;
                    }

                    break;

                    // The house's additive debt event lives OUTSIDE SessionEvent on purpose (the
                    // tolerant-reducer precedent): it is matched here by its literal type.
                case 'session.debt_signaled':
                    ++$newHouseDebt;

                    break;
            }
        }

        $advancing = $newEvidence + $newArtifacts + $closedTodos > 0;

        return new self(
            $fromSeq,
            $toSeq,
            $calls,
            $newFacts,
            $newArtifacts,
            $newEvidence,
            $closedTodos,
            $newHouseDebt,
            $advancing ? self::ADVANCING : self::STALLED,
        );
    }

    /**
     * Its telemetry projection — every counted field, so a caller can surface the receipt without
     * re-deriving it from the stream.
     *
     * @return array{fromSeq: int, toSeq: int, calls: int, newFacts: int, newArtifacts: int,
     *               newEvidence: int, closedTodos: int, newHouseDebt: int, progress: string}
     */
    public function toArray(): array
    {
        return [
            'fromSeq' => $this->fromSeq,
            'toSeq' => $this->toSeq,
            'calls' => $this->calls,
            'newFacts' => $this->newFacts,
            'newArtifacts' => $this->newArtifacts,
            'newEvidence' => $this->newEvidence,
            'closedTodos' => $this->closedTodos,
            'newHouseDebt' => $this->newHouseDebt,
            'progress' => $this->progress,
        ];
    }

    /**
     * Whether a recorded call succeeded, by the same two-source rule {@see SessionFacts} applies:
     * the call-level `ok` AND the result's own declared `ok` when the producer spoke one.
     *
     * @param array<string, mixed> $payload
     */
    private static function callSucceeded(array $payload): bool
    {
        if (($payload['ok'] ?? true) !== true) {
            return false;
        }

        $result = $payload['result'] ?? null;
        if (\is_string($result)) {
            $decoded = json_decode($result, true);
            if (\is_array($decoded) && \is_bool($decoded['ok'] ?? null)) {
                return $decoded['ok'];
            }
        }

        return true;
    }
}
