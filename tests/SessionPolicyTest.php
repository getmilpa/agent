<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Agent\PolicyDecision;
use Milpa\Agent\Session;
use Milpa\Agent\SessionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * La tabla de verdad de hasta dónde puede llegar una sesión sin levantar la mano (P16.5/P16.6).
 *
 * Es la pieza que decide si un proceso automático puede cambiar el código de alguien, así que está
 * escrita para poder objetarla leyéndola: no llama a nadie, no apenda nada, y cada caso de abajo es
 * una fila de esa tabla.
 */
final class SessionPolicyTest extends TestCase
{
    private function sesion(AutonomyMode $modo, string ...$permisos): Session
    {
        return new Session(id: 's1', goal: 'x', mode: $modo, permissions: array_values($permisos));
    }

    /**
     * LEER NO SE PREGUNTA, en ningún modo.
     *
     * Un agente al que hay que autorizarle cada consulta no es un agente supervisado, es uno
     * inservible — y la atención del humano se gasta en lo que no importa, que es como se llega a
     * aprobar sin leer.
     */
    public function testReadingIsNeverAsked(): void
    {
        $politica = new SessionPolicy();

        foreach ([AutonomyMode::Ask, AutonomyMode::Acknowledge, AutonomyMode::Auto] as $modo) {
            self::assertSame(
                PolicyDecision::Allow,
                $politica->decide($this->sesion($modo), 'plugins_list', mutating: false, requiresSignature: false),
                "en modo {$modo->value} leer tendría que pasar",
            );
        }
    }

    /** En `ask`, lo que muta se pregunta. */
    public function testInAskModeAnythingThatMutatesIsAsked(): void
    {
        $decision = (new SessionPolicy())->decide(
            $this->sesion(AutonomyMode::Ask),
            'make',
            mutating: true,
            requiresSignature: false,
        );

        self::assertSame(PolicyDecision::AskPermission, $decision);
    }

    /** Y deja de preguntarse en cuanto esta sesión lo consintió. */
    public function testAGrantedOperationStopsBeingAsked(): void
    {
        $decision = (new SessionPolicy())->decide(
            $this->sesion(AutonomyMode::Ask, 'make'),
            'make',
            mutating: true,
            requiresSignature: false,
        );

        self::assertSame(PolicyDecision::Allow, $decision);
    }

    /** El permiso es por OPERACIÓN: consentir `make` no consiente `plugins_disable`. */
    public function testAGrantIsPerOperationAndNotABlanketYes(): void
    {
        $decision = (new SessionPolicy())->decide(
            $this->sesion(AutonomyMode::Ask, 'make'),
            'plugins_disable',
            mutating: true,
            requiresSignature: false,
        );

        self::assertSame(PolicyDecision::AskPermission, $decision);
    }

    /** En `acknowledge` y `auto`, lo que muta y no exige firma sigue de largo. */
    public function testAcknowledgeAndAutoDoNotPauseBeforeAMutation(): void
    {
        $politica = new SessionPolicy();

        foreach ([AutonomyMode::Acknowledge, AutonomyMode::Auto] as $modo) {
            self::assertSame(
                PolicyDecision::Allow,
                $politica->decide($this->sesion($modo), 'make', mutating: true, requiresSignature: false),
                "en modo {$modo->value} una mutación no debería detenerse",
            );
        }
    }

    /**
     * LA FIRMA NO LA DESBLOQUEA NADIE — ni el modo, ni un permiso otorgado.
     *
     * Es la prueba que justifica el orden de las reglas. Si la firma se evaluara después del permiso
     * o del modo, un `grant` sobre esa operación —o un `auto`— la dejaría pasar, y ahí se habría
     * perdido la única compuerta que nombra la llamada concreta en vez de la categoría. Esta prueba
     * es lo que hace que reordenar esas líneas deje de ser un cambio inocente.
     */
    public function testNoModeAndNoGrantCanPreApproveASignature(): void
    {
        $politica = new SessionPolicy();

        foreach ([AutonomyMode::Ask, AutonomyMode::Acknowledge, AutonomyMode::Auto] as $modo) {
            self::assertSame(
                PolicyDecision::RequireSignature,
                $politica->decide(
                    // Con el permiso YA otorgado, que es el caso que más fácil se cuela.
                    $this->sesion($modo, 'plugins_remove'),
                    'plugins_remove',
                    mutating: true,
                    requiresSignature: true,
                ),
                "en modo {$modo->value} una firma no puede estar pre-aprobada",
            );
        }
    }

    /**
     * La pregunta de permiso trae los ARGUMENTOS.
     *
     * «¿autorizo make?» y «¿autorizo make sobre este plugin con estos campos?» son preguntas
     * distintas, y sólo la segunda se puede contestar.
     */
    public function testThePermissionQuestionCarriesTheArguments(): void
    {
        $pregunta = (new SessionPolicy())->permissionQuestion('make', ['what' => 'entity', 'plugin' => 'Inventario']);

        self::assertSame('perm:make', $pregunta->id);
        self::assertSame(['sí', 'no'], $pregunta->options);
        self::assertStringContainsString('make', $pregunta->question);
        self::assertStringContainsString('Inventario', (string) $pregunta->why);
    }

    /**
     * La de firma NO ofrece un «sí».
     *
     * No hay nada que contestar desde aquí que autorice: la firma se produce con una llave que vive
     * fuera de la sesión. Un «sí» sugeriría que el permiso se puede dar de este lado, y una compuerta
     * que se complace con un clic dejó de ser una compuerta.
     */
    public function testTheSignatureQuestionOffersNoYes(): void
    {
        $pregunta = (new SessionPolicy())->signatureQuestion('plugins_remove', ['name' => 'X']);

        self::assertSame([], $pregunta->options, 'no hay opción que autorice');
        self::assertStringContainsString('--sign', $pregunta->question, 'dice dónde SÍ se puede');
    }

    /** Sin argumentos se dice, en vez de dejar un hueco que parezca un dato faltante. */
    public function testNoArgumentsIsSaidOutLoud(): void
    {
        $pregunta = (new SessionPolicy())->permissionQuestion('plugins_lock', []);

        self::assertSame('sin argumentos', $pregunta->why);
    }

    private function outboundRead(): EffectProfile
    {
        return new EffectProfile(Mutation::None, Externality::ThirdParty, Reversibility::Guaranteed, Authority::Read, rollbackContract: 'nothing to roll back');
    }

    private function localRead(): EffectProfile
    {
        return new EffectProfile(Mutation::None, Externality::None, Reversibility::Guaranteed, Authority::Read, rollbackContract: 'nothing to roll back');
    }

    /** A read that leaves the perimeter (ThirdParty) pauses in ask mode, even though it mutates nothing here. */
    public function testAnOutboundReadPausesInAskMode(): void
    {
        self::assertSame(
            PolicyDecision::AskPermission,
            (new SessionPolicy())->decide($this->sesion(AutonomyMode::Ask), 'web:search', false, false, null, $this->outboundRead()),
        );
    }

    /** A read that stays inside the perimeter still passes free — reading is not asked about. */
    public function testALocalReadStillPassesFree(): void
    {
        self::assertSame(
            PolicyDecision::Allow,
            (new SessionPolicy())->decide($this->sesion(AutonomyMode::Ask), 'read:file', false, false, null, $this->localRead()),
        );
    }

    /** An egress already granted this session is admitted without re-asking — no loop. */
    public function testAnAuthorisedOutboundReadDoesNotReAsk(): void
    {
        self::assertSame(
            PolicyDecision::Allow,
            (new SessionPolicy())->decide($this->sesion(AutonomyMode::Ask, 'web:search'), 'web:search', false, false, null, $this->outboundRead()),
        );
    }

    /** The looser modes do not gate egress — only the strict, human-present mode does. */
    public function testLooserModesDoNotGateEgress(): void
    {
        foreach ([AutonomyMode::Acknowledge, AutonomyMode::Auto] as $modo) {
            self::assertSame(
                PolicyDecision::Allow,
                (new SessionPolicy())->decide($this->sesion($modo), 'web:search', false, false, null, $this->outboundRead()),
                "mode {$modo->value} should not pause on egress",
            );
        }
    }
}
