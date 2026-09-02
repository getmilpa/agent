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
 * A receipt a producer leaves in its own result to declare WHAT a call demonstrated — the value
 * object of the predicate dimension (greenhouse decisions/0187, the EvidenceReceipt continuation of
 * D-02).
 *
 * ── WHAT IT CARRIES, AND WHY EACH FIELD ─────────────────────────────────────────────────────────
 *
 * A call declares a receipt as an `evidence` object in its result. This type reads that object into a
 * single, immutable shape the judge can honour without a branch per producer:
 *
 * - `predicate` — what was demonstrated («served»). The judge matches on this string, never on the
 *   tool that emitted it.
 * - `subject` — the thing it was demonstrated for (the screen name).
 * - `scope` — WHAT the receipt covers: the list of references it stands for. A claim is covered only
 *   when its reference falls within this list ({@see coversReference()}). The default is the exact
 *   subject — today's behaviour — so a receipt that declares nothing extra covers only itself.
 * - `observation` — where a later reader goes to re-check it (a served address). Never load-bearing
 *   for the verdict; it rides back so a refusal or a closure can point at the observable.
 * - `producer` — the operation that left the receipt, reported so the answer teaches; it is NEVER
 *   matched on.
 * - `invalidates` — TRUE when this receipt is an ANTI-receipt: a call declaring that the predicate no
 *   longer holds for the subject (a `screen:forget` revoking a served screen). An invalidating
 *   receipt does not COVER anything; it is what makes an earlier covering receipt go stale.
 *
 * ── FRESHNESS IS NOT HERE, AND THAT IS THE POINT ────────────────────────────────────────────────
 *
 * A receipt does not carry its own freshness. Whether it is still valid is DERIVED by replaying the
 * stream for a later fact that invalidated it ({@see SessionFacts::evidenceByPredicate()}) — never
 * read from a field the producer could set. A payload that asserts `fresh: true` buys nothing: this
 * type does not even read such a field.
 *
 * ── BACKWARD-TOLERANT ───────────────────────────────────────────────────────────────────────────
 *
 * A D-02-shaped plain receipt (`predicate`/`subject`/`servedAt`, no `scope`/`invalidates`) is still
 * read: its scope defaults to the subject and it is not an anti-receipt. No already-recorded receipt
 * is orphaned by the richer shape.
 */
final readonly class EvidenceReceipt
{
    /**
     * @param list<string> $scope the references this receipt covers; defaults to `[subject]`
     */
    public function __construct(
        public string $predicate,
        public string $subject,
        public array $scope,
        public ?string $observation,
        public ?string $producer,
        public bool $invalidates,
    ) {
    }

    /**
     * Read a receipt from a call result's `evidence` object, or `null` when the shape is not one.
     *
     * A value is a receipt only when it is an array carrying a non-empty `predicate` and `subject`.
     * The `scope` defaults to the subject; `observation` reads `observation` then the D-02 `servedAt`
     * spelling; `invalidates` defaults to false. The `producer` is the operation that emitted it,
     * passed in by the reader (it is not part of the receipt payload).
     */
    public static function fromEvidence(mixed $evidence, ?string $producer = null): ?self
    {
        if (! \is_array($evidence)) {
            return null;
        }
        $predicate = \is_string($evidence['predicate'] ?? null) ? trim($evidence['predicate']) : '';
        $subject = \is_string($evidence['subject'] ?? null) ? trim($evidence['subject']) : '';
        if ($predicate === '' || $subject === '') {
            return null;
        }

        $scope = [$subject];
        if (\is_array($evidence['scope'] ?? null)) {
            $declared = [];
            foreach ($evidence['scope'] as $reference) {
                if (\is_string($reference) && trim($reference) !== '') {
                    $declared[] = trim($reference);
                }
            }
            if ($declared !== []) {
                $scope = array_values(array_unique($declared));
            }
        }

        $observation = null;
        if (\is_string($evidence['observation'] ?? null) && trim($evidence['observation']) !== '') {
            $observation = $evidence['observation'];
        } elseif (\is_string($evidence['servedAt'] ?? null) && trim($evidence['servedAt']) !== '') {
            $observation = $evidence['servedAt'];
        }

        return new self(
            $predicate,
            $subject,
            $scope,
            $observation,
            $producer,
            ($evidence['invalidates'] ?? false) === true,
        );
    }

    /** Whether this receipt speaks to the given predicate. */
    public function speaksTo(string $predicate): bool
    {
        return $this->predicate === trim($predicate);
    }

    /** Whether the given reference falls within this receipt's declared scope. */
    public function coversReference(string $reference): bool
    {
        return \in_array(trim($reference), $this->scope, true);
    }
}
