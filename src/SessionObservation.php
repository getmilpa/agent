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

use Milpa\EventStore\EventStoreInterface;

/**
 * El hecho común que necesita quien está depurando — antes de que ninguna superficie lo pinte.
 *
 * ── EL PUESTO, Y POR QUÉ NO ES UN TERCER USUARIO ────────────────────────────────────────────────
 *
 * Hay dos usuarios —el humano y el agente— y un PUESTO TRANSVERSAL que a veces ocupa uno y a veces
 * el otro. Lo que define al puesto no es quién lo llena sino que necesita **las dos vistas a la vez**:
 * lo que el sistema mostró y lo que de verdad ocurrió.
 *
 * Por eso esto no es una tercera verdad. Es UNA fuente, y las proyecciones —la de máquina y la de
 * humano— son trámites encima. Dos implementaciones podrían discrepar; una fuente no.
 *
 * ── LA PROPIEDAD QUE GOBIERNA CADA LÍNEA ────────────────────────────────────────────────────────
 *
 * **Ninguna vista puede saber más que el canal del que deriva.** Cuando el stream calló, esto dice
 * que calló. No va a preguntarle al registro de herramientas cuántas existen para restar, no
 * reconstruye desde el código lo que «debería» haber viajado, no rellena un hueco con una suposición
 * razonable.
 *
 * *No es una precaución: es el nombre correcto de un defecto ya medido.* `agent:catalogue` se
 * anunciaba como la vista del agente y reconstruía desde el código; enseñó 22 de 28 herramientas
 * durante meses, y la que mentía era exactamente la vista que no leía el canal.
 *
 * ── Y POR ESO LAS SIETE SIEMPRE ESTÁN ───────────────────────────────────────────────────────────
 *
 * Las siete preguntas aparecen siempre, contestadas o no. Un hueco declarado se puede auditar; un
 * hueco callado se lee como un cero, y **una vista parcial que no declara ser parcial es más
 * peligrosa que una vista pequeña**: deja depurando el mundo equivocado con confianza.
 */
final class SessionObservation
{
    /** Las siete, en el orden en que se decidieron. */
    private const PREGUNTAS = [
        'tools_offered' => '¿Qué herramientas vio realmente?',
        'context_received' => '¿Qué contexto recibió?',
        'omitted' => '¿Qué fue omitido?',
        'called' => '¿Qué llamó?',
        'returned' => '¿Qué resultado regresó?',
        'gate' => '¿Qué gate intervino?',
        'between_turns' => '¿Qué cambió entre turnos?',
    ];

    /**
     * @param array<string, array{question: string, answered: bool, value?: mixed, because?: string}> $answers
     * @param list<string>                                                                            $cannotSay
     */
    private function __construct(
        public readonly string $session,
        public readonly bool $exists,
        public readonly array $answers,
        public readonly array $cannotSay,
    ) {
    }

    /** Lee la observación de una sesión desde su stream, y de ningún otro lado. */
    public static function of(EventStoreInterface $events, string $session): self
    {
        $eventos = $events->replay(SessionStore::PREFIX . $session);

        if ($eventos === []) {
            // NO ES UNA OBSERVACIÓN VACÍA. Una sesión que no existe y una sesión que no hizo nada se
            // ven igual si las dos devuelven ceros, y mandan a lugares opuestos: a revisar el
            // identificador, o a revisar la app.
            return new self($session, false, self::sinContestar(), array_keys(self::PREGUNTAS));
        }

        $entradas = [];
        $llamadas = [];
        $compuertas = [];
        $turnos = [];
        $omision = null;

        foreach ($eventos as $e) {
            $p = (array) $e->payload;

            switch (SessionEvent::tryFrom($e->type)) {
                case SessionEvent::ModelCalled:
                    $entradas[] = ['seq' => $e->seq] + $p;
                    // La omisión la DECLARA quien filtró. Si ninguna entrada la trae, queda en null y
                    // esa pregunta se reporta sin contestar — nunca en cero.
                    $omision ??= \is_array($p['omitted'] ?? null) ? $p['omitted'] : null;

                    break;

                case SessionEvent::ToolCalled:
                    $llamadas[] = [
                        'seq' => $e->seq,
                        'tool' => (string) ($p['tool'] ?? '?'),
                        'arguments' => (array) ($p['arguments'] ?? []),
                        'ok' => ($p['ok'] ?? true) === true,
                        'mutating' => ($p['mutating'] ?? false) === true,
                        'result' => (string) ($p['result'] ?? ''),
                    ];

                    break;

                case SessionEvent::QuestionAsked:
                    $compuertas[] = [
                        'seq' => $e->seq,
                        'id' => (string) ($p['id'] ?? '?'),
                        'reason' => \is_string($p['reason'] ?? null) ? $p['reason'] : null,
                        'question' => (string) ($p['question'] ?? ''),
                    ];

                    break;

                case SessionEvent::Turn:
                    $turnos[] = [
                        'seq' => $e->seq,
                        'role' => (string) ($p['role'] ?? '?'),
                        'chars' => mb_strlen((string) ($p['content'] ?? '')),
                    ];

                    break;

                default:
                    break;
            }
        }

        $ultima = $entradas === [] ? null : $entradas[array_key_last($entradas)];

        $answers = [
            'tools_offered' => self::respuesta(
                'tools_offered',
                $ultima !== null && ($ultima['toolsUnknown'] ?? false) !== true,
                static fn (): array => (array) ($ultima['tools'] ?? []),
                $ultima === null
                    ? 'nadie grabó lo que se le ofreció a este agente — la entrada no está observada en esta corrida'
                    : 'viajaron herramientas y el observador no supo nombrarlas',
            ),
            'context_received' => self::respuesta(
                'context_received',
                $ultima !== null,
                static fn (): array => [
                    'model' => $ultima['model'] ?? '?',
                    'endpoint' => $ultima['endpoint'] ?? '?',
                    'system' => $ultima['system'] ?? null,
                    'messages' => (array) ($ultima['messages'] ?? []),
                    'calls' => \count($entradas),
                ],
                'nadie grabó lo que se le mandó al modelo en esta sesión',
            ),
            // NUNCA SE CALCULA. Restarle lo ofrecido al catálogo daría un número, y ese número sería
            // conocimiento que el canal no dio.
            'omitted' => self::respuesta(
                'omitted',
                $omision !== null,
                static fn (): array => (array) $omision,
                'nadie declaró haber retenido nada; esto NO quiere decir que no se haya retenido',
            ),
            // ── LAS CUATRO DE SALIDA SIEMPRE ESTAN CONTESTADAS, Y UNA LISTA VACIA ES LA RESPUESTA ──
            //
            // El stream graba estas cuatro cada vez que ocurren, así que su ausencia significa que no
            // ocurrieron — y eso SÍ es saber algo. Marcarlas «sin contestar» colapsaría «no llamó
            // nada» con «nadie lo grabó», que es exactamente la distinción que esta clase existe para
            // sostener, cometida por la clase que la sostiene.
            //
            // Las tres de ENTRADA son al revés: nadie las graba a menos que alguien haya cableado el
            // observador, así que su ausencia no dice nada del agente — dice algo del instrumento.
            'called' => self::respuesta('called', true, static fn (): array => $llamadas, ''),
            'returned' => self::respuesta(
                'returned',
                true,
                static fn (): array => array_map(
                    static fn (array $l): array => ['tool' => $l['tool'], 'ok' => $l['ok'], 'result' => $l['result']],
                    $llamadas,
                ),
                '',
            ),
            'gate' => self::respuesta('gate', true, static fn (): array => $compuertas, ''),
            'between_turns' => self::respuesta('between_turns', true, static fn (): array => $turnos, ''),
        ];

        $mudas = [];
        foreach ($answers as $clave => $r) {
            if ($r['answered'] === false) {
                $mudas[] = $clave;
            }
        }

        return new self($session, true, $answers, $mudas);
    }

    /**
     * @param callable(): mixed $valor
     *
     * @return array{question: string, answered: bool, value?: mixed, because?: string}
     */
    private static function respuesta(string $clave, bool $contestada, callable $valor, string $porque): array
    {
        return $contestada
            ? ['question' => self::PREGUNTAS[$clave], 'answered' => true, 'value' => $valor()]
            : ['question' => self::PREGUNTAS[$clave], 'answered' => false, 'because' => $porque];
    }

    /** @return array<string, array{question: string, answered: bool, because: string}> */
    private static function sinContestar(): array
    {
        $vacias = [];
        foreach (self::PREGUNTAS as $clave => $pregunta) {
            $vacias[$clave] = ['question' => $pregunta, 'answered' => false, 'because' => 'esa sesión no existe en este stream'];
        }

        return $vacias;
    }

    /**
     * La proyección de máquina: los mismos hechos, sin una palabra más.
     *
     * Si algún día enseñara algo que la humana no, habría dos verdades otra vez — y el puesto existe
     * precisamente para no tener que reconciliarlas.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session' => $this->session,
            'exists' => $this->exists,
            'answers' => $this->answers,
            'cannotSay' => $this->cannotSay,
        ];
    }
}
