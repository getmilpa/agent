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
 * Cómo nació una tarjeta. Lo **deriva el sistema**, no lo declara el agente.
 *
 * ── POR QUÉ DERIVADO Y NO DECLARADO ─────────────────────────────────────────────────────────────
 *
 * Porque pedirle al agente que etiquete el origen sería agregarle una decisión, y este programa lleva
 * cuatro tandas midiendo que todo lo que se le agrega le cuesta. El sistema ya sabe lo que hace falta:
 * cuántas herramientas corrieron antes de que la tarjeta apareciera. Preguntar algo que uno mismo
 * puede observar es repartir una decisión que ya estaba tomada.
 *
 * Y hay una razón más fuerte: una etiqueta declarada por quien la escribe es una **afirmación**; una
 * derivada del stream es una **observación**. Cuando las dos se pueden tener, la observación gana.
 *
 * ── LAS CUATRO, Y POR QUÉ SON CUATRO ────────────────────────────────────────────────────────────
 *
 * Nacer `pending` y nacer `done` son actos distintos, y hacerlo antes o después de trabajar también.
 * Cruzar las dos preguntas da cuatro casillas, y las cuatro existen de verdad:
 *
 * | nace | trabajo previo | origen |
 * |---|---|---|
 * | `pending` | no | **planned** — se planeó antes de tocar nada |
 * | `pending` | sí | **discovered** — apareció al ver el terreno |
 * | terminal | sí | **retrospective** — se registra algo que ya ocurrió |
 * | terminal | no | **unsupported** — se declara hecho sin que nada lo respalde |
 *
 * `unsupported` es la que importa. Q-P19-C midió **41
 * tarjetas nacidas `done` y las 41 tras haber corrido herramientas**: cero sin respaldo. Así que hoy
 * ese caso no ocurre — y ése es justamente el momento de nombrarlo, mientras el número es cero y
 * nadie tiene que defenderlo. Si algún día aparece, el sistema ya sabe cómo se llama en vez de
 * confundirlo con un registro retrospectivo legítimo.
 *
 * ── LO QUE ESTO NO ES ───────────────────────────────────────────────────────────────────────────
 *
 * No es una prueba de que la tarjeta describa **ese** trabajo. El sistema observa que hubo trabajo
 * antes, no que la tarjeta hable de él. Distinguir eso pediría leer el contenido, que es otra
 * herramienta y otra pregunta.
 */
enum TodoOrigin: string
{
    /** Se escribió antes de tocar nada: es una intención. */
    case Planned = 'planned';

    /** Apareció con el trabajo ya empezado: alguien vio el terreno y encontró una tarea más. */
    case Discovered = 'discovered';

    /** Registra algo que ya ocurrió. Nace terminada y el stream muestra el trabajo detrás. */
    case Retrospective = 'retrospective';

    /**
     * Nace terminada **sin nada que la respalde**: ninguna herramienta corrió todavía.
     *
     * No se rechaza — el sistema registra lo que pasó, no censura. Se **nombra**, que es distinto: un
     * tablero puede pintarla aparte y un verificador puede contarla sin tener que deducir nada.
     */
    case Unsupported = 'unsupported';

    /**
     * De qué origen es una tarjeta que nace con `$status` tras `$herramientas` llamadas.
     *
     * Sólo tiene sentido al NACER. Un movimiento posterior no tiene origen: tiene un `from` y una
     * versión a la que reemplaza, que es otra cosa y ya se registra.
     */
    public static function derive(TodoStatus $status, int $herramientas): self
    {
        $terminal = $status === TodoStatus::Done;

        if (!$terminal) {
            return $herramientas > 0 ? self::Discovered : self::Planned;
        }

        return $herramientas > 0 ? self::Retrospective : self::Unsupported;
    }

    /** Cómo se lee en una superficie, sin que quien la pinte tenga que traducir un enum. */
    public function label(): string
    {
        return match ($this) {
            self::Planned => 'planeada',
            self::Discovered => 'encontrada sobre la marcha',
            self::Retrospective => 'registrada después de hacerla',
            self::Unsupported => 'declarada hecha sin respaldo',
        };
    }
}
