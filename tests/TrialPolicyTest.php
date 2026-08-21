<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\PolicyDecision;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\AxisReduction;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\ProfileComposition;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * EL ENSAYO NO PIDE PERMISO cuando sus efectos caben enteros en el techo de ensayo Y están confinados
 * (greenhouse decisions/0068, 0069). No «los ensayos no piden permiso»: `Ephemeral` solo no basta —
 * `mutation: ephemeral` + `externality: third_party` es mandar correos desde una copia desechable. El
 * bypass es `perfil efectivo ≤ techo de ensayo AND confinado`, leído de la COMPOSICIÓN (quién produjo el
 * Ephemeral), y la firma nunca se salta. El modo de sesión no es la perilla.
 */
final class TrialPolicyTest extends TestCase
{
    private InMemoryEventStore $eventos;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
    }

    private function sesionEnAsk(): \Milpa\Agent\Session
    {
        $almacen = new SessionStore($this->eventos);
        $almacen->start('s', 'x');   // ask mode by default, nothing granted
        $s = $almacen->load('s');
        self::assertNotNull($s);

        return $s;
    }

    /** A composition whose Ephemeral came from a trial workspace. */
    private function confinada(EffectProfile $effective): ProfileComposition
    {
        return new ProfileComposition($effective, [
            new AxisReduction('mutation', 'persistent', 'ephemeral', 'trial-workspace', 'trial:t1 args:sha256:a bounds:{fs:ro-root+rw-copy,net:unshared,pid:unshared}'),
        ]);
    }

    private function dentroDelTecho(): EffectProfile
    {
        return new EffectProfile(Mutation::Ephemeral, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Configuration);
    }

    // ── control positivo ────────────────────────────────────────────────────────────────────────

    public function testAConfinedCallWithinTheTrialCeilingRunsWithoutAsking(): void
    {
        $p = new SessionPolicy();
        $c = $this->confinada($this->dentroDelTecho());

        self::assertSame(
            PolicyDecision::Allow,
            $p->decide($this->sesionEnAsk(), 'probe', true, false, null, $c->effective, $c),
            'ask mode, nothing granted — and still Allow: the trial is a producer of evidence, not a cheap run',
        );
    }

    // ── los negativos asesinos de 0068 ──────────────────────────────────────────────────────────

    /** EL PRIMERO: Ephemeral + confinada + third_party NO corre libre. «Copia» no es «seguro». */
    public function testAConfinedThirdPartyCallStillAsks(): void
    {
        $p = new SessionPolicy();
        $c = $this->confinada(new EffectProfile(Mutation::Ephemeral, Externality::ThirdParty, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Executable));

        self::assertSame(PolicyDecision::AskPermission, $p->decide($this->sesionEnAsk(), 'capabilities:enable', true, false, null, $c->effective, $c), 'the filesystem is disposable; the email is not');
    }

    /** Ephemeral ALONE is not enough: an op that merely writes temp files is not confined to anything. */
    public function testAnEphemeralCallThatIsNotConfinedStillAsks(): void
    {
        $p = new SessionPolicy();
        $noConfinada = new ProfileComposition($this->dentroDelTecho(), []);

        self::assertSame(PolicyDecision::AskPermission, $p->decide($this->sesionEnAsk(), 'tmp:write', true, false, null, $noConfinada->effective, $noConfinada));
        self::assertSame(PolicyDecision::AskPermission, $p->decide($this->sesionEnAsk(), 'tmp:write', true, false, null, $this->dentroDelTecho(), null), 'without the composition the policy cannot know who produced the Ephemeral');
    }

    /** Privileged authority is not spent freely in a trial: it exceeds the trial ceiling. */
    public function testAConfinedPrivilegedCallStillAsks(): void
    {
        $p = new SessionPolicy();
        $c = $this->confinada(new EffectProfile(Mutation::Ephemeral, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Configuration));

        self::assertSame(PolicyDecision::AskPermission, $p->decide($this->sesionEnAsk(), 'foundation:found', true, false, null, $c->effective, $c));
    }

    /** A signature requirement is never bypassed — it sits above every other decision. */
    public function testASignatureRequirementIsNeverBypassedByATrial(): void
    {
        $p = new SessionPolicy();
        $c = $this->confinada($this->dentroDelTecho());

        self::assertSame(PolicyDecision::RequireSignature, $p->decide($this->sesionEnAsk(), 'probe', true, true, null, $c->effective, $c));
    }

    /** The trial ceiling is what the acta says — conservative, and nothing a human clicked. */
    public function testTheTrialCeilingIsTheDeclaredOne(): void
    {
        $t = SessionPolicy::trialCeiling();

        self::assertSame(Mutation::Ephemeral, $t->mutation);
        self::assertSame(Externality::None, $t->externality, 'exactly None: nothing leaves');
        self::assertSame(Authority::WriteAsUser, $t->authority, 'no Privileged trial without asking');
        self::assertSame(Reversibility::Unknown, $t->reversibility, 'unconstrained: the copy is discarded anyway');
        self::assertSame(Subject::Unknown, $t->subject);
    }

    // ── los hechos del ensayo en el stream ──────────────────────────────────────────────────────

    public function testATrialRunIsRecordedAsAFactAndTheSessionStillLoads(): void
    {
        $almacen = new SessionStore($this->eventos);
        $almacen->start('s', 'x');
        $almacen->recordTrialRun('s', [
            'workspace' => 't1', 'operation' => 'config:set', 'arguments_digest' => 'sha256:a',
            'bounds' => ['fs' => 'ro-root+rw-copy', 'net' => 'unshared', 'pid' => 'unshared'],
            'exit' => 0, 'report' => ['.milpa/agent.json' => 'sha256:b'], 'output_digest' => 'sha256:c',
        ]);
        $almacen->recordTrialPromotion('s', ['workspace' => 't1', 'paths' => ['.milpa/agent.json'], 'diff_digest' => 'sha256:d', 'by' => ['id' => 'rod']]);
        $almacen->recordTrialDiscard('s', ['workspace' => 't1']);

        $tipos = array_map(static fn (Event $e): string => $e->type, $this->eventos->replay(SessionStore::PREFIX . 's'));
        self::assertContains('session.trial_run_recorded', $tipos);
        self::assertContains('session.trial_promoted', $tipos);
        self::assertContains('session.trial_discarded', $tipos);

        $s = $almacen->load('s');
        self::assertNotNull($s, 'the reducer takes the new facts as no-ops in the fold');
        self::assertTrue($s->isRunnable());
    }

    public function testATrialRunWithoutAWorkspaceIsACallerError(): void
    {
        $almacen = new SessionStore($this->eventos);
        $almacen->start('s', 'x');

        $this->expectException(\InvalidArgumentException::class);
        $almacen->recordTrialRun('s', ['operation' => 'config:set']);
    }
}
