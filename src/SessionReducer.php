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
        $parentId = null;
        /** @var array<string, Todo> $todos */
        $todos = [];
        /** @var list<string> $permisos */
        $permisos = [];
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
                SessionEvent::ToolCalled => $turnos[] = [
                    'role' => 'tool',
                    'content' => (\is_string($p['tool'] ?? null) ? $p['tool'] : '?')
                        . ' → ' . (\is_string($p['result'] ?? null) ? $p['result'] : ''),
                    'seq' => $evento->seq,
                ],
                SessionEvent::Compacted => [
                    $resumen = \is_string($p['summary'] ?? null) ? $p['summary'] : $resumen,
                    $compactadoHasta = \is_int($p['through'] ?? null) ? $p['through'] : $compactadoHasta,
                ],
                SessionEvent::PlanSet => $plan = \is_string($p['plan'] ?? null) ? $p['plan'] : $plan,
                SessionEvent::TodoChanged => $todos[\is_string($p['id'] ?? null) ? $p['id'] : ''] = Todo::fromArray($p),
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
                SessionEvent::PermissionGranted => $permisos = $this->conPermiso($permisos, $p),
                SessionEvent::PermissionRevoked => $permisos = $this->sinPermiso($permisos, $p),
                SessionEvent::ModeChanged => $mode = AutonomyMode::tryFrom(
                    \is_string($p['mode'] ?? null) ? $p['mode'] : '',
                ) ?? $mode,
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
            todos: array_values($todos),
            permissions: $permisos,
            summary: $resumen,
            compactedThrough: $compactadoHasta,
            question: $pregunta,
            decisions: $decisiones,
            endedBecause: $terminada,
        );
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
