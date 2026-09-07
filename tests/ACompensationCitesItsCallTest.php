<?php

/**
 * This file is part of Milpa Agent — the session and consent core of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A compensation does not COPY arguments: it CITES them — greenhouse `decisions/0222`.
 *
 * The first version of that acta was refuted by the code it claimed to know. It said the ledger records
 * `arguments_digest` and NOT the arguments, and built a three-way choice on that tension. Both halves
 * were false: `session.tool_called` already records every argument raw, and the reason the digest exists
 * is written in `ConsentBridge` — «a reference to the arguments, not a second copy of them … writing them
 * again here would be a second inventory of the same truth».
 *
 * So nothing is copied. The recipe names the inverse and points at the call that already holds what it
 * needs.
 */
#[CoversClass(SessionStore::class)]
final class ACompensationCitesItsCallTest extends TestCase
{
    /** F1 — the recipe is in the ledger, and it is a citation. */
    public function testTheRecipeNamesTheInverseAndCitesTheCallThatHoldsItsArguments(): void
    {
        $store = new SessionStore($events = new InMemoryEventStore());
        $store->start('s1', 'turn Acme on', AutonomyMode::Ask);

        $seq = $store->recordToolCall('s1', 'plugins_enable', ['name' => 'Acme'], '{"ok":true}', mutating: true);
        $store->recordCompensation('s1', ['of' => 'plugins.enable', 'operation' => 'plugins.disable', 'call_seq' => $seq]);

        $fact = self::lastOf($events, SessionEvent::CompensationRecorded);
        self::assertSame('plugins.enable', $fact['of']);
        self::assertSame('plugins.disable', $fact['operation']);
        self::assertSame($seq, $fact['call_seq']);
    }

    /**
     * F5 — NOTHING IS COPIED. Verified on the payload, not on the intention: no argument of the forward
     * call appears anywhere in the compensation fact.
     */
    public function testTheCompensationCarriesNoArguments(): void
    {
        $store = new SessionStore($events = new InMemoryEventStore());
        $store->start('s1', 'turn Acme on', AutonomyMode::Ask);

        $seq = $store->recordToolCall('s1', 'plugins_enable', ['name' => 'Acme', 'secret' => 'hunter2'], '{"ok":true}', mutating: true);
        $store->recordCompensation('s1', ['of' => 'plugins.enable', 'operation' => 'plugins.disable', 'call_seq' => $seq]);

        $fact = self::lastOf($events, SessionEvent::CompensationRecorded);
        self::assertSame(['of', 'operation', 'call_seq'], array_keys($fact), 'three keys and no more');
        self::assertStringNotContainsString('hunter2', (string) json_encode($fact));
        self::assertStringNotContainsString('Acme', (string) json_encode($fact));
    }

    /**
     * F2's half that lives here: following the citation reaches the arguments, COMPLETE — not the named
     * target alone, which is what the refuted version would have copied and what `screen:declare` (the
     * only live `Guaranteed`) does not even declare.
     */
    public function testFollowingTheCitationReachesTheWholeArgumentList(): void
    {
        $store = new SessionStore($events = new InMemoryEventStore());
        $store->start('s1', 'turn Acme on', AutonomyMode::Ask);

        $arguments = ['name' => 'Acme', 'reason' => 'because the human asked'];
        $seq = $store->recordToolCall('s1', 'plugins_enable', $arguments, '{"ok":true}', mutating: true);
        $store->recordCompensation('s1', ['of' => 'plugins.enable', 'operation' => 'plugins.disable', 'call_seq' => $seq]);

        $cited = self::lastOf($events, SessionEvent::CompensationRecorded)['call_seq'];
        $call = null;
        foreach ($events->replay("agent-session:s1") as $event) {
            if ($event->seq === $cited) {
                $call = $event->payload;
            }
        }

        self::assertNotNull($call, 'the citation resolves inside the same stream');
        self::assertSame($arguments, $call['arguments'], 'and it reaches every argument, not a subset');
    }

    /** A recipe with no citation is a name with no recipe, and it is refused rather than stored. */
    public function testACompensationWithoutItsCitationIsRefused(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CITES the call');

        $store->recordCompensation('s1', ['of' => 'plugins.enable', 'operation' => 'plugins.disable']);
    }

    /** And one that names nothing to undo is a recipe for nothing. */
    public function testACompensationThatNamesNothingIsRefused(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipe for nothing');

        $store->recordCompensation('s1', ['of' => 'plugins.enable', 'call_seq' => 3]);
    }

    /**
     * THE CONTROL for the citation itself: two calls in one session get two different seqs, so a recipe
     * points at ITS call and not merely at «a call». Without this, `call_seq` could be a constant and
     * every test above would still pass.
     */
    public function testTwoCallsGetTwoCitations(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);

        $first = $store->recordToolCall('s1', 'plugins_enable', ['name' => 'Acme'], '{}', mutating: true);
        $second = $store->recordToolCall('s1', 'plugins_enable', ['name' => 'Otro'], '{}', mutating: true);

        self::assertNotSame($first, $second);
    }

    /**
     * @return array<string, mixed>
     */
    private static function lastOf(InMemoryEventStore $events, SessionEvent $type): array
    {
        $found = null;
        foreach ($events->replay("agent-session:s1") as $event) {
            if ($event->type === $type->value) {
                $found = $event->payload;
            }
        }

        self::assertNotNull($found, 'the ledger carries a ' . $type->value);

        return $found;
    }
}
