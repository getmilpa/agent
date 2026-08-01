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
 * Los tipos de evento que una sesión apenda — la lista cerrada de cosas que pueden pasar en ella.
 *
 * ── POR QUÉ UN ENUM Y NO CADENAS ────────────────────────────────────────────────────────────────
 *
 * Porque el reductor hace `match` sobre esto, y un `match` sobre un enum es exhaustivo: agregar un
 * caso aquí sin manejarlo allá no compila. Con cadenas, un evento nuevo que nadie reduce se APENDA
 * igual —el stream lo acepta— y desaparece al reconstruir la sesión. Un dato que se guarda y no se
 * lee es peor que uno que no se guarda: parece que está.
 *
 * El stream es la verdad y NADA se reescribe. Compactar no borra turnos, apenda un resumen; revocar
 * un permiso no quita el evento que lo otorgó, apenda su revocación. Una bitácora que se edita deja
 * de servir para lo único que sirve una bitácora.
 */
enum SessionEvent: string
{
    /** La sesión existe. Lleva el objetivo con el que se abrió y el modo con el que corre. */
    case Started = 'session.started';

    /** Alguien —humano o modelo— dijo algo. El turno completo, con su rol. */
    case Turn = 'session.turn';

    /** Se llamó una herramienta: cuál, con qué, y qué contestó. */
    case ToolCalled = 'session.tool_called';

    /**
     * Se resumió lo viejo (P16.2).
     *
     * Apenda el resumen y hasta qué secuencia cubre; los turnos que resume siguen en el stream. Lo
     * que se acorta es la ventana que se le manda al modelo, no la historia.
     */
    case Compacted = 'session.compacted';

    /** El plan de trabajo, tal como lo escribió el agente (P16.3). */
    case PlanSet = 'session.plan_set';

    /** Un pendiente cambió de estado (P16.3). */
    case TodoChanged = 'session.todo_changed';

    /** El agente necesita una decisión que no es suya y la sesión queda en pausa (P16.4). */
    case QuestionAsked = 'session.question_asked';

    /** El humano contestó y la sesión puede seguir (P16.4). */
    case QuestionAnswered = 'session.question_answered';

    /**
     * Se cerró la ventana para contestar, y eso se DECLARA.
     *
     * ── POR QUÉ NO SE LLAMA «la pregunta expiró» ────────────────────────────────────────────────
     *
     * Porque la pregunta no expiró: **sigue siendo la misma pregunta, y sigue siendo válida.** Lo que
     * se acabó es la autoridad para contestarla DENTRO DE ESTA SESIÓN — el contrato temporal, no el
     * contenido. Quien quiera esa decisión la puede volver a pedir; lo que no puede es contestarla
     * aquí, tarde, como si nada hubiera pasado.
     *
     * El nombre lo corrigió Rod, y la corrección no es cosmética: `question_expired` describía el
     * contenido y este evento habla del contrato. Cubre además los dos tipos de pregunta —permiso y
     * firma— y «permiso venció» habría sido falso para la segunda, donde no hay permiso que otorgar.
     *
     * ── POR QUÉ ES UN EVENTO Y NO UNA COMPARACIÓN ───────────────────────────────────────────────
     *
     * Podría derivarse comparando el plazo con el reloj, y por eso mismo se apenda: una caducidad
     * derivada no deja rastro de CUÁNDO se notó ni de quién actuó en consecuencia, y una sesión que
     * murió por silencio es exactamente el caso donde alguien va a querer saberlo.
     *
     * Dicho como doctrina, y es de Rod: **el tiempo no cambia el estado de un sistema; las decisiones
     * tomadas respecto al tiempo sí.** El reloj no cerró esta sesión — la cerró
     * {@see \Milpa\Agent\SessionStore::expireIfDue()}, en un instante concreto, y dejó este hecho.
     *
     * Ver Q-P19-B.
     */
    case AnswerWindowClosed = 'session.answer_window_closed';

    /** Se otorgó permiso para una operación en esta sesión (P16.5). */
    case PermissionGranted = 'session.permission_granted';

    /** Se retiró ese permiso. No borra el otorgamiento: lo apenda encima (P16.5). */
    case PermissionRevoked = 'session.permission_revoked';

    /** Cambió el modo de autonomía de la sesión (P16.6). */
    case ModeChanged = 'session.mode_changed';

    /** La sesión terminó, con el motivo por el que terminó. */
    case Ended = 'session.ended';
}
