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
 * Una sesión de trabajo, tal como queda después de reproducir su stream.
 *
 * ── ES UN VALOR, NO UN OBJETO CON VIDA ──────────────────────────────────────────────────────────
 *
 * No se muta, no se guarda a sí misma y no sabe que existe un almacén. Es el resultado de reducir
 * eventos, igual que un `SurfaceModel` es el resultado de proyectar una operación (ADR-0035): quien
 * quiera cambiarla apenda un evento y vuelve a reducir. Eso quita de raíz la pregunta «¿esta copia
 * está al día?», que es la que produce dos verdades sobre lo mismo.
 *
 * Nada aquí se reescribe. `compactedThrough` no borra turnos: dice hasta dónde hay un resumen que los
 * cubre, y {@see window()} es quien decide qué se le manda al modelo. El stream conserva todo, porque
 * una bitácora que se edita deja de servir para lo único que sirve una bitácora.
 */
final readonly class Session
{
    /**
     * @param list<array{role: string, content: string, seq: int}>                                 $turns       la conversación completa,
     *                                                                                                          en orden y con su secuencia
     * @param list<Todo>                                                                           $todos
     * @param list<string>                                                                         $permissions operaciones consentidas
     *                                                                                                          para esta sesión
     * @param list<array{question: string, answer: string, by?: Principal|null, expired?: string}> $decisions   lo que el humano
     *                                                                                                          resolvió cuando la
     *                                                                                                          sesión se detuvo a
     *                                                                                                          preguntar
     */
    public function __construct(
        public string $id,
        public string $goal,
        /**
         * Quién invocó esta sesión, o `null` si nadie: es una sesión raíz.
         *
         * La filiación es el primer contrato de un sub-agente, y está aquí y no en una tabla aparte
         * porque una sesión es un stream de eventos: de quién desciende se contesta reproduciéndolo,
         * igual que todo lo demás. Sin esto no se puede auditar un árbol hacia arriba sin adivinar.
         */
        public ?string $parentId = null,
        public AutonomyMode $mode = AutonomyMode::Ask,
        public array $turns = [],
        public ?string $plan = null,
        public array $todos = [],
        public array $permissions = [],
        public ?string $summary = null,
        public int $compactedThrough = 0,
        public ?PendingQuestion $question = null,
        // Lo más caro de perder al compactar: son las decisiones que NO eran del agente. Si se
        // borraran, volvería a preguntarlas o —peor— volvería a suponerlas.
        public array $decisions = [],
        public ?string $endedBecause = null,
    ) {
    }

    /** Si la sesión sigue viva: ni terminada ni esperando una respuesta humana. */
    public function isRunnable(): bool
    {
        return $this->endedBecause === null && $this->question === null;
    }

    /**
     * Lo que se le manda al modelo: el resumen de lo viejo, más los turnos que todavía no cubre.
     *
     * Es la razón entera de que compactar sea un evento y no una reescritura. El stream tiene los
     * cuarenta turnos; esto devuelve un resumen más los últimos ocho, y quien audite mañana sigue
     * teniendo los cuarenta. Si se reemplazaran, la evidencia de cómo se llegó a una decisión se
     * perdería justo en las sesiones donde más importa: las largas.
     *
     * @return list<array{role: string, content: string}>
     */
    public function window(): array
    {
        $ventana = [];
        if ($this->summary !== null && $this->summary !== '') {
            $ventana[] = [
                'role' => 'system',
                'content' => "Resumen de lo que ya pasó en esta sesión:\n" . $this->summary,
            ];
        }

        // EL ESTADO VA EN LA VENTANA, y va DESPUÉS del resumen y ANTES de los turnos: es lo último
        // que se sabe, no algo que pasó. Sin esto, el plan vivía en el stream y no llegaba al modelo,
        // así que el agente lo escribía y no lo volvía a ver — un plan que sólo sirve para auditar es
        // media función. Y se rinde el plan ACTUAL, no el que había cuando se escribió: la ventana
        // describe dónde estamos.
        $estado = $this->stateBriefing();
        if ($estado !== null) {
            $ventana[] = ['role' => 'system', 'content' => $estado];
        }

        foreach ($this->turns as $turno) {
            if ($turno['seq'] > $this->compactedThrough) {
                $ventana[] = ['role' => $turno['role'], 'content' => $turno['content']];
            }
        }

        return $ventana;
    }

    /**
     * El plan y lo que falta, redactado para que el modelo lo lea — o `null` si no hay ninguno.
     *
     * Los pendientes CERRADOS también van, y no por completitud: sin ellos el agente vuelve a hacer
     * lo que ya hizo. Es la falla más cara de una jornada larga porque se paga en pasos y en archivos
     * escritos dos veces, y no se nota hasta que alguien revisa el diff.
     */
    public function stateBriefing(): ?string
    {
        if (($this->plan === null || trim($this->plan) === '') && $this->todos === []) {
            return null;
        }

        $lineas = [];
        if ($this->plan !== null && trim($this->plan) !== '') {
            $lineas[] = 'Plan de esta sesión: ' . trim($this->plan);
        }

        if ($this->todos !== []) {
            $lineas[] = 'Pendientes:';
            foreach ($this->todos as $todo) {
                $marca = match ($todo->status) {
                    TodoStatus::Done => '[x]',
                    TodoStatus::InProgress => '[~]',
                    TodoStatus::Blocked => '[!]',
                    TodoStatus::Pending => '[ ]',
                };
                $lineas[] = "  {$marca} {$todo->id}: {$todo->text}";
            }
        }

        return implode("\n", $lineas);
    }

    /** Si esta sesión ya tiene consentida esa operación. */
    public function allows(string $operation): bool
    {
        return \in_array($operation, $this->permissions, true);
    }

    /**
     * Lo que falta — para saber si la sesión avanza o nomás gasta pasos.
     *
     * @return list<Todo>
     */
    public function pendingTodos(): array
    {
        return array_values(array_filter(
            $this->todos,
            static fn (Todo $t): bool => $t->status !== TodoStatus::Done,
        ));
    }
}
