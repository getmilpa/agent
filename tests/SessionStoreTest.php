<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
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
}
