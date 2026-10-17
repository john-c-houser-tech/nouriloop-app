<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Hosting\Brand;
use App\Service\HelpAssistantService;
use App\Twig\TextExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public help center: a topic index at /help, one page per feature at
 * /help/{topic}, and the natural-language assistant at POST /help/ask (Phase 2).
 * TOPICS is the single source of truth for the slug → title/summary registry
 * (also feeds the assistant's grounding manifest).
 */
#[Route('/help')]
class HelpController extends AbstractController
{
    /** Hard cap on a question's length before it reaches the model. */
    private const MAX_QUESTION_LEN = 500;

    /** @var array<string, array{title: string, summary: string}> */
    public const TOPICS = [
        'dashboard' => [
            'title'   => 'Your Dashboard',
            'summary' => "The Today view: your latest meals, today's inflammation score, and quick symptom check-ins.",
        ],
        'log-meal' => [
            'title'   => 'Logging a Meal',
            'summary' => 'Snap a photo, let the AI estimate the nutrition, then confirm and adjust before saving.',
        ],
        'favorites' => [
            'title'   => 'Favorite Meals',
            'summary' => 'Save meals you eat often and re-log them in one tap — no new photo or AI analysis needed.',
        ],
        'import-recipe' => [
            'title'   => 'Importing a Recipe',
            'summary' => 'Photograph a cookbook page or handwritten recipe card — NouriLoop reads it, '
                . 'works out per-serving nutrition, and saves it as a favorite.',
        ],
        'health-metrics' => [
            'title'   => 'Importing Health Data',
            'summary' => 'Bring in weight, glucose, heart rate, steps, and sleep from Apple Health or other apps.',
        ],
        'supplements' => [
            'title'   => 'Supplements',
            'summary' => 'Scan a supplement label and NouriLoop folds it into your nutrient-adequacy picture.',
        ],
        'inflammation' => [
            'title'   => 'Inflammation Score',
            'summary' => 'A daily, research-based estimate of how pro- or anti-inflammatory your food was.',
        ],
        'symptoms' => [
            'title'   => 'Symptom Tracking',
            'summary' => 'Rate symptoms like joint pain or energy each day and see how they relate to your diet.',
        ],
        'glucose' => [
            'title'   => 'Glucose Insights',
            'summary' => 'If you use a CGM, see each meal\'s blood-sugar response and which meals spiked you most.',
        ],
        'connect-cgm' => [
            'title'   => 'Connect Your Lingo or Stelo (CGM)',
            'summary' => 'Step-by-step: send your continuous glucose readings from your iPhone to NouriLoop '
                . 'automatically.',
        ],
        'goals' => [
            'title'   => 'Nutrition Goals',
            'summary' => 'Set a measurable goal and get scheduled progress check-ins by email or text.',
        ],
        'settings' => [
            'title'   => 'Settings & Notifications',
            'summary' => 'Notifications, timezone, units, language, AI voice, subscription, '
                . 'and account controls — every preference in one hub.',
        ],
    ];

    /**
     * TOPICS with the instance's name in place of "NouriLoop" in each summary (configurable
     * branding, PR 6b). Everything user-facing reads summaries through this: the help pages,
     * the help assistant and the help pages' structured data.
     *
     * @return array<string, array{title: string, summary: string}>
     */
    public static function topicsFor(string $appName): array
    {
        return array_map(
            static fn(array $t): array => ['summary' => str_replace(Brand::NOURILOOP, $appName, $t['summary'])] + $t,
            self::TOPICS,
        );
    }

    #[Route('', name: 'app_help')]
    public function index(Brand $brand): Response
    {
        return $this->render('help/index.html.twig', [
            'topics' => self::topicsFor($brand->name()),
            'seo_description' => 'Learn how to log meals, track supplements, read your inflammation score, '
                . 'connect a CGM, and more.',
        ]);
    }

    /**
     * Natural-language Q&A. Declared before show() so the static `/ask` path wins over
     * the dynamic `/{topic}`. Rate-limited (per user when signed in, else per IP) to
     * bound Claude token spend; requires the JS `X-Requested-With` header as a cheap
     * same-origin guard. Returns the answer twice: raw text + `inline_md`-rendered HTML
     * (the goal-checkin display guard, so a stray `**` never reaches the user).
     */
    #[Route('/ask', name: 'app_help_ask', methods: ['POST'])]
    public function ask(
        Request $request,
        HelpAssistantService $assistant,
        TextExtension $text,
        #[Target('help_assistant')] RateLimiterFactoryInterface $userLimiter,
        #[Target('help_assistant_anon')] RateLimiterFactoryInterface $anonLimiter,
    ): JsonResponse {
        // Same-origin guard: a cross-site form POST can't set X-Requested-With without a
        // CORS preflight we never approve. isXmlHttpRequest() requires it be "XMLHttpRequest".
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate before consuming a limiter slot: an empty submit costs nothing
        // (no Claude call), so it mustn't spend one of the user's hourly questions.
        $question = trim((string) $request->getPayload()->get('question'));
        if ($question === '') {
            return new JsonResponse(['error' => 'Please type a question.'], Response::HTTP_BAD_REQUEST);
        }
        $question = mb_substr($question, 0, self::MAX_QUESTION_LEN);

        $user = $this->getUser();
        $limit = $user instanceof User
            ? $userLimiter->create('help-user-' . $user->getUserIdentifier())
            : $anonLimiter->create('help-ip-' . ($request->getClientIp() ?? 'unknown'));
        if (!$limit->consume(1)->isAccepted()) {
            return new JsonResponse(
                ['error' => "You've reached the help-question limit for now — please try again later."],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $answer = $assistant->ask($question, $user instanceof User ? $user : null);

        return new JsonResponse([
            'answer' => $answer->answer,
            'answerHtml' => $text->inlineMd($answer->answer),
            'pages' => $answer->pages,
        ]);
    }

    #[Route('/{topic}', name: 'app_help_show', requirements: ['topic' => '[a-z-]+'])]
    public function show(string $topic, Brand $brand): Response
    {
        if (!isset(self::TOPICS[$topic])) {
            throw $this->createNotFoundException('Unknown help topic.');
        }
        $meta = self::topicsFor($brand->name())[$topic];

        return $this->render('help/' . $topic . '.html.twig', [
            'topic' => $topic,
            'meta'  => $meta,
            // The topic's summary doubles as its meta description (DRY — TOPICS is the
            // single source of truth for both the page and its SEO copy).
            'seo_description' => $meta['summary'],
        ]);
    }
}
