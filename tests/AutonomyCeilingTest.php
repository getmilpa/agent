<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PolicyDecision;
use Milpa\Agent\Session;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El techo de autonomía: un hijo NO puede exceder a quien lo invocó (§5.3 de la spec de sub-agentes).
 *
 * Antes de esto, `decide()` recibía UNA sesión y consultaba su modo, sin noción de padre: un hijo
 * declarado `auto` bajo un padre en `ask` decidía solo, y nada lo impedía. Es el único punto de esa
 * spec que era un defecto de seguridad y no una capacidad faltante.
 */
#[CoversClass(SessionPolicy::class)]
#[CoversClass(AutonomyMode::class)]
#[CoversClass(SessionStore::class)]
final class AutonomyCeilingTest extends TestCase
{
    private SessionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SessionPolicy();
    }

    private function hija(AutonomyMode $modo): Session
    {
        return new Session(id: 'hija', goal: 'lo que sea', parentId: 'padre', mode: $modo);
    }

    public function testUnHijoEnAutoBajoUnPadreEnAskSePara(): void
    {
        // EL AGUJERO. Sin techo, esta llamada devolvía Allow y el hijo mutaba sin preguntarle a nadie.
        $decision = $this->policy->decide(
            $this->hija(AutonomyMode::Auto),
            'archivos.escribir',
            mutating: true,
            requiresConfirmation: false,
            ceiling: AutonomyMode::Ask,
        );

        self::assertSame(PolicyDecision::AskPermission, $decision);
    }

    public function testUnHijoMasRestrictivoQueSuPadreConservaSuPropiaRestriccion(): void
    {
        // El techo ACOTA, no nivela: un hijo que declaró `ask` no se vuelve `auto` porque el padre
        // pueda. Los permisos sólo se restringen hacia abajo.
        $decision = $this->policy->decide(
            $this->hija(AutonomyMode::Ask),
            'archivos.escribir',
            mutating: true,
            requiresConfirmation: false,
            ceiling: AutonomyMode::Auto,
        );

        self::assertSame(PolicyDecision::AskPermission, $decision);
    }

    public function testUnHijoEnAutoBajoUnPadreEnAutoSigue(): void
    {
        // El control negativo: acotar no puede volver al sistema incapaz de correr solo, o el techo
        // habría cambiado la política en vez de cerrar la fuga (ADR-0029).
        $decision = $this->policy->decide(
            $this->hija(AutonomyMode::Auto),
            'archivos.escribir',
            mutating: true,
            requiresConfirmation: false,
            ceiling: AutonomyMode::Auto,
        );

        self::assertSame(PolicyDecision::Allow, $decision);
    }

    public function testDecidirSobreUnHijoSinSuTechoFallaCerrado(): void
    {
        // Un guardia que depende de que quien llama se acuerde no es un guardia. Sin esto, un futuro
        // camino de spawning que olvidara pasar el techo reabriría la escalada por descuido.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/techo de autonomía/');

        $this->policy->decide($this->hija(AutonomyMode::Auto), 'archivos.escribir', true, false);
    }

    public function testUnaSesionRaizNoNecesitaTecho(): void
    {
        $raiz = new Session(id: 'raiz', goal: 'lo que sea', mode: AutonomyMode::Auto);

        self::assertSame(
            PolicyDecision::Allow,
            $this->policy->decide($raiz, 'archivos.escribir', true, false),
        );
    }

    public function testLaConfirmacionDeclaradaSigueMandandoSobreCualquierTecho(): void
    {
        // Ningún modo y ningún techo saltan una confirmación DECLARADA no otorgada, y ninguno la IMPONE
        // donde no la había: el techo acota la autonomía, no reescribe qué exige consentimiento
        // (greenhouse decisions/0177, elección B: confirmación de sesión, no firma).
        self::assertSame(
            PolicyDecision::AskPermission,
            $this->policy->decide($this->hija(AutonomyMode::Auto), 'liberar', true, true, AutonomyMode::Auto),
        );
    }

    public function testLeerNuncaSePreguntaAunqueElTechoSeaElMasEstricto(): void
    {
        self::assertSame(
            PolicyDecision::Allow,
            $this->policy->decide($this->hija(AutonomyMode::Auto), 'plugins.listar', false, false, AutonomyMode::Ask),
        );
    }

    /** El orden de permisividad, escrito y no inferido del orden de los `case`. */
    public function testElMasRestrictivoDeDosModos(): void
    {
        self::assertSame(AutonomyMode::Ask, AutonomyMode::Auto->strictest(AutonomyMode::Ask));
        self::assertSame(AutonomyMode::Ask, AutonomyMode::Ask->strictest(AutonomyMode::Auto));
        self::assertSame(AutonomyMode::Acknowledge, AutonomyMode::Auto->strictest(AutonomyMode::Acknowledge));
        self::assertSame(AutonomyMode::Auto, AutonomyMode::Auto->strictest(AutonomyMode::Auto));
    }
    // ---- el techo resuelto desde el LINAJE, no copiado al nacer -------------------------------

    public function testElTechoLoImponeElLinajeYNoSeCopiaAlNacer(): void
    {
        // La razón de resolverlo al preguntar: un modo copiado en el evento de apertura se queda
        // viejo en cuanto el padre baja el suyo, y el hijo seguiría en `auto` bajo un padre que ya
        // volvió a `ask`. Un permiso heredado que no sigue a su origen es una declaración rancia.
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'dirigir', AutonomyMode::Auto);
        $almacen->start('hija', 'obedecer', AutonomyMode::Auto, parentId: 'padre');

        self::assertSame(AutonomyMode::Auto, $almacen->ceilingFor('hija'), 'al nacer, el padre podía todo');

        $almacen->setMode('padre', AutonomyMode::Ask);

        self::assertSame(AutonomyMode::Ask, $almacen->ceilingFor('hija'), 'el techo BAJA con el padre');
    }

    public function testElTechoEsElMasRestrictivoDeTodaLaCadenaYNoElDelPadreInmediato(): void
    {
        // Si el abuelo está en `ask`, no hay padre intermedio que pueda devolverle `auto` al nieto.
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('abuelo', 'raíz', AutonomyMode::Ask);
        $almacen->start('padre', 'medio', AutonomyMode::Auto, parentId: 'abuelo');
        $almacen->start('nieto', 'hoja', AutonomyMode::Auto, parentId: 'padre');

        self::assertSame(AutonomyMode::Ask, $almacen->ceilingFor('nieto'));
    }

    public function testUnaSesionRaizNoTieneTecho(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('raiz', 'sola', AutonomyMode::Auto);

        self::assertNull($almacen->ceilingFor('raiz'));
    }

    public function testUnaCadenaRotaSeTrataComoElTechoMasRestrictivo(): void
    {
        // No poder comprobar de quién desciende alguien NO es permiso para asumir que puede todo.
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('huerfana', 'sin padre vivo', AutonomyMode::Auto, parentId: 'fantasma');

        self::assertSame(AutonomyMode::Ask, $almacen->ceilingFor('huerfana'));
    }

    public function testLaFiliacionSobreviveAlReproducirElStream(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('padre', 'dirigir', AutonomyMode::Auto);
        $almacen->start('hija', 'obedecer', AutonomyMode::Ask, parentId: 'padre');

        self::assertSame('padre', $almacen->load('hija')?->parentId);
        self::assertNull($almacen->load('padre')?->parentId);
    }
}
