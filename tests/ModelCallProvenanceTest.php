<?php

/**
 * This file is part of Milpa Agent — governed agent sessions for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\ModelCallIntake;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\FileEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Observed call identities survive intake without becoming inferred or trusted provenance. */
final class ModelCallProvenanceTest extends TestCase
{
    /** A reader can distinguish the producing call from a matching text-only message (Greenhouse E1240). */
    public function testTheAssistantCallAndToolReplyKeepTheirObservedProviderMetadata(): void
    {
        $calls = [['id' => 'provider-call-7', 'type' => 'function', 'function' => [
            'name' => 'HouseDebtRead', 'arguments' => '{"mode":"findings","id":"host-finding"}',
        ]]];
        $intake = ModelCallIntake::fromChannelPayload('https://model.example/v1/chat/completions', [
            'messages' => [
                ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls],
                ['role' => 'tool', 'content' => '{"id":"host-finding"}', 'tool_call_id' => 'provider-call-7', 'name' => 'HouseDebtRead'],
            ],
        ]);

        self::assertSame([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            ['role' => 'tool', 'content' => '{"id":"host-finding"}', 'tool_call_id' => 'provider-call-7', 'name' => 'HouseDebtRead'],
        ], $intake->messages);
        self::assertSame($intake->messages, $intake->toPayload()['messages']);
    }

    /** The old role/content shape stays unchanged when no metadata travelled. */
    public function testAbsentMetadataIsNotInferredFromMessageContentOrOfferedTools(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => 'HouseDebtRead provider-call-7'],
            ['role' => 'tool', 'content' => '{"id":"provider-call-7","name":"HouseDebtRead"}'],
        ];
        $intake = ModelCallIntake::fromChannelPayload('https://model.example/v1/chat/completions', [
            'messages' => $messages,
            'tools' => [['type' => 'function', 'function' => ['name' => 'HouseDebtRead']]],
        ]);

        self::assertSame($messages, $intake->messages);
        self::assertSame($messages, $intake->toPayload()['messages']);
    }

    /** The observer preserves defects for consumers to validate rather than fabricating valid calls. */
    #[DataProvider('rawMetadata')]
    public function testExplicitNullAndMalformedMetadataRemainObservedValues(mixed $value): void
    {
        $message = ['role' => 'tool', 'content' => 'observed', 'tool_calls' => $value, 'tool_call_id' => $value, 'name' => $value];
        $intake = ModelCallIntake::fromChannelPayload('https://model.example/v1/chat/completions', ['messages' => [$message]]);

        self::assertSame([$message], $intake->messages);
        self::assertSame([$message], $intake->toPayload()['messages']);
    }

    /** @return iterable<string, array{mixed}> Raw values are observations, not accepted call schemas. */
    public static function rawMetadata(): iterable
    {
        yield 'explicit null' => [null];
        yield 'unexpected number' => [42];
        yield 'unexpected object shape' => [['unexpected' => true]];
    }

    /** Reopening the durable store retains metadata, while observing adds no conversation turn. */
    public function testProviderIdentitySurvivesTheNativeEventStoreRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'milpa-intake-provenance-');
        self::assertIsString($path);
        try {
            $messages = [['role' => 'tool', 'content' => '{"id":"host-receipt"}', 'tool_call_id' => 'provider-call', 'name' => 'HouseDebtRead']];
            $store = new SessionStore(new FileEventStore($path));
            $store->start('provenance', 'Observe a tool reply.');
            $before = $store->load('provenance')?->turns;
            $store->recordModelCall('provenance', ModelCallIntake::fromChannelPayload('https://model.example/v1/chat/completions', ['messages' => $messages]));
            unset($store);

            $reopened = new SessionStore(new FileEventStore($path));
            $events = array_values(array_filter($reopened->stream('provenance'), static fn ($event): bool => $event->type === 'session.model_called'));
            self::assertCount(1, $events);
            self::assertSame($messages, $events[0]->payload['messages']);
            self::assertSame($before, $reopened->load('provenance')?->turns);
        } finally {
            unlink($path);
        }
    }
}
