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
     * `$messages` preserves the provider wire shape. `$window` preserves the separate logical
     * composition that Session declared before the gateway added prompts, tool exchanges, or
     * provider-specific rewrites. They are deliberately not joined by position or content.
     *
     * @param list<string>                                                                         $tools        Los nombres ofrecidos, en su orden.
     * @param bool                                                                                 $toolsUnknown Viajaron herramientas y esta clase
     *                                                                                                           no supo nombrarlas. NO es lo mismo que
     *                                                                                                           no haber ofrecido ninguna.
     * @param list<array{role: string, content: string}>                                           $messages     LO QUE VIAJÓ, no su tamaño. Los
     *                                                                                                           turnos del stream son lo que la
     *                                                                                                           sesión REGISTRÓ; esto es lo que
     *                                                                                                           de verdad se mandó, y no siempre
     *                                                                                                           coinciden — después de compactar,
     *                                                                                                           la ventana lleva un resumen que
     *                                                                                                           ningún turno contiene (greenhouse
     *                                                                                                           decisions/0039).
     * @param array<string, mixed>|null                                                            $omitted      Lo que alguien DECLARÓ haber
     *                                                                                                           retenido. `null` es «nadie dijo»,
     *                                                                                                           nunca «nada».
     * @param list<array{role: string, content: string, class: value-of<WindowMessageClass>}>|null $window       The logical window declared by its
     *                                                                                                           composer. It travels beside the
     *                                                                                                           wire payload and never inside it.
     */
    private function __construct(
        public readonly string $endpoint,
        public readonly string $model,
        public readonly array $tools,
        public readonly bool $toolsUnknown,
        public readonly ?string $system,
        public readonly array $messages,
        public readonly ?array $omitted,
        public readonly ?array $window,
    ) {
    }

    /**
     * Lee la entrada del agente desde el cuerpo que viajó.
     *
     * @param array<string, mixed>                                                                 $payload El cuerpo que viajó, decodificado.
     * @param array<string, mixed>|null                                                            $omitted Lo que quien filtró haya declarado retener.
     * @param list<array{role: string, content: string, class: value-of<WindowMessageClass>}>|null $window  The composer-owned logical window, kept outside the provider payload.
     */
    public static function fromChannelPayload(
        string $uri,
        array $payload,
        ?array $omitted = null,
        ?array $window = null,
    ): self {
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

            // EL CONTENIDO, NO SU TAMAÑO (greenhouse decisions/0039).
            //
            // `{role, chars}` describe la FORMA de la conversación y deja «qué recibió el agente» sin
            // contestar: dos mensajes distintos del mismo largo son indistinguibles, y todo lo que se
            // construya encima hereda ese hueco — incluida la cadena de autoridad de `decisions/0037`.
            //
            // Se midió antes de decidirlo: grabar el contenido cuesta +18 %, y referenciar el `system`
            // en vez de copiarlo ahorra 73 % del suyo. El stream queda 14 % MÁS CHICO que antes y
            // además auditable (`evidence/0222` y `0223`).
            $mensajes[] = ['role' => $rol, 'content' => $contenido];
        }

        [$tools, $desconocidas] = self::leerTools($payload['tools'] ?? null);

        return new self($uri, (string) ($payload['model'] ?? '?'), $tools, $desconocidas, $system, $mensajes, $omitted, $window);
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
     * CÓMO SE NOMBRA UN `system`, y en un solo lugar.
     *
     * El almacén apenda el hecho y esta clase escribe la referencia, así que los dos tienen que
     * nombrarlo igual o el segundo no encontraría al primero. Componer el nombre en cada lado sería
     * dos ortografías de una convención, que en esta casa ya se pagó una vez (`evidence/0141`).
     *
     * `null` cuando no viajó ningún `system`: no hay hecho que nombrar, y un nombre para la nada
     * afirmaría que hubo prompt.
     */
    public function systemRef(): ?string
    {
        return $this->system === null ? null : 'sha256:' . hash('sha256', $this->system);
    }

    /**
     * La forma con la que esto se apenda al stream.
     *
     * Lleva la REFERENCIA y no el texto: el texto viaja una vez, en su propio hecho
     * ({@see SessionEvent::SystemSet}), y cada llamada apunta al que estaba vigente. Quien reproduce
     * los eventos de esta sesión —y nada más— siempre puede resolverla.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'tools' => $this->tools,
            'toolsUnknown' => $this->toolsUnknown,
            'system_ref' => $this->systemRef(),
            'messages' => $this->messages,
            'omitted' => $this->omitted,
        ];

        if ($this->window !== null) {
            $payload['window'] = $this->window;
        }

        return $payload;
    }
}
