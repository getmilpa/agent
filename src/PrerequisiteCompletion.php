<?php

/**
 * This file is part of milpa/agent.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent;

/** Dispatch success alone cannot discharge an ordering obligation (Greenhouse 0833–0834). */
final class PrerequisiteCompletion
{
    /**
     * Honor an operation's explicit outcome without interpreting its domain data.
     * Legacy complete results without an `ok` declaration keep their dispatch outcome.
     *
     * @param array<string, mixed> $payload Recorded call facts, not model assertions.
     */
    public static function of(array $payload): bool
    {
        if (($payload['ok'] ?? true) !== true || ($payload['awaitingConfirmation'] ?? false) !== false) {
            return false;
        }

        $result = $payload['result'] ?? null;
        if (!\is_string($result)) {
            return false;
        }
        if (\is_int($payload['resultChars'] ?? null) && $payload['resultChars'] > mb_strlen($result)) {
            return false;
        }

        $decoded = json_decode($result, true);

        return !\is_array($decoded) || !array_key_exists('ok', $decoded) || $decoded['ok'] === true;
    }
}
