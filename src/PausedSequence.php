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

/**
 * A GovernedSequence stopped mid-run, waiting for consent — as a fact this package can outlive its
 * process (H-PERSIST-1, greenhouse decisions/0076).
 *
 * ── THE PAUSE AUTHENTICATES THE CURSOR, NOT THE EFFECTS ─────────────────────────────────────────
 *
 * This VO is the minimal declaration a runner needs to REBUILD where a sequence stopped: its
 * declared steps and how far it got. It is not a ledger of what already ran — the effects a
 * sequence already produced left their own facts elsewhere in the stream ({@see
 * SessionEvent::OperationExecuted}), and re-authenticating them here would be a second, mutable
 * truth about the same thing. `nextIndex` is the only cursor a resuming runner needs: it rebuilds
 * `nextIndex` placeholder outcomes and checks `count(done) === nextIndex`, never their content.
 *
 * ── WHY THIS MIRRORS PendingQuestion EXACTLY ────────────────────────────────────────────────────
 *
 * Because the shape of the problem is the same: something the session cannot finish alone, and
 * while it stands the session {@see Session::isRunnable()} is false. A process that dies here is
 * not a session that silently vanished — it is a session paused on a fact any later process can
 * read back.
 */
final readonly class PausedSequence
{
    /**
     * @param list<array{operation: string, arguments: array<string, mixed>}> $steps the sequence's DECLARED
     *                                                                               steps only — operation and
     *                                                                               arguments, never their
     *                                                                               outcomes: what already ran is
     *                                                                               somebody else's fact
     */
    public function __construct(
        public string $sequenceId,
        public string $digest,
        public array $steps,
        public int $nextIndex,
    ) {
    }

    /**
     * Its serializable form, the one that travels in the event's payload.
     *
     * @return array{sequenceId: string, digest: string, steps: list<array{operation: string, arguments: array<string, mixed>}>, nextIndex: int}
     */
    public function toArray(): array
    {
        return [
            'sequenceId' => $this->sequenceId,
            'digest' => $this->digest,
            'steps' => $this->steps,
            'nextIndex' => $this->nextIndex,
        ];
    }

    /**
     * Rebuilds it from an event's payload, tolerating what is missing.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        /** @var list<array{operation: string, arguments: array<string, mixed>}> $pasos */
        $pasos = [];
        if (\is_array($row['steps'] ?? null)) {
            foreach ($row['steps'] as $paso) {
                if (!\is_array($paso)) {
                    continue;
                }
                $pasos[] = [
                    'operation' => \is_string($paso['operation'] ?? null) ? $paso['operation'] : '',
                    'arguments' => \is_array($paso['arguments'] ?? null) ? $paso['arguments'] : [],
                ];
            }
        }

        return new self(
            \is_string($row['sequenceId'] ?? null) ? $row['sequenceId'] : '',
            \is_string($row['digest'] ?? null) ? $row['digest'] : '',
            $pasos,
            \is_int($row['nextIndex'] ?? null) ? $row['nextIndex'] : 0,
        );
    }
}
