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
 * How a model's declared context is split across the composed window — ONE authority, asked by
 * whoever compacts and by whoever composes.
 *
 * ── WHY 60 % OF THE CONTEXT, NOT ALL OF IT ─────────────────────────────────────────────────────
 *
 * The composed session window is not the whole request. Measured on the session that re-entered a
 * 32,768-token context at 35.6k tokens (greenhouse evidence/0443): the session's own composition
 * was ~21.4k estimated tokens, and the rest — system prompt, tool schema catalogue, serialization
 * envelope — rode outside anything this package composes, with generation still needing room after
 * that. Budgeting the composition at 60 % of the declared context leaves the ~40 % that the
 * autopsy measured for everything the composition cannot see.
 *
 * ── WHY THIS SPLIT OF THE COMPOSED SHARE ───────────────────────────────────────────────────────
 *
 * - TURNS, 50 %: the unsummarized tail is the only part that answers «what were we doing a minute
 *   ago», which is what the model needs for the next step; starving it makes the agent repeat work.
 * - FACTS, 30 %: the operational-facts block is the machine continuity contract. In the crashed
 *   window it had grown unbounded to ~12.7k tokens — 59 % of the composition, with `calls` alone
 *   near 10k — so it earns the largest system share, and no more than that.
 * - PROSE, 10 % and BRIEFING, 10 %: both are projections of state the stream already holds; they
 *   answer «what happened» and «what is left», and neither needs more than ~2k tokens on a 32k
 *   model to do it.
 *
 * Estimation everywhere is the Compactor's own ~4 characters per token. Sharing the estimator is
 * what makes the shares add up, instead of being four private opinions about one window.
 */
final readonly class WindowBudget
{
    /** Estimated characters per token — the same cheap estimator {@see Compactor} uses. */
    public const CHARS_PER_TOKEN = 4;

    /** The whole composed window's share of the declared context, in tokens. */
    public int $composedTokens;

    /** The unsummarized turn tail's share, in tokens. */
    public int $turnTokens;

    /** The operational-facts block's share, in tokens. */
    public int $factsTokens;

    /** The prose summary's share, in tokens. */
    public int $proseTokens;

    /** The state briefing's share — plan and todos — in tokens. */
    public int $briefingTokens;

    /**
     * Derive every share from the one number the model actually declares.
     *
     * @param int $contextTokens the model's declared context window, in tokens
     *
     * @throws \InvalidArgumentException when the declared context could not budget anything
     */
    public function __construct(public int $contextTokens)
    {
        if ($contextTokens < 1) {
            throw new \InvalidArgumentException(
                "a declared context of {$contextTokens} tokens cannot budget a window",
            );
        }

        $this->composedTokens = intdiv($contextTokens * 3, 5);
        $this->turnTokens = intdiv($this->composedTokens, 2);
        $this->factsTokens = intdiv($this->composedTokens * 3, 10);
        $this->proseTokens = intdiv($this->composedTokens, 10);
        $this->briefingTokens = intdiv($this->composedTokens, 10);
    }

    /** A share expressed in estimated characters, for whoever bounds strings rather than tokens. */
    public function chars(int $tokens): int
    {
        return $tokens * self::CHARS_PER_TOKEN;
    }

    /**
     * Cut `$text` to `$maxChars` WITH THE CUT NAMED, or return it untouched when it already fits.
     *
     * The marker is part of the discipline, not decoration: a window component that shrank in
     * silence would teach the model that the missing part never happened, which is exactly the
     * lie an event-sourced session exists to prevent. The stream keeps the whole value.
     */
    public static function clamp(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $marker = '… [window budget: elided; the full stream persists]';

        return mb_substr($text, 0, max(0, $maxChars - mb_strlen($marker))) . $marker;
    }
}
