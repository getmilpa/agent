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
 * Quién contestó, y si eso se pudo verificar.
 *
 * ── POR QUÉ EXISTE ──────────────────────────────────────────────────────────────────────────────
 *
 * Porque hasta Q-P19-B no se guardaba. `answer()`
 * recibía el id de la pregunta y la respuesta, y nada más; el reductor apuntaba el par sin principal.
 * `Milpa\Workflow`, comparado en la misma dimensión, no sólo guarda quién aprueba: **prohíbe que el
 * que pide sea el que aprueba**.
 *
 * Hoy se sostenía por accidente —contestar exige la terminal, y quien la tiene es quien lanzó al
 * agente—. En cuanto exista un segundo canal (web, TUI remota, otro proceso) no habría forma de saber
 * quién autorizó qué. **Un permiso sin principal no es auditable**, y este repositorio sostiene que
 * la transparencia produce confianza.
 *
 * ── POR QUÉ LLEVA `verified` Y NO SÓLO UN NOMBRE ────────────────────────────────────────────────
 *
 * Porque las dos fuentes posibles no valen lo mismo, y presentarlas iguales sería peor que no
 * guardar nada:
 *
 * - un {@see \Milpa\Auth\AuthContext} autenticado dice quién es con una credencial detrás → verificado;
 * - una terminal dice el usuario del sistema operativo, que **cualquiera con esa terminal puede ser**
 *   → sin verificar.
 *
 * Guardar el segundo como si fuera el primero fabricaría una cadena de custodia que no existe. Un
 * registro que dice «lo autorizó rod» cuando en realidad dice «lo autorizó quien tenía la máquina de
 * rod» es exactamente la clase de evidencia falsa que este programa lleva un mes desmontando.
 *
 * `null` —nadie dijo quién— sigue siendo válido y es lo que pasa cuando quien llama no lo sabe. Es
 * distinto de un principal sin verificar: uno es «no se sabe», el otro es «se sabe, y no se probó».
 */
final readonly class Principal
{
    /**
     * @param string $id       identificador opaco, con su origen adelante: `cli:rod@laptop`,
     *                         `actor:member:42`. El prefijo importa porque dos canales pueden usar
     *                         el mismo nombre para personas distintas
     * @param bool   $verified si detrás de ese id hubo una credencial que alguien comprobó
     */
    public function __construct(
        public string $id,
        public bool $verified = false,
    ) {
    }

    /**
     * El que dice una terminal: el usuario del sistema, **sin verificar**.
     *
     * No se pretende que sea una identidad: es la mejor pista disponible cuando no hay credencial, y
     * va marcada como lo que es.
     */
    public static function fromTerminal(?string $usuario, ?string $maquina = null): self
    {
        $quien = $usuario !== null && $usuario !== '' ? $usuario : 'desconocido';
        $donde = $maquina !== null && $maquina !== '' ? '@' . $maquina : '';

        return new self('cli:' . $quien . $donde, verified: false);
    }

    /**
     * Su forma serializable, la que viaja en el payload del evento.
     *
     * @return array{id: string, verified: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'verified' => $this->verified];
    }

    /**
     * Lo reconstruye desde el payload, y **nunca sube la confianza**: lo que no diga explícitamente
     * `verified: true` se lee como sin verificar.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
        if ($id === '') {
            return null;
        }

        return new self($id, ($row['verified'] ?? false) === true);
    }
}
