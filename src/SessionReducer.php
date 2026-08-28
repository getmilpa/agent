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
        /** @var array<string, list<?array<string, mixed>>> $sobres operación → sobres, null = sí pelón */
        $sobres = [];
        /** @var list<string> $retiradas */
        $retiradas = [];

        /** @var list<string> $primero */
        $primero = [];

        $huboObligacion = false;
        $resumen = null;
        $compactadoHasta = 0;
        $pregunta = null;
        $secuenciaPausada = null;
        /** @var list<array{question: string, answer: string}> $decisiones */
        $decisiones = [];
        $terminada = null;
        /** @var array<string, mixed>|null $ownership */
        $ownership = null;

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
                // A MESSAGE ENTERS THE CONVERSATION AS `user`, with the sender inside the text.
                //
                // As `user` because to the recipient it is somebody outside telling it something —
                // the same category as its brief. And the sender travels INSIDE the content rather
                // than in a separate field because the model reads content: a `from` that only
                // existed on the event would be auditable and invisible to whoever has to act.
                //
                // It carries `■`, the visual language's system marker, so anyone looking at the
                // screen tells a message from the tree apart from something the model wrote. The
                // model cannot fabricate it: this reduction places it from where the event came.
                SessionEvent::MessageSent => $turnos[] = [
                    'role' => 'user',
                    'content' => sprintf(
                        '■ mensaje de «%s»: %s',
                        \is_string($p['from'] ?? null) ? $p['from'] : '(desconocido)',
                        \is_string($p['content'] ?? null) ? $p['content'] : '',
                    ),
                    'seq' => $evento->seq,
                ],
                // Una llamada a herramienta es parte de la conversación que el modelo tiene que ver:
                // sin ella, retomar una sesión sería retomarla sin saber qué ya se intentó, y el
                // agente repetiría el trabajo que su yo anterior ya hizo.
                SessionEvent::ToolCalled => [
                    // AN OBLIGATION THAT WAS MET STOPS BEING ONE.
                    //
                    // Without this the prerequisite re-arms on every turn, and a resumed session
                    // re-plans each time: measured on a real run, six identical cards for one task
                    // and twenty pending for six. «Plan before starting» meant once, not once per
                    // turn — and a board painted on the second reading is noisy where the empty one
                    // was mute. Neither is a source.
                    $primero = $this->sinCumplir($primero, \is_string($p['tool'] ?? null) ? $p['tool'] : ''),
                    // CUÁNTAS HERRAMIENTAS CORRIERON YA. Es lo que permite que el sistema sepa, sin
                    // preguntarle al agente, si una tarjeta nació antes o después del trabajo.
                    ++$herramientas,
                    ($p['mutating'] ?? false) === true ? ++$mutaciones : null,
                    $turnos[] = [
                    'role' => 'tool',
                    'content' => (\is_string($p['tool'] ?? null) ? $p['tool'] : '?')
                        . ' → ' . (\is_string($p['result'] ?? null) ? $p['result'] : ''),
                        'seq' => $evento->seq,
                        // LAS PARTES, ADEMÁS DE LA CADENA. `content` sigue igual para quien ya lo
                        // leía; esto existe para que {@see Session::window()} pueda aplicarle el
                        // tope AL RESULTADO y no a la línea entera, y le rinda al modelo el mismo
                        // presupuesto de siempre en vez de uno nuevo que incluya el nombre.
                        //
                        // Partir `content` por su flecha para recuperarlas sería releer lo que uno
                        // mismo compuso, y un nombre de herramienta con una flecha adentro rompería
                        // esa lectura el día que exista.
                        'tool' => \is_string($p['tool'] ?? null) ? $p['tool'] : '?',
                        'result' => \is_string($p['result'] ?? null) ? $p['result'] : '',
                    ],
                ],
                SessionEvent::Compacted => [
                    $resumen = \is_string($p['summary'] ?? null) ? $p['summary'] : $resumen,
                    $compactadoHasta = \is_int($p['through'] ?? null) ? $p['through'] : $compactadoHasta,
                ],
                SessionEvent::PlanSet => [
                    // The bookkeeping tools do not travel through `session.tool_called`: they have
                    // events of their own, so the obligation has to be discharged from here too.
                    $primero = $this->sinCumplir($primero, 'plan'),
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
                SessionEvent::TodoChanged => [
                    $primero = $this->sinCumplir($primero, 'todo'),
                    $todos[\is_string($p['id'] ?? null) ? $p['id'] : ''] = $this->conOrigen(
                        Todo::fromArray($p),
                        $todos[\is_string($p['id'] ?? null) ? $p['id'] : ''] ?? null,
                    ),
                ],
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
                // A REMOVED OPTION DOES NOT COME BACK in this session. It is a fact, not a
                // preference: it was appended because someone with authority refused that call, and
                // restoring it without another fact saying so would be a table that shifts on its own.
                SessionEvent::OptionRemoved => $retiradas = \in_array($o = (\is_string($p['option'] ?? null) ? $p['option'] : ''), $retiradas, true) || $o === ''
                    ? $retiradas
                    : [...$retiradas, $o],
                // REPLACED, never accumulated: the standing obligation is the last one somebody
                // with authority declared. Accumulating would leave a session where each `--first`
                // narrows the door further and nobody can widen it again — a table that drifts on
                // its own, in the other direction.
                SessionEvent::PrerequisiteSet => [
                    $primero = array_values(array_filter(
                        \is_array($p['tools'] ?? null) ? $p['tools'] : [],
                        static fn ($t): bool => \is_string($t) && trim($t) !== '',
                    )),
                    // DECLARED OR LIFTED, last one wins — same rule as the list itself. An empty
                    // set is the same authority UNSETTING the discipline, and it must reach the
                    // flag the renewal reads: a lift that only emptied the list would be re-armed
                    // with `todo` on the very next turn — the caller unset it and the session put
                    // it back. Meeting the obligation is different: that empties the list through
                    // its own events and never writes this one, so «being met does not erase having
                    // been obliged» stays true.
                    $huboObligacion = $primero !== [],
                ],
                // THE ASSERTION FOLDS AS DATA, AND THE LAST ONE WINS. No verification happens
                // here — a pure reducer cannot call gpg, and must not pretend to: the grade is
                // produced by re-verifying the signature at consumption, in the app runtime
                // (greenhouse decisions/0056, evidence/0254). Earlier assertions stay in the
                // stream; what this projection answers is who signed this session most recently.
                SessionEvent::OwnershipAsserted => $ownership = $this->assertionFrom($p, $ownership),
                SessionEvent::PermissionGranted => [$permisos, $sobres] = [$this->conPermiso($permisos, $p), $this->conSobre($sobres, $p)],
                SessionEvent::PermissionRevoked => [$permisos, $sobres] = [$this->sinPermiso($permisos, $p), $this->sinSobre($sobres, $p)],
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
                // · `ModelCalled` es la ENTRADA del agente, y el estado de la sesión es lo que pasó
                //   con ella. Meterlo aquí como turno le agregaría a la conversación un renglón que
                //   nunca viajó, y la siguiente llamada mandaría de vuelta al modelo el registro de
                //   la anterior: observar el canal lo habría cambiado. La entrada se lee del stream,
                //   que es donde vive, y no del estado reducido.
                // EL HECHO DE UNA EJECUCIÓN NO CAMBIA EL ESTADO DE LA SESIÓN, y es una decisión.
                //
                // `Session` es lo que hace falta para SEGUIR: el objetivo, el plan, los pendientes, la
                // pregunta abierta. Que una operación se haya materializado no mueve nada de eso — el
                // resultado de la llamada ya viajó como turno y ya está en la ventana.
                //
                // Este evento existe para una pregunta POSTERIOR —quién autorizó y quién ejecutó— que
                // se contesta leyendo el stream, no el estado. Resumirlo aquí sería la segunda verdad
                // que este diseño evita, y una atribución resumida es la que envejece mal.
                // EL `system` TAMPOCO CAMBIA EL ESTADO DE LA SESIÓN, y por la misma razón que
                // `ModelCalled`: es la ENTRADA, y `Session` es lo que hace falta para SEGUIR. El
                // prompt se rearma en cada llamada desde la configuración —medido en
                // `evidence/0223`, donde una sesión lo cambió a media corrida y la siguiente llamada
                // lo recogió— así que doblarlo aquí guardaría una foto vieja al lado de la fuente
                // viva. Este evento existe para el que AUDITA: contesta qué recibió el agente
                // entonces, no con qué sigue ahora.
                SessionEvent::SystemSet,
                SessionEvent::OperationExecuted,
                SessionEvent::EndedWithOpenWork, SessionEvent::TodosTransferred, SessionEvent::ModelCalled,
                // The cost of a call does not change the fold — it is a fact for replay and for
                // return-aware observers, read straight from the stream, never re-derived here.
                SessionEvent::ModelReturned => null,
                // A COMPOSITION DOES NOT CHANGE THE FOLD, and it is a decision the exhaustive match
                // forces (greenhouse decisions/0059). What a session needs to CONTINUE — its goal,
                // plan, pending work — does not move because a rehearsal descended a ceiling. The
                // receipt exists for the human who AUDITS, and is read from the stream, not the state.
                SessionEvent::CeilingComposed => null,
                // THE TRIAL FACTS DO NOT MOVE THE FOLD EITHER (greenhouse decisions/0069): a run in a
                // disposable workspace, its promotion, its discard — all are evidence for the human
                // who audits, read from the stream; none changes what the session IS.
                SessionEvent::TrialRunRecorded, SessionEvent::TrialPromoted, SessionEvent::TrialDiscarded => null,
                // A PAUSED SEQUENCE STOPS THE SESSION exactly like an open PendingQuestion does
                // (H-PERSIST-1, greenhouse decisions/0076): the fact IS the cursor, held here until
                // its matching resume clears it — never re-derived from OperationExecuted, which
                // stays the record of what already happened.
                SessionEvent::SequencePaused => $secuenciaPausada = PausedSequence::fromArray($p),
                SessionEvent::SequenceResumed => $secuenciaPausada = null,
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
            envelopes: $sobres,
            removedOptions: $retiradas,
            runFirst: $primero,
            obligationDeclared: $huboObligacion,
            summary: $resumen,
            compactedThrough: $compactadoHasta,
            question: $pregunta,
            pausedSequence: $secuenciaPausada,
            decisions: $decisiones,
            endedBecause: $terminada,
            ownershipAssertion: $ownership,
        );
    }

    /**
     * The assertion an ownership event carries, EXACTLY as persisted — or the previous one when
     * the event carries none.
     *
     * Untouched on purpose: the consumer re-verifies these bytes against their signature, and a
     * reducer that «normalised» them would break every honest receipt while fixing nothing — a
     * malformed event in the stream is a fact about the stream, not something to repair on read.
     *
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $previous
     *
     * @return array<string, mixed>|null
     */
    private function assertionFrom(array $payload, ?array $previous): ?array
    {
        $assertion = $payload['assertion'] ?? null;
        if (!\is_array($assertion)) {
            return $previous;
        }

        /** @var array<string, mixed> $assertion */
        return $assertion;
    }

    /** La tarjeta nueva con el origen que ya tenía, si lo tenía: nacer se declara una sola vez. */
    private function conOrigen(Todo $nueva, ?Todo $previa): Todo
    {
        if ($nueva->origin !== null || $previa?->origin === null) {
            return $nueva;
        }

        // EVERY field travels: rebuilding by hand is how a new one goes missing with nothing saying
        // so. `replaces` was lost right here, and the test caught it before it reached any board.
        return new Todo(
            $nueva->id,
            $nueva->text,
            $nueva->status,
            $nueva->version,
            $previa->origin,
            $nueva->mutationsAt,
            $nueva->planVersion,
            $nueva->replaces,
            $nueva->bornInPlan,
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
     * The obligation minus what has just run.
     *
     * @param list<string> $pendientes
     *
     * @return list<string>
     */
    private function sinCumplir(array $pendientes, string $corrio): array
    {
        if ($corrio === '' || $pendientes === []) {
            return $pendientes;
        }

        return array_values(array_filter($pendientes, static fn (string $t): bool => $t !== $corrio));
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

    /**
     * Registra el SOBRE de este grant junto a los de la misma operación (greenhouse decisions/0067).
     *
     * `null` es un sí pelón; un stream anterior a los sobres trae sólo `operation` y cae ahí. Un
     * sobre mal formado NO se convierte en sí: se ignora el grant entero, porque leer «sobre ilegible»
     * como «sin cota» sería ensanchar por accidente exactamente lo que el sobre vino a acotar.
     *
     * @param array<string, list<?array<string, mixed>>> $sobres
     * @param array<string, mixed>                       $payload
     *
     * @return array<string, list<?array<string, mixed>>>
     */
    private function conSobre(array $sobres, array $payload): array
    {
        $operacion = \is_string($payload['operation'] ?? null) ? $payload['operation'] : '';
        if ($operacion === '') {
            return $sobres;
        }

        $sobre = $payload['envelope'] ?? null;
        if ($sobre !== null && !\is_array($sobre)) {
            return $sobres;
        }

        $sobres[$operacion][] = $sobre;

        return $sobres;
    }

    /**
     * Revocar quita TODOS los sobres de la operación, pelones y apretados por igual.
     *
     * @param array<string, list<?array<string, mixed>>> $sobres
     * @param array<string, mixed>                       $payload
     *
     * @return array<string, list<?array<string, mixed>>>
     */
    private function sinSobre(array $sobres, array $payload): array
    {
        $operacion = \is_string($payload['operation'] ?? null) ? $payload['operation'] : '';
        unset($sobres[$operacion]);

        return $sobres;
    }
}
