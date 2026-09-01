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
     * @param list<array{role: string, content: string, seq: int}>                                                                                                  $turns       la conversación completa,
     *                                                                                                                                                                           en orden y con su secuencia
     * @param list<Todo>                                                                                                                                            $todos
     * @param list<string>                                                                                                                                          $permissions operaciones consentidas
     *                                                                                                                                                                           para esta sesión
     * @param list<array{question: string, answer: string, by?: Principal|null, executor?: string|null, reason?: string|null, why?: string|null, expired?: string}> $decisions   lo que el humano
     *                                                                                                                                                                           resolvió cuando la
     *                                                                                                                                                                           sesión se detuvo a
     *                                                                                                                                                                           preguntar — con el
     *                                                                                                                                                                           motivo y el objeto de
     *                                                                                                                                                                           la pregunta, para que
     *                                                                                                                                                                           una confirmación se
     *                                                                                                                                                                           pueda consumir sin
     *                                                                                                                                                                           parsear prosa
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
        // QUÉ VERSIÓN DEL PLAN ES ÉSTA. La historia no se reescribe, se supersede: cada vez que el
        // plan cambia de contenido la versión avanza y el evento declara a cuál reemplaza. Reescribir
        // el mismo texto NO avanza — no supersede nada.
        //
        // `0` significa que nunca hubo plan. Una sesión anterior a que esto existiera se reproduce con
        // `1` si tiene alguno: es la mínima consistente con lo que quedó escrito, y no le inventa un
        // linaje que nadie produjo.
        public int $planVersion = 0,
        // CUÁNTAS HERRAMIENTAS HAN CORRIDO en esta sesión. No es telemetría: es lo que le permite al
        // sistema decidir, SIN preguntarle al agente, si una tarjeta nació antes o después del
        // trabajo — y de ahí sale su origen ({@see TodoOrigin}).
        public int $toolCalls = 0,
        // CUÁNTAS DE ESAS CAMBIARON ALGO. Separado de `toolCalls` porque mirar y mover no son lo
        // mismo, y el invariante que el sistema puede verificar sin cooperación del agente se cuenta
        // sobre las mutaciones: cuántas cosas cambiaron mientras una tarjeta declarada seguía abierta.
        public int $mutations = 0,
        public array $todos = [],
        public array $permissions = [],
        /** @var list<string> opciones retiradas de la mesa de esta sesión */
        public array $removedOptions = [],
        public ?string $summary = null,
        public int $compactedThrough = 0,
        public ?PendingQuestion $question = null,
        /**
         * A GovernedSequence stopped mid-run, waiting for consent — or `null` when none is paused.
         *
         * While this is set the session is not runnable ({@see isRunnable()}), the same way an
         * open {@see PendingQuestion} stops it: a process can die here and a later one still finds
         * the cursor, because the pause is a fact of the stream and not a variable that lived only
         * in the dead process's memory (H-PERSIST-1, greenhouse decisions/0076).
         */
        public ?PausedSequence $pausedSequence = null,
        // Lo más caro de perder al compactar: son las decisiones que NO eran del agente. Si se
        // borraran, volvería a preguntarlas o —peor— volvería a suponerlas.
        public array $decisions = [],
        public ?string $endedBecause = null,
        /** @var list<string> tools that must run before any other call proceeds */
        public array $runFirst = [],
        /**
         * Whether this session currently carries a standing discipline: set by the last non-empty
         * `--first`, revoked by an empty one — the same authority that set it, unsetting it.
         *
         * Distinct from `$runFirst`, which empties as soon as the obligation is MET — being met
         * does not erase having been obliged, so the renewal keeps working across resumed turns.
         * Renewing is a property of the session somebody set up that way — not of the app it
         * happens to run in, which is where it lived first and where it could not be measured: an
         * app-level flag reaches both arms of any experiment inside that app, so the control
         * receives the treatment and stops being a control.
         */
        public bool $obligationDeclared = false,
        /**
         * The LAST signed ownership assertion this stream carries, or `null` when nobody signed.
         *
         * Private on purpose: the one reading surface is {@see ownershipAssertion()}, which is
         * where the doctrine about what this data is NOT lives — two doors into a fact this easy
         * to misread would be one door too many.
         *
         * @var array<string, mixed>|null
         */
        private ?array $ownershipAssertion = null,
        /**
         * Los SOBRES de cada permiso: operación → lista de sobres, uno por grant (greenhouse
         * decisions/0067). `null` es un sí pelón (sin cota dentro del techo); un arreglo es el
         * `EffectProfile::toArray()` de un apretón. Va aparte de `$permissions` a propósito: esa lista
         * de nombres la leen el resumidor, la TUI y `agent:show`, y un sobre no es un nombre.
         *
         * @var array<string, list<?array<string, mixed>>>
         */
        public array $envelopes = [],
        /**
         * The session's EVIDENCE LEDGER — every piece recorded, in the order it was recorded.
         *
         * It is what lets a `done` mean more than the agent's word: a todo is verified when this
         * ledger holds verifiable evidence tied to it ({@see evidenceFor()}). Kept whole and never
         * pruned, because «what closed this todo» is an audit question and an audit reads the record,
         * not a summary of it.
         *
         * @var list<Evidence>
         */
        public array $evidence = [],
    ) {
    }

    /**
     * The verifiable evidence tied to a todo — WHAT closed it, read straight from the ledger.
     *
     * Only verifiable pieces count: one that points at nothing cannot be re-checked, so it cannot be
     * what closed anything. This is the query the audit asks — given a todo, show the evidence — and
     * it is answered from the stream's fold, never from a mutable flag on the card.
     *
     * @return list<Evidence>
     */
    public function evidenceFor(string $todoId): array
    {
        return array_values(array_filter(
            $this->evidence,
            static fn (Evidence $e): bool => $e->todo === $todoId && $e->isVerifiable(),
        ));
    }

    /**
     * Whether a todo is BOTH done AND backed by verifiable evidence.
     *
     * The two halves are separate on purpose: a done with an empty {@see evidenceFor()} is a done the
     * ledger cannot vouch for, and that is precisely the state a surface must flag rather than hide.
     */
    public function isDoneVerified(string $todoId): bool
    {
        foreach ($this->todos as $todo) {
            if ($todo->id === $todoId) {
                return $todo->status === TodoStatus::Done && $this->evidenceFor($todoId) !== [];
            }
        }

        return false;
    }

    /**
     * The done todos the ledger cannot vouch for — done, but with no verifiable evidence tied to them.
     *
     * Not censored, NAMED: the system records what happened, and an unevidenced done is a fact worth
     * seeing, not one to erase — the same doctrine {@see TodoOrigin::Unsupported} already applies at
     * birth. A board paints these apart; a verifier counts them without having to deduce anything.
     *
     * @return list<Todo>
     */
    public function unverifiedDones(): array
    {
        return array_values(array_filter(
            $this->todos,
            fn (Todo $t): bool => $t->status === TodoStatus::Done && $this->evidenceFor($t->id) === [],
        ));
    }

    /** Si la sesión sigue viva: ni terminada, ni esperando una respuesta humana, ni una secuencia pausada. */
    public function isRunnable(): bool
    {
        return $this->endedBecause === null && $this->question === null && $this->pausedSequence === null;
    }

    /**
     * EL TOPE ES DE LA VENTANA, y de nadie más.
     *
     * Un resultado de herramienta grande le sirve entero a una superficie —que arma su tabla con
     * él— y le estorba al modelo, cuyo contexto es lo que se acaba en una sesión larga. Durante un
     * tiempo se recortó al ESCRIBIR, así que la ventana obtenía lo que necesitaba y la superficie
     * pagaba la cuenta: medido sobre ganado, `capabilities` contestó 2004 caracteres, el log guardó
     * 600, el valor dejó de parsear y el humano no vio tabla ninguna (greenhouse `evidence/0203`).
     *
     * **El tope pertenece a quien tiene la escasez.** El log guarda entero; aquí se recorta.
     *
     * Se le aplica AL RESULTADO y no a la línea completa, para rendirle al modelo el mismo
     * presupuesto que antes en vez de uno nuevo que incluya el nombre de la herramienta.
     */
    private const MAX_TOOL_RESULT = 600;

    /** @param array{role: string, content: string, tool?: string, result?: string} $turno */
    private static function paraLaVentana(array $turno): string
    {
        if ($turno['role'] !== 'tool' || ! isset($turno['tool'], $turno['result'])) {
            return $turno['content'];
        }

        return $turno['tool'] . ' → ' . mb_substr($turno['result'], 0, self::MAX_TOOL_RESULT);
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
        $providerWindow = [];
        foreach ($this->classifiedWindow() as $message) {
            $providerWindow[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        return $providerWindow;
    }

    /**
     * The composed window with each message's reason declared beside its provider role and content.
     *
     * This is the channel representation, not a provider payload. {@see window()} projects the same
     * composition down to the two provider fields, so classification can be recorded out of band
     * without teaching a gateway or a model a Milpa-only key.
     *
     * @return list<array{role: string, content: string, class: value-of<WindowMessageClass>}>
     */
    public function classifiedWindow(): array
    {
        $window = [];
        if ($this->summary !== null && $this->summary !== '') {
            $window[] = [
                'role' => 'system',
                'content' => "Resumen de lo que ya pasó en esta sesión:\n" . $this->summary,
                'class' => WindowMessageClass::Summary->value,
            ];
        }

        // CURRENT STATE belongs after the summary and before the turns: it is what is known now, not
        // something that happened. The current plan is rendered rather than the historical one, so
        // the window describes where the session is instead of preserving a stale projection.
        $state = $this->stateBriefing();
        if ($state !== null) {
            $window[] = [
                'role' => 'system',
                'content' => $state,
                'class' => WindowMessageClass::Briefing->value,
            ];
        }

        foreach ($this->turns as $turn) {
            if ($turn['seq'] > $this->compactedThrough) {
                $window[] = [
                    'role' => $turn['role'],
                    'content' => self::paraLaVentana($turn),
                    'class' => WindowMessageClass::Turn->value,
                ];
            }
        }

        return $window;
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

    /**
     * The signed ownership assertion of the LAST `ownership_asserted` event, or `null` when nobody
     * ever signed this session.
     *
     * DATA, NOT TRUST. What comes back is the assertion exactly as it was appended — payload,
     * signature, fingerprint, uid — and no verdict about any of it, because the session stores a
     * signed ASSERTION and never a grade (greenhouse decisions/0056). The grade is produced by
     * RE-VERIFYING the signature at consumption, in the app runtime, against the app's registry of
     * recognised fingerprints (greenhouse evidence/0254: a receipt, not a coin). A reader who
     * skips that re-verification holds what somebody once wrote into a stream — which a forger can
     * also do — not who owns this session.
     *
     * @return array<string, mixed>|null
     */
    public function ownershipAssertion(): ?array
    {
        return $this->ownershipAssertion;
    }

    /**
     * Si esta sesión ya tiene consentida esa operación — para ESTA composición, si el grant lleva sobre.
     *
     * Un `sí` pelón deja un sobre `null`: sin cota dentro del techo declarado, admite cualquier
     * composición, como siempre. Una contraoferta ESTRUCTURAL deja un sobre (greenhouse
     * decisions/0067), y entonces una llamada sólo queda admitida si su perfil COMPUESTO es no-más-
     * ancho que ese sobre en las cinco hachas — el único comparador, `isNoWiderThan`. Sin composición
     * no hay admisión bajo un sobre: lo no clasificado nunca viaja en un apretón.
     *
     * Varios grants de la misma operación coexisten; cualquiera que cubra, admite. Un `sí` al lado de
     * un sobre sigue admitiendo todo: apretar un sí ya dado es revocar y apretar.
     */
    public function allows(string $operation, ?\Milpa\Command\Effect\EffectProfile $composed = null): bool
    {
        if (!\in_array($operation, $this->permissions, true)) {
            return false;
        }

        // Sin sobres registrados para la operación (stream anterior a los sobres): un sí pelón.
        $sobres = $this->envelopes[$operation] ?? [null];
        foreach ($sobres as $sobre) {
            if ($sobre === null) {
                return true;
            }
            if ($composed !== null && $composed->isNoWiderThan(\Milpa\Command\Effect\EffectProfile::fromArray($sobre))) {
                return true;
            }
        }

        return false;
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
