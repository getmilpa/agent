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
 * Un pendiente del plan, con el estado en el que está.
 *
 * Vive en el stream y no en el prompt (P16.3). Un plan que sólo existe dentro del contexto se pierde
 * en la primera compactación — que es exactamente cuando más falta hace, porque compactar pasa
 * cuando la sesión ya lleva rato y ya nadie recuerda qué faltaba.
 */
final readonly class Todo
{
    /**
     * @param int $version qué versión de ESTA tarjeta es. La asigna el almacén al apendar, nunca
     *                     quien construye el valor: un número que el llamador elige es un número que
     *                     puede errar, y el linaje deja de leerse. `0` es una tarjeta que todavía no
     *                     entró al stream
     */
    public function __construct(
        public string $id,
        public string $text,
        public TodoStatus $status = TodoStatus::Pending,
        public int $version = 0,
        // CÓMO NACIÓ. `null` en una tarjeta que todavía no entró al stream, y en las de streams
        // anteriores a que esto existiera — no se les inventa un origen que nadie observó.
        public ?TodoOrigin $origin = null,
        // Cuántas mutaciones llevaba la sesión cuando esta tarjeta se tocó por última vez. No es
        // telemetría: es lo que permite preguntar, al cerrar, cuántas cosas cambiaron desde entonces
        // sin que nadie tocara esta tarjeta.
        public int $mutationsAt = 0,
        // WHICH GENERATION OF THE PLAN THIS CARD BELONGS TO. Zero on cards written before the stamp
        // existed, and that reads as «unknown generation» rather than as «the first» — a surface
        // showing them as current would be doing the very thing the stamp exists to stop.
        public int $planVersion = 0,
        // WHICH CARD THIS ONE REPLACED, declared by whoever wrote it and never guessed.
        //
        // Re-planning is what completes long work and it is also what stacked six copies of the same
        // task. Comparing plan generations could not tell «superseded» from «still open from an
        // earlier generation» — that needs knowing two cards speak about the same thing, and only
        // whoever wrote the second one knows it.
        //
        // NOT a status and not a disposition: the replaced card was neither done nor abandoned. It
        // was reformulated, and the record says by which.
        public ?string $replaces = null,
        // THE GENERATION THIS CARD WAS BORN IN, and it never moves again.
        //
        // `planVersion` records the LAST TOUCH, which is the right question for «how current is this»
        // and the wrong one for «is this a restatement of that»: a card moved after a re-plan
        // migrates forward and stops being comparable with the one it duplicates. Three slices in a
        // row were built on the touch stamp and all three read the wrong moment.
        //
        // Both are kept because both are true, and each answers what it was asked.
        public int $bornInPlan = 0,
    ) {
    }

    /**
     * El mismo pendiente en otro estado — un valor nuevo, porque nada aquí se muta.
     *
     * NO toca la versión: quien decide que esto es una transición nueva es el almacén, al escribirla.
     * Un valor en memoria puede construirse y descartarse sin que haya pasado nada.
     */
    public function withStatus(TodoStatus $status): self
    {
        return new self($this->id, $this->text, $status, $this->version, $this->origin, $this->mutationsAt);
    }

    /**
     * Su forma serializable, la que viaja en el payload del evento.
     *
     * @return array{id: string, text: string, status: string, version: int, origin: string|null, mutationsAt: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'status' => $this->status->value,
            'replaces' => $this->replaces,
            'bornInPlan' => $this->bornInPlan,
            'version' => $this->version,
            'origin' => $this->origin?->value,
            'mutationsAt' => $this->mutationsAt,
        ];
    }

    /**
     * Lo reconstruye desde el payload de un evento, tolerando lo que falte.
     *
     * Un payload viejo al que le falta una llave no puede tumbar la reconstrucción de una sesión
     * entera: se cae al default y se sigue. Un stream se lee años después de escribirse.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            \is_string($row['id'] ?? null) ? $row['id'] : '',
            \is_string($row['text'] ?? null) ? $row['text'] : '',
            TodoStatus::tryFrom(\is_string($row['status'] ?? null) ? $row['status'] : '') ?? TodoStatus::Pending,
            // Sin `version` —un evento anterior a que esto existiera— la tarjeta se lee en 1: hubo un
            // hecho, así que hay al menos una versión. Cero sería decir que nunca entró al stream, y
            // entró.
            \is_int($row['version'] ?? null) ? $row['version'] : 1,
            TodoOrigin::tryFrom(\is_string($row['origin'] ?? null) ? $row['origin'] : ''),
            \is_int($row['mutationsAt'] ?? null) ? $row['mutationsAt'] : 0,
            \is_int($row['planVersion'] ?? null) ? $row['planVersion'] : 0,
            \is_string($row['replaces'] ?? null) && $row['replaces'] !== '' ? $row['replaces'] : null,
            \is_int($row['bornInPlan'] ?? null) ? $row['bornInPlan'] : 0,
        );
    }
}
