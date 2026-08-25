<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PausedSequence;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoOrigin;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Una sesión que sobrevive al proceso (P16.1).
 *
 * Antes de esto, `coa agent "…"` era una pregunta con una respuesta: arrancaba sin memoria, corría
 * hasta doce pasos y se moría. El framework le pasaba `[]` de historial en cada llamada, así que
 * preguntarle dos cosas seguidas eran dos desconocidos. Nada de lo demás que una jornada larga
 * necesita —plan, compactación, permisos, preguntas al humano— se sostiene sin que la sesión sea
 * primero una cosa que existe.
 */
final class SessionStoreTest extends TestCase
{
    private function store(): SessionStore
    {
        return new SessionStore(new InMemoryEventStore());
    }

    /** Lo mínimo: se abre, se le habla, y al volver a cargarla está todo. */
    public function testStreamReturnsTheRawEventsInOrder(): void
    {
        // greenhouse evidence/0286: the board's per-turn fold needs the untranslated events, not the
        // reduced Session. stream() hands them over, in order, so a projector can fold its own way.
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->recordTurn('s1', 'assistant', 'voy');
        $almacen->recordToolCall('s1', 'plugins_list', [], 'ok');

        $tipos = array_map(static fn ($e): string => $e->type, $almacen->stream('s1'));

        self::assertSame(['session.started', 'session.turn', 'session.tool_called'], $tipos);
        self::assertSame([], $almacen->stream('nunca-existio'), 'an unknown session is an empty stream');
    }

    public function testASessionSurvivesBeingReloaded(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'migrar el plugin a sqlite');
        $almacen->recordTurn('s1', 'user', '¿por dónde empiezo?');
        $almacen->recordTurn('s1', 'assistant', 'por el repositorio');

        $sesion = $almacen->load('s1');

        self::assertNotNull($sesion);
        self::assertSame('migrar el plugin a sqlite', $sesion->goal);
        self::assertCount(2, $sesion->turns);
        self::assertSame('¿por dónde empiezo?', $sesion->turns[0]['content']);
        self::assertTrue($sesion->isRunnable());
    }

    /** Una sesión que nunca se abrió es `null`, no una sesión vacía. */
    public function testAnUnknownSessionIsNullAndNotAnEmptyOne(): void
    {
        self::assertNull($this->store()->load('no-existe'));
    }

    /**
     * Las llamadas a herramienta son parte de la conversación.
     *
     * Sin ellas, retomar una sesión sería retomarla sin saber qué ya se intentó, y el agente
     * repetiría el trabajo que su yo anterior ya hizo — que es la falla más cara de una jornada
     * larga, porque se paga en pasos y en archivos escritos dos veces.
     */
    public function testToolCallsAreParteOfTheConversation(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->recordToolCall('s1', 'make', ['what' => 'entity'], 'ok: creada');

        $sesion = $almacen->load('s1');

        self::assertNotNull($sesion);
        self::assertCount(1, $sesion->turns);
        self::assertSame('tool', $sesion->turns[0]['role']);
        self::assertStringContainsString('make', $sesion->turns[0]['content']);
        self::assertStringContainsString('ok: creada', $sesion->turns[0]['content']);
    }

    /**
     * COMPACTAR NO BORRA (P16.2).
     *
     * La ventana que se le manda al modelo se acorta; el stream conserva los cuarenta turnos. Es la
     * diferencia entre ahorrar contexto y destruir evidencia, y sólo se nota al día siguiente —
     * cuando alguien pregunta por qué el agente decidió lo que decidió y la respuesta estaba en el
     * turno 7.
     */
    public function testCompactingShortensTheWindowAndKeepsEverything(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'una jornada larga');
        for ($i = 1; $i <= 40; ++$i) {
            $almacen->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', "turno {$i}");
        }

        $antes = $almacen->load('s1');
        self::assertNotNull($antes);
        self::assertCount(40, $antes->turns);
        self::assertCount(40, $antes->window(), 'sin compactar, la ventana es todo');

        // Se resume hasta la secuencia del turno 32; los últimos 8 quedan íntegros.
        $corte = $antes->turns[31]['seq'];
        $almacen->compact('s1', 'Se migraron 3 entidades y quedó pendiente el controller.', $corte);

        $despues = $almacen->load('s1');
        self::assertNotNull($despues);
        self::assertCount(40, $despues->turns, 'el stream conserva TODO');

        $ventana = $despues->window();
        self::assertCount(9, $ventana, 'un resumen más los 8 turnos que no cubre');
        self::assertSame('system', $ventana[0]['role']);
        self::assertStringContainsString('Se migraron 3 entidades', $ventana[0]['content']);
        self::assertSame('turno 33', $ventana[1]['content']);
        self::assertSame('turno 40', $ventana[8]['content']);
    }

    /** El plan y los pendientes viven en el stream, no en el prompt (P16.3). */
    public function testThePlanAndItsTodosLiveInTheStream(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->setPlan('s1', '1. entidad  2. controller  3. rutas');
        $almacen->setTodo('s1', new Todo('t1', 'escribir la entidad'));
        $almacen->setTodo('s1', new Todo('t2', 'escribir el controller'));
        $almacen->setTodo('s1', new Todo('t1', 'escribir la entidad', TodoStatus::Done));

        $sesion = $almacen->load('s1');

        self::assertNotNull($sesion);
        self::assertStringContainsString('controller', (string) $sesion->plan);
        self::assertCount(2, $sesion->todos, 'mover un pendiente no crea otro');
        self::assertCount(1, $sesion->pendingTodos());
        self::assertSame('t2', $sesion->pendingTodos()[0]->id);
    }

    /**
     * Preguntar PAUSA la sesión, y contestar la reanuda (P16.4).
     *
     * Un agente que «pregunta» y sigue con su suposición no preguntó, narró — y la respuesta humana
     * llega cuando el trabajo que dependía de ella ya se hizo mal.
     */
    public function testAskingPausesTheSessionAndAnsweringResumesIt(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('q1', '¿sqlite o mysql?', ['sqlite', 'mysql'], 'cambia el dsn'));

        $enPausa = $almacen->load('s1');
        self::assertNotNull($enPausa);
        self::assertFalse($enPausa->isRunnable(), 'con una pregunta abierta no se sigue');
        self::assertNotNull($enPausa->question);
        self::assertSame(['sqlite', 'mysql'], $enPausa->question->options);

        $almacen->answer('s1', 'q1', 'sqlite');

        $sigue = $almacen->load('s1');
        self::assertNotNull($sigue);
        self::assertTrue($sigue->isRunnable());
        self::assertNull($sigue->question);
        self::assertSame('sqlite', $sigue->turns[0]['content'], 'la respuesta es contexto, no metadato');
    }

    /**
     * A paused GovernedSequence is a first-class session fact (H-PERSIST-1, greenhouse
     * decisions/0076): a process can die between steps and the pause still survives, mirroring
     * how {@see PendingQuestion} pauses a session for a human answer.
     */
    public function testPausingASequenceStopsTheSessionAndResumingClearsIt(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $pausada = new PausedSequence(
            'seq-1',
            'deadbeef',
            [
                ['operation' => 'a', 'arguments' => []],
                ['operation' => 'b', 'arguments' => ['x' => 1]],
            ],
            1,
        );
        $almacen->recordSequencePaused('s1', $pausada);

        $enPausa = $almacen->load('s1');
        self::assertNotNull($enPausa);
        self::assertFalse($enPausa->isRunnable(), 'con una secuencia pausada no se sigue');
        self::assertNotNull($enPausa->pausedSequence);
        self::assertSame('seq-1', $enPausa->pausedSequence->sequenceId);
        self::assertSame('deadbeef', $enPausa->pausedSequence->digest);
        self::assertSame(1, $enPausa->pausedSequence->nextIndex);
        self::assertSame(
            [
                ['operation' => 'a', 'arguments' => []],
                ['operation' => 'b', 'arguments' => ['x' => 1]],
            ],
            $enPausa->pausedSequence->steps,
        );

        $almacen->recordSequenceResumed('s1', 'seq-1');

        $sigue = $almacen->load('s1');
        self::assertNotNull($sigue);
        self::assertTrue($sigue->isRunnable());
        self::assertNull($sigue->pausedSequence);
    }

    /** Los permisos son por operación y por sesión, y revocar no borra que se otorgó (P16.5). */
    public function testPermissionsAreGrantedPerOperationAndRevokingAppendsOnTop(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->grant('s1', 'make');
        $almacen->grant('s1', 'make');
        $almacen->grant('s1', 'test');

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertSame(['make', 'test'], $sesion->permissions, 'otorgar dos veces no duplica');
        self::assertTrue($sesion->allows('make'));
        self::assertFalse($sesion->allows('plugins_disable'));

        $almacen->revoke('s1', 'make');

        $despues = $almacen->load('s1');
        self::assertNotNull($despues);
        self::assertFalse($despues->allows('make'));
        self::assertTrue($despues->allows('test'), 'revocar uno no toca los otros');
    }

    /** El modo se puede cambiar a media sesión, y el último gana (P16.6). */
    public function testTheModeCanChangeMidSession(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x', AutonomyMode::Ask);
        self::assertSame(AutonomyMode::Ask, $almacen->load('s1')?->mode);

        $almacen->setMode('s1', AutonomyMode::Auto);
        self::assertSame(AutonomyMode::Auto, $almacen->load('s1')?->mode);
    }

    /** Una sesión terminada deja de ser corrible, y dice por qué terminó. */
    public function testAnEndedSessionSaysWhy(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->end('s1', 'objetivo cumplido');

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertFalse($sesion->isRunnable());
        self::assertSame('objetivo cumplido', $sesion->endedBecause);
    }

    /** Las sesiones se pueden listar, y no se confunden con otros streams del mismo almacén. */
    public function testSessionsAreListedWithoutSwallowingOtherStreams(): void
    {
        $eventos = new InMemoryEventStore();
        $eventos->append(new Event('pedido:1', 'pedido.creado', [], $eventos->nextSeq()));

        $almacen = new SessionStore($eventos);
        $almacen->start('uno', 'a');
        $almacen->start('dos', 'b');

        $ids = $almacen->ids();
        sort($ids);
        self::assertSame(['dos', 'uno'], $ids, 'el stream del pedido no es una sesión');
    }

    /**
     * Un tipo de evento desconocido se ignora en vez de tumbar la reconstrucción.
     *
     * El stream puede traer eventos de una versión más nueva, o de otro productor que comparte
     * almacén. Reventar aquí haría que una sesión vieja dejara de poder leerse por algo que se agregó
     * después — y una sesión que no se puede leer es una sesión perdida.
     */
    public function testAnUnknownEventTypeDoesNotBreakTheReplay(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $eventos->append(new Event('agent-session:s1', 'session.de_una_version_futura', ['a' => 1], $eventos->nextSeq()));
        $almacen->recordTurn('s1', 'user', 'sigo aquí');

        $sesion = $almacen->load('s1');

        self::assertNotNull($sesion);
        self::assertCount(1, $sesion->turns);
        self::assertSame('sigo aquí', $sesion->turns[0]['content']);
    }

    /**
     * El PLAN y los pendientes llegan a la ventana (P16.3).
     *
     * Vivían en el stream y no en lo que se le manda al modelo, así que el agente los escribía y no
     * los volvía a ver: un plan que sólo sirve para auditar es media función. Van después del resumen
     * y antes de los turnos, porque son lo último que se sabe y no algo que pasó.
     */
    public function testThePlanAndTodosReachTheWindow(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->setPlan('s1', '1. entidad  2. controller');
        $almacen->setTodo('s1', new Todo('t1', 'la entidad', TodoStatus::Done));
        $almacen->setTodo('s1', new Todo('t2', 'el controller'));
        $almacen->recordTurn('s1', 'user', 'sigue');

        $ventana = $almacen->load('s1')?->window() ?? [];

        self::assertSame('system', $ventana[0]['role']);
        self::assertStringContainsString('1. entidad', $ventana[0]['content']);
        self::assertStringContainsString('[x] t1: la entidad', $ventana[0]['content']);
        self::assertStringContainsString('[ ] t2: el controller', $ventana[0]['content']);
        self::assertSame('sigue', $ventana[1]['content'], 'y los turnos van después');
    }

    /**
     * Lo que YA SE HIZO también va.
     *
     * No es por completitud: sin ello el agente vuelve a hacer lo que ya hizo, y esa es la falla más
     * cara de una jornada larga porque se paga en pasos y en archivos escritos dos veces.
     */
    public function testWhatIsAlreadyDoneIsShownToo(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'ya escrita', TodoStatus::Done));

        self::assertStringContainsString('[x] t1: ya escrita', (string) $almacen->load('s1')?->stateBriefing());
    }

    /**
     * Cerrada la ventana para contestar, la sesión MUERE y queda escrito por qué.
     *
     * Sin esto, una sesión con pregunta abierta no es retomable —`isRunnable()` es falso— así que la
     * que nadie contestó no queda pausada: queda muerta sin que nadie la declare muerta, y el tablero
     * la sigue mostrando viva. Lo encontró Q-P19-B comparando con las compuertas de `milpa/workflow`.
     */
    public function testAQuestionPastItsDeadlineEndsTheSessionAndSaysWhy(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion(
            'perm:plugins.disable',
            '¿Lo autorizas?',
            ['sí', 'no'],
            expiresAt: '2026-08-01T10:00:00+00:00',
        ));

        $vencio = $almacen->expireIfDue('s1', new \DateTimeImmutable('2026-08-01T10:00:01+00:00'));

        $sesion = $almacen->load('s1');
        self::assertTrue($vencio);
        self::assertNull($sesion?->question, 'la pregunta se cierra');
        self::assertStringContainsString('ventana para contestar', (string) $sesion?->endedBecause);
        self::assertStringContainsString('¿Lo autorizas?', (string) $sesion?->endedBecause);
        self::assertFalse($sesion?->isRunnable());
    }

    /**
     * Antes del plazo no pasa nada, y llamarlo mil veces no ensucia el stream.
     *
     * Importa porque la idea es llamarlo en cada vuelta: un método que apendara algo cada vez que se
     * pregunta «¿ya venció?» convertiría la comprobación en ruido, y el stream es la evidencia.
     */
    public function testBeforeTheDeadlineNothingHappensAndTheStreamStaysClean(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('q', '¿?', [], expiresAt: '2026-08-01T10:00:00+00:00'));

        $antes = $almacen->load('s1');
        for ($i = 0; $i < 3; ++$i) {
            self::assertFalse($almacen->expireIfDue('s1', new \DateTimeImmutable('2026-08-01T09:59:59+00:00')));
        }

        self::assertEquals($antes, $almacen->load('s1'), 'el stream quedó igual');
    }

    /**
     * Sin plazo, espera para siempre — y eso sigue siendo válido.
     *
     * `null` es «sin plazo» y es una decisión de quien pregunta. Una pregunta que aguarda a que
     * alguien vuelva del fin de semana no debería morirse sola; lo que no podía pasar era que NO SE
     * PUDIERA poner plazo.
     */
    public function testAQuestionWithNoDeadlineNeverExpires(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('q', '¿?'));

        self::assertFalse($almacen->expireIfDue('s1', new \DateTimeImmutable('2099-01-01T00:00:00+00:00')));
        self::assertNotNull($almacen->load('s1')?->question);
    }

    /**
     * Un plazo ilegible NO caduca la pregunta.
     *
     * De los dos errores posibles, tratar «no lo pude leer» como «ya venció» es el caro: mataría
     * sesiones vivas por un error de formato. El otro sólo deja esperando a una que debió morir.
     */
    public function testAnUnreadableDeadlineDoesNotKillTheSession(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('q', '¿?', [], expiresAt: 'mañana temprano'));

        self::assertFalse($almacen->expireIfDue('s1', new \DateTimeImmutable('2099-01-01T00:00:00+00:00')));
        self::assertNotNull($almacen->load('s1')?->question);
    }

    /**
     * La caducidad queda en el registro de decisiones con la respuesta VACÍA.
     *
     * Es la distinción entera: una decisión con respuesta es alguien que decidió; una con respuesta
     * vacía y su fecha es nadie. Meterla como turno del humano —que era la alternativa cómoda— sería
     * poner un silencio en boca de alguien.
     */
    public function testTheExpiryIsRecordedAsADecisionNobodyMade(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('q', '¿Lo autorizas?', [], expiresAt: '2026-08-01T10:00:00+00:00'));
        $almacen->expireIfDue('s1', new \DateTimeImmutable('2026-08-01T11:00:00+00:00'));

        $sesion = $almacen->load('s1');
        self::assertSame('¿Lo autorizas?', $sesion?->decisions[0]['question'] ?? null);
        self::assertSame('', $sesion?->decisions[0]['answer'] ?? null, 'nadie contestó');
        self::assertArrayHasKey('expired', (array) ($sesion?->decisions[0] ?? []));
        $delHumano = array_filter($sesion?->window() ?? [], static fn (array $t): bool => ($t['role'] ?? '') === 'user');
        self::assertSame([], $delHumano, 'no se inventó un turno del humano donde hubo silencio');
    }

    /**
     * Contestar guarda QUIÉN contestó, y si esa identidad se verificó.
     *
     * Sin esto un permiso no es auditable: hoy se sostiene por accidente —contestar exige la
     * terminal— y en cuanto haya un segundo canal nadie podrá saber quién autorizó qué. Lo encontró
     * Q-P19-B comparando con `milpa/workflow`, que además prohíbe que el que pide sea el que aprueba.
     */
    public function testAnsweringRecordsWhoAnsweredAndWhetherItWasVerified(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿Lo autorizas?', ['sí', 'no']));
        $almacen->answer('s1', 'perm:make', 'sí', new Principal('actor:member:42', verified: true));

        $decision = $almacen->load('s1')?->decisions[0] ?? [];

        self::assertInstanceOf(Principal::class, $decision['by'] ?? null);
        self::assertSame('actor:member:42', $decision['by']->id);
        self::assertTrue($decision['by']->verified);
    }

    /**
     * Una terminal aporta un principal SIN VERIFICAR, y se guarda como tal.
     *
     * Es la distinción entera: guardar el usuario del sistema como si fuera una identidad probada
     * fabricaría una cadena de custodia que no existe — diría «lo autorizó rod» cuando lo que se sabe
     * es «lo autorizó quien tenía la máquina de rod».
     */
    public function testATerminalPrincipalIsRecordedAsUnverified(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?'));
        $almacen->answer('s1', 'perm:make', 'sí', Principal::fromTerminal('rod', 'laptop'));

        $quien = $almacen->load('s1')?->decisions[0]['by'] ?? null;

        self::assertSame('cli:rod@laptop', $quien?->id);
        self::assertFalse($quien?->verified, 'una terminal no prueba nada');
    }

    /**
     * Sin principal, la decisión queda con `null` — y leerla NO inventa uno.
     *
     * Importa para las sesiones anteriores a que esto existiera: un reductor que rellenara «cli:?»
     * al leerlas estaría escribiendo evidencia que nadie produjo.
     */
    public function testAnAnswerWithoutAPrincipalStaysWithoutOne(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿?'));
        $almacen->answer('s1', 'perm:make', 'sí');

        $decision = $almacen->load('s1')?->decisions[0] ?? [];

        self::assertArrayHasKey('by', $decision, 'la clave existe: la decisión sabe que nadie la firmó');
        self::assertNull($decision['by'], 'y vale null, no un principal inventado');
    }

    /**
     * Al releer, la confianza NUNCA sube: lo que no diga `verified: true` se lee sin verificar.
     *
     * Un payload manipulado —o uno viejo con otra forma— no puede convertir una identidad declarada
     * en una probada. La confianza sólo la otorga quien la comprobó, y eso pasó al escribir.
     */
    public function testReplayNeverRaisesTheTrustOfAPrincipal(): void
    {
        self::assertFalse((Principal::fromArray(['id' => 'actor:x']))?->verified);
        self::assertFalse((Principal::fromArray(['id' => 'actor:x', 'verified' => 'sí']))?->verified);
        self::assertNull(Principal::fromArray(['verified' => true]), 'sin id no hay principal');
    }

    /**
     * El plan se SUPERSEDE, no se reescribe: cada cambio avanza la versión y declara a cuál reemplaza.
     *
     * Antes cada `plan_set` decía «el plan es esto» sin relación con el anterior, y un stream con
     * cinco planes sueltos conserva los hechos y pierde el linaje — nadie puede decir cuál sustituyó
     * a cuál. Medido en Q-P19-C: cinco sesiones reescribieron su plan con texto distinto.
     */
    public function testTheePlanIsSupersededAndCarriesItsLineage(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->setPlan('s1', '1. mirar');
        self::assertSame(1, $almacen->load('s1')?->planVersion, 'el primero no supersede a nadie');

        $almacen->setPlan('s1', '1. mirar  2. clasificar');
        self::assertSame(2, $almacen->load('s1')?->planVersion);

        $eventos = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.plan_set',
        ));

        self::assertNull($eventos[0]->payload['supersedes'], 'el primero no reemplaza nada');
        self::assertSame(1, $eventos[1]->payload['supersedes'], 'el segundo dice a quién reemplaza');
    }

    /**
     * Reescribir el MISMO texto no supersede nada, y el evento se apenda igual.
     *
     * Las dos mitades importan. La versión no avanza porque no hubo reemplazo — inflarla convertiría
     * el linaje en ruido. Y el evento SÍ queda porque pasó: que el agente vuelva a declarar el plan
     * que ya tenía es un dato sobre el sistema —no tiene cómo saber que ya lo puso— y borrarlo sería
     * decidir por adelantado que no interesa.
     */
    public function testRewritingTheSamePlanSupersedesNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setPlan('s1', '1. mirar');
        $almacen->setPlan('s1', '1. mirar');

        self::assertSame(1, $almacen->load('s1')?->planVersion, 'no avanzó');

        $planes = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.plan_set',
        ));

        self::assertCount(2, $planes, 'los dos hechos quedan en el stream');
        self::assertNull($planes[1]->payload['supersedes'], 'el segundo no reemplaza a nadie');
    }

    /** Una sesión sin plan está en versión cero: no hubo linaje, no se inventa uno. */
    public function testASessionWithNoPlanIsAtVersionZero(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');

        self::assertSame(0, $almacen->load('s1')?->planVersion);
    }

    /**
     * Una tarjeta declara DE DÓNDE A DÓNDE se movió, y a qué versión reemplaza.
     *
     * Antes el evento decía «está en X» y nada más: quién la movió desde dónde había que deducirlo
     * comparando con lo visto antes, y esa deducción vivía en los scripts de análisis y no en el
     * hecho. Dos lectores podían reconstruir historias distintas del mismo stream.
     */
    public function testATodoDeclaresWhereItCameFromAndWhatItSupersedes(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Pending));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::InProgress));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Done));

        $cambios = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.todo_changed',
        ));

        self::assertNull($cambios[0]->payload['from'], 'al nacer no viene de ningún lado');
        self::assertNull($cambios[0]->payload['supersedes']);
        self::assertSame(1, $cambios[0]->payload['version']);

        self::assertSame('pending', $cambios[1]->payload['from'], 'el movimiento se lee, no se deduce');
        self::assertSame(1, $cambios[1]->payload['supersedes']);
        self::assertSame(2, $cambios[1]->payload['version']);

        self::assertSame('in_progress', $cambios[2]->payload['from']);
        self::assertSame(3, $cambios[2]->payload['version']);
    }

    /**
     * Una tarjeta que NACE terminada no dice que viene de `pending`.
     *
     * Es la conducta dominante que midió Q-P19-C —12 de 16 corridas— y la tentación era rellenar
     * `from: pending` para que el tablero pudiera animar el movimiento. Sería inventar una columna
     * que la tarjeta nunca ocupó: el mejor riel no fuerza a que todo se mueva, fuerza a que cada
     * tarjeta declare honestamente cómo nació.
     */
    public function testATodoBornDoneDoesNotClaimToComeFromPending(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->setTodo('s1', new Todo('t1', 'ya lo hice', TodoStatus::Done));

        $cambio = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.todo_changed',
        ))[0];

        self::assertSame('done', $cambio->payload['status']);
        self::assertNull($cambio->payload['from'], 'no cruzó ninguna columna y no dice que sí');
    }

    /** Re-declarar la misma tarjeta no supersede nada, y el evento queda igual. */
    public function testRedeclaringTheSameTodoSupersedesNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Pending));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Pending));

        $cambios = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.todo_changed',
        ));

        self::assertCount(2, $cambios, 'los dos hechos quedan en el stream');
        self::assertSame(1, $cambios[1]->payload['version'], 'la versión no avanzó');
        self::assertNull($cambios[1]->payload['supersedes']);
        self::assertSame(1, $almacen->load('s1')?->todos[0]->version);
    }

    /**
     * Las cuatro formas de nacer, DERIVADAS y no preguntadas.
     *
     * Pedirle al agente que etiquete el origen sería agregarle una decisión, y el sistema ya sabe lo
     * que hace falta: cuántas herramientas corrieron antes. Una etiqueta declarada por quien escribe
     * es una afirmación; una derivada del stream es una observación, y cuando se pueden tener las dos
     * gana la observación.
     */
    public function testHowATodoWasBornIsDerivedFromTheStreamAndNotAsked(): void
    {
        $sinTrabajo = $this->store();
        $sinTrabajo->start('s1', 'x');
        $sinTrabajo->setTodo('s1', new Todo('t1', 'voy a hacerlo', TodoStatus::Pending));
        $sinTrabajo->setTodo('s1', new Todo('t2', 'ya lo hice', TodoStatus::Done));

        $porId = [];
        foreach ($sinTrabajo->load('s1')?->todos ?? [] as $t) {
            $porId[$t->id] = $t;
        }

        self::assertSame(TodoOrigin::Planned, $porId['t1']->origin, 'sin trabajo previo: es intención');
        self::assertSame(
            TodoOrigin::Unsupported,
            $porId['t2']->origin,
            'nace hecha sin que nada la respalde — Q-P19-C midió cero de éstas, y por eso se nombra ahora',
        );

        $conTrabajo = $this->store();
        $conTrabajo->start('s2', 'x');
        $conTrabajo->recordToolCall('s2', 'plugins_architecture', [], 'ok');
        $conTrabajo->setTodo('s2', new Todo('t3', 'apareció al mirar', TodoStatus::Pending));
        $conTrabajo->setTodo('s2', new Todo('t4', 'lo hice', TodoStatus::Done));

        $porId2 = [];
        foreach ($conTrabajo->load('s2')?->todos ?? [] as $t) {
            $porId2[$t->id] = $t;
        }

        self::assertSame(TodoOrigin::Discovered, $porId2['t3']->origin);
        self::assertSame(TodoOrigin::Retrospective, $porId2['t4']->origin);
    }

    /**
     * El origen sobrevive al movimiento: nacer se declara UNA vez.
     *
     * El evento sólo lo lleva al nacer —un movimiento tiene un `from`, no un origen— así que sin esto
     * la tarjeta perdería su origen en cuanto se moviera, y el tablero no podría distinguir una
     * tarjeta planeada que avanzó de una que apareció ya empezada.
     */
    public function testTheOriginSurvivesEveryMove(): void
    {
        $almacen = $this->store();
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Pending));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::InProgress));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Done));

        $tarjeta = ($almacen->load('s1')?->todos ?? [])[0] ?? null;

        self::assertSame(TodoOrigin::Planned, $tarjeta?->origin, 'nació planeada y sigue siéndolo');
        self::assertSame(TodoStatus::Done, $tarjeta?->status);
        self::assertSame(3, $tarjeta?->version);
    }

    /**
     * Terminar con trabajo abierto lo DECLARA, y no lo cierra.
     *
     * Q-P19-C midió 4 de 16 corridas que contestaron dejando una tarjeta sin cerrar, y el sistema no
     * decía nada al respecto: la tarjeta se quedaba en su columna y nadie podía distinguir «se hizo
     * todo» de «se acabó la sesión a media tarea».
     *
     * Y no las marca abandonadas: el sistema no sabe por qué se detuvo el trabajo —pudo esperar
     * autoridad humana, topar el contexto, transferirse, fallar, o dejarse a propósito— así que
     * declara lo observado.
     */
    public function testEndingWithOpenWorkDeclaresItInsteadOfClosingIt(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'terminada', TodoStatus::Done));
        $almacen->setTodo('s1', new Todo('t2', 'a medias', TodoStatus::InProgress));
        $almacen->setTodo('s1', new Todo('t3', 'trabada', TodoStatus::Blocked));

        $almacen->end('s1', 'se acabó');

        $declarado = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.ended_with_open_work',
        ));

        self::assertCount(1, $declarado);
        $ids = array_column($declarado[0]->payload['todos'], 'id');
        self::assertSame(['t2', 't3'], $ids, 'bloqueada cuenta como abierta: está detenida, no terminada');
        self::assertSame('open', $declarado[0]->payload['todos'][0]['disposition'], 'lo observado, no un juicio');

        $sesion = $almacen->load('s1');
        self::assertSame(TodoStatus::InProgress, $sesion?->todos[1]->status, 'y NO se cerraron');
    }

    /** Una sesión que termina sin trabajo abierto no declara nada: no hay nada que decir. */
    public function testEndingCleanDeclaresNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'lista', TodoStatus::Done));
        $almacen->end('s1', 'listo');

        $declarado = array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.ended_with_open_work',
        );

        self::assertSame([], $declarado);
    }

    /**
     * El trabajo abierto se HEREDA con su linaje: quién lo tenía, cómo nació, desde qué versión.
     *
     * La continuidad pertenece al sistema, no a la sesión. Una tarjeta que cambia de dueño y pierde
     * su historia es una tarjeta nueva con el mismo texto, y el tablero que la pinte no podría decir
     * que ya se había trabajado en ella.
     */
    public function testOpenWorkIsInheritedWithItsLineage(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('vieja', 'x');
        $almacen->recordToolCall('vieja', 'plugins_architecture', [], 'ok');
        $almacen->setTodo('vieja', new Todo('t1', 'a medias', TodoStatus::Pending));
        $almacen->setTodo('vieja', new Todo('t1', 'a medias', TodoStatus::InProgress));
        $almacen->start('nueva', 'sigo yo');

        $movidas = $almacen->transferOpenTodos('vieja', 'nueva');

        self::assertSame(1, $movidas);

        $heredada = ($almacen->load('nueva')?->todos ?? [])[0] ?? null;
        self::assertSame('t1', $heredada?->id);
        self::assertSame(TodoStatus::InProgress, $heredada?->status, 'llega donde estaba');
        self::assertSame(
            TodoOrigin::Discovered,
            $heredada?->origin,
            'y con cómo nació — se descubrió trabajando, y eso no cambia al cambiar de dueño',
        );

        $traslado = array_values(array_filter(
            $eventos->replay('agent-session:vieja'),
            static fn ($e): bool => $e->type === 'session.todos_transferred',
        ));
        self::assertSame('nueva', $traslado[0]->payload['to'], 'la origen dice a dónde se fueron');
        self::assertSame(2, $traslado[0]->payload['todos'][0]['version'], 'y desde qué versión');
    }

    /** Transferir cuando no hay nada abierto no es un error: es una sesión que terminó limpia. */
    public function testTransferringNothingIsNotAnError(): void
    {
        $almacen = $this->store();
        $almacen->start('vieja', 'x');
        $almacen->setTodo('vieja', new Todo('t1', 'lista', TodoStatus::Done));
        $almacen->start('nueva', 'y');

        self::assertSame(0, $almacen->transferOpenTodos('vieja', 'nueva'));
    }

    /**
     * EL INVARIANTE: cuántas cosas cambiaron mientras una tarjeta declarada seguía abierta.
     *
     * Es la pregunta de Rod reducida a lo que el sistema puede contestar **sin cooperación del
     * agente**: no «¿esta mutación corresponde a esta tarjeta?» —eso pediría semántica y acabaría en
     * un verificador que interpreta todo— sino «¿cuántas mutaciones ocurrieron desde que nadie toca
     * esta tarjeta?».
     *
     * No dice que esté mal. Dice que **no se explicó**, y eso sí es verificable.
     */
    public function testTheSystemCountsWhatChangedWhileADeclaredTodoStayedOpen(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->setTodo('s1', new Todo('t1', 'apagar el viejo', TodoStatus::Pending));
        $almacen->recordToolCall('s1', 'plugins_disable', [], 'ok', true, mutating: true);
        $almacen->recordToolCall('s1', 'plugins_architecture', [], 'ok', true, mutating: false);
        $almacen->recordToolCall('s1', 'plugins_enable', [], 'ok', true, mutating: true);

        $almacen->end('s1', 'se acabó');

        $declarado = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.ended_with_open_work',
        ))[0];

        self::assertSame(
            2,
            $declarado->payload['todos'][0]['mutationsSince'],
            'dos mutaciones desde que la tarjeta se tocó; la consulta no cuenta',
        );
    }

    /**
     * Una tarjeta que queda abierta sin que pasara nada más NO tiene nada que explicar.
     *
     * Es la mitad que evita que el invariante se vuelva una alarma constante: quedar abierto no es el
     * problema. El problema es quedar abierto mientras el mundo cambia alrededor.
     */
    public function testATodoLeftOpenWithNothingHappeningHasNothingToExplain(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'pendiente', TodoStatus::Pending));
        $almacen->end('s1', 'se acabó');

        $declarado = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.ended_with_open_work',
        ))[0];

        self::assertSame(0, $declarado->payload['todos'][0]['mutationsSince']);
    }

    /** Tocar la tarjeta pone el contador a cero: moverla ES explicarla. */
    public function testTouchingTheTodoResetsWhatItHasToExplain(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'apagar', TodoStatus::Pending));
        $almacen->recordToolCall('s1', 'plugins_disable', [], 'ok', true, mutating: true);
        $almacen->setTodo('s1', new Todo('t1', 'apagar', TodoStatus::InProgress));
        $almacen->end('s1', 'se acabó');

        $declarado = array_values(array_filter(
            $eventos->replay('agent-session:s1'),
            static fn ($e): bool => $e->type === 'session.ended_with_open_work',
        ))[0];

        self::assertSame(0, $declarado->payload['todos'][0]['mutationsSince']);
    }

    /**
     * Una opcion retirada sale de la mesa de la sesion, y el motivo viaja con el hecho.
     *
     * Va al stream y no a un arreglo en memoria porque la mesa pertenece a la SESION: sin este hecho
     * no sobreviviria a una compactacion ni a retomar manana. Y quien lea esto en un ano necesita
     * saber por que esa opcion no estaba, sin poder preguntarle a nadie.
     */
    public function testARemovedOptionLeavesTheTableAndSaysWhy(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');

        $almacen->removeOption('s1', 'plugins_disable', 'request_reads_only', 'la peticion solo preguntaba');
        $almacen->removeOption('s1', 'plugins_disable', 'request_reads_only');
        $almacen->removeOption('s1', '  ', 'x');
        // Sin CODIGO tampoco: un motivo que solo trae prosa no se puede agrupar ni contar, y un hecho
        // sin motivo agrupable es el que nadie va a poder medir en un ano.
        $almacen->removeOption('s1', 'plugins_lock', '  ');

        self::assertSame(['plugins_disable'], $almacen->load('s1')?->removedOptions);

        $hechos = array_values(array_filter(
            $eventos->replay(SessionStore::PREFIX . 's1'),
            static fn ($e): bool => $e->type === 'session.option_removed',
        ));

        self::assertCount(2, $hechos, 'los dos intentos quedan en la historia; el fold es el que no repite');
        self::assertSame('request_reads_only', $hechos[0]->payload['reason']['code']);
        self::assertSame('la peticion solo preguntaba', $hechos[0]->payload['reason']['message']);
        self::assertNull($hechos[1]->payload['reason']['message'], 'el mensaje es opcional; el codigo no');
    }

    /** Y una sesion recien abierta no tiene nada retirado. */
    public function testAFreshSessionHasNothingRemoved(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x');

        self::assertSame([], $almacen->load('s1')?->removedOptions);
    }

    /**
     * AN OBLIGATION THAT WAS MET STOPS BEING ONE.
     *
     * Without this the prerequisite re-arms on every turn: a session resumed after a pause plans
     * again, and again, and a real run produced six identical cards for one task — twenty pending
     * for six. «Plan before starting» meant once, not once per turn, and a board painted on the
     * second reading is noisy where the empty one was mute. Neither is a source anyone can trust.
     */
    public function testAnObligationAlreadyMetDoesNotSurviveIntoTheNextTurn(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'construye algo largo', AutonomyMode::Auto);

        $almacen->requireFirst('s', ['plan']);
        self::assertSame(['plan'], $almacen->load('s')?->runFirst, 'declarada, la obligación rige');

        $almacen->setPlan('s', 'primero esto, luego lo otro');
        self::assertSame([], $almacen->load('s')?->runFirst, 'cumplida, deja de regir — y no se re-arma al retomar');

        // Y VUELVE si alguien con autoridad la vuelve a declarar: quitarla no es perderla.
        $almacen->requireFirst('s', ['plan']);
        self::assertSame(['plan'], $almacen->load('s')?->runFirst);

        // Una lista vacía la levanta, que es la misma autoridad deshaciendo lo que hizo.
        $almacen->requireFirst('s', []);
        self::assertSame([], $almacen->load('s')?->runFirst);
    }

    /**
     * RENEWAL BELONGS TO THE SESSION SOMEBODY SET UP THAT WAY — never to the app around it.
     *
     * It lived in `config/app.php` for exactly one measured run, and that run was thrown away: an
     * app-level flag reaches BOTH arms of an experiment inside that app, so the arm meant to receive
     * nothing started organising too and stopped being a control. A control that receives the
     * treatment measures nothing.
     */
    public function testOnlyASessionGivenAnObligationIsEverRenewed(): void
    {
        $almacen = $this->store();

        $almacen->start('suelta', 'trabaja', AutonomyMode::Auto);
        self::assertFalse($almacen->load('suelta')?->obligationDeclared, 'una sesión que nadie obligó no se renueva jamás');

        $almacen->start('exigida', 'trabaja', AutonomyMode::Auto);
        $almacen->requireFirst('exigida', ['plan']);
        self::assertTrue($almacen->load('exigida')?->obligationDeclared);

        // Y SIGUE SIENDO CIERTO DESPUÉS DE CUMPLIRSE: `runFirst` se vacía al correr el plan, pero
        // haber sido obligada es un hecho del pasado de esta sesión y no se deshace.
        $almacen->setPlan('exigida', 'un plan');
        self::assertSame([], $almacen->load('exigida')?->runFirst);
        self::assertTrue($almacen->load('exigida')?->obligationDeclared, 'cumplir no borra haber sido obligada');
    }

    /**
     * LIFTING ENDS THE DISCIPLINE — not just the standing list.
     *
     * `obligationDeclared` is what the renewal reads, so a lift that only emptied `runFirst` would
     * be re-armed with `todo` on the very next turn: the caller unset it and the session put it
     * back. The empty set is the same authority unsetting what it set. The renewal's default stays
     * ON (Rod, 2026-08-06, with sexto-brazo.tsv in hand), which is exactly what makes this lever
     * load-bearing: it is the per-session exit.
     */
    public function testLiftingTheObligationEndsTheDisciplineNotJustTheStandingList(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x', AutonomyMode::Auto);
        $almacen->requireFirst('s', ['plan']);
        self::assertTrue($almacen->load('s')?->obligationDeclared);

        $almacen->requireFirst('s', []);
        self::assertSame([], $almacen->load('s')?->runFirst);
        self::assertFalse($almacen->load('s')?->obligationDeclared, 'lifted: the renewal has nothing left to renew');

        // And declaring again re-arms it: unsetting is not losing it forever.
        $almacen->requireFirst('s', ['todo']);
        self::assertTrue($almacen->load('s')?->obligationDeclared);
    }

    /**
     * EVERY CARD CARRIES THE PLAN GENERATION IT BELONGS TO.
     *
     * Re-planning is what completes long work — Q-P17-L measured 6/9 against 0/9 — and it is also
     * what stacked six copies of the same card, twenty pending for six real tasks. Benefit and noise
     * came out of the same act, so they get separated where the spec says the board lives: in the
     * reading. Nothing is retired here; the copies happened and stay. What the stamp buys is that a
     * surface can tell which generation is today's.
     */
    public function testEveryCardCarriesThePlanGenerationItBelongsTo(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'trabaja', AutonomyMode::Auto);

        $store->setPlan('s', 'primera versión');
        $store->setTodo('s', new Todo('t1', 'hacer lo primero', TodoStatus::Pending));

        // Re-planear abre una generación: la tarjeta de antes NO cambia, la nueva nace en la otra.
        $store->setPlan('s', 'segunda versión');
        $store->setTodo('s', new Todo('t2', 'hacer lo segundo', TodoStatus::Pending));

        $generations = [];
        foreach ($events->replay('agent-session:s') as $event) {
            if ($event->type === 'session.todo_changed') {
                $generations[$event->payload['id']] = $event->payload['planVersion'] ?? null;
            }
        }

        self::assertSame(1, $generations['t1'], 'la tarjeta del primer plan queda sellada con su generación');
        self::assertSame(2, $generations['t2'], 'la del segundo nace en la siguiente');
    }

    /**
     * A CARD SAYS WHICH ONE IT REPLACED, and the record keeps both.
     *
     * Plan generations could not answer this: the stamp records when a card was last touched, so one
     * moved after a re-plan migrates forward and survives any version compare — verified, seven
     * generations removing seven cards in one run and none in the other. Whether two cards speak
     * about the same task is not derivable without guessing what the agent meant, so it is declared.
     *
     * Neither card is destroyed: the replaced one stays exactly as it was, and the new one carries
     * the link. A surface decides what to show; the stream keeps what happened.
     */
    public function testACardRecordsWhichOneItReplaced(): void
    {
        $store = $this->store();
        $store->start('s', 'trabaja', AutonomyMode::Auto);

        $store->setTodo('s', new Todo('t1', 'registrar los plugins', TodoStatus::Pending));
        $store->setTodo('s', new Todo('t2', 'registrar los TRES plugins', TodoStatus::Pending, replaces: 't1'));

        $porId = [];
        foreach ($store->load('s')?->todos ?? [] as $t) {
            $porId[$t->id] = $t;
        }

        self::assertArrayHasKey('t2', $porId);
        self::assertNull($porId['t1']->replaces, 'la vieja no se toca');
        self::assertSame('t1', $porId['t2']->replaces, 'la nueva dice a cuál deja sin efecto');
        self::assertSame(TodoStatus::Pending, $porId['t1']->status, 'reemplazar no es cerrar: la reemplazada no se marca hecha ni abandonada');
    }

    /**
     * THE BIRTH GENERATION IS FIXED ONCE AND NEVER MOVES AGAIN.
     *
     * `planVersion` records the last touch — right for «how current is this», wrong for «is this a
     * restatement of that»: a card moved after a re-plan migrates forward and stops being comparable
     * with the one it duplicates. Three slices in a row were built on the touch stamp before the
     * measurement showed all three were reading the wrong moment.
     */
    public function testTheBirthGenerationNeverMovesWhileTheTouchStampDoes(): void
    {
        $store = $this->store();
        $store->start('s', 'trabaja', AutonomyMode::Auto);

        $store->setPlan('s', 'primera versión');
        $store->setTodo('s', new Todo('t1', 'registrar', TodoStatus::Pending));

        $store->setPlan('s', 'segunda versión');
        $store->setTodo('s', new Todo('t1', 'registrar', TodoStatus::InProgress));

        $porId = [];
        foreach ($store->load('s')?->todos ?? [] as $t) {
            $porId[$t->id] = $t;
        }

        self::assertSame(1, $porId['t1']->bornInPlan, 'nació en la primera y ahí se queda');
        self::assertSame(2, $porId['t1']->planVersion, 'el último toque sí avanza');
    }

    /** `loadAll()` devuelve cada sesión, idéntica a cargarla suelta, y con el orden de `ids()`. */
    public function testLoadAllReturnsEverySessionEqualToLoadingEachById(): void
    {
        $almacen = $this->store();
        $almacen->start('a', 'la primera');
        $almacen->recordTurn('a', 'user', 'hola');
        $almacen->start('b', 'la segunda');
        $almacen->start('c', 'la tercera');
        $almacen->recordTurn('c', 'assistant', 'listo');

        $todas = $almacen->loadAll();

        self::assertSame($almacen->ids(), array_keys($todas), 'las mismas ids, en el mismo orden que ids()');
        foreach ($almacen->ids() as $id) {
            self::assertEquals($almacen->load($id), $todas[$id], "loadAll()[$id] debe igualar a load($id)");
        }
    }

    /**
     * La propiedad que existe para arreglar: listar N sesiones lee el log UNA vez, no N.
     *
     * El instrumento es un almacén que cuenta lecturas. Su control positivo va incluido: el camino
     * viejo —`load()` en un bucle— SÍ hace que el contador vea una lectura por sesión, así que un 1
     * en el camino nuevo no es que el contador esté ciego.
     */
    public function testLoadAllReadsTheLogOnceWhereLoadingEachByIdReadsItPerSession(): void
    {
        $contador = new class (new InMemoryEventStore()) implements \Milpa\EventStore\EventStoreInterface {
            public int $replay = 0;
            public int $replayAll = 0;

            public function __construct(private \Milpa\EventStore\EventStoreInterface $inner)
            {
            }

            public function append(Event $e): void
            {
                $this->inner->append($e);
            }

            public function replay(string $streamId): array
            {
                ++$this->replay;

                return $this->inner->replay($streamId);
            }

            public function nextSeq(): int
            {
                return $this->inner->nextSeq();
            }

            public function streams(): array
            {
                return $this->inner->streams();
            }

            public function replayAll(): array
            {
                ++$this->replayAll;

                return $this->inner->replayAll();
            }
        };

        $almacen = new SessionStore($contador);
        $almacen->start('a', '1');
        $almacen->start('b', '2');
        $almacen->start('c', '3');

        // CONTROL POSITIVO — el camino viejo: una sesión a la vez lee el log una vez POR sesión.
        $contador->replay = 0;
        $contador->replayAll = 0;
        foreach ($almacen->ids() as $id) {
            $almacen->load($id);
        }
        self::assertSame(3, $contador->replay, 'cargar una por una lee el log una vez por sesión (3)');

        // EL CAMINO NUEVO: loadAll lee el log exactamente una vez, sin importar cuántas sesiones.
        $contador->replay = 0;
        $contador->replayAll = 0;
        $almacen->loadAll();
        self::assertSame(1, $contador->replayAll, 'loadAll lee el log exactamente una vez');
        self::assertSame(0, $contador->replay, 'loadAll no cae en el replay-por-sesión');
    }
}
