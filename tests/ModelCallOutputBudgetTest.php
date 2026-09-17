<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\ModelCallIntake;
use PHPUnit\Framework\TestCase;

/** A budget is observed from the request, never filled from a package default. @internal */
final class ModelCallOutputBudgetTest extends TestCase
{
    public function testObservedProviderFieldsAreRetainedWithoutNormalizingTheirMeaning(): void
    {
        foreach ([['max_completion_tokens' => 8192],['max_tokens' => 37],['max_tokens' => 0],['max_tokens' => 'unknown'],['max_tokens' => null],['max_tokens' => 37,'max_completion_tokens' => 8192]] as $fields) {
            $intake = ModelCallIntake::fromChannelPayload('http://fixture.invalid', $fields + ['model' => 'fixture','max_output_tokens' => 999]);
            self::assertSame($fields, $intake->outputBudget);
            self::assertSame($fields, $intake->toPayload()['outputBudget']);
        }
    }

    public function testAbsenceDoesNotInvent4096OrChangeTheHistoricalPayload(): void
    {
        $intake = ModelCallIntake::fromChannelPayload('http://fixture.invalid', ['model' => 'fixture']);
        self::assertNull($intake->outputBudget);
        self::assertArrayNotHasKey('outputBudget', $intake->toPayload());
    }
}
