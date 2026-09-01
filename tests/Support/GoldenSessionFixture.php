<?php

declare(strict_types=1);

namespace Milpa\Agent\Tests\Support;

use Milpa\Agent\Evidence;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;

/**
 * One deterministic long session, built the same way every time it is asked for.
 *
 * It exists for the golden comparison: the null-budget window must stay byte-identical to what
 * today's composition produces, and that can only be asserted against a fixture whose every event
 * is reproducible. Nothing here is random and nothing depends on the clock the summary can see.
 */
final class GoldenSessionFixture
{
    public const ID = 'golden-1';

    /** Populate `$store` with the fixture session and return its id. */
    public static function build(SessionStore $store): string
    {
        $store->start(self::ID, 'migrate the Inventario plugin to sqlite');
        $store->setPlan(self::ID, '1. entity  2. repository  3. controller');
        $store->setTodo(self::ID, new Todo('t1', 'write the entity', TodoStatus::Done));
        $store->setTodo(self::ID, new Todo('t2', 'write the repository'));
        $store->setTodo(self::ID, new Todo('t3', 'migrate the data', TodoStatus::Blocked));
        $store->setTodo(self::ID, new Todo('t4', 'write the controller', TodoStatus::Done));
        $store->grant(self::ID, 'make');
        $store->grant(self::ID, 'edit');

        $bigResult = (string) json_encode(
            ['capabilities' => array_fill(0, 60, 'a capability with its description')],
        );
        $store->recordToolCall(self::ID, 'capabilities', [], $bigResult, true, false, mb_strlen($bigResult));
        $store->recordToolCall(
            self::ID,
            'implement',
            ['plugin' => 'Inventario', 'class' => 'InventarioEntity'],
            (string) json_encode([
                'ok' => true,
                'file' => 'src/Plugins/Inventario/Entities/InventarioEntity.php',
                'verified' => 'syntax, strict_types, class and namespace',
            ]),
            true,
            true,
            awaitingConfirmation: false,
        );
        $store->recordToolCall(self::ID, 'test', [], 'ok: 12 tests green', true, false);
        $store->recordExecution(self::ID, 'artifact.implement', null, 'agent', null, 'sha256:golden-implement');
        $store->recordEvidence(
            self::ID,
            Evidence::artifact('e1', 'src/Plugins/Inventario/Entities/InventarioEntity.php'),
        );
        $store->ask(self::ID, new PendingQuestion(
            'q1',
            'keep the public class name?',
            ['yes', 'no'],
            '{"operation":"implement","arguments":{"class":"InventarioEntity"}}',
            reason: 'design',
        ));
        $store->answer(self::ID, 'q1', 'yes');

        for ($i = 1; $i <= 40; ++$i) {
            $content = $i % 7 === 0
                ? 'a fat deliberation turn ' . str_repeat('x', 1800) . " {$i}"
                : "turn {$i}";
            $store->recordTurn(self::ID, $i % 2 === 0 ? 'assistant' : 'user', $content);
        }

        return self::ID;
    }
}
