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

namespace Milpa\Agent\Tests;

use Milpa\Agent\ModelCallIntake;
use PHPUnit\Framework\TestCase;

/**
 * What the agent WAS GIVEN, derived from the payload that actually travelled.
 *
 * The stream has always recorded what an agent did and never what it received. This reads the intake
 * back out of the wire format — and the one rule it may not break is that it never invents: when the
 * payload does not say something, the intake says it does not know rather than filling the gap from
 * somewhere else.
 */
final class ModelCallIntakeTest extends TestCase
{
    public function testItReadsTheToolsAnOpenAiPayloadOffered(): void
    {
        $intake = ModelCallIntake::fromChannelPayload('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => 'you are a milpa agent'],
                ['role' => 'user', 'content' => 'hola'],
            ],
            'tools' => [
                ['type' => 'function', 'function' => ['name' => 'plugins_list', 'description' => 'a']],
                ['type' => 'function', 'function' => ['name' => 'config_set', 'description' => 'b']],
            ],
        ]);

        self::assertSame(['plugins_list', 'config_set'], $intake->tools);
        self::assertSame('gpt-4o', $intake->model);
        self::assertSame('you are a milpa agent', $intake->system);
        self::assertFalse($intake->toolsUnknown);
    }

    public function testItReadsTheOtherProvidersSpelling(): void
    {
        $intake = ModelCallIntake::fromChannelPayload('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-sonnet-5',
            'system' => 'you are a milpa agent',
            'messages' => [['role' => 'user', 'content' => 'hola']],
            'tools' => [
                ['name' => 'plugins_list', 'description' => 'a'],
                ['name' => 'config_set', 'description' => 'b'],
            ],
        ]);

        self::assertSame(['plugins_list', 'config_set'], $intake->tools);
        self::assertSame('claude-sonnet-5', $intake->model);
        self::assertSame('you are a milpa agent', $intake->system);
    }

    /**
     * A turn where no tools travelled and a turn whose tools this code could not read are DIFFERENT
     * facts, and collapsing them is the whole defect: one says the agent was offered nothing, the
     * other says the instrument cannot see. A surface that prints `0 tools` for both sends its reader
     * to debug the wrong world with confidence.
     */
    public function testAnEmptyOfferAndAnUnREADABLEOfferAreNotTheSameFact(): void
    {
        $nothingOffered = ModelCallIntake::fromChannelPayload('https://x/y', [
            'model' => 'm',
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ]);

        self::assertSame([], $nothingOffered->tools);
        self::assertFalse($nothingOffered->toolsUnknown, 'no tools key means no tools travelled');

        $unreadable = ModelCallIntake::fromChannelPayload('https://x/y', [
            'model' => 'm',
            'messages' => [['role' => 'user', 'content' => 'hola']],
            'tools' => [['a_shape_nobody_here_knows' => true]],
        ]);

        self::assertSame([], $unreadable->tools);
        self::assertTrue($unreadable->toolsUnknown, 'tools travelled but this code cannot name them');
    }

    /**
     * The conversation's CONTENT already lives in the stream as turns. What is not anywhere is its
     * shape at the moment of the send — after compaction dropped older turns, after the window slid.
     *
     * It used to record `{role, chars}` for exactly that reason, and greenhouse decisions/0039 changed
     * it: a size leaves «what did the agent receive» unanswerable, since two different messages of the
     * same length are indistinguishable. What the send-time record is FOR is answering that, and
     * evidence/0222 measured the price — +18%, against a 73% saving on the system it stopped copying.
     */
    public function testItRecordsWhatTravelledAndNotOnlyItsShape(): void
    {
        $intake = ModelCallIntake::fromChannelPayload('https://x/y', [
            'model' => 'm',
            'messages' => [
                ['role' => 'user', 'content' => 'hola'],
                ['role' => 'assistant', 'content' => 'buenas'],
                ['role' => 'user', 'content' => 'y ahora'],
            ],
        ]);

        self::assertSame(
            [
                ['role' => 'user', 'content' => 'hola'],
                ['role' => 'assistant', 'content' => 'buenas'],
                ['role' => 'user', 'content' => 'y ahora'],
            ],
            $intake->messages,
        );
    }

    /**
     * Nobody declared an omission, so the intake says nobody declared one — it does not go looking
     * for the full catalogue to subtract from. The moment it did, it would know something the channel
     * never said, which is the property this whole seam exists to keep.
     */
    public function testAnUndeclaredOmissionIsUNKNOWNAndNeverZero(): void
    {
        $intake = ModelCallIntake::fromChannelPayload('https://x/y', [
            'model' => 'm',
            'messages' => [['role' => 'user', 'content' => 'hola']],
            'tools' => [['name' => 'plugins_list']],
        ]);

        self::assertNull($intake->omitted, 'not zero: nobody said');
    }

    public function testTheIntakeSerializesToTheEventPayload(): void
    {
        $intake = ModelCallIntake::fromChannelPayload('https://llama.local/v1/chat/completions', [
            'model' => 'qwen3-coder:30b',
            'messages' => [['role' => 'user', 'content' => 'hola']],
            'tools' => [['name' => 'plugins_list']],
        ]);

        self::assertSame([
            'endpoint' => 'https://llama.local/v1/chat/completions',
            'model' => 'qwen3-coder:30b',
            'tools' => ['plugins_list'],
            'toolsUnknown' => false,
            'system_ref' => null,
            'messages' => [['role' => 'user', 'content' => 'hola']],
            'omitted' => null,
        ], $intake->toPayload());
    }
}
