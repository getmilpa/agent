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
     * Se le pidió algo al modelo: qué herramientas le ofrecieron, qué contexto recibió, con qué
     * modelo, contra qué endpoint.
     *
     * ── LA MITAD QUE FALTABA ────────────────────────────────────────────────────────────────────
     *
     * Todo lo de arriba graba lo que el agente HIZO. Nada gravaba lo que al agente LE DIERON, y por
     * eso depurarlo significaba parar un proxy frente al endpoint y leer el cable a mano. De las
     * siete preguntas que gradúan una vista de desarrollador, el canal contestaba cuatro; las tres
     * que faltaban eran todas de la entrada.
     *
     * SE DERIVA DEL CUERPO QUE VIAJÓ, no de lo que el código pensaba mandar. Esa distinción es el
     * contrato: en cuanto el evento diga algo que el canal no dijo, deja de ser una observación y se
     * vuelve un tercer inventario con mejor nombre.
     *
     * NO ES UN TURNO. Observar un canal no puede cambiarlo: si grabar lo que se le pidió al modelo
     * también le metiera un turno a la conversación, cada corrida observada diferiría de la misma
     * corrida sin observar, y una medición que altera lo que mide no es evidencia de nada.
     */
    case ModelCalled = 'session.model_called';

    /**
     * A message between two sessions of the same tree — parent to child, or child to parent.
     *
     * ── WHY AN EVENT AND NOT A VARIABLE ─────────────────────────────────────────────────────────
     *
     * Because the channel is BIDIRECTIONAL and the two sessions live in processes that need not
     * overlap in time: a parent corrects a child that paused yesterday, a child says something while
     * the parent is waiting. A variable requires both to be alive at once; an event does not.
     *
     * And because it stays auditable: «the parent told it to look elsewhere» is a fact of the stream,
     * with its order and its sender, rather than somebody's claim afterwards.
     *
     * ── WHAT A MESSAGE CANNOT DO, WHICH IS HALF THE CONTRACT ────────────────────────────────────
     *
     * **A message carries information, never authority.** It grants no permission, does not raise the
     * autonomy ceiling, does not answer a pending question and does not close a session. Each of
     * those has its own operation with its own contract, and the last three are kept out of the
     * agent's catalogue on purpose (Q-P19-M).
     *
     * Without that rule a parent could write «you may write files now» and the lineage ceiling would
     * become a suggestion — the cheapest authority laundering there is, precisely because it travels
     * through a channel that looks harmless.
     */
    case MessageSent = 'session.message_sent';

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

    /**
     * La sesión terminó **dejando trabajo declarado sin cerrar**, y eso se dice.
     *
     * ── POR QUÉ ES UN HECHO APARTE Y NO UN CAMPO DE `Ended` ─────────────────────────────────────
     *
     * Porque terminar y dejar trabajo abierto son dos cosas: una sesión puede terminar limpia. Meter
     * la lista dentro del `ended` obligaría a leer el motivo de cierre para saber si quedó algo, y a
     * quien busca trabajo huérfano le interesa lo segundo sin lo primero.
     *
     * ── Y POR QUÉ NO LAS CIERRA ─────────────────────────────────────────────────────────────────
     *
     * Porque el sistema no sabe por qué se detuvo el trabajo. Puede haber esperado autoridad humana,
     * haber topado el contexto, haberse transferido, haber fallado, o haberse dejado a propósito para
     * retomar. Marcarlas «abandonadas» sería inferir un juicio de una ausencia — el default es
     * {@see \Milpa\Agent\TodoDisposition::Open}, que dice lo que se observó y no lo que significa.
     *
     * La continuidad pertenece al sistema, no a la sesión: terminar una no mata trabajo que puede
     * seguir en otra.
     */
    case EndedWithOpenWork = 'session.ended_with_open_work';

    /**
     * Un conjunto de tarjetas abiertas pasó a otra sesión, que ahora las tiene.
     *
     * Queda en el stream de la sesión ORIGEN. La sesión destino recibe las tarjetas con su linaje
     * —cómo nacieron y por dónde pasaron— porque una tarjeta que cambia de dueño y pierde su historia
     * es una tarjeta nueva con el mismo texto.
     */
    case TodosTransferred = 'session.todos_transferred';

    /** La sesión terminó, con el motivo por el que terminó. */
    /**
     * Una opción salió de la mesa de ESTA sesión.
     *
     * No es un mensaje: es una mutación del entorno. Q-P19-D/E midieron que una negativa no redirige
     * —0 de 32 corridas volvieron a llamar una herramienta, ni cuando la negativa nombraba la
     * alternativa— y Q-P19-F midió que un catálogo sin la opción sí: 16 de 16 observaron. Lo que
     * mueve al operador no es lo que se le dice, es lo que hay en la mesa.
     *
     * Va al STREAM y no a un arreglo en memoria porque la mesa pertenece a la sesión: sin este hecho
     * no sobreviviría a una compactación ni a retomar mañana, y sería la segunda copia que la spec del
     * tablero prohíbe. Con él, «¿el agente releyó el mundo?» se lee comparando este hecho contra la
     * llamada que vino después, en vez de inferirse.
     *
     * `option` y no `tool` a propósito: hoy la única manifestación medida es una herramienta, pero lo
     * que desaparece es una posibilidad — mañana puede ser una ruta o una capacidad, y la doctrina no
     * cambia. La generalización se implementa cuando haya un segundo caso, no antes (ADR-0037).
     */
    case OptionRemoved = 'session.option_removed';

    /**
     * An ordering obligation that outlives the turn.
     *
     * `--first` used to govern only the invocation that carried it, so a session resumed after a
     * pause came back with the obligation gone — and the thing it was ordering (write the plan
     * before touching anything) is exactly the thing a long session needs to keep doing. Measured in
     * Q-P17-L: with the obligation, 21 plans and 14 cards moved; without it, zero of both and zero
     * work finished.
     *
     * It is a fact of the session and not a flag of the caller, for the same reason a withdrawn
     * option is: rebuilding it from whoever happened to type the command would make the table change
     * depending on who resumed.
     */
    case PrerequisiteSet = 'session.prerequisite_set';

    case Ended = 'session.ended';
}
