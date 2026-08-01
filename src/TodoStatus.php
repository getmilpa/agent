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
 * En qué va un pendiente.
 *
 * Cuatro estados y no dos: «bloqueado» y «pendiente» se ven igual desde afuera y son cosas
 * distintas —uno espera su turno, el otro espera algo que la sesión no controla— y confundirlos hace
 * que una sesión atorada parezca una sesión ocupada.
 */
enum TodoStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Blocked = 'blocked';
}
