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

namespace Milpa\Agent\Tests;

use Milpa\Agent\SessionReducer;
use Milpa\Agent\SessionStore;
use PHPUnit\Framework\TestCase;
use Milpa\EventStore\InMemoryEventStore;

/**
 * A session has an owner when somebody SIGNS it — and what the stream keeps is the ASSERTION.
 *
 * Decided in greenhouse decisions/0056 on top of the receipt doctrine of evidence/0254: the event
 * carries the signed payload, its signature, the fingerprint and the signer's uid — never a trust
 * grade. The grade is produced by RE-VERIFYING the signature at consumption, which happens in the
 * app runtime and not here. What these tests pin is exactly that split: the store refuses a claim
 * that is not an assertion, the fold surfaces the LAST one as data, and the data survives an
 * isolated replay byte-identical — because a receipt that mutates in storage is no receipt.
 */
final class OwnershipIsAnAssertionTest extends TestCase
{
    private const STREAM = SessionStore::PREFIX . 's1';

    /** @return array{payload: string, signature: string, fingerprint: string, uid: ?string} */
    private function assertion(string $who = 'rod'): array
    {
        return [
            'payload' => "session:own {\"session\":\"s1\"} signed-by:{$who}",
            'signature' => "-----BEGIN PGP SIGNATURE-----\n{$who}\n-----END PGP SIGNATURE-----",
            'fingerprint' => 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234',
            'uid' => "{$who} <{$who}@teamx.agency>",
        ];
    }

    public function testAssertingOwnershipAppendsTheEventAndTheSessionCarriesIt(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');

        $assertion = $this->assertion();
        $store->assertOwnership('s1', $assertion);

        $appended = [];
        foreach ($events->replay(self::STREAM) as $e) {
            if ($e->type === 'session.ownership_asserted') {
                $appended[] = $e->payload;
            }
        }
        self::assertCount(1, $appended);
        self::assertSame(['assertion' => $assertion], $appended[0]);

        self::assertSame($assertion, $store->load('s1')?->ownershipAssertion());
    }

    /**
     * The LAST assertion wins — the same rule as every other fold in this reducer. Both facts stay
     * in the stream; what the projection answers is «who signed this session most recently».
     */
    public function testTheLastAssertionWins(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        $store->assertOwnership('s1', $this->assertion('rod'));
        $second = $this->assertion('ana');
        $store->assertOwnership('s1', $second);

        self::assertSame($second, $store->load('s1')?->ownershipAssertion());
    }

    /** A session nobody signed has no assertion: `null`, never an empty shape that looks like one. */
    public function testASessionNobodySignedHasNoAssertion(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        self::assertNull($store->load('s1')?->ownershipAssertion());
    }

    /** An assertion without its signature is not an assertion, and the store says so before appending. */
    public function testAnAssertionWithoutItsSignatureIsRefused(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        $withoutSignature = $this->assertion();
        unset($withoutSignature['signature']);

        $this->expectException(\InvalidArgumentException::class);
        $store->assertOwnership('s1', $withoutSignature);
    }

    /** An empty payload is a signature over nothing: refused for the same reason. */
    public function testAnAssertionWithAnEmptyPayloadIsRefused(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        $emptyPayload = $this->assertion();
        $emptyPayload['payload'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $store->assertOwnership('s1', $emptyPayload);
    }

    /**
     * The property the consumer depends on: a reader with ONLY this session's persisted events
     * reconstructs the assertion BYTE-IDENTICAL. Re-verifying a signature tolerates no drift — one
     * changed byte and the receipt stops matching its signature — so the round trip through the
     * store must be exact, not merely equivalent.
     */
    public function testTheAssertionSurvivesAnIsolatedReplayByteIdentical(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->recordTurn('s1', 'user', 'hola');
        $assertion = $this->assertion();
        $store->assertOwnership('s1', $assertion);

        // The raw persisted events alone — no store, no cache, nothing the writer still holds.
        $rebuilt = (new SessionReducer())->reduce('s1', $events->replay(self::STREAM));

        self::assertSame($assertion, $rebuilt->ownershipAssertion());
        self::assertSame(
            json_encode($assertion),
            json_encode($rebuilt->ownershipAssertion()),
            'the bytes a verifier would re-check must come back exactly as they were signed',
        );
    }
}
