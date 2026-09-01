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
 * Resume una sesión con lo que el stream YA SABE, sin preguntarle a nadie.
 *
 * ── POR QUÉ EL DEFAULT NO USA EL MODELO ─────────────────────────────────────────────────────────
 *
 * Un resumen escrito por el modelo capta el matiz de una conversación, y para una sesión de código el
 * matiz no es lo que se pierde al compactar: lo que se pierde son los HECHOS. Qué era el objetivo,
 * qué herramientas corrieron, qué se autorizó, qué preguntó y qué se le contestó, qué quedó
 * pendiente. Todo eso está en el stream, exacto, y derivarlo no cuesta una llamada ni puede alucinar.
 *
 * Y hay algo peor que caro: un resumen inventado se APENDA como si fuera lo que pasó. A partir de ahí
 * el modelo trabaja sobre una versión de la sesión que nadie escribió. Con el stream íntegro debajo,
 * un humano puede desmentirlo; el modelo, que sólo ve la ventana, no.
 *
 * Un host que prefiera prosa implementa {@see Summarizer} y la inyecta. Este es el default porque
 * equivocarse hacia los hechos es más barato que equivocarse hacia la fluidez.
 */
final readonly class FactualSummarizer implements Summarizer
{
    /**
     * The one label that separates prose from the machine-readable facts in a written summary.
     *
     * Public because it has TWO readers with one contract: {@see withOperationalFacts()} writes it,
     * and {@see Session::classifiedWindow()} finds it again when an oversized summary written before
     * budgets existed must be re-bounded at composition. Two spellings of this line would be two
     * contracts (greenhouse evidence/0141).
     */
    public const FACTS_LABEL = 'Operational facts (JSON; calls do not prove execution): ';

    /**
     * Deriva el resumen de lo apendado hasta `$throughSeq`.
     *
     * El formato es de lista y no de prosa a propósito: se lee rápido, no invita al modelo a
     * continuarlo como si fuera conversación, y cuando algo falta se nota — un párrafo bien escrito al
     * que le falta un hecho parece completo.
     */
    public function summarize(Session $session, int $throughSeq): string
    {
        return $this->compose($session, $throughSeq, false);
    }

    /**
     * The same summary WRITTEN to fit `$maxChars`, the cheapest line collapsing first.
     *
     * What collapses is the done-todo list — it becomes a count, which still says the work
     * happened. What never collapses: lo pendiente (it is the model's next step) and the human's
     * decisions (losing them makes the agent re-ask or, worse, re-assume). If collapsing is not
     * enough the rest is clamped with the cut named, because a summary that shrank in silence
     * reads as complete — that is what makes silent truncation dangerous.
     */
    public function boundedSummary(Session $session, int $throughSeq, int $maxChars): string
    {
        $entero = $this->compose($session, $throughSeq, false);
        if (mb_strlen($entero) <= $maxChars) {
            return $entero;
        }

        $colapsado = $this->compose($session, $throughSeq, true);

        return WindowBudget::clamp($colapsado, $maxChars);
    }

    /** The single writer both {@see summarize()} and {@see boundedSummary()} ask. */
    private function compose(Session $session, int $throughSeq, bool $collapseDone): string
    {
        $lineas = ["Objetivo de la sesión: {$session->goal}."];

        if ($session->plan !== null && trim($session->plan) !== '') {
            $lineas[] = 'Plan: ' . trim($session->plan);
        }

        $herramientas = $this->herramientas($session, $throughSeq);
        if ($herramientas !== []) {
            $partes = [];
            foreach ($herramientas as $nombre => $veces) {
                $partes[] = $veces > 1 ? "{$nombre} ×{$veces}" : $nombre;
            }
            $lineas[] = 'Herramientas usadas: ' . implode(', ', $partes) . '.';
        }

        if ($session->permissions !== []) {
            $lineas[] = 'Autorizado en esta sesión: ' . implode(', ', $session->permissions) . '.';
        }

        $hechos = [];
        $faltan = [];
        foreach ($session->todos as $todo) {
            if ($todo->status === TodoStatus::Done) {
                $hechos[] = $todo->text;
            } else {
                $faltan[] = $todo->text . ($todo->status === TodoStatus::Blocked ? ' (bloqueado)' : '');
            }
        }
        if ($hechos !== []) {
            $lineas[] = $collapseDone
                ? 'Ya hecho: ' . \count($hechos) . ' tareas.'
                : 'Ya hecho: ' . implode('; ', $hechos) . '.';
        }
        if ($faltan !== []) {
            // Lo pendiente va SIEMPRE, aunque el resumen quede más largo: es lo único de este texto
            // que le dice al modelo qué hacer a continuación. Un resumen que cuenta lo que pasó y no
            // lo que falta deja a la sesión sin siguiente paso justo después de compactar.
            $lineas[] = 'Pendiente: ' . implode('; ', $faltan) . '.';
        }

        // Lo que el humano decidió cuando la sesión se detuvo a preguntar. Es lo más caro de perder
        // de toda la sesión: son las decisiones que NO eran del agente, y si se borran al compactar
        // vuelve a preguntarlas o —peor— vuelve a suponerlas.
        if ($session->decisions !== []) {
            $partes = [];
            foreach ($session->decisions as $decision) {
                $partes[] = $decision['question'] . ' → «' . $decision['answer'] . '»';
            }
            $lineas[] = 'Decisiones del humano: ' . implode('; ', $partes);
        }

        $lineas[] = sprintf(
            '(Resumen automático de los primeros %d turnos; el registro completo sigue en la sesión %s.)',
            $this->turnosHasta($session, $throughSeq),
            $session->id,
        );

        return implode("\n", $lineas);
    }

    /**
     * Append the machine-readable operational projection that prose cannot safely reconstruct.
     *
     * This is separate from {@see summarize()} so a host may replace the prose summarizer without
     * replacing the facts. The projection remains local and deterministic: {@see SessionFacts} reads
     * the session stream, applies the same narrow result/artifact/verification rules as recovery
     * queries, and this method only serialises that value into the compacted window.
     */
    public function withOperationalFacts(
        string $summary,
        SessionFacts $facts,
        int $throughSeq,
        ?int $maxTokens = null,
    ): string {
        $value = $facts->operationalFacts($throughSeq);
        if ($maxTokens !== null) {
            $value = self::fitOperationalFacts($value, $maxTokens * WindowBudget::CHARS_PER_TOKEN);
        }

        return implode("\n", array_filter(
            [
                rtrim($summary),
                self::FACTS_LABEL . self::encodeFacts($value),
            ],
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * The one serialisation of a facts value — writer and re-bounder must produce the same bytes.
     *
     * @param array<string, mixed> $facts
     */
    public static function encodeFacts(array $facts): string
    {
        return json_encode(
            $facts,
            \JSON_UNESCAPED_UNICODE
                | \JSON_UNESCAPED_SLASHES
                | \JSON_INVALID_UTF8_SUBSTITUTE
                | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Fit a facts value under `$maxChars` of serialisation, oldest entries first, THE ELISION NAMED.
     *
     * The most recent facts stay whole because they are the ones the next step reads; what is
     * dropped is announced in the block itself — `elided` and `note` — so the model is told there
     * IS more and where it lives, instead of being handed a list that quietly claims to be all of
     * it. Human decisions are never dropped: they are the most expensive thing in the whole session
     * to lose, and they are small. The full set stays queryable through {@see SessionFacts} — this
     * trims only the model-facing projection, never the stream and never a query's answer.
     *
     * @param array<string, mixed> $facts
     *
     * @return array<string, mixed>
     */
    public static function fitOperationalFacts(array $facts, int $maxChars): array
    {
        if (\strlen(self::encodeFacts($facts)) <= $maxChars) {
            return $facts;
        }

        $elided = 0;
        while (true) {
            if (!self::dropOldestEntry($facts)) {
                break;
            }
            ++$elided;
            $facts['elided'] = $elided;
            $facts['note'] = 'query session facts for older operations';
            if (\strlen(self::encodeFacts($facts)) <= $maxChars) {
                break;
            }
        }

        return $facts;
    }

    /**
     * Drop the single oldest droppable entry, or answer `false` when nothing droppable remains.
     *
     * Age is the recorded `seq` across the three stream-ordered lists; `workState` entries carry no
     * sequence, so they go last and front-first. `decisions` is not on the list on purpose.
     *
     * @param array<string, mixed> $facts
     */
    private static function dropOldestEntry(array &$facts): bool
    {
        $oldestKey = null;
        $oldestSeq = \PHP_INT_MAX;
        foreach (['calls', 'executions', 'evidence'] as $key) {
            $list = $facts[$key] ?? null;
            if (!\is_array($list)) {
                continue;
            }
            $head = $list[0] ?? null;
            if (!\is_array($head)) {
                continue;
            }
            // The recorded age lives top-level on calls/executions and nested at source.seq on
            // evidence entries. Reading only the top level made every evidence entry look like seq 0
            // and drain FIRST — doctrinally backwards: evidence backs the dones, so it falls by its
            // real age like everything else (caught by the adversarial verify of this very slice).
            $seq = $head['seq'] ?? ($head['source']['seq'] ?? null);
            $seq = \is_int($seq) ? $seq : 0;
            if ($seq < $oldestSeq) {
                $oldestSeq = $seq;
                $oldestKey = $key;
            }
        }
        if ($oldestKey !== null) {
            $list = $facts[$oldestKey];
            if (\is_array($list)) {
                array_shift($list);
                $facts[$oldestKey] = $list;
            }

            return true;
        }
        $workState = $facts['workState'] ?? null;
        if (\is_array($workState) && $workState !== []) {
            array_shift($workState);
            $facts['workState'] = $workState;

            return true;
        }

        return false;
    }

    /**
     * @return array<string, int> nombre de herramienta => cuántas veces
     */
    private function herramientas(Session $session, int $throughSeq): array
    {
        $conteo = [];
        foreach ($session->turns as $turno) {
            if ($turno['seq'] > $throughSeq || $turno['role'] !== 'tool') {
                continue;
            }

            // Un turno de herramienta se apendó como «nombre → resultado»; sólo interesa el nombre.
            $nombre = trim(explode('→', $turno['content'], 2)[0]);
            if ($nombre === '') {
                continue;
            }

            $conteo[$nombre] = ($conteo[$nombre] ?? 0) + 1;
        }

        return $conteo;
    }

    private function turnosHasta(Session $session, int $throughSeq): int
    {
        $n = 0;
        foreach ($session->turns as $turno) {
            if ($turno['seq'] <= $throughSeq) {
                ++$n;
            }
        }

        return $n;
    }
}
