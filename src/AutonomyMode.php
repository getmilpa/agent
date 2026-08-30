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

/**
 * Hasta dónde puede llegar la sesión sin levantar la mano.
 *
 * ── LO QUE NINGÚN MODO PUEDE HACER ──────────────────────────────────────────────────────────────
 *
 * Saltarse una firma. Una operación que declara `requiresConfirmation` exige un consentimiento que
 * NOMBRA esa llamada concreta —con sus argumentos— y ningún modo puede pre-aprobarla, porque
 * pre-aprobar «lo que el agente decida» es firmar un cheque en blanco. `Auto` significa «no me
 * preguntes por lo reversible», nunca «no me preguntes».
 *
 * Esa es la línea entre autonomía y abdicación, y está aquí y no en una configuración porque una
 * línea que se puede mover con una variable de entorno no es una línea.
 */
enum AutonomyMode: string
{
    /**
     * Pregunta antes de cualquier operación que mute.
     *
     * El default, y no por timidez: una sesión que empieza pidiendo permiso enseña qué va a hacer
     * antes de hacerlo, y quien la mira decide subir el modo cuando ya vio de qué se trata.
     */
    case Ask = 'ask';

    /**
     * Avisa y sigue.
     *
     * Para cuando alguien está mirando: el aviso sirve para interrumpir, no para autorizar. Lo que
     * exige firma se sigue deteniendo.
     */
    case Acknowledge = 'acknowledge';

    /**
     * Sigue hasta terminar, o hasta toparse con algo que exige firma.
     *
     * El modo de una jornada larga sin nadie enfrente. Todo queda apendado, que es lo que hace que
     * «corrió cuarenta pasos solo» sea una afirmación verificable y no una esperanza.
     */
    case Auto = 'auto';

    /**
     * El más restrictivo de dos modos.
     *
     * Existe porque un sub-agente **no puede exceder a quien lo invocó**: un hijo declarado `auto`
     * bajo un padre en `ask` sería una escalada de privilegio, y ninguna firma consiente «lo que el
     * hijo decida». La invariante 2 de la spec de sub-agentes dice esto mismo: los permisos sólo se
     * restringen hacia abajo del árbol, jamás se amplían.
     *
     * El orden es de permisividad —`ask` < `acknowledge` < `auto`— y va escrito aquí y no inferido
     * del orden de los `case`: reordenar un enum no puede cambiar quién manda.
     */
    public function strictest(self $otro): self
    {
        $orden = [self::Ask->value => 0, self::Acknowledge->value => 1, self::Auto->value => 2];

        return $orden[$this->value] <= $orden[$otro->value] ? $this : $otro;
    }

    /** Si este modo tiene que detenerse ante una operación que muta y no exige firma. */
    public function pausesBeforeMutation(): bool
    {
        return $this === self::Ask;
    }

    /**
     * Si una llamada que exige firma se detiene bajo este modo: SIEMPRE.
     *
     * Es un método y no una constante para que se pueda leer desde el código que decide, en vez de
     * que cada llamador recuerde la regla. Una regla que hay que recordar es una regla que un día se
     * olvida.
     */
    public function pausesBeforeSignature(): bool
    {
        return true;
    }

    /**
     * The highest externality a NON-mutating call may carry and still pass unattended under this
     * mode; strictly above it, the gate pauses. Declared here, not inferred — the policy compares
     * against this and does not hardcode a class. Only the strict, human-present mode (Ask) gates
     * egress, parallel to {@see pausesBeforeMutation()}; the looser modes do not (Acknowledge
     * notifies-and-continues, Auto runs). Ask stops at SamePrincipal, so a call reaching a third
     * party or the public pauses while one staying inside the principal's own systems passes.
     */
    public function egressThreshold(): ?\Milpa\Command\Effect\Externality
    {
        return $this === self::Ask ? \Milpa\Command\Effect\Externality::SamePrincipal : null;
    }

    /**
     * Whether a NON-mutating call of externality $externality must pause before it crosses the
     * perimeter under this mode. Egress is ORTHOGONAL to mutation: a read is read-only here yet may
     * still leave the perimeter (a query handed to a third party). True only when $externality is
     * strictly above this mode's {@see egressThreshold()} on the perimeter order
     * None < SamePrincipal < ThirdParty < Public. Unknown is off that axis — an unclassified read is
     * not egress-gated here (fail-closed on the unclassified is a separate decision); the mutation
     * and signature gates still cover it.
     */
    public function pausesBeforeEgress(\Milpa\Command\Effect\Externality $externality): bool
    {
        $threshold = $this->egressThreshold();
        if ($threshold === null) {
            return false;
        }

        $rank = static fn (\Milpa\Command\Effect\Externality $e): ?int => match ($e) {
            \Milpa\Command\Effect\Externality::None => 0,
            \Milpa\Command\Effect\Externality::SamePrincipal => 1,
            \Milpa\Command\Effect\Externality::ThirdParty => 2,
            \Milpa\Command\Effect\Externality::Public => 3,
            \Milpa\Command\Effect\Externality::Unknown => null,
        };
        $mine = $rank($externality);
        $bar = $rank($threshold);

        return $mine !== null && $bar !== null && $mine > $bar;
    }
}
