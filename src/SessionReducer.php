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

/**
 * Convierte el stream de una sesión en la {@see Session} que resulta de él.
 *
 * ── PURO, Y POR ESO CONFIABLE ───────────────────────────────────────────────────────────────────
 *
 * No lee reloj, no escribe, no llama a nadie: los mismos eventos dan la misma sesión, siempre. Eso es
 * lo que permite contestar «¿cómo se veía esto en el paso 12?» reproduciendo un prefijo, y lo que
 * hace que una prueba de una sesión de cuarenta pasos sea un arreglo de eventos y no una simulación.
 *
 * El `match` es EXHAUSTIVO sobre {@see SessionEvent} a propósito. Un caso nuevo sin manejar aquí no
 * compila — mientras que con cadenas se apendaría igual y desaparecería al reconstruir, que es la peor
 * forma de perder un dato: la que parece que lo guardó.
 */
final readonly class SessionReducer
{
    /**
     * Reproduce el stream de una sesión y devuelve la sesión que resulta.
     *
     * El orden es el del stream y no se ordena aquí: quien lo guarda ya lo devuelve en orden, y
     * reordenarlo por secuencia escondería un almacén que dejó de hacerlo.
     *
     * @param list<Event> $events
     */
    public function reduce(string $id, array $events): Session
    {
        $goal = '';
        $mode = AutonomyMode::Ask;
        /** @var list<array{role: string, content: string, seq: int}> $turnos */
        $turnos = [];
        $plan = null;
        $planVersion = 0;
        $herramientas = 0;
        $mutaciones = 0;
        $parentId = null;
        /** @var array<string, Todo> $todos */
        $todos = [];
        /** @var list<string> $permisos */
        $permisos = [];
        /** @var list<string> $retiradas */
        $retiradas = [];
        $resumen = null;
        $compactadoHasta = 0;
        $pregunta = null;
        /** @var list<array{question: string, answer: string}> $decisiones */
        $decisiones = [];
        $terminada = null;

        foreach ($events as $evento) {
            $tipo = SessionEvent::tryFrom($evento->type);
            if ($tipo === null) {
                // Un tipo que este paquete no conoce se IGNORA en vez de tumbar la reconstrucción: el
                // stream puede traer eventos de una versión más nueva, o de otro productor que comparte
                // almacén. Reventar aquí haría que una sesión vieja dejara de poder leerse por algo que
                // se agregó después.
                continue;
            }

            $p = $evento->payload;

            match ($tipo) {
                SessionEvent::Started => [
                    $goal = \is_string($p['goal'] ?? null) ? $p['goal'] : '',
                    $mode = AutonomyMode::tryFrom(\is_string($p['mode'] ?? null) ? $p['mode'] : '') ?? AutonomyMode::Ask,
                    // La filiación viaja en el evento de apertura y en ningún otro: de quién
                    // desciende una sesión no cambia, y un evento que pudiera cambiarlo volvería
                    // reescribible el árbol de permisos.
                    $parentId = \is_string($p['parentId'] ?? null) && $p['parentId'] !== '' ? $p['parentId'] : null,
                ],
                SessionEvent::Turn => $turnos[] = [
                    'role' => \is_string($p['role'] ?? null) ? $p['role'] : 'user',
                    'content' => \is_string($p['content'] ?? null) ? $p['content'] : '',
                    'seq' => $evento->seq,
                ],
                // Una llamada a herramienta es parte de la conversación que el modelo tiene que ver:
                // sin ella, retomar una sesión sería retomarla sin saber qué ya se intentó, y el
                // agente repetiría el trabajo que su yo anterior ya hizo.
                SessionEvent::ToolCalled => [
                    // CUÁNTAS HERRAMIENTAS CORRIERON YA. Es lo que permite que el sistema sepa, sin
                    // preguntarle al agente, si una tarjeta nació antes o después del trabajo.
                    ++$herramientas,
                    ($p['mutating'] ?? false) === true ? ++$mutaciones : null,
                    $turnos[] = [
                    'role' => 'tool',
                    'content' => (\is_string($p['tool'] ?? null) ? $p['tool'] : '?')
                        . ' → ' . (\is_string($p['result'] ?? null) ? $p['result'] : ''),
                        'seq' => $evento->seq,
                    ],
                ],
                SessionEvent::Compacted => [
                    $resumen = \is_string($p['summary'] ?? null) ? $p['summary'] : $resumen,
                    $compactadoHasta = \is_int($p['through'] ?? null) ? $p['through'] : $compactadoHasta,
                ],
                SessionEvent::PlanSet => [
                    $plan = \is_string($p['plan'] ?? null) ? $p['plan'] : $plan,
                    // Sin `version` en el payload —eventos anteriores a que esto existiera— el linaje
                    // se cuenta desde uno. Reproducir una sesión vieja no le inventa versiones que
                    // nadie escribió; le da la mínima consistente con lo que sí quedó.
                    $planVersion = \is_int($p['version'] ?? null) ? $p['version'] : max(1, $planVersion),
                ],
                // EL ORIGEN SOBREVIVE AL MOVIMIENTO. El evento sólo lo lleva al NACER —un movimiento
                // no tiene origen, tiene un `from`— así que reconstruir la tarjeta desde el evento
                // nuevo la dejaría sin él. Conservarlo es trabajo del fold, no del hecho: el stream
                // dice qué pasó y el fold dice cómo quedó.
                SessionEvent::TodoChanged => $todos[\is_string($p['id'] ?? null) ? $p['id'] : ''] = $this->conOrigen(
                    Todo::fromArray($p),
                    $todos[\is_string($p['id'] ?? null) ? $p['id'] : ''] ?? null,
                ),
                SessionEvent::QuestionAsked => $pregunta = PendingQuestion::fromArray($p),
                // Contestar cierra la pregunta ABIERTA, y la respuesta entra como turno: es contexto
                // que el modelo necesita en el siguiente paso, no metadato.
                SessionEvent::QuestionAnswered => [
                    // El PAR se guarda aquí y no se deriva después: cuando llega la respuesta, la
                    // pregunta abierta todavía se conoce. Más adelante ya no — el reductor la cierra,
                    // y desde los turnos sueltos una respuesta es indistinguible de un mensaje
                    // cualquiera del humano.
                    $decisiones[] = [
                        'question' => $pregunta instanceof PendingQuestion ? $pregunta->question : '',
                        'answer' => \is_string($p['answer'] ?? null) ? $p['answer'] : '',
                        // QUIÉN, y si se pudo verificar. `null` es «nadie lo dijo», que es lo que
                        // devuelven las sesiones anteriores a que esto existiera — y leerlas no
                        // inventa un principal para ellas.
                        'by' => \is_array($p['by'] ?? null) ? Principal::fromArray($p['by']) : null,
                        // El proceso que la materializó, al lado y nunca en lugar del actor.
                        'executor' => \is_string($p['executor'] ?? null) ? $p['executor'] : null,
                        // POR QUÉ SE PREGUNTÓ y QUÉ SE ESTABA AUTORIZANDO, heredados de la pregunta.
                        //
                        // Sin esto, una decisión es un par pregunta-respuesta en prosa y nadie puede
                        // consumirla sin parsear texto. Con esto, el contrato de intención (ADR-0044)
                        // puede leer «esta operación, con estos argumentos, ya fue confirmada por el
                        // humano» — que es lo que cierra el ciclo Pregunta → Nueva intención: una
                        // confirmación que no destraba la re-propuesta sería teatro con acta.
                        'reason' => $pregunta instanceof PendingQuestion ? $pregunta->reason : null,
                        'why' => $pregunta instanceof PendingQuestion ? $pregunta->why : null,
                    ],
                    $pregunta = null,
                    $turnos[] = [
                        'role' => 'user',
                        'content' => \is_string($p['answer'] ?? null) ? $p['answer'] : '',
                        'seq' => $evento->seq,
                    ],
                ],
                // Cerrar la ventana cierra la pregunta y DEJA CONSTANCIA, igual que contestar. La diferencia
                // con una respuesta es que aquí nadie decidió: por eso entra en `decisiones` con la
                // respuesta vacía y su motivo, y no como un turno del humano — un turno inventado
                // sería poner en boca de alguien un silencio.
                SessionEvent::AnswerWindowClosed => [
                    $decisiones[] = [
                        'question' => $pregunta instanceof PendingQuestion ? $pregunta->question : '',
                        'answer' => '',
                        'expired' => \is_string($p['at'] ?? null) ? $p['at'] : '',
                    ],
                    $pregunta = null,
                ],
                // UNA OPCIÓN RETIRADA NO VUELVE en esta sesión. Es un hecho, no una preferencia: se
                // apendó porque alguien con autoridad negó esa llamada, y reponerla sin otro hecho que
                // lo diga sería una mesa que cambia sola.
                SessionEvent::OptionRemoved => $retiradas = \in_array($o = (\is_string($p['option'] ?? null) ? $p['option'] : ''), $retiradas, true) || $o === ''
                    ? $retiradas
                    : [...$retiradas, $o],
                SessionEvent::PermissionGranted => $permisos = $this->conPermiso($permisos, $p),
                SessionEvent::PermissionRevoked => $permisos = $this->sinPermiso($permisos, $p),
                SessionEvent::ModeChanged => $mode = AutonomyMode::tryFrom(
                    \is_string($p['mode'] ?? null) ? $p['mode'] : '',
                ) ?? $mode,
                // LOS DOS HECHOS DE FRONTERA NO CAMBIAN EL FOLD, y decirlo aquí es la decisión que el
                // `match` exhaustivo obliga a tomar en vez de dejar pasar:
                //
                // · `EndedWithOpenWork` describe lo que se observó AL CERRAR, y el estado que
                //   agregaría —qué quedó abierto— ya se deriva de los propios pendientes. Duplicarlo
                //   sería tener dos respuestas para «qué falta», y el día que difieran gana la
                //   equivocada.
                // · `TodosTransferred` vive en la sesión ORIGEN y habla de otra: las tarjetas que se
                //   fueron llegan a la destino como sus propios `todo_changed`, en su stream. Aplicarlo
                //   aquí borraría de la origen lo que sí pasó ahí.
                SessionEvent::EndedWithOpenWork, SessionEvent::TodosTransferred => null,
                SessionEvent::Ended => $terminada = \is_string($p['because'] ?? null) ? $p['because'] : 'sin motivo',
            };
        }

        return new Session(
            id: $id,
            goal: $goal,
            parentId: $parentId,
            mode: $mode,
            turns: $turnos,
            plan: $plan,
            planVersion: $planVersion,
            toolCalls: $herramientas,
            mutations: $mutaciones,
            todos: array_values($todos),
            permissions: $permisos,
            removedOptions: $retiradas,
            summary: $resumen,
            compactedThrough: $compactadoHasta,
            question: $pregunta,
            decisions: $decisiones,
            endedBecause: $terminada,
        );
    }

    /** La tarjeta nueva con el origen que ya tenía, si lo tenía: nacer se declara una sola vez. */
    private function conOrigen(Todo $nueva, ?Todo $previa): Todo
    {
        if ($nueva->origin !== null || $previa?->origin === null) {
            return $nueva;
        }

        return new Todo($nueva->id, $nueva->text, $nueva->status, $nueva->version, $previa->origin, $nueva->mutationsAt);
    }

    /**
     * @param list<string>         $permisos
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function conPermiso(array $permisos, array $payload): array
    {
        $operacion = \is_string($payload['operation'] ?? null) ? $payload['operation'] : '';
        if ($operacion === '' || \in_array($operacion, $permisos, true)) {
            return $permisos;
        }

        $permisos[] = $operacion;

        return $permisos;
    }

    /**
     * @param list<string>         $permisos
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function sinPermiso(array $permisos, array $payload): array
    {
        $operacion = \is_string($payload['operation'] ?? null) ? $payload['operation'] : '';

        return array_values(array_filter($permisos, static fn (string $p): bool => $p !== $operacion));
    }
}
