<?php

/**
 * This file is part of Milpa Agent — the session substrate of the Milpa PHP framework.
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

/**
 * Traduce un evento de sesión a lo que una superficie necesita para pintarlo. **Nada más.**
 *
 * ── LA PROPIEDAD QUE NO PUEDE PERDER ────────────────────────────────────────────────────────────
 *
 * **No tiene estado.** Recibe un evento y devuelve lo que ese evento significa; no acumula, no
 * recuerda, no consulta. Lo que se ve es el *fold* del stream, y quien quiera el estado completo
 * llama a {@see SessionStore::load()} — que es el mismo fold que usa todo lo demás.
 *
 * En el momento en que este proyector guardara su propia copia del estado de una tarjeta, habría dos
 * sitios que contestan «en qué va esto» y divergirían; la única pregunta es cuándo. Es la misma regla
 * que Q-P19-B dejó escrita para la caducidad y que ADR-0037 dejó escrita para la cardinalidad.
 *
 * ── POR QUÉ NO PUBLICA NADA ─────────────────────────────────────────────────────────────────────
 *
 * Porque publicar es del host: `milpa/mercure` empuja, `milpa/live-web` sirve, y este paquete no
 * depende de ninguno. Un proyector que además publicara obligaría a que cualquiera que quiera leer un
 * stream se traiga un hub de eventos.
 *
 * ── POR QUÉ CADA HECHO SE TRADUCE Y NINGUNO SE INTERPRETA ───────────────────────────────────────
 *
 * El stream ya dice de dónde a dónde se movió una tarjeta, cómo nació y qué se decidió al cerrar. Un
 * proyector que dedujera el movimiento comparando con lo anterior estaría reconstruyendo lo que el
 * hecho ya trae — y dos lectores con la misma deducción escrita distinto cuentan historias distintas
 * del mismo stream.
 */
final readonly class SessionProjector
{
    /**
     * El evento como lo necesita una superficie, o `null` si no le dice nada a ninguna.
     *
     * `null` no es un descarte silencioso: es la afirmación de que ese hecho no cambia lo que se ve.
     * Un turno del modelo importa en la conversación y no en el tablero, y forzarlo a producir algo
     * llenaría la superficie de ruido que nadie pidió.
     *
     * @return array{session: string, kind: string, at: int, card?: array<string, mixed>, plan?: array<string, mixed>, ended?: array<string, mixed>}|null
     */
    public function project(Event $event): ?array
    {
        $tipo = SessionEvent::tryFrom($event->type);
        if ($tipo === null) {
            // Un tipo que este paquete no conoce no se adivina. Un stream se lee años después de
            // escribirse, y una superficie que inventa qué hacer con lo desconocido pinta cualquier
            // cosa con cara de dato.
            return null;
        }

        $sesion = str_starts_with($event->streamId, SessionStore::PREFIX)
            ? substr($event->streamId, \strlen(SessionStore::PREFIX))
            : $event->streamId;

        $base = ['session' => $sesion, 'kind' => $tipo->value, 'at' => $event->seq];
        $p = $event->payload;

        return match ($tipo) {
            SessionEvent::TodoChanged => [
                ...$base,
                'kind' => 'card',
                'card' => [
                    'id' => \is_string($p['id'] ?? null) ? $p['id'] : '',
                    'text' => \is_string($p['text'] ?? null) ? $p['text'] : '',
                    // LA COLUMNA A LA QUE VA y LA QUE DEJA. `from` en `null` significa que nació ahí:
                    // la tarjeta no cruzó nada, y una superficie que la anime como si hubiera cruzado
                    // estaría contando un movimiento que no ocurrió.
                    'to' => \is_string($p['status'] ?? null) ? $p['status'] : '',
                    'from' => \is_string($p['from'] ?? null) ? $p['from'] : null,
                    'version' => \is_int($p['version'] ?? null) ? $p['version'] : 1,
                    'origin' => \is_string($p['origin'] ?? null) ? $p['origin'] : null,
                    'inheritedFrom' => \is_array($p['inheritedFrom'] ?? null) ? $p['inheritedFrom'] : null,
                ],
            ],
            SessionEvent::PlanSet => [
                ...$base,
                'kind' => 'plan',
                'plan' => [
                    'text' => \is_string($p['plan'] ?? null) ? $p['plan'] : '',
                    'version' => \is_int($p['version'] ?? null) ? $p['version'] : 1,
                    'supersedes' => \is_int($p['supersedes'] ?? null) ? $p['supersedes'] : null,
                ],
            ],
            SessionEvent::EndedWithOpenWork => [
                ...$base,
                'kind' => 'open-work',
                'ended' => ['todos' => \is_array($p['todos'] ?? null) ? $p['todos'] : []],
            ],
            SessionEvent::TodosTransferred => [
                ...$base,
                'kind' => 'transferred',
                'ended' => [
                    'to' => \is_string($p['to'] ?? null) ? $p['to'] : '',
                    'todos' => \is_array($p['todos'] ?? null) ? $p['todos'] : [],
                ],
            ],
            SessionEvent::Ended => [
                ...$base,
                'kind' => 'ended',
                'ended' => ['because' => \is_string($p['because'] ?? null) ? $p['because'] : ''],
            ],
            SessionEvent::QuestionAsked => [
                ...$base,
                'kind' => 'waiting',
                'ended' => ['question' => \is_string($p['question'] ?? null) ? $p['question'] : ''],
            ],
            // Lo que no cambia lo que se ve: turnos, llamadas, permisos, compactación, respuestas,
            // cambios de modo, apertura, y el cierre de la ventana para contestar. Van explícitos y
            // no en un `default` para que agregar un evento nuevo obligue a decidir si se pinta —
            // un `default` silencioso haría que el siguiente hecho nazca invisible.
            SessionEvent::Started,
            SessionEvent::Turn,
            SessionEvent::ToolCalled,
            SessionEvent::Compacted,
            SessionEvent::QuestionAnswered,
            SessionEvent::AnswerWindowClosed,
            SessionEvent::PermissionGranted,
            SessionEvent::PermissionRevoked,
            SessionEvent::ModeChanged => null,
        };
    }

    /**
     * Todo lo pintable de un stream, en orden.
     *
     * Existe para que una superficie que llega tarde pueda ponerse al día con la misma traducción con
     * la que va a recibir lo que siga. Dos caminos —uno para la historia y otro para lo nuevo— son dos
     * oportunidades de pintar distinto el mismo hecho.
     *
     * @param list<Event> $events
     *
     * @return list<array<string, mixed>>
     */
    public function projectAll(array $events): array
    {
        $salida = [];
        foreach ($events as $evento) {
            $proyectado = $this->project($evento);
            if ($proyectado !== null) {
                $salida[] = $proyectado;
            }
        }

        return $salida;
    }
}
