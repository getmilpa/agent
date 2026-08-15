<?php

/**
 * This file is part of Milpa Agent — governed agent sessions for the Milpa PHP framework.
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
 * What the agent WAS GIVEN on one call, read back out of the payload that actually travelled.
 *
 * ── POR QUÉ ESTO NO SE PODÍA CONTESTAR ──────────────────────────────────────────────────────────
 *
 * El stream siempre grabó lo que el agente HIZO —qué llamó, qué le contestaron, qué compuerta lo
 * detuvo— y nada de lo que al agente LE DIERON. De las siete preguntas que gradúan una vista de
 * desarrollador, el canal contestaba cuatro, y las tres que faltaban eran justo las de la entrada:
 * qué herramientas vio, qué contexto recibió, qué se le ocultó.
 *
 * ── LA REGLA QUE ESTA CLASE NO PUEDE ROMPER ─────────────────────────────────────────────────────
 *
 * **Nunca inventa.** Cuando el payload no dice algo, esto dice que no lo sabe en vez de llenar el
 * hueco desde otro lado. Una vista no puede saber más que el canal del que deriva: en cuanto sepa
 * algo que el canal no dijo, dejó de ser una vista del sistema y se volvió una segunda opinión sobre
 * él — que es exactamente el defecto que `agent:catalogue` cargó durante meses.
 */
final class ModelCallIntake
{
    /**
     * @param list<string>                          $tools        Los nombres ofrecidos, en su orden.
     * @param bool                                  $toolsUnknown Viajaron herramientas y esta clase
     *                                                            no supo nombrarlas. NO es lo mismo que
     *                                                            no haber ofrecido ninguna.
     * @param list<array{role: string, chars: int}> $messages     La FORMA de la conversación al momento
     *                                                            de mandarla; el contenido ya vive en el
     *                                                            stream como turnos.
     * @param array<string, mixed>|null             $omitted      Lo que alguien DECLARÓ haber retenido.
     *                                                            `null` es «nadie dijo», nunca «nada».
     */
    private function __construct(
        public readonly string $endpoint,
        public readonly string $model,
        public readonly array $tools,
        public readonly bool $toolsUnknown,
        public readonly ?string $system,
        public readonly array $messages,
        public readonly ?array $omitted,
    ) {
    }

    /**
     * Lee la entrada del agente desde el cuerpo que viajó.
     *
     * @param array<string, mixed>      $payload El cuerpo que viajó, decodificado.
     * @param array<string, mixed>|null $omitted Lo que quien filtró haya declarado retener.
     */
    public static function fromChannelPayload(string $uri, array $payload, ?array $omitted = null): self
    {
        $mensajes = [];
        $system = \is_string($payload['system'] ?? null) ? trim((string) $payload['system']) : null;

        foreach ((array) ($payload['messages'] ?? []) as $m) {
            if (! \is_array($m)) {
                continue;
            }
            $rol = (string) ($m['role'] ?? '?');
            $contenido = \is_string($m['content'] ?? null) ? (string) $m['content'] : '';

            // Un proveedor lleva el prompt del sistema arriba y el otro adentro de los mensajes. Es
            // la misma cosa y se lee igual, porque quien depura pregunta «qué prompt recibió», no
            // «dónde lo puso el formateador».
            if ($rol === 'system' && $system === null) {
                $system = trim($contenido);

                continue;
            }

            $mensajes[] = ['role' => $rol, 'chars' => mb_strlen($contenido)];
        }

        [$tools, $desconocidas] = self::leerTools($payload['tools'] ?? null);

        return new self($uri, (string) ($payload['model'] ?? '?'), $tools, $desconocidas, $system, $mensajes, $omitted);
    }

    /**
     * Las dos ortografías de la familia, y una tercera respuesta que importa tanto como las otras dos.
     *
     * `[[], false]` — no viajaron herramientas.
     * `[[], true]`  — viajaron y esto no supo leerlas.
     *
     * Colapsarlas sería el defecto entero: la primera dice que al agente no le ofrecieron nada, la
     * segunda dice que el instrumento no ve. Una superficie que imprime `0 tools` para ambas manda a
     * depurar el mundo equivocado, y con confianza.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private static function leerTools(mixed $crudas): array
    {
        if (! \is_array($crudas) || $crudas === []) {
            return [[], false];
        }

        $nombres = [];
        foreach ($crudas as $t) {
            $nombre = \is_array($t) ? ($t['function']['name'] ?? $t['name'] ?? null) : null;
            if (\is_string($nombre) && $nombre !== '') {
                $nombres[] = $nombre;
            }
        }

        return $nombres === [] ? [[], true] : [$nombres, false];
    }

    /**
     * La forma con la que esto se apenda al stream.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'tools' => $this->tools,
            'toolsUnknown' => $this->toolsUnknown,
            'system' => $this->system,
            'messages' => $this->messages,
            'omitted' => $this->omitted,
        ];
    }
}
