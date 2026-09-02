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
use Milpa\EventStore\EventStoreInterface;

/**
 * Cheap, read-only answers to ordinary recovery questions over one session stream.
 *
 * This is deliberately narrower than {@see SessionObservation}: an audit needs the whole channel,
 * while recovery usually needs one result, one verification, or one decision. Every answer still
 * cites the event that supports it, and no result is cached or written anywhere.
 *
 * A tool call is called a CALL here, never an execution. `session.tool_called` carries the target and
 * result but does not prove that an effect materialised; `session.operation_executed` proves the
 * effect but carries only an arguments digest, not the target. Joining those without the digest's
 * owning recipe would manufacture a relationship the stream does not declare.
 */
final readonly class SessionFacts
{
    /** Argument names that identify or locate a target without returning source bodies or edit payloads. */
    private const TARGET_ARGUMENTS = [
        'plugin',
        'artifact',
        'class',
        'name',
        'target',
        'path',
        'file',
    ];

    /** Maximum raw result characters returned by an ordinary recovery query. */
    private const MAX_RESULT_CHARS = 4_000;

    /** Maximum result characters carried back into the model window by compaction. */
    private const MAX_COMPACTED_RESULT_CHARS = 600;

    /** Maximum characters returned for a verification's detail. */
    private const MAX_DETAIL_CHARS = 2_000;

    /**
     * Lifecycle ranks from least to most proven. `superseded` sits below `verified` on purpose: it
     * WAS verified and then touched again, so presenting it as still-verified would be the stale
     * declaration this projection exists to prevent. The weakest rank wins when one todo names
     * several artifacts — a card is only as done as its least-proven artifact.
     */
    private const WORK_STATE_RANK = [
        'planned' => 0,
        'attempted' => 1,
        'materialized' => 2,
        'superseded' => 3,
        'verified' => 4,
    ];

    /**
     * @param list<Event> $events the session's events in stream order
     */
    private function __construct(
        public string $session,
        private array $events,
    ) {
    }

    /** Build the query projection from one session stream and no other source. */
    public static function of(EventStoreInterface $events, string $session): self
    {
        return new self($session, $events->replay(SessionStore::PREFIX . $session));
    }

    /**
     * Build the query projection over an already-replayed stream.
     *
     * The door for a fold that is handed the events instead of the store — like
     * {@see SessionProjector::boardCards()} — so every reader derives from ONE projection instead
     * of growing a second translation of the same stream.
     *
     * @param list<Event> $events the session's events in stream order
     */
    public static function fromEvents(string $session, array $events): self
    {
        return new self($session, $events);
    }

    /**
     * Structured facts at compaction time, derived from the same stream as every query.
     *
     * Calls and executions remain separate. A call owns target/result data; an execution receipt owns
     * materialisation and the arguments digest. Without a producer-declared link, combining them would
     * turn temporal proximity into evidence. `stillCurrent` therefore describes only whether a call is
     * the latest recorded call for its named artifact as of `asOfSeq`; execution-effect currentness is
     * `null` because this stream declares no supersession contract for effects. Each item declares
     * whether its sequence is behind the turn cut; facts after the cut still belong here because
     * execution and evidence events are not turns and have no other route into the model window.
     *
     * @return array<string, mixed>
     */
    public function operationalFacts(int $throughSeq): array
    {
        $asOfSeq = 0;
        foreach ($this->events as $event) {
            if ($event->type !== SessionEvent::Compacted->value) {
                $asOfSeq = max($asOfSeq, $event->seq);
            }
        }

        $calls = [];
        $executions = [];
        $evidence = [];
        foreach ($this->events as $event) {
            if ($event->type === SessionEvent::ToolCalled->value) {
                $calls[] = $this->operationalCall($event, $asOfSeq, $event->seq <= $throughSeq);
            } elseif ($event->type === SessionEvent::OperationExecuted->value) {
                $executions[] = $this->executionFact($event, $asOfSeq, $event->seq <= $throughSeq);
            } elseif ($event->type === SessionEvent::EvidenceRecorded->value) {
                $evidence[] = $this->evidenceFact($event, $event->seq <= $throughSeq);
            }
        }

        return [
            'schema' => 'milpa.agent.operational-facts.v1',
            'session' => $this->session,
            'throughSeq' => $throughSeq,
            'asOfSeq' => $asOfSeq,
            'calls' => $calls,
            'executions' => $executions,
            'decisions' => $this->decisionsThrough($throughSeq),
            'evidence' => $evidence,
            // The lifecycle survives the cut with the facts it is derived from: without this line,
            // «attempted but not materialized» would be a distinction the model has to carry in its
            // own reasoning — which a measured run showed it pays tokens for, and loses.
            'workState' => $this->artifactWorkStates(),
        ];
    }

    /**
     * Return the last recorded tool call whose structured target names the artifact.
     *
     * Only target fields are returned from the arguments. A complete `implement.content`, edit
     * pairs, and any other payload stay in the stream instead of turning a narrow query into another
     * observation dump.
     *
     * @return array<string, mixed>
     */
    public function lastCallForArtifact(string $artifact): array
    {
        $artifact = trim($artifact);
        if ($artifact === '') {
            return $this->notFound('name the artifact to recover its last call');
        }

        for ($i = \count($this->events) - 1; $i >= 0; --$i) {
            $event = $this->events[$i];
            if ($event->type !== SessionEvent::ToolCalled->value || ! $this->matchesArtifact($event, $artifact)) {
                continue;
            }

            return $this->callAnswer($event, $artifact);
        }

        return $this->notFound(sprintf('no recorded call names artifact "%s"', $artifact));
    }

    /**
     * Return the last result of one operation, optionally narrowed to an artifact.
     *
     * The outer `ok` says whether the QUERY found a fact. `call.succeeded` says whether the selected
     * call succeeded, so a failed `implement` remains recoverable without masquerading as not-found.
     *
     * @return array<string, mixed>
     */
    public function operationResult(string $operation, ?string $artifact = null): array
    {
        $operation = trim($operation);
        $artifact = $artifact === null ? null : trim($artifact);
        if ($operation === '') {
            return $this->notFound('name the operation whose result should be recovered');
        }
        if ($artifact === '') {
            return $this->notFound('an artifact filter cannot be empty');
        }

        for ($i = \count($this->events) - 1; $i >= 0; --$i) {
            $event = $this->events[$i];
            if ($event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $tool = $event->payload['tool'] ?? null;
            if (! \is_string($tool) || $tool !== $operation) {
                continue;
            }
            if ($artifact !== null && ! $this->matchesArtifact($event, $artifact)) {
                continue;
            }

            return $this->callAnswer($event, $artifact);
        }

        $subject = $artifact === null ? $operation : sprintf('%s on "%s"', $operation, $artifact);

        return $this->notFound(sprintf('no recorded result for %s', $subject));
    }

    /**
     * Return the latest EXPLICIT verification verdict recorded for an artifact.
     *
     * This does not inspect the filesystem and does not promote a generic `ok` into verification.
     * It recognises only result shapes that declare the verdict: `verified`, `verify.ok`, or a
     * validator's `checks` plus top-level `ok`. A later unrelated read does not erase that fact.
     *
     * @return array<string, mixed>
     */
    public function lastVerificationOf(string $artifact): array
    {
        $artifact = trim($artifact);
        if ($artifact === '') {
            return $this->notFound('name the artifact whose verification should be recovered');
        }

        for ($i = \count($this->events) - 1; $i >= 0; --$i) {
            $event = $this->events[$i];
            if ($event->type !== SessionEvent::ToolCalled->value || ! $this->matchesArtifact($event, $artifact)) {
                continue;
            }

            $result = $this->decodeResult($event->payload['result'] ?? '');
            $declaration = $this->verificationDeclaration($event, $result);
            if ($declaration === null) {
                continue;
            }

            $detail = $this->boundedValue($declaration['detail'], self::MAX_DETAIL_CHARS);

            return [
                'ok' => true,
                'session' => $this->session,
                'artifact' => $artifact,
                'verification' => [
                    'seq' => $event->seq,
                    'operation' => \is_string($event->payload['tool'] ?? null) ? $event->payload['tool'] : '?',
                    'verified' => $declaration['verified'],
                    'detail' => $detail['value'],
                    'detailChars' => $detail['chars'],
                    'detailTruncated' => $detail['truncated'],
                    'evidence' => ['event' => SessionEvent::ToolCalled->value, 'seq' => $event->seq],
                ],
            ];
        }

        return $this->notFound(sprintf('no explicit verification fact names artifact "%s"', $artifact));
    }

    /**
     * Return the last recorded call whose result DECLARES a matching evidence receipt, or `ok:false`.
     *
     * ── THE PREDICATE DIMENSION, PRODUCER-AGNOSTIC (greenhouse decisions/0187) ───────────────────
     *
     * The named readers above answer «what happened to THIS artifact/operation». This one answers
     * «what did some call DEMONSTRATE», reading a receipt a producer left in its own result: a
     * `evidence` object carrying a `predicate` and a `subject`. It matches on those two strings and
     * on the call having succeeded — NEVER on which tool produced the receipt. So `screen:declare`'s
     * served receipt and any future operation that serves a screen and declares the same predicate
     * are read the same way; the judge that consumes this owes no branch per producer.
     *
     * The receipt travels in the same `session.tool_called` fact every call already leaves — it is
     * read, not indexed a second time. A failed call carries no covering receipt: a receipt is only
     * as true as the call that returned it.
     *
     * ── FRESHNESS AND SCOPE (greenhouse decisions/0187, the EvidenceReceipt continuation of D-02) ──
     *
     * The receipt is read as an {@see EvidenceReceipt}, which adds the two dimensions D-02 deferred:
     *
     * - SCOPE: the lookup matches a receipt whose declared scope COVERS the queried subject, not only
     *   one whose subject equals it. The default scope is the exact subject, so a plain D-02 receipt
     *   keeps matching exactly; a receipt that declares a wider scope covers every reference in it.
     * - FRESHNESS: the latest covering receipt is not trusted for being latest. Its `fresh` flag is
     *   DERIVED by scanning the stream FORWARD for a later fact that invalidated it for the same
     *   subject ({@see laterInvalidatorOf()}) — a `screen:forget` declaring the served predicate no
     *   longer holds, or a failed re-declare of the same served subject. A stale receipt still
     *   returns (`ok:true`) so the caller can name WHY it will not close a claim, carrying the seq it
     *   was observed at, the seq that invalidated it, and the operation that did.
     *
     * The verdict is never read from the receipt payload: a receipt that asserts its own freshness
     * buys nothing, because this reads later stream facts, not the field.
     *
     * @return array<string, mixed>
     */
    public function evidenceByPredicate(string $predicate, string $subject): array
    {
        $predicate = trim($predicate);
        $subject = trim($subject);
        if ($predicate === '' || $subject === '') {
            return $this->notFound('name the predicate and the subject whose evidence receipt should be recovered');
        }

        for ($i = \count($this->events) - 1; $i >= 0; --$i) {
            $event = $this->events[$i];
            if ($event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $payload = $event->payload;
            $decoded = $this->decodeResult($payload['result'] ?? '');
            if (! \is_array($decoded) || ! $this->callSucceeded($payload, $decoded)) {
                continue;
            }
            $producer = \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?';
            $receipt = EvidenceReceipt::fromEvidence($decoded['evidence'] ?? null, $producer);
            // A covering receipt speaks to the predicate, covers the subject within its scope, and is
            // not itself an invalidation (an anti-receipt revokes, it does not cover).
            if ($receipt === null
                || $receipt->invalidates
                || ! $receipt->speaksTo($predicate)
                || ! $receipt->coversReference($subject)
            ) {
                continue;
            }

            $invalidator = $this->laterInvalidatorOf($event->seq, $predicate, $subject);

            return [
                'ok' => true,
                'session' => $this->session,
                'evidence' => [
                    'predicate' => $predicate,
                    'subject' => $receipt->subject,
                    'scope' => $receipt->scope,
                    'operation' => $producer,
                    'seq' => $event->seq,
                    'observedAtSeq' => $event->seq,
                    'fresh' => $invalidator === null,
                    'invalidatedAtSeq' => $invalidator['seq'] ?? null,
                    'invalidatedBy' => $invalidator['operation'] ?? null,
                    'servedAt' => $receipt->observation,
                    'evidence' => ['event' => SessionEvent::ToolCalled->value, 'seq' => $event->seq],
                ],
            ];
        }

        return $this->notFound(sprintf(
            'no recorded call declares evidence predicate "%s" for subject "%s"',
            $predicate,
            $subject,
        ));
    }

    /**
     * The later fact that invalidated a covering receipt, or `null` when the receipt is still fresh.
     *
     * Scans the stream FORWARD of the receipt's seq for the first ToolCalled event that either
     * declares an INVALIDATION of the same predicate+subject (a successful anti-receipt, e.g.
     * `screen:forget`) or is a FAILED re-declare/supersede naming the same subject and predicate in
     * its receipt (the model tried to re-establish it and the attempt did not hold). Both put the
     * demonstrated state in question, so the fail-closed judge treats the standing receipt as stale.
     *
     * The verdict is derived here, from recorded later facts — never from a field the receipt set.
     *
     * @return array{seq: int, operation: string}|null
     */
    private function laterInvalidatorOf(int $receiptSeq, string $predicate, string $subject): ?array
    {
        foreach ($this->events as $event) {
            if ($event->seq <= $receiptSeq || $event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $payload = $event->payload;
            $decoded = $this->decodeResult($payload['result'] ?? '');
            if (! \is_array($decoded)) {
                continue;
            }
            $receipt = EvidenceReceipt::fromEvidence($decoded['evidence'] ?? null);
            if ($receipt === null || ! $receipt->speaksTo($predicate) || ! $receipt->coversReference($subject)) {
                continue;
            }
            $succeeded = $this->callSucceeded($payload, $decoded);
            // A successful anti-receipt revokes it; a failed attempt to re-establish it also puts the
            // served state in question. A succeeded, non-invalidating receipt is a fresh RE-DECLARE —
            // the latest covering receipt handled it above, so it never reaches here as this receipt.
            if (($succeeded && $receipt->invalidates) || ! $succeeded) {
                return [
                    'seq' => $event->seq,
                    'operation' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?',
                ];
            }
        }

        return null;
    }

    /**
     * Return the last answered human decision and the two stream facts that evidence it.
     *
     * An unanswered question is not a decision. If an old or malformed stream has an answer without
     * its question, the answer still returns with `question: null` and a null question evidence
     * reference; the projection preserves the gap instead of inventing the missing half.
     *
     * @return array<string, mixed>
     */
    public function lastDecision(): array
    {
        $decisions = $this->decisionsThrough(\PHP_INT_MAX);
        if ($decisions === []) {
            return $this->notFound('this session has no answered decision');
        }

        return [
            'ok' => true,
            'session' => $this->session,
            'decision' => $decisions[array_key_last($decisions)],
        ];
    }

    /**
     * The derived work state of every artifact this session touched, in first-touch order.
     *
     * Each artifact carries the lifecycle the stream can PROVE: `planned` (a todo names it),
     * `attempted` (a call targeted it), `materialized` (a mutating call's own `ok:true` result — a
     * call that only asked for confirmation does not count), `verified` (the latest
     * producer-declared verification verdict is positive), and `superseded` (a mutating call
     * touched the artifact after that verification, so the verdict can no longer be presented as
     * current). Attempted-not-materialized and done-but-unverified are therefore states, not
     * distinctions the model must carry in its own reasoning.
     *
     * The derivation reads only the call channel and the todos. Execution receipts prove that
     * SOMETHING materialised but name no artifact, and joining them here would manufacture a
     * relationship the stream does not declare — they remain their own list in
     * {@see operationalFacts()}, exactly as separate as they are recorded.
     *
     * @return array<string, mixed>
     */
    public function workState(): array
    {
        return [
            'ok' => true,
            'session' => $this->session,
            'artifacts' => $this->artifactWorkStates(),
        ];
    }

    /**
     * The derived work state of ONE named artifact, or `ok:false` when nothing touched or planned it.
     *
     * Unlike {@see workState()}, which can only enumerate identities the calls declared, this
     * query can answer `planned` for an artifact no call touched yet: the caller names it, and a
     * todo whose text names it too is the stream's proof that it is on the plan.
     *
     * @return array<string, mixed>
     */
    public function workStateFor(string $artifact): array
    {
        $artifact = trim($artifact);
        if ($artifact === '') {
            return $this->notFound('name the artifact whose work state should be derived');
        }

        $calls = [];
        foreach ($this->events as $event) {
            if ($event->type === SessionEvent::ToolCalled->value && $this->matchesArtifact($event, $artifact)) {
                $calls[] = $event;
            }
        }

        $identities = [['kind' => 'name', 'value' => $artifact]];
        foreach ($calls as $call) {
            foreach ($this->artifactIdentities($call) as $identity) {
                if (! \in_array($identity, $identities, true)) {
                    $identities[] = $identity;
                }
            }
        }

        $entry = $this->workStateEntry($identities, $calls);
        if ($calls === [] && $entry['todos'] === []) {
            return $this->notFound(sprintf('nothing touched or planned artifact "%s"', $artifact));
        }

        return [
            'ok' => true,
            'session' => $this->session,
            'artifact' => $artifact,
            'workState' => $entry,
        ];
    }

    /**
     * The weakest derived work state among the artifacts a todo's text names, or `null` when the
     * todo names nothing this session touched.
     *
     * The WEAKEST on purpose — fail closed: a card is only as done as its least-proven artifact,
     * so a `done` over one verified and one merely attempted artifact reads `attempted`.
     *
     * @return array{state: string, artifact: string}|null
     */
    public function workStateForTodo(string $todoId): ?array
    {
        $weakest = null;
        foreach ($this->artifactWorkStates() as $entry) {
            if (! \in_array($todoId, $entry['todos'], true)) {
                continue;
            }
            $rank = self::WORK_STATE_RANK[$entry['state']] ?? 0;
            if ($weakest === null || $rank < $weakest['rank']) {
                $weakest = ['rank' => $rank, 'state' => $entry['state'], 'artifact' => $entry['artifact']['value']];
            }
        }

        return $weakest === null ? null : ['state' => $weakest['state'], 'artifact' => $weakest['artifact']];
    }

    /**
     * One lifecycle entry per artifact the calls touched, grouped by the same identity rules every
     * named query already uses — never by a second matching authority.
     *
     * @return list<array<string, mixed>>
     */
    private function artifactWorkStates(): array
    {
        /** @var list<array{identities: list<array{kind: string, value: string}>, calls: list<Event>}> $groups */
        $groups = [];
        foreach ($this->events as $event) {
            if ($event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $identities = array_values(array_unique($this->artifactIdentities($event), \SORT_REGULAR));
            if ($identities === []) {
                continue;
            }

            $home = null;
            foreach ($groups as $i => $group) {
                if ($this->identitiesOverlap($group['identities'], $identities)) {
                    $home = $i;

                    break;
                }
            }
            if ($home === null) {
                $groups[] = ['identities' => $identities, 'calls' => [$event]];

                continue;
            }
            foreach ($identities as $identity) {
                if (! \in_array($identity, $groups[$home]['identities'], true)) {
                    $groups[$home]['identities'][] = $identity;
                }
            }
            $groups[$home]['calls'][] = $event;
        }

        $entries = [];
        foreach ($groups as $group) {
            $entries[] = $this->workStateEntry($group['identities'], $group['calls']);
        }

        return $entries;
    }

    /**
     * Derive one artifact's lifecycle entry from the calls that targeted it and the todos naming it.
     *
     * @param list<array{kind: string, value: string}> $identities
     * @param list<Event>                              $calls
     *
     * @return array<string, mixed>
     */
    private function workStateEntry(array $identities, array $calls): array
    {
        $attempts = [];
        $materialization = null;
        $verification = null;
        foreach ($calls as $call) {
            $payload = $call->payload;
            $operation = \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?';
            $decoded = $this->decodeResult($payload['result'] ?? '');
            $succeeded = $this->callSucceeded($payload, $decoded);
            $mutating = ($payload['mutating'] ?? false) === true;
            // A call that only ASKED did not do: counting it as materialisation would repeat the
            // very double-count `awaitingConfirmation` was recorded to prevent.
            $onlyAsked = ($payload['awaitingConfirmation'] ?? null) === true;
            $attempts[] = [
                'seq' => $call->seq,
                'operation' => $operation,
                'succeeded' => $succeeded,
                'mutating' => $mutating,
                'awaitingConfirmation' => \is_bool($payload['awaitingConfirmation'] ?? null)
                    ? $payload['awaitingConfirmation']
                    : null,
            ];
            if ($mutating && $succeeded && ! $onlyAsked) {
                $materialization = ['seq' => $call->seq, 'operation' => $operation];
            }
            $declaration = $this->verificationDeclaration($call, $decoded);
            if ($declaration !== null) {
                $verification = ['seq' => $call->seq, 'operation' => $operation, 'verified' => $declaration['verified']];
            }
        }

        // A mutating call AFTER the verification supersedes it — even a failed one, because a
        // failed mutation is not proof nothing changed (the measured wound: `make` created the
        // file AND returned ok:false). The stream cannot prove the verdict survived the touch,
        // so the projection fails closed instead of presenting a stale verdict as current.
        $supersededBy = null;
        if ($verification !== null && $verification['verified'] === true) {
            foreach ($calls as $call) {
                $payload = $call->payload;
                if ($call->seq <= $verification['seq']
                    || ($payload['mutating'] ?? false) !== true
                    || ($payload['awaitingConfirmation'] ?? null) === true
                ) {
                    continue;
                }
                $supersededBy = [
                    'seq' => $call->seq,
                    'operation' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?',
                ];
            }
        }

        $planned = null;
        $todos = [];
        foreach ($this->events as $event) {
            if ($event->type !== SessionEvent::TodoChanged->value) {
                continue;
            }
            $todoId = \is_string($event->payload['id'] ?? null) ? $event->payload['id'] : '';
            $text = \is_string($event->payload['text'] ?? null) ? $event->payload['text'] : '';
            if ($todoId === '' || ! $this->textNamesAny($text, $identities)) {
                continue;
            }
            if ($planned === null) {
                $planned = ['todo' => $todoId, 'seq' => $event->seq];
            }
            if (! \in_array($todoId, $todos, true)) {
                $todos[] = $todoId;
            }
        }

        if ($supersededBy !== null) {
            $state = 'superseded';
        } elseif ($verification !== null && $verification['verified'] === true) {
            $state = 'verified';
        } elseif ($materialization !== null) {
            $state = 'materialized';
        } elseif ($attempts !== []) {
            $state = 'attempted';
        } else {
            $state = 'planned';
        }

        return [
            'artifact' => $identities[0],
            'identities' => $identities,
            'state' => $state,
            'planned' => $planned,
            'todos' => $todos,
            'attempts' => $attempts,
            'materialization' => $materialization,
            'verification' => $verification,
            'supersededBy' => $supersededBy,
        ];
    }

    /**
     * Whether two identity sets speak about the same artifact, by the rules a named query uses.
     *
     * @param list<array{kind: string, value: string}> $a
     * @param list<array{kind: string, value: string}> $b
     */
    private function identitiesOverlap(array $a, array $b): bool
    {
        foreach ($a as $mine) {
            foreach ($b as $theirs) {
                if ($this->valuesNameSameArtifact($mine['value'], $theirs['value'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a todo's free text names any of an artifact's identities.
     *
     * Containment for a qualified spelling, whole-word match for the bare leaf: prose mentions a
     * class the way people write it, and a leaf glued inside a longer identifier is not a mention.
     *
     * @param list<array{kind: string, value: string}> $identities
     */
    private function textNamesAny(string $text, array $identities): bool
    {
        if (trim($text) === '') {
            return false;
        }
        foreach ($identities as $identity) {
            $value = $identity['value'];
            if ($value !== '' && str_contains($text, $value)) {
                return true;
            }
            $leaf = $this->artifactLeaf($value);
            if ($leaf !== ''
                && preg_match('/(?<![A-Za-z0-9_])' . preg_quote($leaf, '/') . '(?![A-Za-z0-9_])/u', $text) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * How to recover a truncated fact's full value: re-invoke the RECORDED operation with the same
     * arguments — identified here by name and canonical digest. `sameCallRecorded` says the stream
     * holds exactly this call; whether re-invoking is safe is the CALLER's decision, which is why
     * the hint names the operation instead of promising it is read-only. What it rules out is the
     * measured spiral: concluding the data is permanently lost because only the cut cache is visible.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{operation: string, argumentsDigest: string, sameCallRecorded: bool}
     */
    private function refetchRecovery(array $payload): array
    {
        return [
            'operation' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?',
            'argumentsDigest' => 'sha256:' . hash('sha256', $this->canonicalJson($payload['arguments'] ?? null)),
            'sameCallRecorded' => true,
        ];
    }

    /** The canonical JSON of a value — object keys sorted recursively — so equal arguments digest equal. */
    private function canonicalJson(mixed $value): string
    {
        $encoded = json_encode($this->sortedKeys($value), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) ? $encoded : '';
    }

    /** The same value with every associative level sorted by key; lists keep their order. */
    private function sortedKeys(mixed $value): mixed
    {
        if (! \is_array($value)) {
            return $value;
        }
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortedKeys($item);
        }
        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    /** @return array<string, mixed> */
    private function operationalCall(Event $event, int $asOfSeq, bool $coveredByCompaction): array
    {
        $payload = $event->payload;
        $decoded = $this->decodeResult($payload['result'] ?? '');
        $result = $this->resultProjection(
            $payload['result'] ?? '',
            \is_int($payload['resultChars'] ?? null) ? $payload['resultChars'] : null,
            self::MAX_COMPACTED_RESULT_CHARS,
        );
        $artifacts = array_values(array_unique($this->artifactIdentities($event), \SORT_REGULAR));
        $verification = null;
        $declaration = $this->verificationDeclaration($event, $decoded);
        if ($declaration !== null) {
            $detail = $this->boundedValue($declaration['detail'], self::MAX_DETAIL_CHARS);
            $verification = [
                'verified' => $declaration['verified'],
                'detail' => $detail['value'],
                'detailChars' => $detail['chars'],
                'detailTruncated' => $detail['truncated'],
            ];
        }

        $call = [
            'seq' => $event->seq,
            'operation' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?',
            'target' => $this->targetArguments($payload['arguments'] ?? null),
            'succeeded' => $this->callSucceeded($payload, $decoded),
            'mutating' => ($payload['mutating'] ?? false) === true,
            'awaitingConfirmation' => \is_bool($payload['awaitingConfirmation'] ?? null)
                ? $payload['awaitingConfirmation']
                : null,
            'resultSummary' => $result['value'],
            'resultChars' => $result['declaredChars'],
            'resultStoredChars' => $result['storedChars'],
            'resultSummaryChars' => $result['returnedChars'],
            'resultTruncated' => $result['truncated'],
            'artifacts' => $artifacts,
            'verification' => $verification,
            'stillCurrent' => $artifacts === [] ? null : ! $this->hasLaterCallForAny($event, $artifacts),
            'currentScope' => $artifacts === [] ? 'unknown' : 'latest_recorded_call',
            'currentAsOfSeq' => $asOfSeq,
            'coveredByCompaction' => $coveredByCompaction,
            'source' => ['event' => SessionEvent::ToolCalled->value, 'seq' => $event->seq],
        ];
        // A truncated fact must say how to recover the full value. An agent that saw only the cut
        // cache after compaction CONCLUDED the data was permanently lost and spiralled — the cap
        // stays (it is what bounds the window); the recovery rides with it.
        if ($result['truncated'] === true) {
            $call['refetch'] = $this->refetchRecovery($payload);
        }

        return $call;
    }

    /** @return array<string, mixed> */
    private function executionFact(Event $event, int $asOfSeq, bool $coveredByCompaction): array
    {
        $payload = $event->payload;

        return [
            'seq' => $event->seq,
            'operation' => \is_string($payload['operation'] ?? null) ? $payload['operation'] : '?',
            'argumentsDigest' => \is_string($payload['arguments_digest'] ?? null) ? $payload['arguments_digest'] : null,
            'executedBy' => \is_array($payload['executed_by'] ?? null) ? $payload['executed_by'] : null,
            'authorizedBy' => \is_array($payload['authorized_by'] ?? null) ? $payload['authorized_by'] : null,
            'stillCurrent' => null,
            'currentScope' => 'effect_currentness_not_declared',
            'currentAsOfSeq' => $asOfSeq,
            'coveredByCompaction' => $coveredByCompaction,
            'source' => ['event' => SessionEvent::OperationExecuted->value, 'seq' => $event->seq],
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceFact(Event $event, bool $coveredByCompaction): array
    {
        $evidence = Evidence::fromArray($event->payload);

        return [
            ...$evidence->toArray(),
            'verifiable' => $evidence->isVerifiable(),
            'coveredByCompaction' => $coveredByCompaction,
            'source' => ['event' => SessionEvent::EvidenceRecorded->value, 'seq' => $event->seq],
        ];
    }

    /**
     * Answered decisions paired by the canonical reducer's open-question fold and marked against the cut.
     *
     * @return list<array<string, mixed>>
     */
    private function decisionsThrough(int $throughSeq): array
    {
        $openQuestion = null;
        $decisions = [];
        foreach ($this->events as $event) {
            $type = SessionEvent::tryFrom($event->type);
            if ($type === SessionEvent::QuestionAsked) {
                $openQuestion = $event;

                continue;
            }
            if ($type === SessionEvent::AnswerWindowClosed) {
                $openQuestion = null;

                continue;
            }
            if ($type !== SessionEvent::QuestionAnswered) {
                continue;
            }

            $questionPayload = $openQuestion instanceof Event ? $openQuestion->payload : [];
            $answerId = \is_string($event->payload['id'] ?? null) ? $event->payload['id'] : '';
            $questionId = $openQuestion instanceof Event && \is_string($questionPayload['id'] ?? null)
                ? $questionPayload['id']
                : null;
            $decisions[] = [
                'id' => $answerId,
                'questionId' => $questionId,
                'idMatches' => $questionId === null ? null : $questionId === $answerId,
                'question' => $openQuestion instanceof Event
                    ? (\is_string($questionPayload['question'] ?? null) ? $questionPayload['question'] : '')
                    : null,
                'answer' => \is_string($event->payload['answer'] ?? null) ? $event->payload['answer'] : '',
                'reason' => \is_string($questionPayload['reason'] ?? null) ? $questionPayload['reason'] : null,
                'why' => \is_string($questionPayload['why'] ?? null) ? $questionPayload['why'] : null,
                'by' => \is_array($event->payload['by'] ?? null) ? $event->payload['by'] : null,
                'executor' => \is_string($event->payload['executor'] ?? null) ? $event->payload['executor'] : null,
                'evidence' => [
                    'question' => $openQuestion instanceof Event
                        ? ['event' => SessionEvent::QuestionAsked->value, 'seq' => $openQuestion->seq]
                        : null,
                    'answer' => ['event' => SessionEvent::QuestionAnswered->value, 'seq' => $event->seq],
                ],
            ];
            if ($throughSeq !== \PHP_INT_MAX) {
                $decisions[array_key_last($decisions)]['coveredByCompaction'] = $event->seq <= $throughSeq;
            }
            $openQuestion = null;
        }

        return $decisions;
    }

    /** @return array<string, mixed> */
    private function callAnswer(Event $event, ?string $artifact): array
    {
        $payload = $event->payload;
        $decoded = $this->decodeResult($payload['result'] ?? '');
        $result = $this->resultProjection(
            $payload['result'] ?? '',
            \is_int($payload['resultChars'] ?? null) ? $payload['resultChars'] : null,
        );

        $answer = [
            'ok' => true,
            'session' => $this->session,
            'call' => [
                'seq' => $event->seq,
                'operation' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?',
                'target' => $this->targetArguments($payload['arguments'] ?? null),
                'succeeded' => $this->callSucceeded($payload, $decoded),
                'mutating' => ($payload['mutating'] ?? false) === true,
                'awaitingConfirmation' => \is_bool($payload['awaitingConfirmation'] ?? null)
                    ? $payload['awaitingConfirmation']
                    : null,
                'result' => $result['value'],
                'resultChars' => $result['declaredChars'],
                'resultStoredChars' => $result['storedChars'],
                'resultReturnedChars' => $result['returnedChars'],
                'resultTruncated' => $result['truncated'],
            ],
        ];
        // The same truncation honesty the compaction block carries: a bounded answer names the
        // re-invocation that returns the full, current value instead of reading as a dead end.
        if ($result['truncated'] === true) {
            $answer['call']['refetch'] = $this->refetchRecovery($payload);
        }
        if ($artifact !== null) {
            $answer['artifact'] = $artifact;
        }

        return $answer;
    }

    /** @param array<string, mixed> $payload */
    private function callSucceeded(array $payload, mixed $decoded): bool
    {
        $succeeded = ($payload['ok'] ?? true) === true;
        if (\is_array($decoded) && \is_bool($decoded['ok'] ?? null)) {
            $succeeded = $succeeded && $decoded['ok'];
        }

        return $succeeded;
    }

    /**
     * Whether a later call names any artifact identity carried by this call.
     *
     * This establishes only the latest recorded CALL. It does not infer that either call executed.
     *
     * @param list<array{kind: string, value: string}> $artifacts
     */
    private function hasLaterCallForAny(Event $call, array $artifacts): bool
    {
        foreach ($this->events as $event) {
            if ($event->seq <= $call->seq || $event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            foreach ($artifacts as $artifact) {
                if ($this->matchesArtifact($event, $artifact['value'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function targetArguments(mixed $arguments): array
    {
        if (! \is_array($arguments)) {
            return [];
        }

        $target = [];
        foreach (self::TARGET_ARGUMENTS as $key) {
            if (\is_string($arguments[$key] ?? null) && trim($arguments[$key]) !== '') {
                $target[$key] = $arguments[$key];
            }
        }

        return $target;
    }

    private function matchesArtifact(Event $event, string $artifact): bool
    {
        foreach ($this->artifactIdentities($event) as $identity) {
            if ($this->valuesNameSameArtifact($identity['value'], $artifact)) {
                return true;
            }
        }

        return false;
    }

    /** Whether one identity value and one queried spelling name the same artifact. */
    private function valuesNameSameArtifact(string $candidate, string $artifact): bool
    {
        if ($this->isQualified($artifact)) {
            return $this->isQualified($candidate) && $this->sameQualifiedArtifact($candidate, $artifact);
        }

        return $this->normaliseArtifact($candidate) === $this->normaliseArtifact($artifact)
            || $this->artifactLeaf($candidate) === $this->artifactLeaf($artifact);
    }

    /**
     * Identities from documented devtools target/result positions — never arbitrary nested metadata.
     *
     * @return list<array{kind: string, value: string}>
     */
    private function artifactIdentities(Event $event): array
    {
        $tool = \is_string($event->payload['tool'] ?? null) ? $event->payload['tool'] : '';
        $arguments = \is_array($event->payload['arguments'] ?? null) ? $event->payload['arguments'] : [];
        $result = $this->decodeResult($event->payload['result'] ?? '');
        $identities = [];

        switch ($tool) {
            case 'implement':
            case 'edit':
                $this->addIdentity($identities, 'class', $arguments['class'] ?? null);
                if (\is_array($result)) {
                    $this->addIdentity($identities, 'class', $result['class'] ?? null);
                    $this->addIdentity($identities, 'path', $result['file'] ?? null);
                }

                break;

            case 'make':
                $this->addIdentity($identities, 'name', $arguments['name'] ?? null);
                if (\is_array($result) && \is_array($result['files'] ?? null)) {
                    foreach ($result['files'] as $file) {
                        if (\is_array($file)) {
                            $this->addIdentity($identities, 'path', $file['path'] ?? null);
                        }
                    }
                }

                break;

            case 'validate':
                $this->addIdentity($identities, 'target', $arguments['target'] ?? null);
                if (\is_array($result)) {
                    $this->addIdentity($identities, 'target', $result['target'] ?? null);
                    $this->addIdentity($identities, 'path', $result['manifest'] ?? null);
                }

                break;

            case 'artifact_contract':
            case 'artifact:contract':
            case 'artifact.contract':
                $this->addIdentity($identities, 'name', $arguments['name'] ?? null);
                if (\is_array($result)) {
                    $this->addIdentity($identities, 'class', $result['class'] ?? null);
                    $this->addIdentity($identities, 'name', $result['name'] ?? null);
                    $this->addIdentity($identities, 'path', $result['file'] ?? null);
                    $this->addIdentity($identities, 'path', $result['path'] ?? null);
                }

                break;
        }

        return $identities;
    }

    /**
     * @param list<array{kind: string, value: string}> $identities
     */
    private function addIdentity(array &$identities, string $kind, mixed $value): void
    {
        if (\is_string($value) && trim($value) !== '') {
            $identities[] = ['kind' => $kind, 'value' => $value];
        }
    }

    private function sameQualifiedArtifact(string $candidate, string $artifact): bool
    {
        $candidate = $this->normaliseArtifact($candidate);
        $artifact = $this->normaliseArtifact($artifact);

        return $candidate === $artifact
            || str_ends_with($candidate, '/' . $artifact)
            || str_ends_with($artifact, '/' . $candidate);
    }

    private function isQualified(string $artifact): bool
    {
        return str_contains($artifact, '/') || str_contains($artifact, '\\');
    }

    private function artifactLeaf(string $artifact): string
    {
        $parts = explode('/', $this->normaliseArtifact($artifact));
        $leaf = $parts[array_key_last($parts)] ?? '';

        return str_ends_with($leaf, '.php') ? substr($leaf, 0, -4) : $leaf;
    }

    private function normaliseArtifact(string $artifact): string
    {
        return trim(str_replace('\\', '/', trim($artifact)), '/');
    }

    private function decodeResult(mixed $result): mixed
    {
        if (! \is_string($result)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        return json_last_error() === \JSON_ERROR_NONE ? $decoded : $result;
    }

    /**
     * @return array{value: mixed, declaredChars: int|null, storedChars: int, returnedChars: int, truncated: bool|null}
     */
    private function resultProjection(mixed $result, ?int $declaredChars, int $limit = self::MAX_RESULT_CHARS): array
    {
        if (\is_string($result)) {
            $raw = $result;
        } else {
            $encoded = json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $raw = \is_string($encoded) ? $encoded : '';
        }

        $storedChars = mb_strlen($raw);
        $projectionCut = $storedChars > $limit;
        $returned = $projectionCut ? mb_substr($raw, 0, $limit) : $this->decodeResult($result);
        $returnedChars = $projectionCut ? mb_strlen($returned) : $storedChars;
        $cutBeforeStore = $declaredChars === null ? null : $declaredChars > $storedChars;

        return [
            'value' => $returned,
            'declaredChars' => $declaredChars,
            'storedChars' => $storedChars,
            'returnedChars' => $returnedChars,
            'truncated' => $projectionCut ? true : $cutBeforeStore,
        ];
    }

    /**
     * @return array{value: mixed, chars: int, truncated: bool}
     */
    private function boundedValue(mixed $value, int $limit): array
    {
        if (\is_string($value)) {
            $raw = $value;
        } else {
            $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $raw = \is_string($encoded) ? $encoded : '';
        }

        $chars = mb_strlen($raw);
        if ($chars <= $limit) {
            return ['value' => $value, 'chars' => $chars, 'truncated' => false];
        }

        return ['value' => mb_substr($raw, 0, $limit), 'chars' => $chars, 'truncated' => true];
    }

    /**
     * Read only result schemas declared by the devtools operation that produced them.
     *
     * @return array{verified: bool, detail: mixed}|null
     */
    private function verificationDeclaration(Event $event, mixed $result): ?array
    {
        if (! \is_array($result)) {
            return null;
        }

        $tool = \is_string($event->payload['tool'] ?? null) ? $event->payload['tool'] : '';
        $callOk = ($event->payload['ok'] ?? true) === true;

        if ($tool === 'implement' || $tool === 'edit') {
            if (($result['ok'] ?? null) !== true || ! \is_string($result['verified'] ?? null) || trim($result['verified']) === '') {
                return null;
            }

            return ['verified' => $callOk, 'detail' => $result['verified']];
        }

        if ($tool === 'make') {
            if (! \is_bool($result['ok'] ?? null)
                || ! \is_array($result['verify'] ?? null)
                || ! \is_bool($result['verify']['ok'] ?? null)
            ) {
                return null;
            }

            return [
                'verified' => $callOk && $result['ok'] && $result['verify']['ok'],
                'detail' => $result['verify'],
            ];
        }

        if ($tool === 'validate') {
            if (! \is_bool($result['ok'] ?? null) || ! \is_array($result['checks'] ?? null)) {
                return null;
            }

            return ['verified' => $callOk && $result['ok'], 'detail' => $result['checks']];
        }

        return null;
    }

    /** @return array{ok: false, session: string, error: string} */
    private function notFound(string $error): array
    {
        return ['ok' => false, 'session' => $this->session, 'error' => $error];
    }
}
