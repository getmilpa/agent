<?php

/**
 * This file is part of milpa/agent.
 *
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\Agent\Tests;

use Milpa\Agent\FactualSummarizer;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Agent\Tests\Support\LegacyTodoWriter;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * What the house itself writes for the model and the human speaks the house's language — English
 * (greenhouse decisions/0138, 0514).
 *
 * evidence/1036 found every compaction summary of an English session opening with «Objetivo de la
 * sesión…», and the consent question in Spanish, in an English-first house: the resident read its own
 * session back in a language nobody on it spoke. With English content in, nothing the house composes
 * around it may carry Spanish. The probe is the characters Spanish needs and English never does, so a
 * new label written in Spanish fails here without anyone listing it.
 *
 * @internal
 */
final class TheHouseSpeaksEnglishTest extends TestCase
{
    /** Spanish diacritics, or a common Spanish word — 1036's hint «arranca desde el siguiente…» had no accent. */
    private const SPANISH_ONLY = '/[áéíóúñ¿¡]|\\b(que|para|desde|del|los|las|una|esta|este|siguiente|corre|arranca|nombre|pendiente|objetivo|sesi[oó]n|autorizas?|petici[oó]n|nombra|hecho|resumen|herramientas|contesta|pídele|dile|confirmas|sobre|quiere|correr)\\b/iu';

    /** The probe's own positive control: it sees the Spanish 1036 recorded, accented or not. */
    public function testTheProbeSeesWhat1036Recorded(): void
    {
        foreach (['Objetivo de la sesión: x.', 'arranca desde el siguiente comando o request — corre `plugins.list` para verlo',
            'El agente quiere correr «make». ¿Lo autorizas en esta sesión?', 'Pendiente: t2.'] as $spanish) {
            self::assertMatchesRegularExpression(self::SPANISH_ONLY, $spanish);
        }
        self::assertDoesNotMatchRegularExpression(self::SPANISH_ONLY, 'Session goal: build the blog. Still to do: write the controller.');
    }

    public function testTheCompactionSummaryTheWindowAndTheBoardAreEnglish(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'build the blog');
        $store->setPlan('s1', '1. entity  2. controller');
        LegacyTodoWriter::write($events, 's1', new Todo('t1', 'write the entity', TodoStatus::Done));
        $store->setTodo('s1', new Todo('t2', 'write the controller'));
        $store->setTodo('s1', new Todo('t3', 'migrate the data', TodoStatus::Blocked));
        $store->grant('s1', 'make');
        $store->recordToolCall('s1', 'make', [], 'ok');
        $store->ask('s1', new PendingQuestion('perm:make', 'Allow make?', ['yes', 'no']));
        $store->answer('s1', 'perm:make', 'yes');

        $session = $store->load('s1');
        self::assertNotNull($session);
        $summary = (new FactualSummarizer())->summarize($session, \PHP_INT_MAX);
        $store->compact('s1', $summary, \PHP_INT_MAX);
        $compacted = $store->load('s1');
        self::assertNotNull($compacted);

        $composed = [
            'summary' => $summary,
            'window' => implode("\n", array_map(static fn (array $m): string => $m['content'], $compacted->window())),
            'board' => (string) $compacted->stateBriefing(),
            'collapsed board' => (string) $compacted->stateBriefing(60),
        ];
        foreach ($composed as $what => $text) {
            self::assertNotSame('', $text, "the {$what} was composed");
            self::assertDoesNotMatchRegularExpression(self::SPANISH_ONLY, $text, "the {$what} speaks English");
        }
        self::assertStringContainsString('Session goal: build the blog.', $summary);

        // A goal that already ends its sentence is not given a second period (1036: «…is served..»).
        $store->start('s2', 'Confirm /blog is served.');
        $second = $store->load('s2');
        self::assertNotNull($second);
        self::assertStringStartsWith("Session goal: Confirm /blog is served.\n", (new FactualSummarizer())->summarize($second, \PHP_INT_MAX));
        self::assertStringContainsString('Still to do:', $summary);
    }

    public function testTheQuestionsTheHouseAsksAreEnglish(): void
    {
        $policy = new SessionPolicy();
        foreach ([
            $policy->permissionQuestion('make', []),
            $policy->permissionQuestion('make', ['plugin' => 'Blog']),
            $policy->signatureQuestion('capabilities:enable', []),
        ] as $question) {
            self::assertDoesNotMatchRegularExpression(self::SPANISH_ONLY, $question->question);
            self::assertDoesNotMatchRegularExpression(self::SPANISH_ONLY, (string) $question->why);
        }
    }
}
