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

        // Leer no se pregunta. Un agente al que hay que autorizarle cada consulta no es un agente
        // supervisado, es uno inservible — y la supervisión se gasta en lo que no importa.
        if (!$mutating) {
            return PolicyDecision::Allow;
        }

        if ($session->allows($operation)) {
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
            question: "El agente quiere correr «{$operation}», que cambia algo. ¿Lo autorizas en esta sesión?",
            options: ['sí', 'no'],
            why: $detalle,
            // Sin plazo si nadie lo pone, y eso NO es un descuido: cuánto tiempo tiene un humano para
            // contestar es una decisión de producto, no un default que este paquete pueda inventar.
            // Lo que sí no podía pasar era que no se PUDIERA poner ({@see PendingQuestion}).
            expiresAt: $expiresAt?->format(\DateTimeInterface::ATOM),
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
        );
    }
}
