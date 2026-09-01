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

use Milpa\Agent\Compactor;
use Milpa\Agent\FactualSummarizer;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Agent\WindowBudget;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The whole window fits the model that has to read it — enforced by construction, never by luck.
 *
 * Measured on greenhouse evidence/0443: with every tool result capped at 600 chars and the turn
 * tail budgeted, a real session still re-entered a 32,768-token context at 35.6k tokens, because
 * NOTHING bounded the system side — the operational-facts block alone had grown to ~12.7k tokens.
 * These tests are the acceptance criteria for the fix: composed-under-budget on a deep session,
 * byte-identical composition when no budget is declared, and every elision NAMED with the full
 * record still queryable.
 */
final class WindowBudgetTest extends TestCase
{
    private const CONTEXT = 32768;

    /** A session deep enough that an unbudgeted composition provably overflows the target. */
    private function deepStore(): SessionStore
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('deep', 'build the tasks application end to end');
        $store->setPlan('deep', '1. entities  2. services  3. controllers  4. tests  5. preview');
        for ($i = 1; $i <= 18; ++$i) {
            $store->setTodo('deep', new Todo(
                "t{$i}",
                "step {$i} of the build, with enough words to weigh something",
                $i <= 12 ? TodoStatus::Done : TodoStatus::Pending,
            ));
        }
        $store->grant('deep', 'make');
        $store->grant('deep', 'edit');

        $fat = '{"ok":true,"detail":"' . str_repeat('d', 1400) . '"}';
        for ($i = 1; $i <= 90; ++$i) {
            $store->recordToolCall(
                'deep',
                'tool_' . ($i % 9),
                ['path' => "src/Generated/File{$i}.php"],
                $fat,
                true,
                $i % 3 === 0,
                mb_strlen($fat),
            );
        }
        for ($i = 1; $i <= 10; ++$i) {
            $store->recordExecution('deep', "op.exec{$i}", null, 'agent', null, "sha256:x{$i}");
        }
        $store->ask('deep', new PendingQuestion('q1', 'keep the schema?', ['yes', 'no'], reason: 'design'));
        $store->answer('deep', 'q1', 'yes');
        for ($i = 1; $i <= 30; ++$i) {
            $content = $i % 5 === 0
                ? 'a long deliberation ' . str_repeat('x', 2500) . " {$i}"
                : "conversation turn {$i}";
            $store->recordTurn('deep', $i % 2 === 0 ? 'assistant' : 'user', $content);
        }

        return $store;
    }

    /** @return array<string, mixed> the facts block parsed back out of a written summary */
    private static function factsOutOf(string $summary): array
    {
        $at = mb_strpos($summary, FactualSummarizer::FACTS_LABEL);
        self::assertNotFalse($at, 'the summary must carry the facts block');
        $decoded = json_decode(
            mb_substr($summary, $at + mb_strlen(FactualSummarizer::FACTS_LABEL)),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function estimatedTokens(Session $session, ?int $contextTokens): int
    {
        $chars = 0;
        foreach ($session->window($contextTokens) as $message) {
            $chars += mb_strlen($message['content']);
        }

        return intdiv($chars, WindowBudget::CHARS_PER_TOKEN);
    }

    /** Every share derives from the one number the model declares, and they add up to the target. */
    public function testTheSharesDeriveFromTheDeclaredContext(): void
    {
        $budget = new WindowBudget(self::CONTEXT);

        self::assertSame(19660, $budget->composedTokens, '60% of the context: the rest is system prompt, tool catalogue, generation');
        self::assertSame(9830, $budget->turnTokens);
        self::assertSame(5898, $budget->factsTokens);
        self::assertSame(1966, $budget->proseTokens);
        self::assertSame(1966, $budget->briefingTokens);
        self::assertLessThanOrEqual(
            $budget->composedTokens,
            $budget->turnTokens + $budget->factsTokens + $budget->proseTokens + $budget->briefingTokens,
        );
        self::assertSame(4 * $budget->factsTokens, $budget->chars($budget->factsTokens));
    }

    /** A context that cannot budget anything refuses instead of silently composing nonsense. */
    public function testAContextTooSmallToBudgetRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WindowBudget(0);
    }

    /** The clamp cuts WITH THE CUT NAMED — and does not touch what already fits. */
    public function testTheClampNamesTheCutAndLeavesWhatFitsAlone(): void
    {
        self::assertSame('already short', WindowBudget::clamp('already short', 100));

        $cut = WindowBudget::clamp(str_repeat('a', 500), 120);
        self::assertLessThanOrEqual(120, mb_strlen($cut));
        self::assertStringContainsString('[window budget: elided; the full stream persists]', $cut);
    }

    /**
     * ACCEPTANCE (a): a deep session composes at or under ~60% of the declared context.
     *
     * The headroom is the point — system prompt, tool schema catalogue and generation live in the
     * other 40%, measured at ~12k tokens of the request that died (evidence/0443).
     */
    public function testADeepSessionComposesUnderTheBudget(): void
    {
        $store = $this->deepStore();
        $session = $store->load('deep') ?? self::fail('no session');

        $compactor = new Compactor(maxTurns: 40, keepRecent: 10, windowBudget: self::CONTEXT);
        self::assertNotNull($compactor->compactIfNeeded($store, $session));

        $after = $store->load('deep') ?? self::fail('no session after compaction');
        $estimate = self::estimatedTokens($after, self::CONTEXT);

        self::assertLessThanOrEqual(
            intdiv(self::CONTEXT * 3, 5),
            $estimate,
            'the composed window must leave 40% of the context to what composition cannot see',
        );
    }

    /**
     * THE CONTROL for the acceptance: the same session, unbudgeted, overflows the target.
     *
     * Without this the assertion above could pass on a fixture too thin to prove anything —
     * the instrument before the finding.
     */
    public function testTheSameSessionWithoutABudgetOverflowsTheTarget(): void
    {
        $store = $this->deepStore();
        $session = $store->load('deep') ?? self::fail('no session');

        $compactor = new Compactor(maxTurns: 40, keepRecent: 10);
        self::assertNotNull($compactor->compactIfNeeded($store, $session));

        $after = $store->load('deep') ?? self::fail('no session after compaction');

        self::assertGreaterThan(
            intdiv(self::CONTEXT * 3, 5),
            self::estimatedTokens($after, null),
            'the fixture must be fat enough that the budget is what makes it fit',
        );
    }

    /**
     * ACCEPTANCE (c): the elision is named in the block, and the full set stays queryable.
     *
     * The trim touches only the model-facing projection. `SessionFacts` — the recovery contract —
     * still answers with every operation ever recorded, and nothing in the stream moved.
     */
    public function testTheElisionIsNamedAndSessionFactsStillReturnsTheFullSet(): void
    {
        $store = $this->deepStore();
        $session = $store->load('deep') ?? self::fail('no session');
        (new Compactor(maxTurns: 40, keepRecent: 10, windowBudget: self::CONTEXT))
            ->compactIfNeeded($store, $session);
        $after = $store->load('deep') ?? self::fail('no session after compaction');

        $facts = self::factsOutOf($after->summary ?? self::fail('no summary'));

        self::assertIsInt($facts['elided']);
        self::assertGreaterThan(0, $facts['elided']);
        self::assertSame('query session facts for older operations', $facts['note']);
        self::assertIsArray($facts['calls']);
        self::assertLessThan(90, \count($facts['calls']), 'something was actually elided');
        self::assertIsArray($facts['decisions']);
        self::assertCount(1, $facts['decisions'], 'human decisions are never dropped');

        $full = $store->facts('deep')->operationalFacts($after->compactedThrough);
        self::assertIsArray($full['calls']);
        self::assertCount(90, $full['calls'], 'the projection was trimmed; the query was not');
        self::assertArrayNotHasKey('elided', $full, 'the query answer does not inherit the window trim');

        // The MOST RECENT facts stay whole: the last kept call is the last recorded one.
        $keptCalls = array_values($facts['calls']);
        $lastKept = $keptCalls[\count($keptCalls) - 1];
        $lastRecorded = $full['calls'][\count($full['calls']) - 1];
        self::assertIsArray($lastKept);
        self::assertIsArray($lastRecorded);
        self::assertSame($lastRecorded['seq'], $lastKept['seq']);
    }

    /** Oldest first means oldest by recorded sequence ACROSS the lists, not per list. */
    public function testFitDropsTheOldestBySequenceAcrossListsAndSparesDecisions(): void
    {
        $pad = str_repeat('p', 260);
        $facts = [
            'schema' => 'milpa.agent.operational-facts.v1',
            'session' => 's',
            'calls' => [
                ['seq' => 1, 'operation' => 'a', 'pad' => $pad],
                ['seq' => 5, 'operation' => 'b', 'pad' => $pad],
            ],
            'executions' => [
                ['seq' => 3, 'operation' => 'c', 'pad' => $pad],
            ],
            'decisions' => [
                ['id' => 'q1', 'answer' => 'yes'],
            ],
            'evidence' => [],
            'workState' => [
                ['artifact' => 'X', 'pad' => $pad],
            ],
        ];

        // Measured on this fixture: 800 chars leaves room for one padded entry — seq 1 (calls)
        // goes first, then seq 3 (executions), and the drop stops BEFORE touching workState.
        $fitted = FactualSummarizer::fitOperationalFacts($facts, 800);

        self::assertIsArray($fitted['calls']);
        self::assertCount(1, $fitted['calls']);
        self::assertSame(5, $fitted['calls'][0]['seq'], 'the newest call is what survives');
        self::assertSame([], $fitted['executions']);
        self::assertIsArray($fitted['workState']);
        self::assertCount(1, $fitted['workState'], 'workState is not touched while sequenced lists can still pay');
        self::assertSame([['id' => 'q1', 'answer' => 'yes']], $fitted['decisions']);
        self::assertSame(2, $fitted['elided']);
        self::assertSame('query session facts for older operations', $fitted['note']);

        // And when even that is too much, workState pays last — the decision never does.
        $squeezed = FactualSummarizer::fitOperationalFacts($facts, 500);

        self::assertSame([], $squeezed['calls']);
        self::assertSame([], $squeezed['executions']);
        self::assertSame([], $squeezed['workState']);
        self::assertSame([['id' => 'q1', 'answer' => 'yes']], $squeezed['decisions']);
        self::assertSame(4, $squeezed['elided']);
    }

    /** What already fits is returned untouched — no elision field invented for nothing. */
    public function testFitLeavesAFittingValueByteUntouched(): void
    {
        $facts = ['schema' => 's', 'calls' => [['seq' => 1]], 'decisions' => []];

        self::assertSame($facts, FactualSummarizer::fitOperationalFacts($facts, 10_000));
    }

    /** The declared context caps the tail even where nobody set `maxTokens` — and the narrower wins. */
    public function testTheDeclaredContextCapsTheUnsummarizedTail(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        for ($i = 1; $i <= 8; ++$i) {
            $store->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', str_repeat('t', 1300));
        }
        $session = $store->load('s1') ?? self::fail('no session');

        // ~2600 estimated tokens of tail.
        self::assertFalse(
            (new Compactor(maxTurns: 40, keepRecent: 4))->shouldCompact($session),
            'without a budget the turn count decides, and 8 < 40',
        );
        self::assertTrue(
            (new Compactor(maxTurns: 40, keepRecent: 4, windowBudget: 8000))->shouldCompact($session),
            'a declared 8k context makes the turns share 2400 tokens, and the tail is past it',
        );
        self::assertTrue(
            (new Compactor(maxTurns: 40, keepRecent: 4, maxTokens: 2000, windowBudget: 800000))->shouldCompact($session),
            'an explicit maxTokens narrower than a huge budget still wins',
        );
        self::assertFalse(
            (new Compactor(maxTurns: 40, keepRecent: 4, maxTokens: 2000000, windowBudget: 800000))->shouldCompact($session),
            'and a wide pair stays quiet: the budget never widens an explicit cap into firing',
        );
    }

    /** Closed todos collapse to a count under the briefing budget; the open ones stay whole. */
    public function testTheBriefingCollapsesClosedTodosUnderItsBudget(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->setPlan('s1', 'ship the feature');
        for ($i = 1; $i <= 40; ++$i) {
            $store->setTodo('s1', new Todo("d{$i}", "already done piece {$i}", TodoStatus::Done));
        }
        $store->setTodo('s1', new Todo('o1', 'the very next open step'));
        $store->setTodo('s1', new Todo('o2', 'the second open step', TodoStatus::InProgress));
        $session = $store->load('s1') ?? self::fail('no session');

        $full = $session->stateBriefing();
        self::assertNotNull($full);
        self::assertStringContainsString('already done piece 40', $full);
        self::assertSame($full, $session->stateBriefing(100_000), 'a roomy budget changes nothing');

        $bounded = $session->stateBriefing(600);
        self::assertNotNull($bounded);
        self::assertLessThanOrEqual(600, mb_strlen($bounded));
        self::assertStringContainsString('the very next open step', $bounded);
        self::assertStringContainsString('the second open step', $bounded);
        self::assertStringNotContainsString('already done piece', $bounded);
        self::assertStringContainsString('40 tareas cerradas', $bounded, 'the collapse is a named count, not an absence');
    }

    /** Composition drops the OLDEST turns when they cannot fit — and says so in the window. */
    public function testCompositionDropsOldestTurnsAndNamesTheElision(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        for ($i = 1; $i <= 50; ++$i) {
            $store->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', "turn {$i} " . str_repeat('c', 390));
        }
        $session = $store->load('s1') ?? self::fail('no session');

        $classified = $session->classifiedWindow(4000);

        $chars = 0;
        foreach ($classified as $message) {
            $chars += mb_strlen($message['content']);
        }
        self::assertLessThanOrEqual(4000 * 3 / 5 * WindowBudget::CHARS_PER_TOKEN, $chars);

        self::assertSame('system', $classified[0]['role']);
        self::assertStringContainsString('older turns elided from this window', $classified[0]['content']);
        self::assertStringContainsString('s1', $classified[0]['content'], 'it says where the full record lives');
        self::assertStringContainsString('turn 50', $classified[\count($classified) - 1]['content']);
        self::assertSame(
            [],
            array_filter($classified, static fn (array $m): bool => str_contains($m['content'], 'turn 1 ')),
            'the oldest turn is what pays',
        );
    }

    /** An oversized summary written before budgets existed is re-bounded by the same named rule. */
    public function testAnOversizedPreBudgetSummaryIsReboundAtComposition(): void
    {
        $calls = [];
        for ($i = 1; $i <= 60; ++$i) {
            $calls[] = ['seq' => $i, 'operation' => "op{$i}", 'pad' => str_repeat('z', 700)];
        }
        $oldSummary = "prose written long ago\n" . FactualSummarizer::FACTS_LABEL
            . FactualSummarizer::encodeFacts([
                'schema' => 'milpa.agent.operational-facts.v1',
                'session' => 's1',
                'calls' => $calls,
                'executions' => [],
                'decisions' => [['id' => 'q1', 'answer' => 'yes']],
                'evidence' => [],
                'workState' => [],
            ]);

        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->recordTurn('s1', 'user', 'recent turn');
        $store->compact('s1', $oldSummary, 0);
        $session = $store->load('s1') ?? self::fail('no session');

        $budget = new WindowBudget(16000);
        $window = $session->classifiedWindow(16000);
        self::assertSame('summary', $window[0]['class']);
        self::assertLessThanOrEqual(
            $budget->chars($budget->proseTokens + $budget->factsTokens),
            mb_strlen($window[0]['content']),
        );

        $facts = self::factsOutOf($window[0]['content']);
        self::assertIsInt($facts['elided']);
        self::assertGreaterThan(0, $facts['elided']);
        self::assertIsArray($facts['calls']);
        $kept = array_values($facts['calls']);
        self::assertSame(60, $kept[\count($kept) - 1]['seq'], 'newest facts survive the re-bound');
        self::assertSame([['id' => 'q1', 'answer' => 'yes']], $facts['decisions']);
        self::assertStringContainsString('prose written long ago', $window[0]['content']);
    }

    /** A summary that does not parse as prose-plus-facts is clamped whole, the cut still named. */
    public function testAnUnparsableOversizedSummaryIsClampedWithTheCutNamed(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->recordTurn('s1', 'user', 'recent turn');
        $store->compact('s1', str_repeat('no structure here ', 3000), 0);
        $session = $store->load('s1') ?? self::fail('no session');

        $budget = new WindowBudget(16000);
        $window = $session->classifiedWindow(16000);

        self::assertSame('summary', $window[0]['class']);
        self::assertLessThanOrEqual(
            $budget->chars($budget->proseTokens + $budget->factsTokens),
            mb_strlen($window[0]['content']),
        );
        self::assertStringContainsString('[window budget: elided; the full stream persists]', $window[0]['content']);
    }

    /** A custom summarizer's prose is clamped under the budget too — never silently. */
    public function testACustomSummarizersProseIsClampedUnderTheBudget(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        for ($i = 1; $i <= 50; ++$i) {
            $store->recordTurn('s1', $i % 2 === 0 ? 'assistant' : 'user', "turno {$i}");
        }
        $compactor = new Compactor(
            maxTurns: 40,
            keepRecent: 12,
            summarizer: new class () implements \Milpa\Agent\Summarizer {
                public function summarize(Session $session, int $throughSeq): string
                {
                    return str_repeat('verbose model prose ', 2000);
                }
            },
            windowBudget: 8000,
        );

        $summary = $compactor->compactIfNeeded($store, $store->load('s1') ?? self::fail('no session'));

        self::assertNotNull($summary);
        $budget = new WindowBudget(8000);
        $proseAndLabel = mb_substr($summary, 0, (int) mb_strpos($summary, FactualSummarizer::FACTS_LABEL));
        self::assertLessThanOrEqual($budget->chars($budget->proseTokens) + 2, mb_strlen($proseAndLabel));
        self::assertStringContainsString('[window budget: elided; the full stream persists]', $summary);
        self::assertStringContainsString(FactualSummarizer::FACTS_LABEL, $summary, 'the facts contract survives the clamp');
    }
    public function testEvidenceFallsByItsRecordedAgeNotFirst(): void
    {
        $facts = [
            'calls' => [['seq' => 5, 'tool' => 'make', 'resultSummary' => str_repeat('a', 400)]],
            'executions' => [['seq' => 8, 'operation' => 'make', 'digest' => str_repeat('b', 200)]],
            'evidence' => [[
                'kind' => 'test passed',
                'reference' => str_repeat('c', 200),
                'source' => ['event' => 'session.evidence_recorded', 'seq' => 12],
            ]],
            'decisions' => [],
            'workState' => [],
        ];
        $whole = \strlen((string) json_encode($facts));

        $fitted = FactualSummarizer::fitOperationalFacts($facts, $whole - 100);

        self::assertSame([], $fitted['calls'], 'the OLDEST recorded fact (the call, seq 5) falls first');
        self::assertCount(1, $fitted['executions'], 'younger than the call, still standing');
        self::assertCount(1, $fitted['evidence'], 'evidence (seq 12) is the youngest — it must NOT drain first');
        self::assertSame(1, $fitted['elided'], 'the drop is named, never silent');
    }

}
