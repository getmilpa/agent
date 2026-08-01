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
 * Qué se hace con una llamada que el agente quiere hacer.
 *
 * Tres respuestas y no dos, porque «pedir permiso» y «pedir una firma» son cosas distintas y
 * confundirlas cuesta en las dos direcciones: tratar una firma como un permiso la vuelve
 * pre-aprobable, y tratar un permiso como una firma convierte cada `make` en un trámite de llaves.
 */
enum PolicyDecision
{
    /** Adelante: o no muta, o esta sesión ya lo consintió, o el modo no pregunta por esto. */
    case Allow;

    /**
     * Alto, y pregúntale al humano si autoriza esta operación en esta sesión.
     *
     * Lo que se responde es «sí a ESTA operación, en ESTA sesión» — una frase que alguien puede
     * evaluar. Queda apendada, porque un permiso sin registro es indistinguible de uno que nadie dio.
     */
    case AskPermission;

    /**
     * Alto, y esto no lo desbloquea ningún permiso de sesión: necesita una firma.
     *
     * Una firma NOMBRA la llamada concreta con sus argumentos, y se produce con una llave que vive
     * fuera de la sesión. Por eso ningún modo la pre-aprueba: hacerlo sería firmar un cheque en
     * blanco a nombre de lo que el agente decida.
     */
    case RequireSignature;
}
