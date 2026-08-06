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

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * El proyector traduce, no interpreta — y no guarda nada.
 *
 * Es la pieza que permite que un tablero muestre el trabajo en vivo sin tener estado propio. En el
 * momento en que guardara su copia habría dos sitios contestando «en qué va esto», y divergirían.
 */
final class SessionProjectorTest extends TestCase
{
    /**
     * Un movimiento se LEE del hecho: de dónde, a dónde, y qué versión.
     *
     * El stream ya lo trae. Un proyector que lo dedujera comparando con lo anterior estaría
     * reconstruyendo lo que el hecho ya dice, y dos lectores con esa deducción escrita distinto
     * cuentan historias distintas del mismo stream.
     */
    public function testAMoveIsReadFromTheFactAndNotDeduced(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Pending));
        $almacen->setTodo('s1', new Todo('t1', 'mirar', TodoStatus::Done));

        $pintables = (new SessionProjector())->projectAll($eventos->replay('agent-session:s1'));
        $tarjetas = array_values(array_filter($pintables, static fn (array $x): bool => $x['kind'] === 'card'));

        self::assertSame('s1', $tarjetas[0]['session'], 'el id sale del stream, no se pide aparte');
        self::assertNull($tarjetas[0]['card']['from'], 'nació ahí: no cruzó nada');
        self::assertSame('pending', $tarjetas[0]['card']['to']);

        self::assertSame('pending', $tarjetas[1]['card']['from'], 'y el movimiento viene dado');
        self::assertSame('done', $tarjetas[1]['card']['to']);
        self::assertSame(2, $tarjetas[1]['card']['version']);
    }

    /**
     * Lo que no cambia lo que se ve devuelve `null`, y eso es una afirmación.
     *
     * Un turno del modelo importa en la conversación y no en el tablero. Forzar a que todo hecho
     * produzca algo llenaría la superficie de ruido que nadie pidió.
     */
    public function testWhatDoesNotChangeTheViewProducesNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->grant('s1', 'plugins.disable');
        $almacen->setMode('s1', AutonomyMode::Auto);

        $pintables = (new SessionProjector())->projectAll($eventos->replay('agent-session:s1'));

        self::assertSame([], $pintables, 'abrir, permitir y cambiar de modo no pintan nada');
    }

    /**
     * Hablar y llamar herramientas SÍ proyectan: son la actividad.
     *
     * No mueven una tarjeta —el tablero no los pinta— pero son exactamente lo que una pantalla tiene
     * que estar diciendo mientras se espera. «El tablero no lo pinta» no es «no es proyectable»: se
     * proyecta una vez y cada superficie filtra, porque dos traducciones del mismo stream divergen en
     * el evento que nadie probó.
     */
    public function testTalkingAndCallingToolsProjectActivity(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordTurn('s1', 'user', 'hola');
        $almacen->recordToolCall('s1', 'plugins_list', [], 'ok');
        $almacen->recordToolCall('s1', 'make', [], 'ok', true, true);
        $almacen->recordTurn('s1', 'assistant', 'listo');

        $actividad = array_values(array_filter(
            (new SessionProjector())->projectAll($eventos->replay('agent-session:s1')),
            static fn (array $p): bool => $p['kind'] === 'activity',
        ));

        self::assertCount(4, $actividad);
        self::assertSame('thinking', $actividad[0]['activity']['state'], 'la pregunta ya está guardada: el modelo tiene la palabra');
        self::assertSame('tool', $actividad[1]['activity']['state']);
        self::assertSame('plugins_list', $actividad[1]['activity']['detail'], 'el nombre es lo que hace observable que algo pasa');
        self::assertFalse($actividad[1]['activity']['mutating']);
        self::assertTrue($actividad[2]['activity']['mutating'], 'y se distingue la que toca algo');
        self::assertSame('ready', $actividad[3]['activity']['state']);
    }

    /** El plan proyecta su linaje: qué versión es y a cuál reemplaza. */
    public function testThePlanProjectsItsLineage(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setPlan('s1', '1. mirar');
        $almacen->setPlan('s1', '1. mirar  2. decidir');

        $planes = array_values(array_filter(
            (new SessionProjector())->projectAll($eventos->replay('agent-session:s1')),
            static fn (array $x): bool => $x['kind'] === 'plan',
        ));

        self::assertNull($planes[0]['plan']['supersedes']);
        self::assertSame(1, $planes[1]['plan']['supersedes']);
        self::assertSame(2, $planes[1]['plan']['version']);
    }

    /** Cerrar con trabajo abierto llega a la superficie con lo que no se explicó. */
    public function testOpenWorkReachesTheSurfaceWithWhatWasNotExplained(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'a medias', TodoStatus::Pending));
        $almacen->recordToolCall('s1', 'plugins_disable', [], 'ok', true, mutating: true);
        $almacen->end('s1', 'se acabó');

        $abierto = array_values(array_filter(
            (new SessionProjector())->projectAll($eventos->replay('agent-session:s1')),
            static fn (array $x): bool => $x['kind'] === 'open-work',
        ));

        self::assertSame(1, $abierto[0]['ended']['todos'][0]['mutationsSince']);
    }

    /**
     * Un tipo de evento que este paquete no conoce no se adivina.
     *
     * Un stream se lee años después de escribirse, y una superficie que inventa qué hacer con lo
     * desconocido pinta cualquier cosa con cara de dato.
     */
    /**
     * Answering PROJECTS: it is what clears the waiting banner.
     *
     * This event used to translate to `null` — «does not change what you see» — and that was false
     * for the board: a surface showing the question kept showing it until the NEXT event arrived,
     * so an answer without a resumption left an already-answered question on screen. Found watching
     * a real session with the page open. The attribution travels whole: actor and executor are two
     * identities and both were already in the fact.
     */
    public function testAnsweringProjectsSoTheWaitingBannerCanClear(): void
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s1', 'x');
        $almacen->ask('s1', new \Milpa\Agent\PendingQuestion('q1', '¿Confirmas make?'));
        $almacen->answer('s1', 'q1', 'sí', executor: 'cli:rod');

        $pintables = (new SessionProjector())->projectAll($eventos->replay('agent-session:s1'));
        $contestada = array_values(array_filter($pintables, static fn (array $x): bool => $x['kind'] === 'answered'));

        self::assertCount(1, $contestada, 'la respuesta se proyecta, no se calla');
        self::assertSame('sí', $contestada[0]['answered']['answer']);
        self::assertSame('cli:rod', $contestada[0]['answered']['executor']);
    }

    public function testAnUnknownEventTypeIsNotGuessed(): void
    {
        $evento = new \Milpa\EventStore\Event('agent-session:s1', 'algo.que.no.existe', [], 1);

        self::assertNull((new SessionProjector())->project($evento));
    }

    /**
     * Every event the enum names has a DECIDED translation — even when the decision is «this
     * changes nothing on any surface».
     *
     * The projector's match carries no default on purpose: an event added to the enum without a
     * case there must break loudly, never be born invisible. This test is that breakage made
     * visible in CI — it feeds the projector one event of every kind the enum knows, and an
     * undecided case dies here with \UnhandledMatchError instead of in someone's browser.
     */
    public function testEveryEventTheEnumNamesHasADecidedTranslation(): void
    {
        $proyector = new SessionProjector();

        // `null` is a decision («not painted anywhere»), not an omission: what this guards is the
        // projection CALL itself — an undecided enum case dies right here with \UnhandledMatchError
        // instead of in someone's browser, and the count below proves no case was skipped.
        $decisiones = [];
        foreach (\Milpa\Agent\SessionEvent::cases() as $tipo) {
            $decisiones[] = $proyector->project(new \Milpa\EventStore\Event('agent-session:s1', $tipo->value, [], 1));
        }

        self::assertCount(\count(\Milpa\Agent\SessionEvent::cases()), $decisiones);
    }
}
