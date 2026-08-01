<?php

/**
 * This file is part of Milpa Agent — the session substrate of the Milpa PHP framework.
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
 * Qué pasó con una tarjeta que seguía abierta cuando la sesión terminó.
 *
 * ── POR QUÉ NO ES `TodoStatus` ──────────────────────────────────────────────────────────────────
 *
 * Porque son dos ejes distintos y fundirlos perdería los dos. `TodoStatus` dice **en qué columna está
 * la tarjeta** —`pending`, `in_progress`, `done`, `blocked`— y esto dice **qué se decidió sobre ella
 * cuando su sesión se acabó**. Una tarjeta puede quedar `in_progress` y estar transferida, o `blocked`
 * y estar abandonada: la columna no contesta la pregunta.
 *
 * ── LO QUE EL SISTEMA OBSERVA Y LO QUE ALGUIEN TIENE QUE DECIR ──────────────────────────────────
 *
 * Sólo dos de estas cinco las puede poner el sistema, y la diferencia importa:
 *
 * - {@see self::Open} y {@see self::Transferred} son **observaciones**. La sesión terminó y la tarjeta
 *   seguía abierta; o alguien la movió a otra sesión y eso quedó escrito. El sistema lo ve sin
 *   preguntarle a nadie.
 * - {@see self::Blocked}, {@see self::Deferred} y {@see self::Abandoned} son **juicios**. Sólo quien
 *   sabe por qué se detuvo el trabajo puede decir si esperaba algo, si se pospuso a propósito, o si se
 *   soltó. El sistema no las infiere, y llamar «abandonada» a una tarjeta que quedó abierta sería
 *   exactamente eso: inferir un juicio de una ausencia.
 *
 * Por eso el default al cerrar es `Open` y no `Abandoned`. Una sesión que termina no mata trabajo que
 * puede continuar en otra — la continuidad pertenece al sistema, no a la sesión.
 */
enum TodoDisposition: string
{
    /**
     * La sesión terminó y la tarjeta seguía abierta. **Nadie decidió nada todavía.**
     *
     * Es el default y es deliberadamente el más débil: dice lo que se observó y no lo que significa.
     */
    case Open = 'open';

    /** Se movió a otra sesión, que ahora la tiene. Observable: el traslado dejó su propio hecho. */
    case Transferred = 'transferred';

    /** Alguien declaró que espera algo externo. Un juicio: el sistema no sabe qué se espera. */
    case Blocked = 'blocked';

    /** Alguien la pospuso a propósito. Un juicio, y distinto de soltarla. */
    case Deferred = 'deferred';

    /** Alguien declaró que ya no se va a hacer. El único que cierra sin hacer, y por eso se declara. */
    case Abandoned = 'abandoned';

    /** Si el sistema puede ponerla por su cuenta, o hace falta que alguien la declare. */
    public function isObserved(): bool
    {
        return $this === self::Open || $this === self::Transferred;
    }

    /** Cómo se lee en una superficie, sin que quien la pinte tenga que traducir un enum. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'quedó abierta',
            self::Transferred => 'pasó a otra sesión',
            self::Blocked => 'esperando algo',
            self::Deferred => 'pospuesta',
            self::Abandoned => 'abandonada',
        };
    }
}
