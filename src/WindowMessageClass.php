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
 * Why a message exists in a composed session window.
 *
 * The class is independent from the provider role: roles remain provider protocol, while this value
 * lets channel consumers distinguish projected state from conversation without reading prose.
 */
enum WindowMessageClass: string
{
    case Summary = 'summary';
    case Briefing = 'briefing';
    case Turn = 'turn';
}
