<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiClient;
use App\Ai\AiFeature;
use App\Controller\HelpController;
use App\Dto\HelpAnswer;
use App\Entity\Favorite;
use App\Entity\MealEntry;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Hosting\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase 2 of the help system: answers a free-text question about NouriLoop. It
 * grounds Claude **only** in the help-center content (the {@see HelpController::TOPICS}
 * manifest + the plain text of the 2–3 most relevant pages, picked by keyword overlap
 * — no embeddings needed at ~10 topics), so answers can't wander into things the app
 * doesn't do. Returns a {@see HelpAnswer} (prose + recommended pages). Retrieval is
 * multilingual: the Unicode-safe, accent-folding tokenizer matches the question
 * against the English corpus PLUS every locale's keyword line from the translation
 * catalogs (`help_assistant.keywords.*`), so a Spanish/German/Russian/Hindi question
 * still pulls the right grounding page even though the help pages themselves stay
 * English (parked decision).
 *
 * Runs as a light feature ({@see AiFeature::HelpAssistant}), so the shared
 * {@see AiClient} answers it with the instance's cheaper model (Haiku on Anthropic). A
 * failure degrades to a deterministic fallback that just points at the matched pages,
 * so the widget always shows something useful. The client is injected lazily, so a
 * page that never asks a question doesn't resolve the API key.
 */
class HelpAssistantService
{
    private const MAX_TOKENS = 700;
    private const MAX_GROUNDING_PAGES = 3;
    /** Chars of each grounding page sent to Claude — enough for our short pages. */
    private const PAGE_TEXT_CAP = 1500;

    /**
     * Dropped from keyword matching — common words that don't signal a topic.
     * Multilingual (accent-folded forms, matching what {@see keywords()} produces):
     * question words and function words for the supported languages, so e.g. Spanish
     * "cómo"/"para" can't score a spurious hit against a curated keyword line.
     */
    private const STOPWORDS = [
        // en
        'the', 'a', 'an', 'and', 'or', 'but', 'how', 'do', 'i', 'to', 'my', 'in', 'on',
        'of', 'is', 'it', 'can', 'with', 'for', 'what', 'where', 'when', 'why', 'me',
        'you', 'your', 'this', 'that', 'are', 'be', 'get', 'use', 'using', 'app',
        'nouriloop', 'does', 'about', 'from', 'will', 'would', 'should', 'if', 'so',
        // es ("cómo" → "como", "qué" → "que" after folding)
        'como', 'que', 'por', 'para', 'con', 'una', 'uno', 'unos', 'unas', 'los', 'las',
        'del', 'mis', 'puedo', 'hacer', 'donde', 'cuando', 'esta', 'este', 'son', 'ser',
        'hay', 'mas', 'desde',
        // fr
        'comment', 'les', 'des', 'mon', 'mes', 'une', 'pour', 'avec', 'est', 'sont',
        'quoi', 'dans', 'faire', 'puis',
        // de ("für" → "fur")
        'wie', 'der', 'die', 'das', 'und', 'ich', 'ein', 'eine', 'mein', 'meine',
        'kann', 'was', 'ist', 'mit', 'fur', 'auf', 'von', 'wo', 'wann',
        // ru
        'как', 'что', 'мой', 'моя', 'мои', 'мне', 'это', 'для', 'где', 'когда', 'могу',
        // hi
        'कैसे', 'क्या', 'मैं', 'मेरा', 'मेरी', 'मेरे', 'कहां', 'कहाँ',
    ];

    private \Transliterator|false|null $accentFolder = null;

    /** @var array<string, string> per-request memo of localizedKeywords() by slug */
    private array $keywordLineMemo = [];

    public function __construct(
        #[Autowire(lazy: true)]
        private readonly AiClient $ai,
        private readonly HelpContentExtractor $content,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LocalePromptBuilder $localePrompt,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly Brand $brand,
    ) {
    }

    public function ask(string $question, ?User $user): HelpAnswer
    {
        $slugs = $this->getRelevantPages($question);

        try {
            $result = $this->ai->complete(
                AiFeature::HelpAssistant,
                $user,
                $this->buildPrompt($question, $slugs, $user),
                [['role' => 'user', 'content' => $question]],
                $this->localePrompt->maxTokens($this->localePrompt->currentLocale(), self::MAX_TOKENS),
            );

            return $this->parseAnswer($result->text, $slugs);
        } catch (\Throwable $e) {
            $this->logger->warning('Help assistant Claude call failed; using fallback.', ['exception' => $e]);

            return $this->fallback($slugs);
        }
    }

    /**
     * The help topics most relevant to the question, best first — by how many of the
     * question's keywords appear in each topic's title + summary + body text + the
     * per-locale keyword lines from the translation catalogs (see localizedKeywords()),
     * so a Spanish/German/… question matches even though the help corpus itself is
     * English. Empty when nothing overlaps (the prompt still ships the full manifest).
     *
     * @return list<string> topic slugs
     */
    public function getRelevantPages(string $question): array
    {
        $needles = $this->keywords($question);
        if ($needles === []) {
            return [];
        }

        $scored = [];
        foreach (HelpController::TOPICS as $slug => $meta) {
            $haystack = $this->keywords(
                $meta['title'] . ' ' . $meta['summary'] . ' '
                . $this->localizedKeywords($slug) . ' ' . $this->content->bodyText($slug),
            );
            $score = 0;
            foreach ($needles as $needle) {
                if (isset($haystack[$needle])) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[$slug] = $score;
            }
        }

        arsort($scored);

        return array_slice(array_keys($scored), 0, self::MAX_GROUNDING_PAGES);
    }

    /**
     * @param list<string> $slugs grounding pages
     */
    private function buildPrompt(string $question, array $slugs, ?User $user): string
    {
        $manifest = '';
        foreach (HelpController::topicsFor($this->brand->name()) as $slug => $meta) {
            $manifest .= sprintf("- %s (%s): %s\n", $meta['title'], $this->helpUrl($slug), $meta['summary']);
        }

        $pageContent = '';
        foreach ($slugs as $slug) {
            $body = mb_substr($this->content->bodyText($slug), 0, self::PAGE_TEXT_CAP);
            $pageContent .= sprintf(
                "## %s (%s)\n%s\n\n",
                HelpController::TOPICS[$slug]['title'],
                $this->helpUrl($slug),
                $body,
            );
        }
        if ($pageContent === '') {
            $pageContent = "(No page closely matched — answer from the topic list above, or say you're not sure.)\n";
        }

        $context = $user instanceof User ? $this->userContext($user) : '';

        // The instance's name, not always "NouriLoop": the answer is shown to the user, and
        // a self-hosted copy carries its operator's name (configurable branding, PR 6b).
        $name = $this->brand->name();

        return <<<PROMPT
            You are the {$name} help assistant. {$name} is a nutrition-tracking app:
            photo-based meal logging with AI nutrition estimates, daily inflammation scoring,
            health-metric imports, supplements, symptom tracking, glucose insights, and
            scheduled nutrition-goal check-ins.

            Answer the user's question clearly and concisely, in plain language for someone
            with no technical background. Base your answer ONLY on the help content below —
            if the answer isn't there, say you're not sure and suggest the closest topic.
            Do not invent features. Write plain prose (no Markdown headings or lists);
            **bold** is the only markup allowed.

            Help topics available:
            {$manifest}
            Relevant page content:
            {$pageContent}{$context}
            Respond with ONLY a JSON object, no prose around it, in exactly this shape:
            {"answer": "...", "pages": [{"title": "...", "url": "/help/slug", "reason": "..."}]}
            Include 1-3 of the most relevant pages (use the exact titles and URLs above). If
            none are relevant, use an empty pages array.
            PROMPT
        // The help corpus is English; the "answer" value localizes for
        // non-English visitors (request locale, so anonymous visitors count).
        // Page titles/URLs stay exactly as listed.
        . ($this->localePrompt->forCurrentLocale() !== ''
            ? "\n\n" . $this->localePrompt->forCurrentLocale()
                . 'Write the "answer" value in that language; keep the JSON shape and the '
                . 'page titles/URLs exactly as listed above.'
            : '');
    }

    private function userContext(User $user): string
    {
        try {
            $meals = $this->em->getRepository(MealEntry::class)->count(['user' => $user]);
            $favorites = $this->em->getRepository(Favorite::class)->count(['user' => $user]);
            $goals = $this->em->getRepository(NutritionGoal::class)->count(['user' => $user]);
            $name = $user->getDisplayName() ?: 'The user';

            return sprintf(
                "\nThe person asking is signed in: %s has logged %d meal(s) total, has %d saved "
                . "favorite(s), and %s set up nutrition goal(s). Tailor pointers to this when useful.\n",
                $name,
                $meals,
                $favorites,
                $goals > 0 ? 'has' : 'has not',
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Parse Claude's JSON reply into a HelpAnswer, validating page links against the
     * real help routes so a hallucinated URL can never reach the user.
     *
     * @param list<string> $slugs fallback grounding pages
     */
    private function parseAnswer(string $raw, array $slugs): HelpAnswer
    {
        $json = $this->decodeJson($raw);
        $answer = trim((string) ($json['answer'] ?? ''));
        if ($answer === '') {
            return $this->fallback($slugs);
        }

        $validUrls = $this->validHelpUrls();
        $pages = [];
        $seen = [];
        foreach ((array) ($json['pages'] ?? []) as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $url = (string) ($page['url'] ?? '');
            $slug = $validUrls[$url] ?? null;
            if ($slug === null || isset($seen[$url])) {
                continue; // unknown / hallucinated / duplicate link — drop it
            }
            $seen[$url] = true;
            $pages[] = [
                'title' => HelpController::TOPICS[$slug]['title'],
                'url' => $url,
                'reason' => trim((string) ($page['reason'] ?? '')),
            ];
            if (\count($pages) >= self::MAX_GROUNDING_PAGES) {
                break; // hard-cap at the documented 1-3 pages
            }
        }

        return new HelpAnswer($answer, $pages);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        // Tolerate a ```json fence or stray prose around the object.
        if (preg_match('/\{.*\}/s', $raw, $m) === 1) {
            $raw = $m[0];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Deterministic answer when Claude is unavailable: point at the matched pages.
     *
     * @param list<string> $slugs
     */
    private function fallback(array $slugs): HelpAnswer
    {
        $pages = [];
        foreach ($slugs as $slug) {
            $pages[] = [
                'title' => HelpController::TOPICS[$slug]['title'],
                'url' => $this->helpUrl($slug),
                'reason' => HelpController::topicsFor($this->brand->name())[$slug]['summary'],
            ];
        }

        // Localized like the Claude path (request locale, anonymous included) —
        // a Claude outage must degrade in the visitor's language too. Page
        // titles/reasons stay English with the rest of the help corpus.
        $answer = $pages === []
            ? $this->translator->trans('help_assistant.fallback_none')
            : $this->translator->trans('help_assistant.fallback_intro');

        return new HelpAnswer($answer, $pages);
    }

    /**
     * Lowercased, accent-folded keyword set as `word => word` (deduped). The split is
     * Unicode-aware — any letter/digit in any script survives, and combining marks
     * (\p{M}) count as word characters because Devanagari vowel signs/virama are
     * marks, not letters: without them "भोजन" would shatter at every matra. Latin
     * diacritics are folded to ASCII ("cómo" → "como",
     * "inflamación" → "inflamacion") so accented and unaccented spellings match; the
     * folding is applied to both the question and the haystack, so it can never
     * *break* a match. Non-Latin scripts pass through Latin-ASCII untouched.
     *
     * @return array<string, string>
     */
    private function keywords(string $text): array
    {
        $text = mb_strtolower($text);
        $translit = $this->accentFolder();
        if ($translit instanceof \Transliterator) {
            $text = $translit->transliterate($text) ?: $text;
        }
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_flip(self::STOPWORDS);
        $out = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || isset($stop[$word])) {
                continue;
            }
            $out[$word] = $word;
        }

        return $out;
    }

    /** intl ships Latin-ASCII everywhere we run; the false guard just keeps a broken ICU from fataling. */
    private function accentFolder(): \Transliterator|false
    {
        return $this->accentFolder ??= (\Transliterator::create('Latin-ASCII') ?? false);
    }

    /**
     * Every supported locale's curated search-keyword line for one topic, from the
     * translation catalogs (`help_assistant.keywords.<slug>`, in en + all five full
     * catalogs — CatalogConsistencyTest enforces that; override locales fall back to
     * their base). All locales are folded in — not just the active request locale —
     * because the question's language and the UI locale are independent (an anonymous
     * visitor with an English browser can ask in Spanish). Memoized per request.
     */
    private function localizedKeywords(string $slug): string
    {
        if (isset($this->keywordLineMemo[$slug])) {
            return $this->keywordLineMemo[$slug];
        }

        $id = 'help_assistant.keywords.' . $slug;
        $lines = [];
        foreach (SupportedLocale::cases() as $locale) {
            $line = $this->translator->trans($id, [], 'messages', $locale->value);
            if ($line !== $id) { // trans() echoes the id when the key is missing everywhere
                $lines[$line] = true; // fallback locales repeat the en line — dedupe
            }
        }

        return $this->keywordLineMemo[$slug] = implode(' ', array_keys($lines));
    }

    private function helpUrl(string $slug): string
    {
        return $this->urlGenerator->generate('app_help_show', ['topic' => $slug]);
    }

    /** @return array<string, string> help URL => slug, for validating returned links */
    private function validHelpUrls(): array
    {
        $map = [];
        foreach (array_keys(HelpController::TOPICS) as $slug) {
            $map[$this->helpUrl($slug)] = $slug;
        }

        return $map;
    }
}
