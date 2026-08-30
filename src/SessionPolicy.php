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
 * Decide si una llamada del agente procede, hay que preguntar, o hace falta una firma (P16.5/P16.6).
 *
 * ── PURA, Y POR ESO DISCUTIBLE ──────────────────────────────────────────────────────────────────
 *
 * No llama a nadie, no apenda nada, no sabe qué es una herramienta ni un modelo: recibe la sesión y
 * tres hechos sobre la operación, y devuelve una de tres respuestas. Eso la vuelve una tabla de
 * verdad que alguien puede leer y objetar sin ejecutar nada — que es lo mínimo que se le puede pedir
 * a la pieza que decide hasta dónde puede llegar un proceso automático sobre el código de alguien.
 *
 * ── EL ORDEN DE LAS REGLAS NO ES CASUAL ─────────────────────────────────────────────────────────
 *
 * La firma se evalúa ANTES que el permiso otorgado y antes que el modo. Si se evaluara después, un
 * `grant` sobre esa operación —o un modo `auto`— la dejaría pasar, y ahí se habría perdido la única
 * compuerta que nombra la llamada concreta en vez de la categoría. Ese orden es la regla; lo demás
 * es conveniencia.
 */
final readonly class SessionPolicy
{
    /**
     * Qué hacer con esta llamada, dada la sesión en la que ocurre.
     *
     * @param bool $mutating          si la operación cambia algo. Lo declara ella misma; leer es
     *                                gratis porque un agente que no puede leer no puede decidir nada
     * @param bool $requiresSignature si la operación exige consentimiento firmado para ESTA llamada
     */
    public function decide(
        Session $session,
        string $operation,
        bool $mutating,
        bool $requiresSignature,
        ?AutonomyMode $ceiling = null,
        // EL PERFIL COMPUESTO DE ESTA LLAMADA, para juzgarla contra el SOBRE de un grant apretado
        // (greenhouse decisions/0067). Opcional porque un sí pelón (sobre null) admite sin mirarlo;
        // bajo un sobre, `null` significa «sin clasificar» y lo no clasificado nunca viaja en un
        // apretón. La policy es el ÚNICO juez: compara aquí, con el único comparador, y en ningún
        // otro lado — un segundo comparador en la compuerta sería un segundo juez.
        ?\Milpa\Command\Effect\EffectProfile $composed = null,
        // LA COMPOSICIÓN ENTERA, con sus reducciones, para el bypass de ENSAYO (greenhouse
        // decisions/0068, 0069): saber que un `Ephemeral` lo produjo un trial workspace —y no una
        // operación que escribe temporales— se lee de QUIÉN lo bajó, no del perfil efectivo solo. Un
        // `bool confinado` desde la compuerta haría de ella un segundo juez; la composición, no.
        ?\Milpa\Command\Effect\ProfileComposition $composition = null,
    ): PolicyDecision {
        // FALLA CERRADO si la sesión tiene padre y nadie trajo su techo.
        //
        // El parámetro es opcional porque una sesión raíz no tiene techo, no porque se pueda omitir
        // cuando lo hay: sin esta línea, un futuro camino de spawning que olvidara pasarlo le daría
        // al hijo la autonomía que declaró, y la escalada de privilegio volvería por descuido en vez
        // de por diseño. Un guardia que depende de que quien llama se acuerde no es un guardia.
        if ($session->parentId !== null && $ceiling === null) {
            throw new \LogicException(sprintf(
                'La sesión "%s" desciende de "%s", así que decidir sin su techo de autonomía dejaría '
                . 'que un hijo excediera a su padre. Pide el techo con SessionStore::ceilingFor().',
                $session->id,
                $session->parentId,
            ));
        }

        // PRIMERO la firma. Ningún permiso de sesión y ningún modo la desbloquean — ver el docblock:
        // moverla más abajo la volvería pre-aprobable, que es exactamente lo que no puede ser.
        if ($requiresSignature) {
            return PolicyDecision::RequireSignature;
        }

        // Reading is not asked about — HERE. But a read can still cross the perimeter: a query
        // handed to a third party is read-only locally and outbound to the world. Mutation and
        // externality are orthogonal axes ({@see \Milpa\Command\Effect\Externality} — the
        // dimension `mutating: bool` could never carry), and the gate governs the boundary crossings
        // relevant to the session mode, not mutation alone. The effective mode declares the egress
        // threshold it lets pass unattended; the policy compares, it does not hardcode a class. An
        // op with no composed profile reads as None here — nothing declared to leave.
        if (!$mutating) {
            $efectivo = $ceiling === null ? $session->mode : $session->mode->strictest($ceiling);
            $externality = \Milpa\Command\Effect\Externality::None;
            if ($composed !== null) {
                $externality = $composed->externality;
            }

            if (!$efectivo->pausesBeforeEgress($externality)) {
                return PolicyDecision::Allow;
            }

            // A grant already given THIS session admits the crossing — do not re-ask, exactly as the
            // mutation path honours a prior grant below. Without this line an authorised egress
            // re-pauses on every call and the agent loops, never executing (found by Rod: web:search
            // asked, granted, and asked again — four identical decisions, zero results).
            if ($session->allows($operation, $composed)) {
                return PolicyDecision::Allow;
            }

            return PolicyDecision::AskPermission;
        }

        // EL ENSAYO NO PIDE PERMISO — cuando sus efectos caben ENTEROS en el techo de ensayo Y están
        // confinados a un TrialWorkspace (greenhouse decisions/0068). No «los ensayos no piden
        // permiso»: `Ephemeral` solo no basta, porque `ephemeral` + `third_party` es mandar correos
        // desde una copia desechable — el filesystem es desechable, el correo no. Por eso el bypass
        // es `perfil efectivo ≤ techo de ensayo AND confinado`, y «confinado» se lee de la
        // COMPOSICIÓN (quién produjo el Ephemeral), nunca del perfil solo. Va DESPUÉS de la firma —
        // que no se salta— y ANTES de los permisos y del modo: el modo de sesión no es la perilla
        // (la UX no legisla riesgo); la misma operación en el mismo workspace tiene el mismo techo
        // esté la sesión en `ask` o en `auto`.
        if (
            $composition !== null
            && $composed !== null
            && $composition->confinedByTrial()
            && $composed->isNoWiderThan(self::trialCeiling())
        ) {
            return PolicyDecision::Allow;
        }

        if ($session->allows($operation, $composed)) {
            return PolicyDecision::Allow;
        }

        // El modo EFECTIVO, no el declarado: un hijo vale lo que valga el más restrictivo de su
        // linaje. La invariante es que los permisos sólo se restringen hacia abajo del árbol.
        $efectivo = $ceiling === null ? $session->mode : $session->mode->strictest($ceiling);

        return $efectivo->pausesBeforeMutation()
            ? PolicyDecision::AskPermission
            : PolicyDecision::Allow;
    }

    /**
     * El techo de ENSAYO: lo más que una llamada confinada puede ser sin pedir permiso (decisions/0069).
     *
     * Conservador a propósito, y nada que un humano haya clickeado: `mutation` a lo sumo Ephemeral
     * (lo que escribe muere con el workspace), `externality` EXACTAMENTE None (nada sale),
     * `authority` a lo sumo WriteAsUser (ningún ensayo privilegiado sin preguntar); reversibilidad y
     * sujeto sin restricción — la copia se descarta de todos modos. Se ajusta sólo por acta.
     */
    public static function trialCeiling(): \Milpa\Command\Effect\EffectProfile
    {
        return new \Milpa\Command\Effect\EffectProfile(
            \Milpa\Command\Effect\Mutation::Ephemeral,
            \Milpa\Command\Effect\Externality::None,
            \Milpa\Command\Effect\Reversibility::Unknown,
            \Milpa\Command\Effect\Authority::WriteAsUser,
            subject: \Milpa\Command\Effect\Subject::Unknown,
        );
    }

    /**
     * La pregunta que se le hace al humano cuando hay que pedir permiso.
     *
     * Trae los argumentos en `why` porque «¿autorizo `make`?» y «¿autorizo `make` sobre ESTE plugin
     * con ESTOS campos?» son preguntas distintas, y sólo la segunda se puede contestar. Las opciones
     * son dos: consentir la operación para el resto de la sesión, o negarla — «sólo esta vez» pide
     * que la sesión sepa que un permiso se gastó, y un permiso que se gasta necesita un evento que
     * registre su uso. Se agrega cuando haya quien lo pida, no antes.
     *
     * @param array<string, mixed> $arguments
     */
    public function permissionQuestion(
        string $operation,
        array $arguments,
        ?\DateTimeImmutable $expiresAt = null,
    ): PendingQuestion {
        $detalle = $arguments === []
            ? 'sin argumentos'
            : (json_encode($arguments, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: 'sin argumentos');

        return new PendingQuestion(
            id: 'perm:' . $operation,
            question: "El agente quiere correr «{$operation}». ¿Lo autorizas en esta sesión?",
            options: ['sí', 'no'],
            why: $detalle,
            // Sin plazo si nadie lo pone, y eso NO es un descuido: cuánto tiempo tiene un humano para
            // contestar es una decisión de producto, no un default que este paquete pueda inventar.
            // Lo que sí no podía pasar era que no se PUDIERA poner ({@see PendingQuestion}).
            expiresAt: $expiresAt?->format(\DateTimeInterface::ATOM),
            // El código que el docblock de `reason` anuncia. Anunciarlo sin emitirlo era la misma
            // clase de defecto que el «no calla» del juez con un NullLogger — lo cazó la revisión
            // adversaria de Q-P19-M comparando la promesa contra el código.
            reason: 'permission',
        );
    }

    /**
     * La pregunta cuando lo que falta es una firma.
     *
     * No ofrece «sí»: no hay nada que contestar aquí que autorice: la firma se produce con una llave
     * y se pasa a la operación. Ofrecer un «sí» sugeriría que el permiso se puede dar desde este
     * lado, y una compuerta que se puede complacer con un clic dejó de ser una compuerta.
     *
     * @param array<string, mixed> $arguments
     */
    public function signatureQuestion(string $operation, array $arguments): PendingQuestion
    {
        $detalle = $arguments === []
            ? 'sin argumentos'
            : (json_encode($arguments, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: 'sin argumentos');

        return new PendingQuestion(
            id: 'sign:' . $operation,
            question: "«{$operation}» exige una firma que nombre esta llamada. Córrela tú con --sign y "
                . 'retoma la sesión.',
            options: [],
            why: $detalle,
            reason: 'signature',
        );
    }
}
