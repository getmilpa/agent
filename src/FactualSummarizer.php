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
     * Deriva el resumen de lo apendado hasta `$throughSeq`.
     *
     * El formato es de lista y no de prosa a propósito: se lee rápido, no invita al modelo a
     * continuarlo como si fuera conversación, y cuando algo falta se nota — un párrafo bien escrito al
     * que le falta un hecho parece completo.
     */
    public function summarize(Session $session, int $throughSeq): string
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
            $lineas[] = 'Ya hecho: ' . implode('; ', $hechos) . '.';
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
