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
    private const PREFIX = 'agent-session:';

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
    public function recordToolCall(string $id, string $tool, array $arguments, string $result, bool $ok = true): void
    {
        $this->append($id, SessionEvent::ToolCalled, [
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => $result,
            'ok' => $ok,
        ]);
    }

    /**
     * Apenda un resumen de todo lo ocurrido hasta `$throughSeq` (P16.2).
     *
     * Los turnos resumidos SIGUEN en el stream. Lo que cambia es {@see Session::window()}, que deja de
     * mandárselos al modelo. Reemplazarlos ahorraría bytes y destruiría la evidencia de cómo se llegó
     * a donde se llegó — en las sesiones largas, que son las únicas que se compactan.
     */
    public function compact(string $id, string $summary, int $throughSeq): void
    {
        $this->append($id, SessionEvent::Compacted, ['summary' => $summary, 'through' => $throughSeq]);
    }

    /** Fija el plan de trabajo (P16.3). */
    public function setPlan(string $id, string $plan): void
    {
        $this->append($id, SessionEvent::PlanSet, ['plan' => $plan]);
    }

    /** Crea o mueve un pendiente (P16.3). */
    public function setTodo(string $id, Todo $todo): void
    {
        $this->append($id, SessionEvent::TodoChanged, $todo->toArray());
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
    public function answer(string $id, string $questionId, string $answer, ?Principal $by = null): void
    {
        $this->append($id, SessionEvent::QuestionAnswered, [
            'id' => $questionId,
            'answer' => $answer,
            'by' => $by?->toArray(),
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
        $this->append($id, SessionEvent::Ended, ['because' => $because]);
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
