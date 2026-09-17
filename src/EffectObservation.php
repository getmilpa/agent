<?php

declare(strict_types=1);

namespace Milpa\Agent;

/**
 * An execution observer's witness, never an authority ceiling or a tool's own prose.
 * Stable identities let the session distinguish another copy from new work (greenhouse 0346/0663).
 *
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
final readonly class EffectObservation
{
    /** @var list<string> */
    public array $artifacts;

    /** @var list<string> */
    public array $evidence;

    /** @var list<string> Distinct diagnostic observations, never positive verification. */
    public array $diagnostics;

    /** Validate raw observer input before exposing typed identities.
     * @param array<mixed> $artifacts
     * @param array<mixed> $evidence
     * @param array<mixed> $diagnostics
     */
    public function __construct(
        public string $producer,
        public bool $known,
        array $artifacts = [],
        array $evidence = [],
        array $diagnostics = [],
    ) {
        if (trim($producer) === '' || (!$known && ($artifacts !== [] || $evidence !== [] || $diagnostics !== []))) {
            throw new \InvalidArgumentException('An observation names its producer; unknown cannot assert effects.');
        }
        $this->artifacts = self::identities($artifacts);
        $this->evidence = self::identities($evidence);
        $this->diagnostics = self::identities($diagnostics);
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<string>
     */
    private static function identities(array $ids): array
    {
        if (!array_is_list($ids)) {
            throw new \InvalidArgumentException('Effect identities must be lists.');
        }
        $valid = [];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
                throw new \InvalidArgumentException('Effect identities must be SHA-256 digests.');
            }
            $valid[] = $id;
        }
        return $valid;
    }

    /** Serialize the measured identities and their provenance.
     * @return array{schema: string, producer: string, known: bool, artifacts: list<string>, evidence: list<string>, diagnostics?: list<string>}
     */
    public function toArray(): array
    {
        return ['schema' => $this->diagnostics === [] ? 'milpa.agent.effect-observation/v1' : 'milpa.agent.effect-observation/v2', 'producer' => $this->producer,
            'known' => $this->known, 'artifacts' => $this->artifacts, 'evidence' => $this->evidence]
            + ($this->diagnostics === [] ? [] : ['diagnostics' => $this->diagnostics]);
    }

    /** Malformed or newer wire observations remain unknown, never a legacy proxy. */
    public static function fromArray(mixed $value): self
    {
        try {
            if (!is_array($value) || !in_array($value['schema'] ?? null, ['milpa.agent.effect-observation/v1', 'milpa.agent.effect-observation/v2'], true)
                || !is_string($value['producer'] ?? null) || !is_bool($value['known'] ?? null)
                || !is_array($value['artifacts'] ?? null) || !is_array($value['evidence'] ?? null)) {
                throw new \InvalidArgumentException('Unrecognized observation.');
            }
            $diagnostics = $value['diagnostics'] ?? [];
            if (($value['schema'] === 'milpa.agent.effect-observation/v2' && !is_array($value['diagnostics'] ?? null))
                || ($value['schema'] === 'milpa.agent.effect-observation/v1' && array_key_exists('diagnostics', $value))) {
                throw new \InvalidArgumentException('Diagnostic identities require the versioned observation schema.');
            }
            return new self($value['producer'], $value['known'], $value['artifacts'], $value['evidence'], $diagnostics);
        } catch (\InvalidArgumentException) {
            return new self('unrecognized-observation', false);
        }
    }

    /** Correlate argument data independently of map ordering; this digest grants no authority.
     * @param array<string, mixed> $arguments
     */
    public static function argumentsDigest(array $arguments): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical($arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
