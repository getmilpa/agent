<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\Compactor;
use Milpa\Agent\Evidence;
use Milpa\Agent\FactualSummarizer;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Session;
use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Summarizer;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Que el contexto quepa sin que la historia se pierda (P16.2).
 *
 * Una jornada larga se queda sin ventana justo a la mitad, que es cuando ya hay trabajo hecho que
 * conviene no repetir. Compactar es la respuesta, y lo que la hace segura es que acorta lo que se le
 * manda al modelo y NO lo que quedó apendado.
 */
final class CompactorTest extends TestCase
{
    private function almacenCon(int $turnos): SessionStore
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'migrar el plugin a sqlite');
        for ($i = 1; $i <= $turnos; ++$i) {
            $almacen->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', "turno {$i}");
        }

        return $almacen;
    }

    /** Por debajo del umbral no se toca nada: compactar temprano gasta contexto en un resumen que sobra. */
    public function testBelowTheThresholdNothingHappens(): void
    {
        $almacen = $this->almacenCon(10);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compactador = new Compactor(maxTurns: 40, keepRecent: 12);

        self::assertFalse($compactador->shouldCompact($sesion));
        self::assertNull($compactador->compactIfNeeded($almacen, $sesion));
        self::assertNull($almacen->load('s1')?->summary);
    }

    /**
     * Al pasarse: la ventana se acorta, el stream conserva TODO.
     *
     * Es la propiedad entera de P16.2 en dos asserts. Si algún día el segundo falla, lo que se
     * habría perdido es la evidencia de cómo se llegó a una decisión — y sólo en las sesiones largas,
     * donde más cuesta reconstruirla.
     */
    public function testOverTheThresholdTheWindowShrinksAndTheStreamKeepsEverything(): void
    {
        $almacen = $this->almacenCon(50);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);
        self::assertCount(50, $sesion->window(), 'antes de compactar, la ventana es todo');

        $resumen = (new Compactor(maxTurns: 40, keepRecent: 12))->compactIfNeeded($almacen, $sesion);
        self::assertNotNull($resumen);

        $despues = $almacen->load('s1');
        self::assertNotNull($despues);
        self::assertCount(50, $despues->turns, 'el stream conserva los 50');
        self::assertCount(13, $despues->window(), 'la ventana: un resumen más los 12 recientes');
        self::assertSame('turno 39', $despues->window()[1]['content']);
        self::assertSame('turno 50', $despues->window()[12]['content']);
    }

    /**
     * Compactar dos veces seguidas NO vuelve a compactar.
     *
     * El umbral mide los turnos que todavía NO están resumidos. Midiendo el total, una sesión larga
     * volvería a compactar en cada vuelta —el total nunca baja— y apendaría un resumen por turno,
     * cada uno tapando al anterior.
     */
    public function testCompactingTwiceInARowDoesNothingTheSecondTime(): void
    {
        $almacen = $this->almacenCon(50);
        $compactador = new Compactor(maxTurns: 40, keepRecent: 12);

        $primero = $compactador->compactIfNeeded($almacen, $almacen->load('s1') ?? self::fail('sin sesión'));
        self::assertNotNull($primero);

        $segundo = $compactador->compactIfNeeded($almacen, $almacen->load('s1') ?? self::fail('sin sesión'));
        self::assertNull($segundo, 'ya está compactada: no hay 40 turnos sin resumir');
    }

    /** Y vuelve a tocar cuando se acumulan otros tantos. */
    public function testItCompactsAgainOnceEnoughNewTurnsPileUp(): void
    {
        $almacen = $this->almacenCon(50);
        $compactador = new Compactor(maxTurns: 40, keepRecent: 12);
        $compactador->compactIfNeeded($almacen, $almacen->load('s1') ?? self::fail('sin sesión'));

        for ($i = 51; $i <= 100; ++$i) {
            $almacen->recordTurn('s1', 'user', "turno {$i}");
        }

        $otra = $compactador->compactIfNeeded($almacen, $almacen->load('s1') ?? self::fail('sin sesión'));
        self::assertNotNull($otra);
        self::assertCount(100, $almacen->load('s1')?->turns ?? [], 'y sigue sin borrar nada');
    }

    /**
     * Una configuración que no acortaría nada NO compacta.
     *
     * Con `keepRecent >= maxTurns` el corte no avanzaría y la sesión quedaría compactándose para
     * siempre sin reducir la ventana. Negarse es mejor que hacerlo en vano: lo segundo apendaría
     * basura en cada vuelta y se vería como si funcionara.
     */
    public function testAConfigurationThatWouldNotShortenAnythingRefuses(): void
    {
        $almacen = $this->almacenCon(50);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        self::assertFalse((new Compactor(maxTurns: 12, keepRecent: 12))->shouldCompact($sesion));
        self::assertFalse((new Compactor(maxTurns: 10, keepRecent: 30))->shouldCompact($sesion));
    }

    /** El resumidor se puede sustituir: hay hosts que van a preferir prosa de un modelo. */
    public function testTheSummarizerIsReplaceable(): void
    {
        $almacen = $this->almacenCon(50);
        $compactador = new Compactor(
            maxTurns: 40,
            keepRecent: 12,
            summarizer: new class () implements Summarizer {
                public function summarize(Session $session, int $throughSeq): string
                {
                    return "resumen a la medida hasta {$throughSeq}";
                }
            },
        );

        $resumen = $compactador->compactIfNeeded($almacen, $almacen->load('s1') ?? self::fail('sin sesión'));

        self::assertStringContainsString('resumen a la medida', (string) $resumen);
        self::assertStringContainsString(
            'Operational facts (JSON; calls do not prove execution): ',
            (string) $resumen,
            'custom prose must not replace the structured continuity contract',
        );
    }

    /**
     * El resumen por defecto trae los HECHOS que el stream ya sabe.
     *
     * No usa el modelo, y esa es la decisión: para una sesión de código lo que se pierde al compactar
     * no es el matiz, son el objetivo, lo pendiente y lo que el humano decidió. Derivarlo del stream
     * no cuesta una llamada y no puede alucinar — y un resumen inventado se apenda como si fuera lo
     * que pasó, con el modelo trabajando después sobre una versión de la sesión que nadie escribió.
     */
    public function testTheDefaultSummaryCarriesTheFactsTheStreamAlreadyKnows(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'migrar Inventario a sqlite');
        $almacen->setPlan('s1', '1. entidad  2. controller');
        $almacen->setTodo('s1', new Todo('t1', 'escribir la entidad', TodoStatus::Done));
        $almacen->setTodo('s1', new Todo('t2', 'escribir el controller'));
        $almacen->setTodo('s1', new Todo('t3', 'migrar los datos', TodoStatus::Blocked));
        $almacen->grant('s1', 'make');
        $almacen->recordToolCall('s1', 'make', [], 'ok');
        $almacen->recordToolCall('s1', 'make', [], 'ok');
        $almacen->recordToolCall('s1', 'test', [], 'ok');
        $almacen->ask('s1', new PendingQuestion('perm:make', '¿autorizas make?', ['sí', 'no']));
        $almacen->answer('s1', 'perm:make', 'sí');

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $resumen = (new FactualSummarizer())->summarize($sesion, \PHP_INT_MAX);

        self::assertStringContainsString('migrar Inventario a sqlite', $resumen);
        self::assertStringContainsString('1. entidad', $resumen);
        self::assertStringContainsString('make ×2', $resumen, 'cuántas veces, no sólo cuáles');
        self::assertStringContainsString('test', $resumen);
        self::assertStringContainsString('Autorizado en esta sesión: make', $resumen);
        self::assertStringContainsString('Ya hecho: escribir la entidad', $resumen);
        self::assertStringContainsString('escribir el controller', $resumen);
        self::assertStringContainsString('migrar los datos (bloqueado)', $resumen);
        self::assertStringContainsString('¿autorizas make? → «sí»', $resumen, 'lo que decidió el humano');
    }

    /**
     * Lo PENDIENTE va aunque el resumen quede más largo.
     *
     * Es lo único del resumen que le dice al modelo qué hacer a continuación. Uno que cuenta lo que
     * pasó y no lo que falta deja a la sesión sin siguiente paso justo después de compactar.
     */
    public function testWhatIsStillPendingAlwaysMakesTheCut(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x');
        $almacen->setTodo('s1', new Todo('t1', 'lo que falta'));

        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        self::assertStringContainsString(
            'Pendiente: lo que falta',
            (new FactualSummarizer())->summarize($sesion, \PHP_INT_MAX),
        );
    }
    /**
     * Window-aware: pocos turnos pero GRANDES revientan la ventana antes de `maxTurns`. El disparo por
     * presupuesto de tokens compacta ANTES de que el proveedor rechace el request — la muerte «exceeds
     * context size» que el conteo de turnos deja pasar (greenhouse evidence/0436).
     */
    public function testCompactsOnTokenBudgetEvenBelowMaxTurns(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'construir la app de tareas');
        $grande = str_repeat('x', 5000);   // ~1250 tokens por turno
        for ($i = 1; $i <= 8; ++$i) {
            $almacen->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', $grande);
        }
        $sesion = $almacen->load('s1') ?? self::fail('sin sesion');

        // 8 turnos < maxTurns=40 → el conteo NO dispara; pero ~10k tokens > maxTokens=4000 → SÍ dispara.
        self::assertFalse((new Compactor(maxTurns: 40, keepRecent: 12))->shouldCompact($sesion), 'por turnos no toca');

        $ventana = new Compactor(maxTurns: 40, keepRecent: 12, maxTokens: 4000);
        self::assertTrue($ventana->shouldCompact($sesion), 'por ventana SI toca');

        $resumen = $ventana->compactIfNeeded($almacen, $sesion);
        self::assertNotNull($resumen);
        $despues = $almacen->load('s1') ?? self::fail('sin sesion');
        self::assertCount(8, $despues->turns, 'el stream conserva los 8 integros');
        self::assertGreaterThan(0, $despues->compactedThrough, 'compacto hasta un corte');
    }

    /**
     * A real cut must preserve the structured facts the covered tool turn used to expose.
     *
     * The call and execution stay separate on purpose: a tool call owns its target and result, while
     * an execution receipt alone proves materialisation and owns the arguments digest. Compaction may
     * project both, but it must not manufacture a link the stream does not declare.
     */
    public function testCoveredOperationalFactsRemainReadableAfterCompactionWithoutRunningAgain(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'implement a greeter');
        $store->recordToolCall(
            's1',
            'implement',
            ['plugin' => 'Demo', 'class' => 'GreeterService', 'content' => '<?php final class GreeterService {}'],
            (string) json_encode([
                'ok' => true,
                'file' => 'src/Plugins/Demo/Services/GreeterService.php',
                'class' => 'App\\Plugins\\Demo\\Services\\GreeterService',
                'verified' => 'syntax, strict_types, class and namespace',
            ]),
            true,
            true,
            awaitingConfirmation: false,
        );
        $callSeq = $events->nextSeq() - 1;
        $store->recordExecution('s1', 'artifact.implement', null, 'agent', null, 'sha256:greeter-call');
        $executionSeq = $events->nextSeq() - 1;
        $store->recordEvidence(
            's1',
            Evidence::artifact('e-greeter', 'src/Plugins/Demo/Services/GreeterService.php'),
        );
        $store->ask('s1', new PendingQuestion(
            'q-greeter',
            'Keep the public class name?',
            ['yes', 'no'],
            '{"operation":"implement","arguments":{"class":"GreeterService"}}',
            reason: 'design',
        ));
        $store->answer('s1', 'q-greeter', 'yes');
        for ($i = 1; $i <= 5; ++$i) {
            $store->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', "filler {$i}");
        }
        $store->recordExecution('s1', 'cache.refresh', null, 'agent', null, 'sha256:recent-call');
        $store->recordEvidence('s1', Evidence::operationOk('e-recent', 'cache.refresh sha256:recent-call'));
        $store->recordTurn('s1', 'assistant', 'filler 6');

        $before = iterator_to_array($events->replay(SessionStore::PREFIX . 's1'));
        $compactor = new Compactor(maxTurns: 3, keepRecent: 1);
        self::assertNotNull($compactor->compactIfNeeded(
            $store,
            $store->load('s1') ?? self::fail('session did not load'),
        ));

        $after = $store->load('s1') ?? self::fail('compacted session did not load');
        self::assertGreaterThanOrEqual($callSeq, $after->compactedThrough, 'the call is behind the real cut');
        self::assertGreaterThanOrEqual($executionSeq, $after->compactedThrough, 'the execution is behind the real cut');

        $summary = $after->summary ?? self::fail('compaction did not leave a summary');
        $marker = 'Operational facts (JSON; calls do not prove execution): ';
        $markerAt = strpos($summary, $marker);
        self::assertNotFalse($markerAt, 'the model-facing summary must carry a structured fact block');
        $snapshot = json_decode(
            substr($summary, $markerAt + strlen($marker)),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($snapshot);

        self::assertSame('implement', $snapshot['calls'][0]['operation']);
        self::assertContains(
            'src/Plugins/Demo/Services/GreeterService.php',
            array_column($snapshot['calls'][0]['artifacts'], 'value'),
        );
        self::assertTrue($snapshot['calls'][0]['verification']['verified']);
        self::assertSame('syntax, strict_types, class and namespace', $snapshot['calls'][0]['verification']['detail']);
        self::assertTrue($snapshot['calls'][0]['resultSummary']['ok']);
        self::assertTrue($snapshot['calls'][0]['stillCurrent']);
        self::assertSame('latest_recorded_call', $snapshot['calls'][0]['currentScope']);
        self::assertArrayNotHasKey('argumentsDigest', $snapshot['calls'][0], 'a call does not borrow an execution receipt');
        self::assertSame('artifact.implement', $snapshot['executions'][0]['operation']);
        self::assertSame('sha256:greeter-call', $snapshot['executions'][0]['argumentsDigest']);
        self::assertArrayNotHasKey('resultSummary', $snapshot['executions'][0], 'an execution does not borrow a call result');
        self::assertNull(
            $snapshot['executions'][0]['stillCurrent'],
            'the stream proves execution but declares no effect supersession contract',
        );
        self::assertTrue($snapshot['executions'][0]['coveredByCompaction']);
        self::assertSame('cache.refresh', $snapshot['executions'][1]['operation']);
        self::assertFalse(
            $snapshot['executions'][1]['coveredByCompaction'],
            'a non-turn fact after the turn cut has no other route into the model window',
        );
        self::assertSame('e-greeter', $snapshot['evidence'][0]['id']);
        self::assertSame('e-recent', $snapshot['evidence'][1]['id']);
        self::assertFalse($snapshot['evidence'][1]['coveredByCompaction']);
        self::assertSame('q-greeter', $snapshot['decisions'][0]['id']);
        self::assertSame('design', $snapshot['decisions'][0]['reason']);
        self::assertTrue($snapshot['decisions'][0]['coveredByCompaction']);

        self::assertSame(
            $snapshot,
            $store->facts('s1')->operationalFacts($after->compactedThrough),
            'the summary and narrow recovery query must read through the same projection',
        );

        $afterEvents = iterator_to_array($events->replay(SessionStore::PREFIX . 's1'));
        self::assertCount(count($before) + 1, $afterEvents, 'compaction appends only its own event');
        self::assertSame(SessionEvent::Compacted->value, $afterEvents[array_key_last($afterEvents)]->type);
        foreach ([SessionEvent::ToolCalled, SessionEvent::OperationExecuted, SessionEvent::EvidenceRecorded] as $type) {
            self::assertSame(
                count(array_filter($before, static fn ($event): bool => $event->type === $type->value)),
                count(array_filter($afterEvents, static fn ($event): bool => $event->type === $type->value)),
                sprintf('%s must not be re-run or re-recorded by compaction', $type->value),
            );
        }
    }
}
