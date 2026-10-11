<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\GoalFrequency;
use App\Enum\GoalOutputFormat;
use App\Repository\NutritionGoalRepository;
use App\Service\GoalConversationService;
use App\Service\GoalDefinitionFactory;
use App\Service\GoalFactory;
use App\Service\GoalSafetyValidator;
use App\Service\LocaleFormatter;
use App\Support\EnumHelper;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The guided goal-setting chat: the primary way to create a goal. The transcript
 * and the pending structured proposal live in the session (like the meal clarify
 * flow); only the confirmed goal is persisted, via the shared GoalFactory.
 */
class GoalConversationController extends AbstractController
{
    private const HISTORY_KEY = 'goal_chat_history';
    private const PROPOSAL_KEY = 'goal_chat_proposal';

    /** Longest message the chat accepts — every turn is resent to Claude. */
    public const MAX_MESSAGE_LENGTH = 1000;

    /**
     * User messages per conversation. Each turn resends the whole transcript, so
     * cost grows quadratically with length; a hard cap (then "start over") bounds
     * it without silently dropping early turns the proposal may depend on.
     */
    public const MAX_USER_TURNS = 12;

    public function __construct(
        private readonly GoalConversationService $conversation,
        private readonly GoalSafetyValidator $safety,
        private readonly GoalFactory $factory,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly LocaleFormatter $localeFormatter,
    ) {
    }

    #[Route('/goals/new', name: 'app_goals_new', methods: ['GET'])]
    public function chat(Request $request, NutritionGoalRepository $goals): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();

        return $this->render('goals/chat.html.twig', [
            'greeting' => $this->conversation->greeting(),
            'history'  => $session->get(self::HISTORY_KEY, []),
            'proposal' => $session->get(self::PROPOSAL_KEY),
            'warnings' => [],
            // Quick-start strip (one-tap starter goals) only until a first goal exists.
            'showStarters' => $goals->count(['user' => $user]) === 0,
            'turnLimitReached' => self::userTurns($session->get(self::HISTORY_KEY, [])) >= self::MAX_USER_TURNS,
            'maxMessageLength' => self::MAX_MESSAGE_LENGTH,
        ]);
    }

    #[Route('/goals/new/message', name: 'app_goals_chat_message', methods: ['POST'])]
    public function message(
        Request $request,
        #[Target('goal_chat')] RateLimiterFactoryInterface $limiter,
    ): Response {
        if (!$this->isCsrfTokenValid('goal-chat', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_new');
        }

        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();

        $text = trim((string) $request->request->get('message'));
        if ($text === '') {
            return $this->redirectToRoute('app_goals_new');
        }

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $this->addFlash('warning', $this->translator->trans(
                'goal.chat.too_long',
                ['max' => self::MAX_MESSAGE_LENGTH],
            ));

            return $this->redirectToRoute('app_goals_new');
        }

        /** @var list<array{role: string, content: string}> $history */
        $history = $session->get(self::HISTORY_KEY, []);
        if (self::userTurns($history) >= self::MAX_USER_TURNS) {
            $this->addFlash('warning', $this->translator->trans('goal.chat.turn_limit'));

            return $this->redirectToRoute('app_goals_new');
        }

        // Consumed only for a message that will actually reach Claude.
        if (!$limiter->create('goal-chat-user-' . $user->getUserIdentifier())->consume(1)->isAccepted()) {
            $this->addFlash('warning', $this->translator->trans('goal.chat.rate_limited'));

            return $this->redirectToRoute('app_goals_new');
        }

        $history[] = ['role' => 'user', 'content' => $text];

        try {
            $turn = $this->conversation->converse($user, $history);
        } catch (\Throwable $e) {
            $this->logger->error('Goal conversation failed: {error}', ['error' => $e->getMessage()]);
            $this->addFlash('error', $this->translator->trans('goal.chat.error_reply'));
            // Keep the user's message so they don't have to retype it.
            $session->set(self::HISTORY_KEY, $history);

            return $this->redirectToRoute('app_goals_new');
        }

        $history[] = ['role' => 'assistant', 'content' => $turn['reply']];
        $session->set(self::HISTORY_KEY, $history);
        $session->set(self::PROPOSAL_KEY, $turn['proposal']);

        return $this->redirectToRoute('app_goals_new');
    }

    #[Route('/goals/new/confirm', name: 'app_goals_chat_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('goal-chat-confirm', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_new');
        }

        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();

        $proposal = $session->get(self::PROPOSAL_KEY);
        if (!is_array($proposal)) {
            $this->addFlash('error', $this->translator->trans('goal.chat.no_proposal'));

            return $this->redirectToRoute('app_goals_new');
        }

        $definition = GoalDefinitionFactory::fromArray($proposal);
        $warnings = $this->safety->validate($definition);

        if ($warnings !== [] && !$request->request->getBoolean('acknowledge_warnings')) {
            return $this->render('goals/chat.html.twig', [
                'greeting' => $this->conversation->greeting(),
                'history'  => $session->get(self::HISTORY_KEY, []),
                'proposal' => $proposal,
                'warnings' => $warnings,
                'turnLimitReached' => self::userTurns($session->get(self::HISTORY_KEY, [])) >= self::MAX_USER_TURNS,
                'maxMessageLength' => self::MAX_MESSAGE_LENGTH,
            ]);
        }

        $goal = $this->factory->create(
            $user,
            $definition,
            (string) ($proposal['name'] ?? ''),
            EnumHelper::resolve(GoalFrequency::class, $proposal['frequency'] ?? null, GoalFrequency::Weekly),
            isset($proposal['dayOfWeek']) ? (int) $proposal['dayOfWeek'] : 7,
            (int) ($proposal['hour'] ?? 18),
            (int) ($proposal['minute'] ?? 0),
            EnumHelper::resolve(GoalOutputFormat::class, $proposal['outputFormat'] ?? null, GoalOutputFormat::Summary),
        );

        $session->remove(self::HISTORY_KEY);
        $session->remove(self::PROPOSAL_KEY);

        $this->addFlash('success', $this->translator->trans('goal.created', [
            // nextRunAt is a server-frame scheduling instant — convert to the
            // owner's zone for display, like the goal pages' user_date filter.
            'time' => $goal->getNextRunAt() !== null
                ? $this->localeFormatter
                    ->date($goal->getNextRunAt(), 'EEEMMMdjm', new \DateTimeZone($user->getTimezone()))
                : '',
        ]));

        return $this->redirectToRoute('app_goals_show', ['id' => $goal->getId()]);
    }

    #[Route('/goals/new/reset', name: 'app_goals_chat_reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        if ($this->isCsrfTokenValid('goal-chat', (string) $request->request->get('_token'))) {
            $session = $request->getSession();
            $session->remove(self::HISTORY_KEY);
            $session->remove(self::PROPOSAL_KEY);
        }

        return $this->redirectToRoute('app_goals_new');
    }

    /** @param list<array{role: string, content: string}> $history */
    private static function userTurns(array $history): int
    {
        return count(array_filter($history, static fn (array $turn): bool => $turn['role'] === 'user'));
    }
}
