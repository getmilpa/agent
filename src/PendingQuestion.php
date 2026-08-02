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
 * Una decisión que la sesión no puede tomar sola, con las opciones que el propio agente propuso.
 *
 * ── PREGUNTAR ES PAUSAR ─────────────────────────────────────────────────────────────────────────
 *
 * Mientras esto exista, la sesión no es corrible ({@see Session::isRunnable()}). No es un aviso que
 * pasa de largo: es un alto. Un agente que «pregunta» y sigue adelante con su suposición no preguntó,
 * narró — y lo peor de eso es que la respuesta humana llega cuando el trabajo que dependía de ella ya
 * se hizo mal.
 *
 * Las opciones las propone el agente porque es quien sabe qué bifurcaciones tiene enfrente. Que sean
 * opciones y no texto libre es lo que permite contestar «2» desde una terminal, un TUI o un chat sin
 * que la respuesta se tenga que interpretar otra vez.
 */
final readonly class PendingQuestion
{
    /**
     * ── POR QUÉ LLEVA FECHA DE CADUCIDAD ────────────────────────────────────────────────────────
     *
     * Porque sin ella una pregunta espera **para siempre**, y una sesión con pregunta abierta no es
     * retomable: `Session::isRunnable()` es falso mientras la haya. O sea que una sesión que nadie
     * contestó no queda pausada — queda **muerta sin que nadie la declare muerta**, y el tablero de
     * sesiones la sigue mostrando viva.
     *
     * Lo encontró Q-P19-B comparando este sistema con
     * las compuertas de `milpa/workflow`: de siete dimensiones sólo coincidieron en dos, y las dos
     * eran ausencias — ninguno de los dos caduca.
     *
     * `null` sigue significando «sin plazo», que es lo que había y a veces es lo correcto: una
     * pregunta que espera a que alguien vuelva del fin de semana no debería morirse sola. Lo que no
     * puede pasar es que **no se pueda poner plazo**.
     *
     * @param list<string> $options
     * @param string|null  $expiresAt instante ISO-8601 tras el cual la pregunta ya no vale; `null` es
     *                                sin plazo, y es una decisión de quien pregunta, no un default
     *                                escondido
     */
    public function __construct(
        public string $id,
        public string $question,
        public array $options = [],
        public ?string $why = null,
        public ?string $expiresAt = null,
        /**
         * Por qué se preguntó, como CÓDIGO estable — `permission`, `signature`, `target_not_named`.
         *
         * El texto de `question` cambia —se redacta, se traduce—; el código no. Es el mismo idioma que
         * `option_removed.reason.code`, y existe por lo mismo: una proyección que quiera contar
         * cuántas pausas produjo el contrato de intención contra cuántas produjo la política de
         * permisos tiene que poder hacerlo sin parsear prosa. `null` es «no se dijo», que es lo que
         * devuelven las preguntas anteriores a que esto existiera.
         */
        public ?string $reason = null,
    ) {
    }

    /**
     * ¿Ya pasó su plazo?
     *
     * Recibe el instante en vez de leer el reloj: una caducidad que consulta la hora por su cuenta no
     * se puede probar sin esperar, y una prueba que espera es una prueba que nadie corre.
     */
    public function hasExpired(\DateTimeImmutable $now): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $plazo = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $this->expiresAt);

        // Un plazo ilegible NO caduca la pregunta. Tratar «no lo pude leer» como «ya venció» mataría
        // sesiones vivas por un error de formato, y de los dos errores posibles ése es el caro.
        return $plazo !== false && $now > $plazo;
    }

    /**
     * Su forma serializable, la que viaja en el payload del evento.
     *
     * @return array{id: string, question: string, options: list<string>, why: string|null, expiresAt: string|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'options' => $this->options,
            'why' => $this->why,
            'expiresAt' => $this->expiresAt,
            'reason' => $this->reason,
        ];
    }

    /**
     * La reconstruye desde el payload de un evento, tolerando lo que falte.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        /** @var list<string> $opciones */
        $opciones = [];
        if (\is_array($row['options'] ?? null)) {
            foreach ($row['options'] as $opcion) {
                if (\is_string($opcion)) {
                    $opciones[] = $opcion;
                }
            }
        }

        return new self(
            \is_string($row['id'] ?? null) ? $row['id'] : '',
            \is_string($row['question'] ?? null) ? $row['question'] : '',
            $opciones,
            \is_string($row['why'] ?? null) ? $row['why'] : null,
            \is_string($row['expiresAt'] ?? null) ? $row['expiresAt'] : null,
            \is_string($row['reason'] ?? null) ? $row['reason'] : null,
        );
    }
}
