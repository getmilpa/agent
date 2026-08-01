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
    public function __construct(
        private int $maxTurns = 40,
        private int $keepRecent = 12,
        private Summarizer $summarizer = new FactualSummarizer(),
    ) {
    }

    /** Si a esta sesión le toca compactar ahora. */
    public function shouldCompact(Session $session): bool
    {
        return $this->maxTurns > $this->keepRecent && \count($this->pendientes($session)) > $this->maxTurns;
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
        $corte = $pendientes[\count($pendientes) - $this->keepRecent - 1]['seq'];

        $resumen = $this->summarizer->summarize($session, $corte);
        $store->compact($session->id, $resumen, $corte);

        return $resumen;
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
