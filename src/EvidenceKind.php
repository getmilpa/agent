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
 * ── WHY THESE THREE, AND WHY A CLOSED SET ───────────────────────────────────────────────────────
 *
 * Because a todo used to reach `done` on the agent's word alone: nothing tied «done» to anything a
 * later reader could re-check. A real audit caught the agent claiming progress it had not grounded.
 * The fix is not to trust harder — it is to require the claim to POINT at something that either
 * exists or re-runs. These three are exactly the things that do:
 *
 * - {@see self::ArtifactCreated} — a file or output was materialised; a reader opens it.
 * - {@see self::OperationOk} — a governed operation returned `ok: true`; the receipt is in the stream.
 * - {@see self::TestPassed} — a test or check passed; a reader re-runs it.
 *
 * Each is a POSITIVE attestation by construction. There is no `failure` case on purpose: a failure is
 * not evidence that a todo is done, so it never enters this ledger as one. What is recorded here are
 * the things that, if false, a reader can catch — which is the whole difference between evidence and a
 * claim.
 *
 * An enum and not free strings for the same reason {@see SessionEvent} is one: the projector and the
 * reducer `match` on it, and a fourth kind nobody named would be data that saves and never reads —
 * worse than data that is never saved, because it looks like it is there.
 */
enum EvidenceKind: string
{
    /** A file or output was created; the reference is where to find it. */
    case ArtifactCreated = 'artifact_created';

    /** A governed operation returned `ok: true`; the reference names the operation (and its digest). */
    case OperationOk = 'operation_ok';

    /** A test or verification passed; the reference names the test or the command that re-runs it. */
    case TestPassed = 'test_passed';

    /** How it reads on a surface, so whoever paints it need not translate an enum. */
    public function label(): string
    {
        return match ($this) {
            self::ArtifactCreated => 'artifact created',
            self::OperationOk => 'operation returned ok',
            self::TestPassed => 'test passed',
        };
    }
}
