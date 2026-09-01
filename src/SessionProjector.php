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
     * @return array{session: string, kind: string, at: int, card?: array<string, mixed>, plan?: array<string, mixed>, ended?: array<string, mixed>, activity?: array<string, mixed>, message?: array<string, mixed>, answered?: array<string, mixed>}|null
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
                    // WHETHER A `done` IS BACKED BY VERIFIABLE EVIDENCE, so a stateless surface can
                    // flag an unevidenced done without holding the whole fold. `null` where it does
                    // not apply — a card that is not `done`, or a stream written before this existed,
                    // which reads as «unknown», never as «verified».
                    'evidenced' => \is_bool($p['evidenced'] ?? null) ? $p['evidenced'] : null,
                ],
            ],
            // EVIDENCE IS ITS OWN KIND: it does not move a card, it VOUCHES for one. A surface paints
            // it beside the todo it closes — or on its own when it is not yet tied — and the reference
            // is the load-bearing part, because it is what a reader follows to re-check the claim.
            SessionEvent::EvidenceRecorded => [
                ...$base,
                'kind' => 'evidence',
                'card' => [
                    'id' => \is_string($p['id'] ?? null) ? $p['id'] : '',
                    'evidenceKind' => \is_string($p['kind'] ?? null) ? $p['kind'] : '',
                    'reference' => \is_string($p['reference'] ?? null) ? $p['reference'] : '',
                    'todo' => \is_string($p['todo'] ?? null) ? $p['todo'] : null,
                    'detail' => \is_string($p['detail'] ?? null) ? $p['detail'] : null,
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
            // ANSWERING PROJECTS: it is what clears the waiting banner. This event translated to
            // `null` — «does not change what you see» — and that was false for the board: a surface
            // showing the question kept showing it until the NEXT event arrived, so an answer
            // without a resumption left an already-answered question on screen. Found watching a
            // real session with the page open, not reading this file.
            //
            // The attribution travels whole and untouched: actor and executor are two identities,
            // both already in the fact, and a surface that wants to say WHO answered must not have
            // to re-derive it.
            SessionEvent::QuestionAnswered => [
                ...$base,
                'kind' => 'answered',
                'answered' => [
                    'answer' => \is_string($p['answer'] ?? null) ? $p['answer'] : '',
                    'by' => \is_array($p['by'] ?? null) ? $p['by'] : null,
                    'executor' => \is_string($p['executor'] ?? null) ? $p['executor'] : null,
                ],
            ],
            // ── ACTIVIDAD: EN QUÉ ESTÁ LA SESIÓN AHORA MISMO ───────────────────────────────
            //
            // Un turno y una llamada a herramienta no cambian el TABLERO —ninguna tarjeta se mueve—
            // pero sí cambian lo que una pantalla tiene que estar diciendo mientras se espera. La
            // primera versión de este proyector los mandaba a `null` porque el tablero no los pinta,
            // y ahí estaba el error: **«el tablero no lo pinta» no es «no es proyectable»**.
            //
            // Se proyectan una vez y cada superficie filtra lo que le sirve. La alternativa —una
            // segunda traducción para la actividad— sería la copia que esta clase existe para no
            // tener: dos lecturas del mismo stream que divergen en el evento que nadie probó.
            SessionEvent::Turn => [
                ...$base,
                'kind' => 'activity',
                'activity' => [
                    // `user` significa que la pregunta ya está guardada y el modelo tiene la palabra;
                    // `assistant`, que contestó. Son los dos extremos de la espera.
                    'state' => \is_string($p['role'] ?? null) && $p['role'] === 'assistant' ? 'ready' : 'thinking',
                    'detail' => null,
                ],
            ],
            // A MESSAGE IS ITS OWN KIND, not an activity turn.
            //
            // Folding it into `activity` would tell a surface «the model is thinking» when what
            // actually happened is that somebody in the tree said something — and the sender would be
            // lost, which is the one detail a reader needs to tell a correction from an answer.
            SessionEvent::MessageSent => [
                ...$base,
                'kind' => 'message',
                'message' => [
                    'from' => \is_string($p['from'] ?? null) ? $p['from'] : '(unknown)',
                    'content' => \is_string($p['content'] ?? null) ? $p['content'] : '',
                ],
            ],
            SessionEvent::ToolCalled => [
                ...$base,
                'kind' => 'activity',
                'activity' => [
                    'state' => 'tool',
                    // EL NOMBRE, que es lo que hace observable que algo pasa: un texto fijo durante
                    // dieciséis segundos no distingue trabajo de cuelgue; un nombre que cambia sí.
                    'detail' => \is_string($p['tool'] ?? null) ? $p['tool'] : null,
                    'mutating' => ($p['mutating'] ?? false) === true,
                    'ok' => ($p['ok'] ?? true) === true,
                    // LO QUE LA HERRAMIENTA CONTESTÓ, para que una superficie pueda ARMAR la vista
                    // del dato en vez de enseñar la transcripción que el modelo hizo de él.
                    //
                    // Va por aquí y no por una segunda lectura del stream: esta clase es la única
                    // traducción, y dos lecturas del mismo evento divergen en el caso que nadie
                    // probó. Viaja tal cual quedó guardado —recortado por quien lo guardó— así que
                    // quien lo consuma tiene que tolerar que no parsee: un resultado a medias es un
                    // texto, no una tabla, y fingir lo contrario sería inventar filas.
                    'result' => \is_string($p['result'] ?? null) ? $p['result'] : null,
                ],
            ],
            // Lo que no cambia lo que se ve: permisos, compactación, cambios de modo, apertura, y
            // el cierre de la ventana para contestar. Van explícitos y no en un `default`
            // para que agregar un evento nuevo obligue a decidir si se pinta — un `default`
            // silencioso haría que el siguiente hecho nazca invisible.
            // LA MESA CAMBIÓ, y eso se pinta: una superficie que muestre lo que el agente puede hacer
            // tiene que enterarse. El tablero lo ignora —no es una tarjeta— y una terminal puede
            // decirlo; cada superficie filtra, como con `activity`.
            // The ordering obligation is painted because it explains why a call did not proceed:
            // without it a surface shows a refusal with no visible cause, and whoever looks reads it
            // as a failure.
            SessionEvent::PrerequisiteSet => [
                ...$base,
                'kind' => 'prerequisite-set',
                'activity' => [
                    'state' => 'prerequisite-set',
                    'detail' => implode(', ', array_filter(
                        \is_array($p['tools'] ?? null) ? $p['tools'] : [],
                        static fn ($t): bool => \is_string($t),
                    )) ?: null,
                ],
            ],
            SessionEvent::OptionRemoved => [
                ...$base,
                'kind' => 'option-removed',
                'activity' => [
                    'state' => 'option-removed',
                    'detail' => \is_string($p['option'] ?? null) ? $p['option'] : null,
                    // EL CÓDIGO va aparte del mensaje: una superficie agrupa por código y muestra el
                    // mensaje, y ninguna tiene que leer prosa para saber de qué motivo se trata.
                    'why' => \is_string($p['reason']['code'] ?? null) ? $p['reason']['code'] : null,
                    'detailWhy' => \is_string($p['reason']['message'] ?? null) ? $p['reason']['message'] : null,
                ],
            ],
            // ── THE EXECUTED OPERATION IS NOT A PER-EVENT CARD (greenhouse evidence/0284 → 0285) ────
            //
            // evidence/0284 first made this a per-event card, one per operation. evidence/0285 measured
            // that the wrong granularity: a real workday had 18 tool calls but 2 governed operations,
            // and one turn's eight tool calls were one unit of work. The board's unit is the assistant
            // TURN, folded by {@see boardCards()} from the whole stream — so a single operation is no
            // longer a card on its own. Here it stays audit material, read from the stream where an
            // audit looks; the board's fold reads it too, as one fact of a turn.
            SessionEvent::OperationExecuted,
            // ── LA ENTRADA NO SE PROYECTA AQUI, Y ES UNA DECISION ───────────────────────────
            //
            // Esta es la vista del HUMANO en vivo: lo que esta pasando y lo que espera respuesta. La
            // entrada del agente —cuantas herramientas le ofrecieron, que contexto recibio— es un
            // renglon por turno que ese lector no pidio, y una superficie que en cada linea aclara
            // algo que nadie necesita se vuelve ilegible.
            //
            // No es que se pierda: vive en el stream y la superficie de desarrollador la lee de ahi.
            // Que el humano pueda verla NO exige que la vea siempre.
            // EL `system` NO SE PINTA. Es la ENTRADA del agente, igual que `ModelCalled`, y quien mira
            // la pantalla está viendo lo que pasó, no lo que se mandó. Pintarlo metería en la corrida
            // un renglón de kilobytes que nadie pidió, cada vez que alguien toca la configuración.
            SessionEvent::SystemSet,
            // THE OWNERSHIP ASSERTION IS NOT PAINTED, and that is a decision the exhaustive match
            // forces. Painting it would put a signature block on the live human's screen as if it
            // meant something there — and on this surface, unverified, it means nothing yet: the
            // grade only exists after re-verification at consumption (greenhouse decisions/0056,
            // evidence/0254). Whoever needs it reads it from the fold, where its docblock says
            // what it is not.
            SessionEvent::OwnershipAsserted,
            // A composition receipt is for the AUDIT surface (greenhouse decisions/0059,
            // evidence/0240), not for the live agent transcript — this projector renders the
            // conversation, and the receipt is read from the stream by render-audit instead.
            SessionEvent::CeilingComposed,
            // Trial facts (greenhouse decisions/0069) are audit material too: what ran in a copy, what
            // was promoted, what was discarded — read from the stream, not painted in the transcript.
            SessionEvent::TrialRunRecorded,
            SessionEvent::TrialPromoted,
            SessionEvent::TrialDiscarded,
            // A paused/resumed sequence (H-PERSIST-1, greenhouse decisions/0076) is not painted by
            // THIS projector either — same decision as QuestionAsked's sibling facts above: this is
            // the live human transcript, and a sequence's own surface reads its cursor from the
            // stream directly, not from a per-event translation nobody asked this one to produce.
            SessionEvent::SequencePaused,
            SessionEvent::SequenceResumed,
            SessionEvent::ModelCalled,
            SessionEvent::ModelReturned,
            SessionEvent::ModelReasoned,
            SessionEvent::Started,
            SessionEvent::Compacted,
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

    /**
     * The board as a FOLD, not a per-event projection: one card per assistant turn, aggregating the
     * work that turn did — the operations and tool calls the runtime already recorded between it and
     * the next turn — with `mutated` true when a governed operation ran. The work unit is the turn
     * (greenhouse evidence/0285), read from stream ORDER, with zero agent narration: eight tool calls
     * in one turn are one card, not eight. Explicit `TodoChanged` cards keep their place beside the
     * derived ones — the todo lost the monopoly on the board, not the capability.
     *
     * This is a divergent translation of the same stream {@see project()} renders per-event for the
     * transcript. That is deliberate: the board and the transcript are two views of one stream, and
     * two translations of one stream diverge, so the board folds where the transcript maps.
     *
     * @param list<\Milpa\EventStore\Event> $events
     *
     * @return list<array{session: string, kind: string, at: int, card: array<string, mixed>}>
     */
    public function boardCards(array $events): array
    {
        $sesion = '';
        $turnSeq = null;
        $ultimoTurno = null;
        $turnCards = [];
        $todoCards = [];
        foreach ($events as $event) {
            $tipo = SessionEvent::tryFrom($event->type);
            if ($tipo === null) {
                continue;
            }
            if ($sesion === '') {
                $sesion = str_starts_with($event->streamId, SessionStore::PREFIX)
                    ? substr($event->streamId, \strlen(SessionStore::PREFIX))
                    : $event->streamId;
            }
            $p = $event->payload;

            if ($tipo === SessionEvent::Turn) {
                // ANY turn opens a work cycle, not only an assistant one. A real agent loop records a
                // USER turn, then its work (tools/operations), then the ASSISTANT response — the work
                // lives BETWEEN a user turn and the assistant turn that closes it. Keying only on
                // assistant turns lost every real run's cards (greenhouse evidence/0287, caught by the
                // pin's method: a live model, not a hand-seeded stream where the assistant turn is
                // written first). The cycle a card belongs to is the last turn opened before its work.
                $turnSeq = $event->seq;
                $ultimoTurno = $event->seq;

                continue;
            }

            if ($tipo === SessionEvent::ToolCalled || $tipo === SessionEvent::OperationExecuted) {
                if ($turnSeq === null) {
                    continue; // work before any turn belongs to no cycle
                }
                $esOperacion = $tipo === SessionEvent::OperationExecuted;
                $nombre = $esOperacion
                    ? (\is_string($p['operation'] ?? null) ? $p['operation'] : '?')
                    : (\is_string($p['tool'] ?? null) ? $p['tool'] : '?');
                $muta = $esOperacion || ($p['mutating'] ?? false) === true;
                $clave = 'turn:' . $turnSeq;
                if (! isset($turnCards[$clave])) {
                    $turnCards[$clave] = [
                        'session' => $sesion,
                        'kind' => 'card',
                        'at' => $turnSeq,
                        'card' => ['id' => $clave, 'operations' => [], 'mutated' => false, 'text' => '', 'to' => 'done', 'from' => null, 'origin' => 'turn'],
                    ];
                }
                $turnCards[$clave]['card']['operations'][] = $nombre;
                $turnCards[$clave]['card']['mutated'] = $turnCards[$clave]['card']['mutated'] || $muta;
                $turnCards[$clave]['card']['text'] = implode(' · ', $turnCards[$clave]['card']['operations']);

                continue;
            }

            if ($tipo === SessionEvent::TodoChanged) {
                $id = \is_string($p['id'] ?? null) ? $p['id'] : '';
                $todoCards[$id] = [
                    'session' => $sesion,
                    'kind' => 'card',
                    'at' => $event->seq,
                    'card' => [
                        'id' => $id,
                        'text' => \is_string($p['text'] ?? null) ? $p['text'] : '',
                        'to' => \is_string($p['status'] ?? null) ? $p['status'] : '',
                        'from' => \is_string($p['from'] ?? null) ? $p['from'] : null,
                        'origin' => 'todo',
                    ],
                ];
            }
        }

        // A cycle is `doing` while it is the last turn opened (the agent is still in it); once a later
        // turn exists — the assistant's response, or the next user message — the work it held is `done`.
        // Derived from stream order alone, never asked of the agent.
        foreach ($turnCards as &$carta) {
            $carta['card']['to'] = ($carta['at'] === $ultimoTurno) ? 'doing' : 'done';
        }
        unset($carta);

        // The todo card surfaces the DERIVED work state of the artifact it names, so «done but
        // unverified» and «attempted but not materialized» are visible states, not prose. Derived
        // through SessionFacts — the one projection every artifact query reads — never by a second
        // matching authority here; `null` is the honest answer for a card naming nothing touched.
        if ($todoCards !== []) {
            $facts = SessionFacts::fromEvents($sesion, $events);
            foreach ($todoCards as &$tarjeta) {
                $estado = $facts->workStateForTodo((string) $tarjeta['card']['id']);
                $tarjeta['card']['workState'] = $estado['state'] ?? null;
                $tarjeta['card']['workStateArtifact'] = $estado['artifact'] ?? null;
            }
            unset($tarjeta);
        }

        return array_values(array_merge($turnCards, $todoCards));
    }
}
