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
 * One piece of verifiable evidence in a session's ledger — the thing that lets a todo reach `done`
 * on more than the agent's word.
 *
 * ── IT LIVES IN THE STREAM, LIKE EVERYTHING ELSE ────────────────────────────────────────────────
 *
 * Evidence is appended as {@see SessionEvent::EvidenceRecorded} and folded back, exactly the way a
 * {@see Todo} is. It is not a second store bolted beside the session: it is one more fact in the same
 * append-only log, so «what closed this todo» is answered by replaying the stream and never by
 * trusting a mutable field somewhere.
 *
 * ── WHAT IT POINTS AT, AND WHY THAT IS THE POINT ────────────────────────────────────────────────
 *
 * The `reference` is the whole reason this type exists. A todo marked done carries a claim; this
 * carries something a later reader can go CHECK — a path to open, an operation whose `ok:true` is in
 * the stream, a test to re-run. A piece of evidence with an empty reference points at nothing, so it
 * cannot be re-checked, so it is not verifiable ({@see isVerifiable()}) — and the completion gate
 * refuses to close a todo on it.
 */
final readonly class Evidence
{
    /**
     * @param string       $id        this evidence's own id, unique within the session
     * @param EvidenceKind $kind      what verifiable thing this is
     * @param string       $reference where to go to re-check it: a file path, an operation name (with
     *                                its arguments digest), a test name or the command that re-runs it.
     *                                Empty means it points at nothing, and then it is not verifiable
     * @param string|null  $todo      the todo this evidence closes, or `null` when it is recorded to the
     *                                ledger before being tied to one
     * @param string|null  $detail    an optional human note; never load-bearing for verifiability
     */
    public function __construct(
        public string $id,
        public EvidenceKind $kind,
        public string $reference,
        public ?string $todo = null,
        public ?string $detail = null,
    ) {
    }

    /** An artifact was created at `$reference`. */
    public static function artifact(string $id, string $reference, ?string $todo = null, ?string $detail = null): self
    {
        return new self($id, EvidenceKind::ArtifactCreated, $reference, $todo, $detail);
    }

    /**
     * A governed operation returned `ok:true`. `$reference` names it — the operation, ideally with its
     * arguments digest — so a reader can find the matching {@see SessionEvent::OperationExecuted}.
     */
    public static function operationOk(string $id, string $reference, ?string $todo = null, ?string $detail = null): self
    {
        return new self($id, EvidenceKind::OperationOk, $reference, $todo, $detail);
    }

    /** A test or verification passed; `$reference` names it or the command that re-runs it. */
    public static function testPassed(string $id, string $reference, ?string $todo = null, ?string $detail = null): self
    {
        return new self($id, EvidenceKind::TestPassed, $reference, $todo, $detail);
    }

    /** The same evidence tied to a todo — a new value, because nothing here mutates. */
    public function forTodo(string $todo): self
    {
        return new self($this->id, $this->kind, $this->reference, $todo, $this->detail);
    }

    /**
     * Whether this can actually be re-checked: it points at something.
     *
     * A reference is what a reader follows to confirm the claim. Without one there is nothing to
     * follow, and «evidence» that cannot be re-checked is a claim wearing the word. The kind itself is
     * always a positive attestation — there is no failing kind — so verifiability turns entirely on
     * there being a reference to go to.
     */
    public function isVerifiable(): bool
    {
        return trim($this->reference) !== '';
    }

    /**
     * Its serialisable form, the one that travels in the event payload.
     *
     * @return array{id: string, kind: string, reference: string, todo: string|null, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'reference' => $this->reference,
            'todo' => $this->todo,
            'detail' => $this->detail,
        ];
    }

    /**
     * Rebuilds it from an event payload, tolerating what is missing.
     *
     * A payload written before a key existed cannot topple the reconstruction of a whole session: it
     * falls to the default and reading continues. A stream is read years after it is written.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            \is_string($row['id'] ?? null) ? $row['id'] : '',
            EvidenceKind::tryFrom(\is_string($row['kind'] ?? null) ? $row['kind'] : '') ?? EvidenceKind::ArtifactCreated,
            \is_string($row['reference'] ?? null) ? $row['reference'] : '',
            \is_string($row['todo'] ?? null) && $row['todo'] !== '' ? $row['todo'] : null,
            \is_string($row['detail'] ?? null) && $row['detail'] !== '' ? $row['detail'] : null,
        );
    }
}
