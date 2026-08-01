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
 * Escribe el resumen con el que se reemplaza la parte vieja de la ventana (P16.2).
 *
 * ── POR QUÉ ES UNA INTERFAZ ─────────────────────────────────────────────────────────────────────
 *
 * Porque hay dos maneras legítimas de resumir una sesión y este paquete no tiene por qué elegir. Se
 * le puede pedir al modelo —que capta el matiz y cuesta una llamada, y puede alucinar sobre lo que
 * pasó— o se puede derivar de los hechos que el stream ya guarda, que no cuesta nada y no puede
 * inventar. {@see FactualSummarizer} es lo segundo y es el default; un host que prefiera lo primero
 * implementa esto y lo inyecta.
 *
 * Lo que NO es negociable es qué se resume y hasta dónde: eso lo decide {@see Compactor}, porque de
 * ahí depende que la ventana quede consistente. Un resumidor que además eligiera el corte podría
 * dejar turnos que ni están resumidos ni se mandan.
 */
interface Summarizer
{
    /**
     * Resume lo ocurrido en `$session` hasta la secuencia `$throughSeq`, inclusive.
     *
     * Lo de después NO se resume: sigue viajando íntegro en la ventana, y duplicarlo en el resumen
     * gastaría contexto en decir dos veces lo mismo.
     */
    public function summarize(Session $session, int $throughSeq): string;
}
