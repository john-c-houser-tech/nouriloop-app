<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Service\HelpAssistantService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase-2 help assistant: POST /help/ask answers questions grounded in the help
 * pages, with Claude mocked (FakeAnthropicResponder) so it's deterministic. Also
 * exercises keyword retrieval, the same-origin guard, validation, and the anon
 * rate limit. In-process WebTestCase (no browser); kept under tests/E2E so it runs
 * in the DB-having e2e CI job alongside HelpPagesTest.
 */
final class HelpAssistantTest extends WebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    public function testAskReturnsAnswerAndValidatedPages(): void
    {
        $client = static::createClient();
        $this->resetAnonLimiter();

        $client->request('POST', '/help/ask', ['question' => 'How do I log a meal?'], [], self::XHR);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotSame('', $data['answer']);
        // The mock answer carries **bold**; answerHtml must render it as <strong> (the inline_md guard).
        self::assertStringContainsString('<strong>Log</strong>', $data['answerHtml']);
        self::assertStringNotContainsString('**', $data['answerHtml']);
        // Returned page links are validated against real help routes.
        self::assertSame('/help/log-meal', $data['pages'][0]['url']);
    }

    public function testRejectsCrossSiteRequestWithoutXhrHeader(): void
    {
        $client = static::createClient();
        $client->request('POST', '/help/ask', ['question' => 'How do I log a meal?']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectsEmptyQuestion(): void
    {
        $client = static::createClient();
        $this->resetAnonLimiter();

        $client->request('POST', '/help/ask', ['question' => '   '], [], self::XHR);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnonRateLimitReturns429(): void
    {
        $client = static::createClient();
        $this->resetAnonLimiter();

        // Anon limit is 5/hour by IP; the 6th request trips it.
        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/help/ask', ['question' => 'How do I log a meal?'], [], self::XHR);
            self::assertResponseIsSuccessful();
        }
        $client->request('POST', '/help/ask', ['question' => 'How do I log a meal?'], [], self::XHR);
        self::assertResponseStatusCodeSame(429);
    }

    public function testBlankQuestionsDoNotSpendTheRateLimit(): void
    {
        $client = static::createClient();
        $this->resetAnonLimiter();

        // Rejected before the limiter: ten blank submits cost nothing…
        for ($i = 0; $i < 10; $i++) {
            $client->request('POST', '/help/ask', ['question' => '   '], [], self::XHR);
            self::assertResponseStatusCodeSame(400);
        }
        // …so the anon allowance (5/hour) is still whole.
        $client->request('POST', '/help/ask', ['question' => 'How do I log a meal?'], [], self::XHR);
        self::assertResponseIsSuccessful();
    }

    public function testKeywordRetrievalRanksRelevantTopics(): void
    {
        static::createClient();
        $assistant = static::getContainer()->get(HelpAssistantService::class);

        self::assertContains('log-meal', $assistant->getRelevantPages('how do I log a meal from a photo'));
        self::assertContains('supplements', $assistant->getRelevantPages('scan a supplement label for nutrients'));
        self::assertContains('glucose', $assistant->getRelevantPages('see my blood sugar response after a meal'));
    }

    /**
     * Multilingual retrieval: a Spanish question matches the per-locale keyword
     * lines (help_assistant.keywords.* in the es catalog) even though the help
     * corpus itself is English — the Copilot-flagged PR #154 limitation.
     */
    public function testSpanishQuestionsRetrieveGroundingPages(): void
    {
        static::createClient();
        $assistant = static::getContainer()->get(HelpAssistantService::class);

        self::assertContains('log-meal', $assistant->getRelevantPages('¿Cómo registro una comida?'));
        self::assertContains(
            'supplements',
            $assistant->getRelevantPages('escanear una etiqueta de suplemento'),
        );
        self::assertContains('glucose', $assistant->getRelevantPages('picos de azúcar en la sangre'));
        self::assertContains('connect-cgm', $assistant->getRelevantPages('conectar mi sensor Lingo al iPhone'));
    }

    /**
     * Accent folding runs on both sides, so an unaccented spelling still matches an
     * accented catalog keyword ("inflamacion" ↔ "inflamación") and vice versa.
     */
    public function testAccentFoldingMatchesEitherSpelling(): void
    {
        static::createClient();
        $assistant = static::getContainer()->get(HelpAssistantService::class);

        self::assertContains('inflammation', $assistant->getRelevantPages('mi puntuación de inflamación'));
        self::assertContains('inflammation', $assistant->getRelevantPages('mi puntuacion de inflamacion'));
    }

    /**
     * Every full catalog carries keyword lines, so German, French, and the non-Latin
     * scripts (Cyrillic, Devanagari) all retrieve — the tokenizer splits on Unicode
     * letters, not [a-z].
     */
    public function testAllFullLocalesRetrieveGroundingPages(): void
    {
        static::createClient();
        $assistant = static::getContainer()->get(HelpAssistantService::class);

        self::assertContains('log-meal', $assistant->getRelevantPages('Mahlzeit per Foto erfassen'));
        self::assertContains(
            'supplements',
            $assistant->getRelevantPages('scanner une étiquette de complément'),
        );
        self::assertContains('log-meal', $assistant->getRelevantPages('как записать приём пищи'));
        self::assertContains('log-meal', $assistant->getRelevantPages('मैं भोजन कैसे दर्ज करूं'));
    }

    /**
     * A language outside the supported set still tokenizes safely (no crash, no
     * mangling) — it just matches nothing until a catalog exists for it.
     */
    public function testUnsupportedScriptIsTokenizedSafely(): void
    {
        static::createClient();
        $assistant = static::getContainer()->get(HelpAssistantService::class);

        self::assertSame([], $assistant->getRelevantPages('πώς καταγράφω ένα γεύμα'));
    }

    /** Clear the anon limiter for the test IP so rate state can't leak between tests. */
    private function resetAnonLimiter(): void
    {
        $factory = static::getContainer()->get('limiter.help_assistant_anon');
        $factory->create('help-ip-127.0.0.1')->reset();
    }
}
