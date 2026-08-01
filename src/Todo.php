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
    public function __construct(
        public string $id,
        public string $text,
        public TodoStatus $status = TodoStatus::Pending,
    ) {
    }

    /** El mismo pendiente en otro estado — un valor nuevo, porque nada aquí se muta. */
    public function withStatus(TodoStatus $status): self
    {
        return new self($this->id, $this->text, $status);
    }

    /**
     * Su forma serializable, la que viaja en el payload del evento.
     *
     * @return array{id: string, text: string, status: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'status' => $this->status->value];
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
        );
    }
}
