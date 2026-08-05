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

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * Abre, continúa y reconstruye sesiones sobre un log append-only.
 *
 * ── POR QUÉ EVENT-SOURCED Y NO UNA FILA QUE SE ACTUALIZA ────────────────────────────────────────
 *
 * Porque de una sesión larga importa tanto en qué quedó como CÓMO llegó ahí. Una fila con
 * `estado_actual` contesta lo primero y borra lo segundo en cada `UPDATE`, y lo segundo es justo lo
 * que alguien va a querer al día siguiente: qué permiso se otorgó y cuándo, qué preguntó el agente,
 * qué se le contestó, en qué paso se torció. Con un stream, «el agente corrió cuarenta pasos solo» es
 * una afirmación verificable en vez de una esperanza.
 *
 * Y hace posible lo demás: retomar es replicar, compactar es apendar un resumen sin perder los turnos,
 * y una pregunta pendiente es un evento sin su par — no un flag que alguien tiene que acordarse de
 * limpiar.
 *
 * ── EL ALMACÉN ES PRESTADO ──────────────────────────────────────────────────────────────────────
 *
 * `milpa/event-store` ya trae el log (JSONL en disco o en memoria) detrás de una interfaz. Este
 * paquete no reinventa persistencia: pone el vocabulario de una sesión encima. Una app que ya guarda
 * eventos guarda las sesiones de su agente en el mismo lugar.
 */
final readonly class SessionStore
{
    /**
     * El prefijo de stream de toda sesión.
     *
     * Público desde que existe {@see SessionProjector}: una superficie que traduce eventos tiene que
     * saber cuál es el id de la sesión dentro del stream, y la alternativa era que se lo escribiera a
     * mano. Dos lugares con la misma cadena escrita aparte es como se llega a que un proyector deje
     * de reconocer los streams que el almacén escribe.
     */
    public const PREFIX = 'agent-session:';

    public function __construct(private EventStoreInterface $events)
    {
    }

    /**
     * Abre una sesión con su objetivo y su modo, y devuelve su identificador.
     *
     * $parentId declara de quién desciende. Va en el evento de apertura porque no cambia: de quién
     * es hija una sesión es un hecho de su nacimiento, y un evento posterior que lo moviera volvería
     * reescribible el árbol de permisos entero.
     *
     * El id lo pone quien llama porque este paquete no decide cómo se nombran las cosas de una app —y
     * porque un id inyectado es un id que una prueba puede fijar. Sin fuente de aleatoriedad adentro
     * no hay nada que sustituir para que las pruebas sean deterministas.
     */
    public function start(
        string $id,
        string $goal,
        AutonomyMode $mode = AutonomyMode::Ask,
        ?string $parentId = null,
    ): string {
        $this->append($id, SessionEvent::Started, [
            'goal' => $goal,
            'mode' => $mode->value,
            'parentId' => $parentId,
        ]);

        return $id;
    }

    /**
     * El techo de autonomía que los ANTEPASADOS de esta sesión le imponen, o `null` si es raíz.
     *
     * Se resuelve al preguntar y no se copia al nacer, y la diferencia es todo el punto: un modo
     * copiado en el evento de apertura se queda viejo en cuanto el padre baja el suyo con
     * `setMode()`, y un hijo seguiría en `auto` bajo un padre que ya volvió a `ask`. Un permiso
     * heredado que no sigue a su origen es una declaración rancia, que es exactamente la clase de
     * mentira que este repositorio lleva semanas quitando de otros lados.
     *
     * Devuelve el MÁS RESTRICTIVO de toda la cadena, no el del padre inmediato: si el abuelo está en
     * `ask`, no hay padre intermedio que pueda devolverle `auto` al nieto.
     *
     * Una cadena rota —un padre que ya no existe— NO se ignora: se trata como el techo más
     * restrictivo. No poder comprobar de quién desciende alguien no es permiso para asumir que puede
     * todo (ADR-0029).
     */
    public function ceilingFor(string $id): ?AutonomyMode
    {
        $sesion = $this->load($id);
        if ($sesion?->parentId === null) {
            return null;
        }

        $techo = null;
        $visto = [$id => true];
        $actual = $sesion->parentId;

        while ($actual !== null) {
            // Un ciclo en la filiación no puede colgar el proceso ni conceder permisos: se corta y
            // se devuelve lo más restrictivo. Que sea imposible construirlo no lo vuelve imposible
            // de encontrar en un stream escrito por otra versión.
            if (isset($visto[$actual])) {
                return AutonomyMode::Ask;
            }
            $visto[$actual] = true;

            $padre = $this->load($actual);
            if ($padre === null) {
                return AutonomyMode::Ask;
            }

            $techo = $techo === null ? $padre->mode : $techo->strictest($padre->mode);
            $actual = $padre->parentId;
        }

        return $techo;
    }

    /** Reconstruye la sesión reproduciendo su stream; `null` si nunca se abrió. */
    public function load(string $id): ?Session
    {
        $eventos = $this->events->replay(self::PREFIX . $id);
        if ($eventos === []) {
            return null;
        }

        return (new SessionReducer())->reduce($id, $eventos);
    }

    /**
     * Los identificadores de todas las sesiones que este almacén conoce.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->events->streams() as $stream) {
            if (str_starts_with($stream, self::PREFIX)) {
                $ids[] = substr($stream, \strlen(self::PREFIX));
            }
        }

        return $ids;
    }

    /** Apenda un turno de la conversación. */
    public function recordTurn(string $id, string $role, string $content): void
    {
        $this->append($id, SessionEvent::Turn, ['role' => $role, 'content' => $content]);
    }

    /**
     * Apenda una llamada a herramienta con lo que devolvió.
     *
     * @param array<string, mixed> $arguments
     */
    public function recordToolCall(
        string $id,
        string $tool,
        array $arguments,
        string $result,
        bool $ok = true,
        bool $mutating = false,
    ): void {
        $this->append($id, SessionEvent::ToolCalled, [
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => $result,
            'ok' => $ok,
            // SI ESTA LLAMADA CAMBIÓ ALGO. Lo sabe quien tiene la operación —la compuerta— y hasta
            // ahora no lo escribía, así que el stream no distinguía mirar de mover. Sin esa
            // distinción no se puede verificar nada sobre las mutaciones: son invisibles como tales.
            'mutating' => $mutating,
        ]);
    }

    /**
     * Apenda un resumen de todo lo ocurrido hasta `$throughSeq` (P16.2).
     *
     * Los turnos resumidos SIGUEN en el stream. Lo que cambia es {@see Session::window()}, que deja de
     * mandárselos al modelo. Reemplazarlos ahorraría bytes y destruiría la evidencia de cómo se llegó
     * a donde se llegó — en las sesiones largas, que son las únicas que se compactan.
     */
    /**
     * A message from one session to another in the same tree. It lands in the RECIPIENT's stream.
     *
     * ── WHY THE RECIPIENT'S ─────────────────────────────────────────────────────────────────────
     *
     * Because that is where it has to reach the model's window: a message living in the sender's
     * stream would be a private note the recipient never reads. And because the child may be paused
     * or running at another moment — the event waits; a variable does not.
     *
     * ── WHAT THIS METHOD DOES NOT CHECK, AND WHO DOES ───────────────────────────────────────────
     *
     * FILIATION. It does not verify that `$from` and `$to` belong to the same tree, and that is not
     * an oversight: this store is the scribe, not the authority. Whoever decides if one session may
     * talk to another is the operation that offers it — the one that sees the lineage and can refuse
     * with a reason. Putting it here would give the scribe a policy, and a policy in the scribe is a
     * policy nobody can substitute.
     *
     * What IS invariant, and lives in the name for that reason: a message carries INFORMATION. It
     * grants no permission, raises no ceiling, answers no pending question and closes nothing — that
     * is `grant`, `setMode`, `answer` and `end`, each with its own contract.
     */
    public function message(string $to, string $from, string $content): void
    {
        $this->append($to, SessionEvent::MessageSent, ['from' => $from, 'content' => $content]);
    }

    /**
     * Resume lo que ya pasó hasta `$throughSeq`, para que la ventana quepa sin perder el hilo.
     *
     * No borra nada: el stream sigue completo y el resumen es un evento más. Compactar es una
     * decisión sobre qué se le ENSEÑA al modelo, nunca sobre qué se guarda.
     */
    public function compact(string $id, string $summary, int $throughSeq): void
    {
        $this->append($id, SessionEvent::Compacted, ['summary' => $summary, 'through' => $throughSeq]);
    }

    /** Fija el plan de trabajo (P16.3). */
    public function setPlan(string $id, string $plan): void
    {
        // LA HISTORIA NO SE REESCRIBE, SE SUPERSEDE. Antes cada `plan_set` decía «el plan es esto» sin
        // relación con el anterior: cinco sesiones de Q-P19-C reescribieron su plan con texto distinto
        // y el stream se quedó con cinco planes sueltos, sin decir cuál sustituye a cuál. Un log
        // append-only que no declara el reemplazo conserva los hechos y pierde el linaje.
        //
        // La versión la calcula ESTE método y no quien llama: pedirle al agente que numere sus planes
        // sería una decisión más que puede errar, y el número tiene que ser correcto para que la
        // cadena se pueda leer.
        $anterior = $this->load($id);
        $version = $anterior instanceof Session ? $anterior->planVersion : 0;

        // Reescribir el MISMO texto no supersede nada: el evento se apenda igual —pasó, y el stream
        // registra lo que pasó— pero la versión no avanza y no reemplaza a nadie. Así el linaje por
        // versión cuenta la historia del plan, y el stream crudo sigue mostrando que el agente lo
        // volvió a declarar, que es un dato sobre el sistema y no sobre él.
        $cambio = ($anterior instanceof Session ? $anterior->plan : null) !== $plan;

        $this->append($id, SessionEvent::PlanSet, [
            'plan' => $plan,
            'version' => $cambio ? $version + 1 : $version,
            'supersedes' => $cambio && $version > 0 ? $version : null,
        ]);
    }

    /**
     * Crea o mueve un pendiente (P16.3), declarando **de dónde a dónde**.
     *
     * ── LA MISMA DOCTRINA QUE EL PLAN: NO SE REESCRIBE, SE SUPERSEDE ────────────────────────────
     *
     * Antes el evento decía «esta tarjeta está en X» y nada más. Quién la movió desde dónde había que
     * **deducirlo** comparando con lo que se hubiera visto antes en el stream — y esa deducción vivía
     * en los scripts de análisis, no en el hecho. Dos lectores podían reconstruir historias distintas
     * del mismo stream, que es justo lo que un log append-only existe para impedir.
     *
     * Ahora cada evento lleva su versión, la versión a la que reemplaza, y el estado del que viene.
     * Un tablero **lee** el movimiento en vez de inferirlo.
     *
     * Como con el plan: re-declarar la MISMA tarjeta —mismo texto, mismo estado— no supersede nada y
     * no avanza la versión, pero el evento se apenda igual, porque ocurrió.
     */
    public function setTodo(string $id, Todo $todo): void
    {
        $sesion = $this->load($id);
        $previo = null;
        foreach ($sesion instanceof Session ? $sesion->todos : [] as $t) {
            if ($t->id === $todo->id) {
                $previo = $t;

                break;
            }
        }

        $cambio = $previo === null
            || $previo->status !== $todo->status
            || $previo->text !== $todo->text;
        $version = $previo === null ? 1 : ($cambio ? $previo->version + 1 : $previo->version);

        $this->append($id, SessionEvent::TodoChanged, [
            ...$todo->toArray(),
            'version' => $version,
            // CUÁNTAS MUTACIONES LLEVABA LA SESIÓN cuando esta tarjeta se tocó por última vez. Es el
            // dato que permite preguntar después, sin cooperación de nadie: ¿cuántas cosas cambiaron
            // en el mundo desde que nadie mira esta tarjeta?
            'mutationsAt' => $sesion instanceof Session ? $sesion->mutations : 0,
            // CÓMO NACIÓ, derivado y no preguntado. Sólo al nacer: un movimiento posterior no tiene
            // origen, tiene un `from` — y ponerle uno sería reescribir cómo apareció cada vez que se
            // mueve ({@see TodoOrigin}).
            'origin' => $previo === null
                ? TodoOrigin::derive($todo->status, $sesion instanceof Session ? $sesion->toolCalls : 0)->value
                : null,
            // A qué versión de ESTA tarjeta reemplaza. `null` al nacer: no reemplaza a nadie.
            'supersedes' => $cambio && $previo !== null ? $previo->version : null,
            // DE DÓNDE VIENE. Es el dato que el tablero necesita para pintar un movimiento y el que
            // antes había que deducir. `null` al nacer — una tarjeta que aparece no viene de ningún
            // lado, y decir que viene de `pending` sería inventar una columna que nunca ocupó.
            'from' => $cambio && $previo !== null ? $previo->status->value : null,
        ]);
    }

    /** Levanta la mano: la sesión queda en pausa hasta que alguien conteste (P16.4). */
    public function ask(string $id, PendingQuestion $question): void
    {
        $this->append($id, SessionEvent::QuestionAsked, $question->toArray());
    }

    /**
     * Cierra la ventana para contestar si su plazo ya venció, y declara muerta la sesión.
     *
     * Lo que vence NO es la pregunta —sigue siendo válida y se puede volver a hacer— sino la
     * autoridad para contestarla dentro de ESTA sesión. Ver {@see SessionEvent::AnswerWindowClosed}.
     *
     * ── POR QUÉ TERMINA LA SESIÓN Y NO SÓLO LA PREGUNTA ─────────────────────────────────────────
     *
     * Porque la pregunta existe para que el agente pueda seguir, y sin respuesta no puede: cerrarla
     * y dejar la sesión viva la mandaría a preguntar lo mismo, o —peor— a seguir sin el permiso que
     * estaba esperando. Terminarla convierte el limbo en un hecho con motivo, que es justo lo que
     * faltaba (Q-P19-B).
     *
     * ── POR QUÉ RECIBE EL INSTANTE ──────────────────────────────────────────────────────────────
     *
     * Para que se pueda probar sin esperar, y para que quien la llame decida qué reloj vale. Un
     * método que consulta la hora por su cuenta obliga a que las pruebas duerman, y una prueba que
     * duerme es una prueba que alguien acaba borrando.
     *
     * No hace nada si no hay pregunta, si no tiene plazo, o si el plazo no ha vencido: devuelve
     * `false` y el stream queda igual. Se puede llamar en cada vuelta sin ensuciar nada.
     */
    public function expireIfDue(string $id, \DateTimeImmutable $now): bool
    {
        $sesion = $this->load($id);
        if ($sesion?->question === null || !$sesion->question->hasExpired($now)) {
            return false;
        }

        $cuando = $now->format(\DateTimeInterface::ATOM);
        $this->append($id, SessionEvent::AnswerWindowClosed, [
            'id' => $sesion->question->id,
            'at' => $cuando,
        ]);
        $this->end($id, sprintf(
            'se cerró el %s la ventana para contestar «%s», y nadie contestó',
            $cuando,
            $sesion->question->question,
        ));

        return true;
    }

    /**
     * Contesta la pregunta abierta y desbloquea la sesión (P16.4).
     *
     * `$by` es QUIÉN contestó. Es opcional porque un llamador puede no saberlo, no porque dé igual:
     * un permiso sin principal no es auditable ({@see Principal}). Y va como objeto y no como cadena
     * para que la respuesta cargue si esa identidad se verificó o sólo se declaró.
     */
    public function answer(
        string $id,
        string $questionId,
        string $answer,
        ?Principal $by = null,
        ?string $executor = null,
    ): void {
        $this->append($id, SessionEvent::QuestionAnswered, [
            'id' => $questionId,
            'answer' => $answer,
            'by' => $by?->toArray(),
            // EL EJECUTOR ACOMPAÑA AL ACTOR, no lo sustituye. Son dos identidades: quién autorizó y
            // qué proceso lo materializó. Anotar el proceso donde había una persona identificada
            // convierte una cadena de custodia real en una falsa.
            'executor' => $executor,
        ]);
    }

    /**
     * Retirar una opción de la mesa de esta sesión.
     *
     * Se llama cuando una autoridad ya negó esa llamada: la negativa deja de ser un mensaje y pasa a
     * ser una mutación del entorno. Q-P19-D/E midieron que decirle que no —incluso nombrándole la
     * alternativa— no lo redirige: 0 de 32 volvieron a llamar una herramienta. Q-P19-F midió que una
     * mesa sin la opción sí: 16 de 16 observaron.
     *
     * El motivo viaja con el hecho porque quien lea este stream mañana necesita saber **por qué** esa
     * opción no estaba, y no puede preguntárselo a nadie.
     *
     * ── CÓDIGO Y MENSAJE, NO PROSA SUELTA ───────────────────────────────────────────────────────
     *
     * El mensaje cambia —se reescribe, se traduce, se afina—; el código no. Una proyección que quiera
     * agrupar, contar o traducir motivos tiene que poder hacerlo **sin parsear prosa**, y un stream se
     * lee años después de escribirse: para entonces la frase de hoy puede no existir en ningún lado.
     *
     * Es la misma forma que la frontera ya usa (`reason_code`), y por la misma razón.
     */
    public function removeOption(string $id, string $option, string $code, ?string $message = null): void
    {
        $option = trim($option);
        $code = trim($code);
        if ($option === '' || $code === '') {
            return;
        }

        $this->append($id, SessionEvent::OptionRemoved, [
            'option' => $option,
            'reason' => ['code' => $code, 'message' => $message],
        ]);
    }

    /**
     * Consiente una operación para el resto de esta sesión (P16.5).
     *
     * Por operación y por sesión, nunca global: «sí a `make`, en esta sesión» es una frase que alguien
     * puede evaluar. «Sí a lo que el agente decida» no lo es.
     */
    public function grant(string $id, string $operation): void
    {
        $this->append($id, SessionEvent::PermissionGranted, ['operation' => $operation]);
    }

    /** Retira ese permiso — apendando encima, sin borrar que se otorgó (P16.5). */
    public function revoke(string $id, string $operation): void
    {
        $this->append($id, SessionEvent::PermissionRevoked, ['operation' => $operation]);
    }

    /** Cambia el modo de autonomía a media sesión (P16.6). */
    public function setMode(string $id, AutonomyMode $mode): void
    {
        $this->append($id, SessionEvent::ModeChanged, ['mode' => $mode->value]);
    }

    /** Cierra la sesión con el motivo por el que se cerró. */
    public function end(string $id, string $because): void
    {
        // ANTES DE CERRAR, DECIR QUÉ QUEDÓ ABIERTO. Sin esto, una sesión que termina con trabajo
        // declarado y sin resolver no dice nada al respecto: las tarjetas se quedan en su columna y
        // nadie puede distinguir «se hizo todo» de «se acabó la sesión a media tarea».
        //
        // No las cierra, y ésa es la decisión: el sistema no sabe por qué se detuvo el trabajo, así
        // que las declara `Open` —lo observado— en vez de «abandonadas», que sería inferir un juicio
        // de una ausencia ({@see TodoDisposition}).
        $sesion = $this->load($id);
        $abiertas = [];
        foreach ($this->openTodos($id) as $todo) {
            $abiertas[] = [
                'id' => $todo->id,
                'status' => $todo->status->value,
                'version' => $todo->version,
                'disposition' => TodoDisposition::Open->value,
                // EL INVARIANTE, y es lo único que este hecho afirma: cuántas mutaciones ocurrieron
                // DESPUÉS de que esta tarjeta se tocó por última vez.
                //
                // `0` es una tarjeta que quedó abierta y sobre la que no pasó nada más — no hay nada
                // que explicar. Un número alto es el sistema diciendo: cambiaron siete cosas y esta
                // tarjeta no se movió ni se cerró. No dice que esté mal, dice que no se explicó.
                'mutationsSince' => max(0, ($sesion instanceof Session ? $sesion->mutations : 0) - $todo->mutationsAt),
            ];
        }

        if ($abiertas !== []) {
            $this->append($id, SessionEvent::EndedWithOpenWork, ['todos' => $abiertas]);
        }

        $this->append($id, SessionEvent::Ended, ['because' => $because]);
    }

    /**
     * Los hechos de una sesión, en orden, desde `$since` en adelante.
     *
     * Existe para que una superficie —terminal, navegador, agente— consuma el stream **traducido por
     * el mismo proyector** en vez de leerlo cruda cada quien. `$since` es la secuencia del último
     * hecho que ya vio, así que ponerse al día y recibir lo nuevo son el mismo camino: dos caminos
     * distintos son dos oportunidades de pintar distinto el mismo hecho.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $id, int $since = 0): array
    {
        $eventos = [];
        foreach ($this->events->replay(self::PREFIX . $id) as $evento) {
            if ($evento->seq > $since) {
                $eventos[] = $evento;
            }
        }

        return (new SessionProjector())->projectAll($eventos);
    }

    /**
     * Las tarjetas de esta sesión que todavía no están en un estado terminal.
     *
     * `blocked` cuenta como abierta: bloqueada es trabajo detenido, no trabajo terminado, y meterla
     * con `done` haría desaparecer justo lo que alguien tiene que ir a destrabar.
     *
     * @return list<Todo>
     */
    public function openTodos(string $id): array
    {
        $sesion = $this->load($id);
        $abiertas = [];
        foreach ($sesion instanceof Session ? $sesion->todos : [] as $todo) {
            if ($todo->status !== TodoStatus::Done) {
                $abiertas[] = $todo;
            }
        }

        return $abiertas;
    }

    /**
     * Pasa las tarjetas abiertas de una sesión a otra, con su linaje.
     *
     * ── QUIÉN HEREDA EL TRABAJO ─────────────────────────────────────────────────────────────────
     *
     * La pregunta es de Rod y es arquitectónica: terminar una sesión no debería matar trabajo que
     * puede continuar. Este método es la respuesta mínima — alguien nombra la sesión que hereda, y el
     * traslado deja hecho en las DOS: en la origen queda que se fueron y a dónde, y en la destino
     * llegan con **cómo nacieron** y **desde qué versión vienen**.
     *
     * Una tarjeta que cambia de dueño y pierde su historia es una tarjeta nueva con el mismo texto, y
     * el tablero que la pinte no podría decir que ya se había trabajado en ella.
     *
     * Devuelve cuántas se movieron. Cero si no había abiertas: transferir nada no es un error, es una
     * sesión que terminó limpia.
     */
    public function transferOpenTodos(string $from, string $to): int
    {
        $abiertas = $this->openTodos($from);
        if ($abiertas === []) {
            return 0;
        }

        $ids = [];
        foreach ($abiertas as $todo) {
            $ids[] = ['id' => $todo->id, 'version' => $todo->version];

            // Llega a la destino como una tarjeta nueva de ESE stream —versión 1 ahí— pero con su
            // origen intacto y diciendo de dónde viene. El linaje no se reescribe: se continúa.
            $this->append($to, SessionEvent::TodoChanged, [
                ...$todo->toArray(),
                'version' => 1,
                'supersedes' => null,
                'from' => null,
                'origin' => $todo->origin?->value,
                'inheritedFrom' => ['session' => $from, 'version' => $todo->version],
            ]);
        }

        $this->append($from, SessionEvent::TodosTransferred, ['to' => $to, 'todos' => $ids]);

        return \count($abiertas);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(string $id, SessionEvent $type, array $payload): void
    {
        $this->events->append(new Event(
            streamId: self::PREFIX . $id,
            type: $type->value,
            payload: $payload,
            seq: $this->events->nextSeq(),
        ));
    }
}
