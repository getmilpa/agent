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

use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signature that opened a sequence stays in the session, so the calls after it can cite it
 * (greenhouse decisions/0458, 0500) — as DATA: the fold hands it back and the consumer re-verifies.
 */
final class SequenceReceiptIsAFactTest extends TestCase
{
    /** @return array{payload: string, signature: string, fingerprint: string, uid: ?string} */
    private function receipt(string $who = 'resident'): array
    {
        return [
            'payload' => "{\"operation\":\"agent\",\"arguments\":{\"session\":\"s1\"},\"by\":\"{$who}\"}",
            'signature' => "-----BEGIN PGP SIGNATURE-----\n{$who}\n-----END PGP SIGNATURE-----",
            'fingerprint' => 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234',
            'uid' => "{$who} <{$who}@lab>",
        ];
    }

    public function testASessionWithNoSignedCallHasNoReceipt(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        self::assertNull($store->load('s1')?->sequenceAuthorization());
    }

    public function testTheFoldHandsBackTheReceiptExactlyAsRecorded(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $seq = $store->authorizeSequence('s1', 'agent', $this->receipt());

        $standing = $store->load('s1')?->sequenceAuthorization();

        self::assertNotNull($standing);
        self::assertSame('agent', $standing['operation']);
        self::assertSame($this->receipt(), $standing['receipt']);
        self::assertSame($seq, $standing['seq']);
    }

    public function testTheLastSignatureWinsAndACitationChangesNothing(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->authorizeSequence('s1', 'agent', $this->receipt('first'));
        $second = $store->authorizeSequence('s1', 'agent', $this->receipt('second'));
        $store->citeAuthorization('s1', 'agent', 'sha256:abc');

        $standing = $store->load('s1')?->sequenceAuthorization();

        self::assertSame($second, $standing['seq'] ?? null);
        self::assertSame($this->receipt('second'), $standing['receipt'] ?? null);
    }

    public function testAReleasedReceiptNoLongerStands(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->authorizeSequence('s1', 'recipe:apply', $this->receipt());
        $store->releaseAuthorization('s1', 'recipe completed');

        self::assertNull($store->load('s1')?->sequenceAuthorization());

        // A new signature opens the sequence again.
        $store->authorizeSequence('s1', 'recipe:apply', $this->receipt('again'));
        self::assertSame($this->receipt('again'), $store->load('s1')?->sequenceAuthorization()['receipt'] ?? null);
    }

    public function testAnEndedSessionContinuesNothing(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->authorizeSequence('s1', 'agent', $this->receipt());
        $store->end('s1', 'done');

        self::assertNull($store->load('s1')?->sequenceAuthorization());
    }

    public function testTheCitationIsInTheStreamForTheAudit(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->authorizeSequence('s1', 'agent', $this->receipt());
        $store->citeAuthorization('s1', 'agent', 'sha256:abc');

        $cited = array_values(array_filter(
            $store->stream('s1'),
            static fn ($e): bool => $e->type === 'session.authorization_cited',
        ));

        self::assertCount(1, $cited);
        self::assertSame(['operation' => 'agent', 'receipt' => 'sha256:abc'], $cited[0]->payload);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function receiptsThatCannotReverify(): iterable
    {
        yield 'no payload' => [['signature' => 's', 'fingerprint' => 'f', 'uid' => null]];
        yield 'empty signature' => [['payload' => 'p', 'signature' => '', 'fingerprint' => 'f', 'uid' => null]];
        yield 'no fingerprint' => [['payload' => 'p', 'signature' => 's', 'uid' => null]];
        yield 'no uid declared' => [['payload' => 'p', 'signature' => 's', 'fingerprint' => 'f']];
    }

    /** @param array<string, mixed> $receipt */
    #[DataProvider('receiptsThatCannotReverify')]
    public function testAReceiptThatCannotReverifyIsRefusedAtTheDoor(array $receipt): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        $this->expectException(\InvalidArgumentException::class);
        $store->authorizeSequence('s1', 'agent', $receipt);
    }

    public function testABlankOperationIsRefused(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');

        $this->expectException(\InvalidArgumentException::class);
        $store->authorizeSequence('s1', ' ', $this->receipt());
    }

    public function testTheReceiptIsNotPaintedOnTheLiveScreen(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'x');
        $store->authorizeSequence('s1', 'agent', $this->receipt());
        $store->citeAuthorization('s1', 'agent', 'sha256:abc');
        $store->releaseAuthorization('s1', 'done');

        $painted = json_encode((new SessionProjector())->projectAll($store->stream('s1')));

        self::assertStringNotContainsString('PGP SIGNATURE', (string) $painted);
    }
}
