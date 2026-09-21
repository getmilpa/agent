<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\ModelCallIntake;
use PHPUnit\Framework\TestCase;

/** Only observed thinking declarations become durable facts. */
final class ThinkingIntakeTest extends TestCase
{
    public function testAbsenceAndNonObjectValuesDoNotInventProviderDefaults(): void
    {
        foreach ([[], ['thinking' => null], ['thinking' => 'disabled']] as $payload) {
            $intake = ModelCallIntake::fromChannelPayload('https://fixture.invalid', $payload);
            self::assertNull($intake->thinking);
            self::assertArrayNotHasKey('thinking', $intake->toPayload());
        }
    }

    public function testTheObservedObjectIsPreservedWithoutNormalizingOrEnablingIt(): void
    {
        foreach ([['type' => 'disabled'], ['type' => 'adaptive'], ['unknown' => 'recorded'], []] as $thinking) {
            $intake = ModelCallIntake::fromChannelPayload('https://fixture.invalid', ['thinking' => $thinking]);
            self::assertSame($thinking, $intake->thinking);
            self::assertSame($thinking, $intake->toPayload()['thinking']);
        }
    }
}
