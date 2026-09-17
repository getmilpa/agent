<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\{ModelCallIntake, SessionStore};
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

final class ModelCallFormatTest extends TestCase
{
    public function testTheRecordedFormatComesFromTheWireAndAbsenceAddsNothing(): void
    {
        $body = ['model' => 'fixture','messages' => [['role' => 'user','content' => 'Diagnose']]];
        $old = ModelCallIntake::fromChannelPayload('http://fixture.invalid', $body)->toPayload();
        self::assertArrayNotHasKey('response_format', $old);
        $format = ['type' => 'json_schema','json_schema' => ['name' => 'diagnosis','strict' => true,'schema' => ['type' => 'object']]];
        $intake = ModelCallIntake::fromChannelPayload('http://fixture.invalid', $body + ['response_format' => $format]);
        self::assertSame($format, $intake->responseFormat);
        self::assertSame($old + ['response_format' => $format], $intake->toPayload());
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Diagnose');
        $sessions->recordModelCall('s', $intake);
        $calls = array_values(array_filter((new SessionStore($events))->stream('s'), static fn ($e) => $e->type === 'session.model_called'));
        self::assertSame($format, $calls[0]->payload['response_format']);
        self::assertArrayNotHasKey('response_format', ModelCallIntake::fromChannelPayload('http://fixture.invalid', $body + ['response_format' => null])->toPayload());
        self::assertArrayNotHasKey('response_format', ModelCallIntake::fromChannelPayload('http://fixture.invalid', $body + ['response_format' => 'invalid'])->toPayload());
    }
}
