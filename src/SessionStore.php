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
     * The raw events of one session, in order — for a fold a reduced {@see Session} cannot express,
     * like the board's per-turn cards (greenhouse evidence/0286). Where {@see load()} reduces the
     * stream to state, this returns the stream itself, untranslated, for a projector to fold its way.
     *
     * @return list<Event>
     */
    public function stream(string $id): array
    {
        return $this->events->replay(self::PREFIX . $id);
    }

    /**
     * Cada sesión que este almacén conoce, reconstruida en UNA sola lectura del log.
     *
     * {@see self::load()} reproduce el stream de UNA sesión; llamarlo en un bucle sobre
     * {@see self::ids()} lee el log entero una vez por sesión, lo que se vuelve cuadrático cuando hay
     * muchas —el defecto que hacía colgarse a `/sessions`—. `loadAll()` lee el log una sola vez
     * ({@see EventStoreInterface::replayAll()}) y reduce cada stream, así listar N sesiones cuesta una
     * lectura y no N.
     *
     * @return array<string, Session> id de sesión → sesión, en el mismo orden que {@see self::ids()}
     */
    public function loadAll(): array
    {
        $sesiones = [];
        $reducer = new SessionReducer();
        foreach ($this->events->replayAll() as $stream => $eventos) {
            if (!str_starts_with($stream, self::PREFIX) || $eventos === []) {
                continue;
            }

            $id = substr($stream, \strlen(self::PREFIX));
            $sesiones[$id] = $reducer->reduce($id, $eventos);
        }

        return $sesiones;
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
     * @param int|null             $resultChars Cuánto medía el resultado ANTES de cualquier
     *                                          recorte. `null` es «nadie lo dijo».
     */
    public function recordToolCall(
        string $id,
        string $tool,
        array $arguments,
        string $result,
        bool $ok = true,
        bool $mutating = false,
        ?int $resultChars = null,
        ?bool $awaitingConfirmation = null,
    ): void {
        $this->append($id, SessionEvent::ToolCalled, [
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => $result,
            // CUÁNTO MEDÍA DE VERDAD, dicho por quien lo cortó.
            //
            // `result` puede venir recortado para no cargarle a la ventana lo que no aporta al
            // retomar, y quien lo recorta es el único punto del sistema donde la cadena entera
            // existe. Sin este número, una vista recibe 600 caracteres y no tiene forma de saber si
            // son 600 de 600 o 600 de 2026 — calcularlo sería inventarlo.
            //
            // `null` es «nadie lo dijo», JAMÁS «no se cortó»: los eventos escritos antes de que esto
            // existiera no pueden distinguir, y afirmar que están completos sería exactamente la
            // mentira que este campo viene a impedir.
            'resultChars' => $resultChars,
            // SI ESTA LLAMADA SÓLO PIDIÓ, en vez de haber hecho.
            //
            // Una petición de confirmación y una escritura consumada vuelven las dos con éxito, y
            // sobre una operación que muta las dos se graban `ok` y `mutating` — que es verdad en
            // ambas: la operación muta y la llamada no falló. Sin este campo, quien cuente
            // mutaciones desde el stream cuenta DOS donde hubo UNA, y esa es justamente la cuenta
            // que gobierna el consentimiento (greenhouse evidence/0200).
            //
            // No se corrige ningún campo: faltaba el que nadie escribía.
            //
            // `null` es «nadie lo dijo». Los eventos anteriores a esto no pueden distinguir, y
            // darlos por consumados sería cometer contra la historia la misma sobrecuenta que este
            // campo vino a quitar del presente.
            'awaitingConfirmation' => $awaitingConfirmation,
            'ok' => $ok,
            // SI ESTA LLAMADA CAMBIÓ ALGO. Lo sabe quien tiene la operación —la compuerta— y hasta
            // ahora no lo escribía, así que el stream no distinguía mirar de mover. Sin esa
            // distinción no se puede verificar nada sobre las mutaciones: son invisibles como tales.
            'mutating' => $mutating,
        ]);
    }

    /**
     * Apenda lo que se le pidió al modelo — la entrada del agente, leída del cuerpo que viajó.
     *
     * Va junto a lo que el agente hizo con ella y en el mismo orden, porque la pregunta que esto
     * existe para contestar —«¿por qué llamó eso?»— sólo se contesta viendo las dos cosas en su
     * secuencia: qué le ofrecieron, y qué escogió.
     */
    public function recordModelCall(string $id, ModelCallIntake $intake): void
    {
        // EL `system` SE APENDA CUANDO CAMBIA, y la llamada lo referencia (greenhouse decisions/0039).
        //
        // Va ANTES de la llamada que lo usa, no después: quien reproduce hacia adelante tiene que
        // tenerlo cuando lo necesita. Apendarlo después dejaría la primera llamada de cada prompt
        // irresoluble para un lector honesto, que es justo la propiedad que esto viene a dar.
        $ref = $intake->systemRef();
        if ($ref !== null && $this->systemVigente($id) !== $ref) {
            $this->append($id, SessionEvent::SystemSet, ['ref' => $ref, 'system' => $intake->system]);
        }

        $this->append($id, SessionEvent::ModelCalled, $intake->toPayload());
    }

    /**
     * Appends what a model call COST, once its response was decoded — the half {@see recordModelCall}
     * cannot supply because it fires before any reply exists (greenhouse H-USAGE-1).
     *
     * `$return` is the shape {@see \Milpa\AiGateway\ReturnObserver::observeReturn()} hands over:
     * `model` and a `usage` already normalized across providers to `prompt_tokens`,
     * `completion_tokens`, `total_tokens`, and `cached_tokens` when one was declared. It is appended
     * as given — the store records what the gateway measured and adds no arithmetic of its own.
     *
     * @param array<string, mixed> $return
     */
    public function recordModelReturn(string $id, array $return): void
    {
        $this->append($id, SessionEvent::ModelReturned, $return);
    }

    /**
     * Appends what a model call REASONED, once its response was decoded — the deliberation neither
     * {@see recordModelCall} (the input) nor {@see recordModelReturn} (the cost) carries.
     *
     * `$reasoning` is the provider's `reasoning_content` for the call, verbatim, as the gateway's
     * {@see \Milpa\AiGateway\ReasoningObserver::observeReasoning()} hands it over. It is appended as
     * given — the store records what the model spoke and adds nothing. Fires only when the provider
     * actually reasoned: a silent model records no event here, never an empty one.
     */
    public function recordModelReasoning(string $id, string $reasoning): void
    {
        $this->append($id, SessionEvent::ModelReasoned, ['reasoning' => $reasoning]);
    }

    /**
     * La referencia del `system` que esta sesión ya declaró, o `null` si todavía no declara ninguno.
     *
     * Se pregunta al stream DE ESTA SESIÓN y a ningún otro. Compartir la respuesta entre sesiones
     * ahorraría un evento y rompería lo único que este diseño promete: que una sesión leída aislada se
     * reconstruye. La segunda sesión resolvería contra un hecho que su propio canal no contiene.
     */
    private function systemVigente(string $id): ?string
    {
        $ultima = null;
        foreach ($this->events->replay(self::PREFIX . $id) as $evento) {
            if ($evento->type === SessionEvent::SystemSet->value) {
                $ultima = \is_string($evento->payload['ref'] ?? null) ? $evento->payload['ref'] : $ultima;
            }
        }

        return $ultima;
    }

    /**
     * Appends the fact that an operation was MATERIALISED — the one thing no other event declares.
     *
     * Both identities are written as they were OBSERVED at this moment, and neither may be rebuilt
     * later from whoever happens to read the stream. That is the whole point of the event: a durable
     * fact whose author changes with its reader is two incompatible histories.
     *
     * @param string                                                           $operation       the canonical operation identity, never a surface spelling
     * @param ?Principal                                                       $executedBy      observed NOW; `null` is an honest gap and stays a gap
     * @param string                                                           $executorSource  where that observation came from, so a reader can weigh it
     * @param ?array{principal: ?string, provenance: string, session: ?string} $authorizedBy
     *                                                                                          the authority that covered this call; `null` says plainly
     *                                                                                          that none did, which is a fact and not a silence
     * @param string                                                           $argumentsDigest a reference to the arguments, not a second copy of them
     */
    public function recordExecution(
        string $id,
        string $operation,
        ?Principal $executedBy,
        string $executorSource,
        ?array $authorizedBy,
        string $argumentsDigest,
    ): void {
        $this->append($id, SessionEvent::OperationExecuted, [
            'operation' => $operation,
            // AN OBSERVATION THAT SAYS IT IS ONE. `principal` may be null; `source` and `verified`
            // never are, because a name without its provenance is the false evidence this program
            // has spent a month taking apart. Executing does not verify anybody.
            'executed_by' => [
                'principal' => $executedBy?->id,
                'source' => $executorSource,
                'verified' => $executedBy !== null && $executedBy->verified,
            ],
            'authorized_by' => $authorizedBy,
            'arguments_digest' => $argumentsDigest,
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
     * Summarises what happened up to `$throughSeq`, so the window fits without losing the thread.
     *
     * It deletes nothing: the stream stays whole and the summary is one more event. Compacting is a
     * decision about what the model is SHOWN, never about what is kept.
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
        $session = $this->load($id);
        $previo = null;
        foreach ($session instanceof Session ? $session->todos : [] as $t) {
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
            'mutationsAt' => $session instanceof Session ? $session->mutations : 0,
            // CÓMO NACIÓ, derivado y no preguntado. Sólo al nacer: un movimiento posterior no tiene
            // origen, tiene un `from` — y ponerle uno sería reescribir cómo apareció cada vez que se
            // mueve ({@see TodoOrigin}).
            'origin' => $previo === null
                ? TodoOrigin::derive($todo->status, $session instanceof Session ? $session->toolCalls : 0)->value
                : null,
            // WHICH GENERATION OF THE PLAN THIS CARD BELONGS TO.
            //
            // Without the stamp, re-planning stacks generations and every one of them reads as
            // today's state: measured on a real session, TWENTY pending cards for SIX tasks, six
            // copies of the same one. And re-planning is precisely what completes long work
            // (Q-P17-L: 6/9 against 0/9), so benefit and noise came out of the same act.
            //
            // It STAMPS and retires nothing: the six copies happened and they stay. What stops
            // happening is all six being presented as current — and that is fixed where the board
            // spec already says everything lives: «the board holds no state; what you see is the
            // fold of the stream».
            'planVersion' => $session instanceof Session ? $session->planVersion : 0,
            // AND THE BIRTH ONE, fixed once and never touched again — the same treatment `origin`
            // gets. Without it there is no way to ask whether two cards are one task restated.
            'bornInPlan' => $previo === null
                ? ($session instanceof Session ? $session->planVersion : 0)
                : $previo->bornInPlan,
            // A qué versión de ESTA tarjeta reemplaza. `null` al nacer: no reemplaza a nadie.
            'supersedes' => $cambio && $previo !== null ? $previo->version : null,
            // DE DÓNDE VIENE. Es el dato que el tablero necesita para pintar un movimiento y el que
            // antes había que deducir. `null` al nacer — una tarjeta que aparece no viene de ningún
            // lado, y decir que viene de `pending` sería inventar una columna que nunca ocupó.
            'from' => $cambio && $previo !== null ? $previo->status->value : null,
            // WHETHER THIS DONE IS BACKED BY VERIFIABLE EVIDENCE, DERIVED and not declared.
            //
            // Only meaningful for a `done`; `null` otherwise. It is READ from the ledger at write
            // time — «does this session already hold verifiable evidence tied to this todo?» — never
            // taken as a caller's word, because a derived observation beats a declared assertion when
            // both are available (the same rule {@see TodoOrigin} follows). The gate that closes a
            // todo WITH evidence ({@see completeTodo()}) records that evidence first, so this reads
            // true; a raw `done` with nothing behind it reads false and is NAMED, not censored — a
            // surface flags it, exactly like {@see TodoOrigin::Unsupported}.
            'evidenced' => $todo->status === TodoStatus::Done
                ? ($session instanceof Session && $session->evidenceFor($todo->id) !== [])
                : null,
        ]);
    }

    /**
     * Records one piece of evidence into the session's ledger (D2, backlog).
     *
     * It refuses evidence that points at nothing: a piece with an empty reference cannot be
     * re-checked, and recording it would let the ledger carry a claim wearing the word «evidence».
     * That refusal is a caller error the author must see, not a silent no-op — the same way an
     * ownership assertion without its signature is refused rather than stored as prose.
     */
    public function recordEvidence(string $id, Evidence $evidence): void
    {
        if (!$evidence->isVerifiable()) {
            throw new \InvalidArgumentException(
                'evidence must point at something a reader can re-check: a non-empty reference — a '
                . 'path, an operation, a test. Evidence that cannot be re-checked is a claim, and the '
                . 'ledger records evidence, not claims (D2).',
            );
        }

        $this->append($id, SessionEvent::EvidenceRecorded, $evidence->toArray());
    }

    /**
     * Closes a todo WITH its evidence — the sanctioned, fail-closed path to `done` (D2, backlog).
     *
     * ── WHY THIS EXISTS BESIDE {@see setTodo()} ─────────────────────────────────────────────────
     *
     * A todo used to reach `done` on the agent's word, and a real audit caught the agent claiming
     * progress it had not grounded. This is the path that cannot do that: it REQUIRES a verifiable
     * piece of evidence, ties it to the todo, records it, and only then moves the card. It fails
     * closed — an unverifiable piece, or a todo that does not exist, is refused and NOTHING moves.
     *
     * The raw {@see setTodo()} stays for backward compatibility and for the honest recording of an
     * unevidenced done (which it stamps `evidenced: false`, named not censored). This method is the
     * one an operation reaches for when it means «done, and here is why».
     */
    public function completeTodo(string $id, string $todoId, Evidence $evidence): void
    {
        if (!$evidence->isVerifiable()) {
            throw new \InvalidArgumentException(
                'a todo cannot be closed on evidence that points at nothing: the evidence needs a '
                . 'non-empty reference a reader can re-check (D2).',
            );
        }

        $session = $this->load($id);
        $tarjeta = null;
        foreach ($session instanceof Session ? $session->todos : [] as $t) {
            if ($t->id === $todoId) {
                $tarjeta = $t;

                break;
            }
        }

        if ($tarjeta === null) {
            throw new \InvalidArgumentException(sprintf(
                'cannot close todo "%s": it is not a todo of this session. Evidence closes a todo '
                . 'that exists, never one conjured at completion time (D2).',
                $todoId,
            ));
        }

        // Record the evidence FIRST, tied to this todo: the move to `done` reloads the session and
        // reads the ledger, so with the evidence already in it the transition stamps `evidenced: true`
        // on its own — one source, the ledger, and no second claim to keep in step.
        $this->recordEvidence($id, $evidence->forTodo($todoId));
        $this->setTodo($id, $tarjeta->withStatus(TodoStatus::Done));
    }

    /**
     * The verifiable evidence tied to a todo — WHAT closed it (D2, backlog).
     *
     * The query the audit asks, answered from the session's fold. An empty list on a `done` todo is
     * the flag that it reached `done` unevidenced.
     *
     * @return list<Evidence>
     */
    public function evidenceFor(string $id, string $todoId): array
    {
        $session = $this->load($id);

        return $session instanceof Session ? $session->evidenceFor($todoId) : [];
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
     * Records that a GovernedSequence stopped mid-run, waiting for consent — the session is now
     * paused on it, mirroring {@see ask()} exactly (H-PERSIST-1, greenhouse decisions/0076).
     *
     * The append is part of the fail-closed frontier: it runs AFTER the sequence's prefix executed
     * and BEFORE the caller's process leaves, so a process that dies right here leaves a session
     * paused on a fact — never an in-memory cursor nobody wrote down.
     */
    public function recordSequencePaused(string $id, PausedSequence $paused): void
    {
        $this->append($id, SessionEvent::SequencePaused, $paused->toArray());
    }

    /**
     * Records that a paused sequence's consent arrived and the session can continue, mirroring
     * {@see answer()}.
     *
     * It does not re-run or re-verify anything: it only clears the pause this store's own
     * {@see recordSequencePaused()} set, the same way answering a question clears it without
     * re-deciding it.
     */
    public function recordSequenceResumed(string $id, string $sequenceId): void
    {
        $this->append($id, SessionEvent::SequenceResumed, ['sequenceId' => $sequenceId]);
    }

    /**
     * Appends a SIGNED claim of ownership over this session (greenhouse decisions/0056).
     *
     * What is appended is the ASSERTION — payload, signature, fingerprint, uid — and never a trust
     * grade: the grade is produced by re-verifying the signature at consumption, in the app
     * runtime, against the app's own registry of recognised fingerprints (greenhouse
     * evidence/0254). This store is the scribe, not the verifier — a verdict written here would be
     * the stored coin that doctrine exists to forbid.
     *
     * What it DOES refuse is a claim that is not an assertion: an assertion without its signature
     * is not an assertion, it is prose with a confident name — and appending it would hand the
     * consumer a receipt that can never re-verify, indistinguishable from a forgery. The shape is
     * checked here, at the door, because the stream keeps whatever it is given forever.
     *
     * @param array<string, mixed> $assertion the signed claim: non-empty string `payload`,
     *                                        `signature` and `fingerprint`, plus `uid` — the
     *                                        signer's self-declared name, string or `null`,
     *                                        present so nobody mistakes its absence for a gap
     *
     * @throws \InvalidArgumentException when the claim does not carry that shape
     */
    public function assertOwnership(string $id, array $assertion): void
    {
        foreach (['payload', 'signature', 'fingerprint'] as $field) {
            if (!\is_string($assertion[$field] ?? null) || $assertion[$field] === '') {
                throw new \InvalidArgumentException(sprintf(
                    'an ownership assertion without its "%s" is not an assertion: it must carry '
                    . 'non-empty string "payload", "signature" and "fingerprint" — nothing less '
                    . 'can be re-verified at consumption (greenhouse decisions/0056)',
                    $field,
                ));
            }
        }

        if (!\array_key_exists('uid', $assertion) || ($assertion['uid'] !== null && !\is_string($assertion['uid']))) {
            throw new \InvalidArgumentException(
                'an ownership assertion must declare its "uid" — the signer\'s self-declared name '
                . 'as a string, or null to say plainly that none was declared (greenhouse decisions/0056)',
            );
        }

        $this->append($id, SessionEvent::OwnershipAsserted, ['assertion' => $assertion]);
    }

    /**
     * Record that a composition LOWERED this call's ceiling — the receipt of greenhouse
     * decisions/0059, so an Audit view can paint why authority was not required.
     *
     * It refuses an empty or malformed receipt: a composition that reduced nothing is not a fact
     * worth a line, and a receipt with no operation or a non-list of reductions is a caller error the
     * author must see, not a silent no-op that would fill the channel with «nothing happened».
     *
     * @param array{operation?: mixed, reductions?: mixed} $composition operation + the AxisReductions ProfileComposition rendered
     */
    public function recordCeilingComposition(string $id, array $composition): void
    {
        $operation = $composition['operation'] ?? null;
        $reductions = $composition['reductions'] ?? null;
        if (! \is_string($operation) || $operation === '' || ! \is_array($reductions) || ! array_is_list($reductions)) {
            throw new \InvalidArgumentException(
                'a ceiling composition must name its operation and carry a list of reductions; '
                . 'a receipt without them is a caller error, not a fact',
            );
        }
        if ($reductions === []) {
            throw new \InvalidArgumentException(
                'a composition that reduced nothing is not a fact worth recording — record only when a descent lowered the ceiling',
            );
        }

        $this->append($id, SessionEvent::CeilingComposed, ['composition' => $composition]);
    }

    /**
     * Graba que una operación corrió en un TRIAL WORKSPACE, con su reporte (greenhouse decisions/0069).
     *
     * El hecho nombra el workspace, la llamada exacta (digest), las cotas que el runner IMPUSO, el
     * código de salida y el REPORTE — el diff que el HOST calculó sobre la copia. Sin workspace u
     * operación no es un hecho: es un error de quien llama, y se rechaza en vez de apendarse a medias.
     *
     * @param array<string, mixed> $run workspace, operation, arguments_digest, bounds, exit, report, output_digest
     */
    public function recordTrialRun(string $id, array $run): void
    {
        if (!\is_string($run['workspace'] ?? null) || $run['workspace'] === '' || !\is_string($run['operation'] ?? null) || $run['operation'] === '') {
            throw new \InvalidArgumentException('a trial run names its workspace and its operation; without them it is not a fact');
        }
        if (!\is_array($run['report'] ?? null)) {
            throw new \InvalidArgumentException('a trial run carries its report — the diff the host computed — even when empty');
        }

        $this->append($id, SessionEvent::TrialRunRecorded, $run);
    }

    /**
     * Graba que efectos observados en un trial workspace ENTRARON al host — por `sandbox:promote`.
     *
     * @param array<string, mixed> $promotion workspace, paths, diff_digest, by
     */
    public function recordTrialPromotion(string $id, array $promotion): void
    {
        if (!\is_string($promotion['workspace'] ?? null) || $promotion['workspace'] === '' || !\is_array($promotion['paths'] ?? null)) {
            throw new \InvalidArgumentException('a promotion names its workspace and the paths it introduced');
        }

        $this->append($id, SessionEvent::TrialPromoted, $promotion);
    }

    /**
     * Graba que un trial workspace se descartó: sus escrituras murieron con él.
     *
     * @param array<string, mixed> $discard workspace
     */
    public function recordTrialDiscard(string $id, array $discard): void
    {
        if (!\is_string($discard['workspace'] ?? null) || $discard['workspace'] === '') {
            throw new \InvalidArgumentException('a discard names its workspace');
        }

        $this->append($id, SessionEvent::TrialDiscarded, $discard);
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
     * Declare what must run before anything else, for the rest of this session.
     *
     * An empty list lifts it: the same authority that set the obligation has to be able to unset it,
     * and without that the only way out would be opening another session.
     *
     * @param list<string> $tools
     */
    public function requireFirst(string $id, array $tools): void
    {
        $limpias = [];
        foreach ($tools as $t) {
            if (trim($t) !== '') {
                $limpias[] = trim($t);
            }
        }

        $this->append($id, SessionEvent::PrerequisiteSet, ['tools' => $limpias]);
    }

    /**
     * Consiente una operación para el resto de esta sesión (P16.5).
     *
     * Por operación y por sesión, nunca global: «sí a `make`, en esta sesión» es una frase que alguien
     * puede evaluar. «Sí a lo que el agente decida» no lo es.
     */
    /**
     * Otorga esa operación — con un SOBRE, si el humano la apretó (greenhouse decisions/0067).
     *
     * Sin sobre es el sí pelón de siempre y el evento queda byte a byte como hoy. Con sobre, el
     * permiso vale sólo para composiciones no-más-anchas que él; `$provenance` lleva lo que un auditor
     * necesita para recomputar el meet: `base`, `requested`, `question`, `arguments_digest`, `by`.
     *
     * @param array<string, mixed>|null $envelope   `EffectProfile::toArray()` del sobre, o null
     * @param array<string, mixed>      $provenance base/requested/question/arguments_digest/by
     */
    public function grant(string $id, string $operation, ?array $envelope = null, array $provenance = []): void
    {
        $payload = ['operation' => $operation];
        if ($envelope !== null) {
            $payload['envelope'] = $envelope;
            foreach (['base', 'requested', 'question', 'arguments_digest', 'by'] as $k) {
                if (\array_key_exists($k, $provenance)) {
                    $payload[$k] = $provenance[$k];
                }
            }
        }

        $this->append($id, SessionEvent::PermissionGranted, $payload);
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
     * Lo que se puede observar de esta sesión — el hecho común, antes de proyectarlo.
     *
     * Vive aquí y no en quien pinta, para que ninguna superficie tenga que alcanzar el stream por su
     * cuenta: repartir los eventos crudos para armar una vista es exactamente como aparece un segundo
     * lector de los mismos hechos, y dos lectores divergen.
     */
    public function observation(string $id): SessionObservation
    {
        return SessionObservation::of($this->events, $id);
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
