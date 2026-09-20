<?php

/**
 * This file is part of Milpa Agent — governed agent sessions for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The cap belongs to whoever has the scarcity.
 *
 * One truncation used to serve two consumers with opposite needs: the window wants a tool result
 * short because context is what runs out on a long session, and a surface wants it whole to build
 * the data view. Cutting at write gave the window what it needed and made the surface pay — measured
 * on cattle, `capabilities` came back at 2004 characters, the log kept 600, the value stopped
 * parsing and the human saw no table at all.
 */
final class WindowCapTest extends TestCase
{
    private function store(InMemoryEventStore $eventos): SessionStore
    {
        return new SessionStore($eventos);
    }

    /** @return array{0: SessionStore, 1: InMemoryEventStore} */
    private function conUnaLlamada(string $resultado): array
    {
        $eventos = new InMemoryEventStore();
        $almacen = $this->store($eventos);
        $almacen->start('s1', 'x');
        $almacen->recordToolCall('s1', 'capabilities', [], $resultado, true, false, mb_strlen($resultado));

        return [$almacen, $eventos];
    }

    /** Un JSON completo llega completo al hecho, y por eso se puede parsear. */
    public function testABigResultIsKeptWholeInTheLog(): void
    {
        $json = (string) json_encode(['capabilities' => array_fill(0, 60, 'una capacidad con su descripción')]);
        self::assertGreaterThan(2000, mb_strlen($json), 'el fixture tiene que pasar el tope o no prueba nada');

        [$almacen, $eventos] = $this->conUnaLlamada($json);

        $guardado = null;
        foreach ($eventos->replay(SessionStore::PREFIX . 's1') as $e) {
            if ($e->type === 'session.tool_called') {
                $guardado = (string) $e->payload['result'];
            }
        }

        self::assertSame($json, $guardado, 'el log guarda lo que la herramienta contestó');
        self::assertNotNull(json_decode($guardado), 'y por eso una superficie puede armar su tabla');
    }

    /** Y aun así el modelo no lo recibe entero: la ventana es la que tiene la escasez. */
    public function testTheWindowStillCarriesTheCappedValue(): void
    {
        $json = (string) json_encode(['capabilities' => array_fill(0, 60, 'una capacidad con su descripción')]);
        [$almacen] = $this->conUnaLlamada($json);

        $ventana = $almacen->load('s1')?->window() ?? [];
        $deHerramienta = array_values(array_filter($ventana, static fn (array $m): bool => $m['role'] === 'assistant'));

        self::assertCount(1, $deHerramienta);
        self::assertLessThan(mb_strlen($json), mb_strlen($deHerramienta[0]['content']));
        $data = json_decode(explode("\n", $deHerramienta[0]['content'], 2)[1], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(mb_substr($json, 0, 600), $data['result']);
        self::assertTrue($data['window_truncated']);
    }

    /** A short result remains byte-identical inside the explicitly quoted historical data. */
    public function testAShortResultIsPreservedInsideTheHistoryEnvelope(): void
    {
        [$almacen] = $this->conUnaLlamada('ok: dos plugins');

        $ventana = $almacen->load('s1')?->window() ?? [];
        $deHerramienta = array_values(array_filter($ventana, static fn (array $m): bool => $m['role'] === 'assistant'));

        $data = json_decode(explode("\n", $deHerramienta[0]['content'], 2)[1], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('capabilities', $data['tool']);
        self::assertSame('ok: dos plugins', $data['result']);
        self::assertFalse($data['window_truncated']);
    }

    /** Y el corte de la ventana no se anuncia como corte del resultado: son topes distintos. */
    public function testTheLogDoesNotReportItselfCutWhenOnlyTheWindowCapped(): void
    {
        $json = (string) json_encode(['capabilities' => array_fill(0, 60, 'una capacidad con su descripción')]);
        [$almacen, $eventos] = $this->conUnaLlamada($json);

        $r = \Milpa\Agent\SessionObservation::of($eventos, 's1')->answers['returned']['value'][0];

        self::assertFalse($r['truncated'], 'el log no cortó nada; el que recorta es la ventana');
        self::assertSame(mb_strlen($json), $r['resultChars']);
    }
}
