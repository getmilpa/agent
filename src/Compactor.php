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
 * Decide CUÁNDO se compacta una sesión y HASTA DÓNDE, y lo apenda (P16.2).
 *
 * ── QUÉ SE ACORTA Y QUÉ NO ─────────────────────────────────────────────────────────────────────
 *
 * Se acorta la VENTANA —lo que se le manda al modelo— y no la historia. El resumen se apenda como un
 * evento más; los turnos que resume siguen ahí, en orden, con su secuencia. Un stream que se
 * reescribiera para ahorrar contexto destruiría la evidencia de cómo se llegó a una decisión justo en
 * las sesiones largas, que son las únicas que se compactan — o sea que perdería lo que vale, y sólo
 * ahí donde vale.
 *
 * ── POR QUÉ SE CONSERVAN LOS ÚLTIMOS TURNOS ÍNTEGROS ───────────────────────────────────────────
 *
 * Porque un resumen contesta «qué ha pasado» y no «qué estábamos haciendo hace un minuto», y lo
 * segundo es lo que el modelo necesita para dar el siguiente paso. Resumirlo TODO deja una sesión que
 * sabe su historia y no sabe en qué iba — la falla se ve como un agente que después de compactar
 * repite trabajo o pregunta algo que acaba de contestarse.
 *
 * ── COMPACTAR NO ES GRATIS, ASÍ QUE NO SE HACE DOS VECES ───────────────────────────────────────
 *
 * {@see shouldCompact()} mide los turnos que TODAVÍA no están resumidos, no todos. Sin eso, una sesión
 * larga volvería a compactar en cada vuelta —el total nunca baja— y apendaría un resumen por turno.
 */
final readonly class Compactor
{
    /**
     * @param int $maxTurns   cuántos turnos sin resumir se toleran antes de compactar
     * @param int $keepRecent cuántos se conservan íntegros al hacerlo. Menor que `$maxTurns` por
     *                        fuerza: si fueran iguales, compactar no acortaría nada y la sesión
     *                        quedaría compactándose para siempre sin avanzar
     */
    /**
     * @param int $maxTokens presupuesto de tokens de la VENTANA sin resumir antes de compactar. El
     *                       conteo de turnos no ve el TAMAÑO de un turno, así que veinte turnos con
     *                       salidas grandes revientan la ventana del proveedor mucho antes de los
     *                       `maxTurns` — el 400 «exceeds context size» llega DESPUÉS, a media jornada.
     *                       Con esto la compactación dispara ANTES de enviar. `0` la apaga (solo turnos).
     */
    /**
     * @param int|null $windowBudget the model's DECLARED CONTEXT in tokens, or `null` for the
     *                               unbudgeted behavior, byte-for-byte. When set, the composition is
     *                               budgeted by construction ({@see WindowBudget} carries the split
     *                               and the WHY): the turn tail is capped at the turns share — and
     *                               by `$maxTokens` too when both speak, the narrower winning — the
     *                               prose summary is written under the prose share, and the
     *                               operational-facts block under the facts share, oldest entries
     *                               elided first with the elision NAMED in the block itself.
     *                               Measured need: greenhouse evidence/0443 — with every tool result
     *                               capped and the tail budgeted, the UNBOUNDED system side still
     *                               re-entered a 32,768-token context at 35.6k.
     */
    public function __construct(
        private int $maxTurns = 40,
        private int $keepRecent = 12,
        private Summarizer $summarizer = new FactualSummarizer(),
        private int $maxTokens = 0,
        private ?int $windowBudget = null,
    ) {
    }

    /** The derived shares, or `null` when this compactor was built without a declared context. */
    private function budget(): ?WindowBudget
    {
        return $this->windowBudget === null ? null : new WindowBudget($this->windowBudget);
    }

    /**
     * The tokens the unsummarized tail may hold before compaction fires — `0` means uncapped.
     *
     * With no declared context this IS `$maxTokens`, unchanged. With one, the turns share caps the
     * tail even when `$maxTokens` was never set, and when both speak the narrower wins: a budget
     * that could only widen an explicit cap would not be a budget.
     */
    private function tailTokens(): int
    {
        $budget = $this->budget();
        if ($budget === null) {
            return $this->maxTokens;
        }

        return $this->maxTokens > 0 ? min($this->maxTokens, $budget->turnTokens) : $budget->turnTokens;
    }

    /**
     * Estimación barata de tokens de una lista de turnos: ~4 chars por token.
     *
     * @param list<array{role: string, content: string, seq: int}> $turns
     */
    private function estimateTokens(array $turns): int
    {
        $chars = 0;
        foreach ($turns as $t) {
            $chars += \strlen((string) $t['content']);
        }

        return intdiv($chars, WindowBudget::CHARS_PER_TOKEN);
    }

    /** Si a esta sesión le toca compactar ahora. */
    public function shouldCompact(Session $session): bool
    {
        $pend = $this->pendientes($session);
        if ($this->maxTurns > $this->keepRecent && \count($pend) > $this->maxTurns) {
            return true;
        }

        // Window-aware: compacta ANTES de que la ventana crezca hasta que el proveedor la rechace.
        $cap = $this->tailTokens();

        return $cap > 0 && $this->estimateTokens($pend) > $cap;
    }

    /**
     * Compacta si toca, y devuelve el resumen que apendó — o `null` si no tocaba.
     *
     * Apenda y devuelve, en vez de devolver para que otro apende: dejar el apendado en manos de quien
     * llama abriría la puerta a una sesión que «se compactó» sin que el evento exista, y ahí la
     * ventana y el stream contarían cosas distintas.
     */
    public function compactIfNeeded(SessionStore $store, Session $session): ?string
    {
        if (!$this->shouldCompact($session)) {
            return null;
        }

        $pendientes = $this->pendientes($session);
        $corte = $this->cutPoint($pendientes);

        $budget = $this->budget();
        $factual = $this->summarizer instanceof FactualSummarizer
            ? $this->summarizer
            : new FactualSummarizer();
        // The budgeted summary is WRITTEN bounded rather than trimmed after the fact: the writer
        // knows what is cheap to collapse (closed todos become a count) and what must survive whole
        // (what is pending, what the human decided). A custom summarizer's prose cannot be rebuilt,
        // so it is clamped with the cut named — never silently.
        if ($budget !== null && $this->summarizer instanceof FactualSummarizer) {
            $resumen = $this->summarizer->boundedSummary($session, $corte, $budget->chars($budget->proseTokens));
        } else {
            $resumen = $this->summarizer->summarize($session, $corte);
            if ($budget !== null) {
                $resumen = WindowBudget::clamp($resumen, $budget->chars($budget->proseTokens));
            }
        }
        $resumen = $factual->withOperationalFacts(
            $resumen,
            $store->facts($session->id),
            $corte,
            $budget?->factsTokens,
        );
        $store->compact($session->id, $resumen, $corte);

        return $resumen;
    }

    /**
     * El `seq` hasta donde se resume: se conservan los turnos MÁS RECIENTES que caben, acotados por el
     * turno (`keepRecent`) Y por el presupuesto de tokens de la cola (60% de `maxTokens`, lo que sea
     * MENOS turnos). El presupuesto gana para que la ventana quepa aunque un turno reciente sea enorme;
     * siempre se conserva al menos uno («qué estábamos haciendo») y se resume al menos uno (progreso).
     *
     * @param list<array{role: string, content: string, seq: int}> $pend
     */
    private function cutPoint(array $pend): int
    {
        $n = \count($pend);
        $cap = $this->tailTokens();
        $keepBudget = $cap > 0 ? intdiv($cap * 3, 5) : \PHP_INT_MAX;
        $tokens = 0;
        $keep = 0;
        for ($i = $n - 1; $i >= 0 && $keep < $this->keepRecent; $i--) {
            $t = $this->estimateTokens([$pend[$i]]);
            if ($keep > 0 && ($tokens + $t) > $keepBudget) {
                break;
            }
            $tokens += $t;
            $keep++;
        }
        if ($keep >= $n) {
            $keep = \max(1, $n - 1);
        }
        $cutIdx = $n - $keep - 1;

        return $cutIdx >= 0 ? $pend[$cutIdx]['seq'] : 0;
    }

    /**
     * Los turnos que la ventana todavía manda íntegros — o sea, los que un resumen anterior no cubre.
     *
     * @return list<array{role: string, content: string, seq: int}>
     */
    private function pendientes(Session $session): array
    {
        return array_values(array_filter(
            $session->turns,
            static fn (array $turno): bool => $turno['seq'] > $session->compactedThrough,
        ));
    }
}
