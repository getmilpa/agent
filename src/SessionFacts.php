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

    /** Maximum characters returned for a verification's detail. */
    private const MAX_DETAIL_CHARS = 2_000;

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
        $openQuestion = null;
        $lastQuestion = null;
        $lastAnswer = null;

        foreach ($this->events as $event) {
            $type = SessionEvent::tryFrom($event->type);
            if ($type === SessionEvent::QuestionAsked) {
                // Same fold as SessionReducer: the latest question is the one currently open.
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

            // Pair with what was OPEN, not with a backward search by id. The canonical reducer does
            // exactly this; if the answer carries another id, the disagreement is exposed below.
            $lastQuestion = $openQuestion;
            $lastAnswer = $event;
            $openQuestion = null;
        }

        if (! $lastAnswer instanceof Event) {
            return $this->notFound('this session has no answered decision');
        }

        $answerId = \is_string($lastAnswer->payload['id'] ?? null) ? $lastAnswer->payload['id'] : '';
        $questionPayload = $lastQuestion instanceof Event ? $lastQuestion->payload : [];
        $questionId = $lastQuestion instanceof Event && \is_string($questionPayload['id'] ?? null)
            ? $questionPayload['id']
            : null;

        return [
            'ok' => true,
            'session' => $this->session,
            'decision' => [
                'id' => $answerId,
                'questionId' => $questionId,
                'idMatches' => $questionId === null ? null : $questionId === $answerId,
                'question' => $lastQuestion instanceof Event
                    ? (\is_string($questionPayload['question'] ?? null) ? $questionPayload['question'] : '')
                    : null,
                'answer' => \is_string($lastAnswer->payload['answer'] ?? null) ? $lastAnswer->payload['answer'] : '',
                'reason' => \is_string($questionPayload['reason'] ?? null) ? $questionPayload['reason'] : null,
                'why' => \is_string($questionPayload['why'] ?? null) ? $questionPayload['why'] : null,
                'by' => \is_array($lastAnswer->payload['by'] ?? null) ? $lastAnswer->payload['by'] : null,
                'executor' => \is_string($lastAnswer->payload['executor'] ?? null) ? $lastAnswer->payload['executor'] : null,
                'evidence' => [
                    'question' => $lastQuestion instanceof Event
                        ? ['event' => SessionEvent::QuestionAsked->value, 'seq' => $lastQuestion->seq]
                        : null,
                    'answer' => ['event' => SessionEvent::QuestionAnswered->value, 'seq' => $lastAnswer->seq],
                ],
            ],
        ];
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
        $succeeded = ($payload['ok'] ?? true) === true;
        if (\is_array($decoded) && \is_bool($decoded['ok'] ?? null)) {
            $succeeded = $succeeded && $decoded['ok'];
        }

        $answer = [
            'ok' => true,
            'session' => $this->session,
            'call' => [
                'seq' => $event->seq,
                'operation' => \is_string($payload['tool'] ?? null) ? $payload['tool'] : '?',
                'target' => $this->targetArguments($payload['arguments'] ?? null),
                'succeeded' => $succeeded,
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
        if ($artifact !== null) {
            $answer['artifact'] = $artifact;
        }

        return $answer;
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
            $candidate = $identity['value'];
            if ($this->isQualified($artifact)) {
                if ($this->isQualified($candidate) && $this->sameQualifiedArtifact($candidate, $artifact)) {
                    return true;
                }

                continue;
            }

            if ($this->normaliseArtifact($candidate) === $this->normaliseArtifact($artifact)
                || $this->artifactLeaf($candidate) === $this->artifactLeaf($artifact)
            ) {
                return true;
            }
        }

        return false;
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
    private function resultProjection(mixed $result, ?int $declaredChars): array
    {
        if (\is_string($result)) {
            $raw = $result;
        } else {
            $encoded = json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $raw = \is_string($encoded) ? $encoded : '';
        }

        $storedChars = mb_strlen($raw);
        $projectionCut = $storedChars > self::MAX_RESULT_CHARS;
        $returned = $projectionCut ? mb_substr($raw, 0, self::MAX_RESULT_CHARS) : $this->decodeResult($result);
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
