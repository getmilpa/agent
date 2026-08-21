<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\PolicyDecision;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * EL GRANT LLEVA SOBRE (greenhouse decisions/0067).
 *
 * Hoy un permiso es un NOMBRE de operación, para toda la sesión, sin cota: una vez dado, la policy
 * deja pasar cualquier llamada de esa operación, compusiera lo que compusiera. Un humano que quiere
 * «sí, pero sólo si es reversible» no tenía dónde decirlo. Ahora `PermissionGranted` puede llevar un
 * SOBRE —un `EffectProfile`— y una llamada sólo queda admitida si su perfil COMPUESTO es no-más-ancho
 * que ese sobre en las cinco hachas.
 *
 * Y el `sí` pelón queda byte a byte como hoy: sobre `null` = sin cota dentro del techo declarado.
 * Nadie que no contraoferte ve un cambio; `permissions` sigue siendo la lista de nombres que el
 * resumidor, la TUI y `agent:show` ya leen.
 */
final class EnvelopeOnTheGrantTest extends TestCase
{
    private InMemoryEventStore $eventos;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
    }

    private function store(): SessionStore
    {
        return new SessionStore($this->eventos);
    }

    /** The operation's DECLARED ceiling — what a plain «sí» grants, op-wide. */
    private function techo(): EffectProfile
    {
        return new EffectProfile(
            Mutation::Persistent,
            Externality::SamePrincipal,
            Reversibility::ManualRecovery,
            Authority::WriteAsUser,
            subject: Subject::Configuration,
        );
    }

    /** A tightened envelope: «only if compensatable». */
    private function sobre(): EffectProfile
    {
        return $this->techo()->meet(EffectProfile::fromPartial(['reversibility' => 'compensatable']));
    }

    /** A call that composed DOWN to compensatable (e.g. via a certified descent) — fits the envelope. */
    private function llamadaQueCabe(): EffectProfile
    {
        return $this->sobre();
    }

    /** A call that composed at the ceiling (no descent) — does NOT fit the tightened envelope. */
    private function llamadaQueNoCabe(): EffectProfile
    {
        return $this->techo();
    }

    // ── el sí pelón: idéntico a hoy ──────────────────────────────────────────────────────────────

    public function testAPlainGrantAdmitsEveryCompositionAsToday(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set');

        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);
        self::assertSame(['config:set'], $sesion->permissions, 'the list of names is untouched — consumers keep reading it');
        self::assertTrue($sesion->allows('config:set'));
        self::assertTrue($sesion->allows('config:set', $this->llamadaQueNoCabe()), 'null envelope = unbounded within the ceiling');
        self::assertTrue($sesion->allows('config:set', null));
    }

    /** A stream written before envelopes existed replays exactly like a plain sí. */
    public function testALegacyGrantEventWithoutAnEnvelopeKeyIsAPlainGrant(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $this->eventos->append(new Event(SessionStore::PREFIX . 's', 'session.permission_granted', ['operation' => 'config:set'], $this->eventos->nextSeq()));

        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->allows('config:set', $this->llamadaQueNoCabe()));
    }

    public function testAPlainGrantWritesNoEnvelope(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set');

        $granted = $this->eventosDe('session.permission_granted');
        self::assertCount(1, $granted);
        self::assertNull($granted[0]->payload['envelope'] ?? null, 'a plain sí carries no envelope');
    }

    // ── el sobre: admite lo que cabe, refusa lo que no ──────────────────────────────────────────

    public function testAnEnvelopedGrantAdmitsOnlyCompositionsNoWiderThanTheEnvelope(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set', $this->sobre()->toArray());

        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);
        self::assertSame(['config:set'], $sesion->permissions, 'the name is still listed: it IS granted, within a bound');
        self::assertTrue($sesion->allows('config:set', $this->llamadaQueCabe()), 'a call within the envelope is admitted');
        self::assertFalse($sesion->allows('config:set', $this->llamadaQueNoCabe()), 'a call that composes above the envelope is NOT');
    }

    /** Unclassified never rides a tightening: no composition, no admission under an enveloped grant. */
    public function testWithoutACompositionAnEnvelopedGrantDoesNotAdmit(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set', $this->sobre()->toArray());

        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);
        self::assertFalse($sesion->allows('config:set', null));
        self::assertFalse($sesion->allows('config:set'));
    }

    /** A plain sí beside an envelope admits as a plain sí — a tightening cannot narrow a sí already given. */
    public function testAPlainGrantBesideAnEnvelopeStillAdmitsEverything(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set', $this->sobre()->toArray());
        $almacen->grant('s', 'config:set');

        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);
        self::assertTrue($sesion->allows('config:set', $this->llamadaQueNoCabe()));
    }

    public function testRevokingRemovesEnvelopedAndPlainGrantsAlike(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set', $this->sobre()->toArray());
        $almacen->grant('s', 'config:set');
        $almacen->revoke('s', 'config:set');

        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);
        self::assertSame([], $sesion->permissions);
        self::assertFalse($sesion->allows('config:set', $this->llamadaQueCabe()));
        self::assertFalse($sesion->allows('config:set'));
    }

    /** The tightening is a FACT on the stream: who, to what, from which base, over which call. */
    public function testAnEnvelopedGrantRecordsItsProvenance(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set', $this->sobre()->toArray(), [
            'base' => $this->techo()->toArray(),
            'requested' => ['reversibility' => 'compensatable'],
            'question' => 'perm:config:set',
            'arguments_digest' => 'sha256:abc',
            'by' => ['id' => 'rod', 'verified' => true],
        ]);

        $p = $this->eventosDe('session.permission_granted')[0]->payload;
        self::assertSame('config:set', $p['operation']);
        self::assertSame('compensatable', $p['envelope']['reversibility'] ?? null);
        self::assertSame('manual_recovery', $p['base']['reversibility'] ?? null, 'the base it was meet-ed against');
        self::assertSame(['reversibility' => 'compensatable'], $p['requested'], 'what the human asked, for the delta audit');
        self::assertSame('perm:config:set', $p['question']);
        self::assertSame('sha256:abc', $p['arguments_digest']);
        self::assertSame('rod', $p['by']['id'] ?? null);
    }

    // ── la policy es el único juez ──────────────────────────────────────────────────────────────

    public function testThePolicyAdmitsACallThatFitsTheEnvelopeAndAsksForOneThatDoesNot(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');   // ask mode by default
        $almacen->grant('s', 'config:set', $this->sobre()->toArray());
        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);

        $policy = new SessionPolicy();

        self::assertSame(
            PolicyDecision::Allow,
            $policy->decide($sesion, 'config:set', true, false, null, $this->llamadaQueCabe()),
            'within the envelope: admitted, the same call runs without re-proposal',
        );
        self::assertSame(
            PolicyDecision::AskPermission,
            $policy->decide($sesion, 'config:set', true, false, null, $this->llamadaQueNoCabe()),
            'above the envelope: not covered, falls through to the mode — ask pauses with a fresh question',
        );
        self::assertSame(
            PolicyDecision::AskPermission,
            $policy->decide($sesion, 'config:set', true, false, null, null),
            'no composition: unclassified never rides a tightening',
        );
    }

    public function testThePolicyStillAdmitsAPlainGrantWhateverTheComposition(): void
    {
        $almacen = $this->store();
        $almacen->start('s', 'x');
        $almacen->grant('s', 'config:set');
        $sesion = $almacen->load('s');
        self::assertNotNull($sesion);

        $policy = new SessionPolicy();
        self::assertSame(PolicyDecision::Allow, $policy->decide($sesion, 'config:set', true, false, null, $this->llamadaQueNoCabe()));
        self::assertSame(PolicyDecision::Allow, $policy->decide($sesion, 'config:set', true, false, null, null));
        self::assertSame(PolicyDecision::Allow, $policy->decide($sesion, 'config:set', true, false), 'the old signature still works');
    }

    /** @return list<Event> */
    private function eventosDe(string $tipo): array
    {
        return array_values(array_filter(
            $this->eventos->replay(SessionStore::PREFIX . 's'),
            static fn (Event $e): bool => $e->type === $tipo,
        ));
    }
}
