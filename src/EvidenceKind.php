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
 * What KIND of verifiable thing closed a todo — the closed list of what counts as evidence.
 *
 * ── WHY A CLOSED SET, AND WHAT EACH CASE READS ──────────────────────────────────────────────────
 *
 * Because a todo used to reach `done` on the agent's word alone: nothing tied «done» to anything a
 * later reader could re-check. A real audit caught the agent claiming progress it had not grounded.
 * The fix is not to trust harder — it is to require the claim to POINT at something that either
 * exists or re-runs. Each case is exactly such a thing:
 *
 * - {@see self::ArtifactCreated} — a file or output was materialised; a reader opens it.
 * - {@see self::OperationOk} — a governed operation returned `ok: true`; the receipt is in the stream.
 * - {@see self::TestPassed} — a test or check passed; a reader re-runs it.
 * - {@see self::ScreenServed} — a screen was served; a reader opens it at its served address.
 *
 * ── WHY THE FOURTH IS A PREDICATE, NOT A PRODUCER (greenhouse decisions/0187) ────────────────────
 *
 * The first three grew from the audit as producer-shaped facts — a make/implement materialisation, an
 * operation receipt, a verification verdict. A measured run then showed the gap: an agent served three
 * real screens with `screen:declare` (ok:true, a served address), but no honest closer could close the
 * preview todo, because a served screen is none of those three producers. The evidence existed; the
 * judge had no case for what it DEMONSTRATED. So {@see self::ScreenServed} reads a PREDICATE — «served»
 * — and the judge that maps to it matches the receipt's predicate and subject, never the tool that
 * emitted it. A future operation that serves a screen and declares the same predicate is covered the
 * same way, without a new case here.
 *
 * Each case is a POSITIVE attestation by construction. There is no `failure` case on purpose: a failure
 * is not evidence that a todo is done, so it never enters this ledger as one. What is recorded here are
 * the things that, if false, a reader can catch — which is the whole difference between evidence and a
 * claim.
 *
 * An enum and not free strings for the same reason {@see SessionEvent} is one: readers `match` on it,
 * and a kind nobody named would be data that saves and never reads — worse than data that is never
 * saved, because it looks like it is there. The set grows only by a deliberately named case.
 */
enum EvidenceKind: string
{
    /** A file or output was created; the reference is where to find it. */
    case ArtifactCreated = 'artifact_created';

    /** A governed operation returned `ok: true`; the reference names the operation (and its digest). */
    case OperationOk = 'operation_ok';

    /** A test or verification passed; the reference names the test or the command that re-runs it. */
    case TestPassed = 'test_passed';

    /** A screen was served (predicate «served»); the reference names the served screen, opened at its address. */
    case ScreenServed = 'screen_served';

    /** How it reads on a surface, so whoever paints it need not translate an enum. */
    public function label(): string
    {
        return match ($this) {
            self::ArtifactCreated => 'artifact created',
            self::OperationOk => 'operation returned ok',
            self::TestPassed => 'test passed',
            self::ScreenServed => 'screen served',
        };
    }
}
