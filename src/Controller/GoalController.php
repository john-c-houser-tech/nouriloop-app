<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GoalDefinition;
use App\Entity\NutritionGoal;
use App\Entity\User;
use App\Enum\GoalAggregation;
use App\Enum\GoalDirection;
use App\Enum\GoalFrequency;
use App\Enum\GoalMetric;
use App\Enum\GoalOutputFormat;
use App\Repository\GoalCheckinRepository;
use App\Repository\NutritionGoalRepository;
use App\Service\CheckinAllowance;
use App\Service\DietaryReference;
use App\Service\GoalDefinitionFactory;
use App\Service\GoalFactory;
use App\Service\GoalMetricCalculator;
use App\Service\GoalSafetyValidator;
use App\Service\GoalScheduler;
use App\Service\LocaleFormatter;
use App\Service\StarterGoals;
use App\Service\SubscriptionService;
use App\Support\EnumHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class GoalController extends AbstractController
{
    /**
     * Hard cap on the editable evaluation prompt (~2k input tokens). Bounds the
     * per-run API cost a user could create by pasting a huge prompt; the default
     * template is ~1.2k chars, so there's generous room to customize. The response
     * is separately capped at 512 output tokens in GoalEvaluationService.
     */
    public const MAX_PROMPT_LENGTH = 8000;

    public function __construct(
        private readonly NutritionGoalRepository $goals,
        private readonly EntityManagerInterface $em,
        private readonly GoalScheduler $scheduler,
        private readonly GoalSafetyValidator $safety,
        private readonly GoalFactory $factory,
        private readonly DietaryReference $reference,
        private readonly GoalMetricCalculator $calculator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleFormatter $localeFormatter,
        private readonly CheckinAllowance $allowance,
        private readonly SubscriptionService $subscriptions,
        private readonly string $chartsDir,
    ) {
    }

    #[Route('/goals', name: 'app_goals', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('goals/index.html.twig', [
            'goals' => $this->goals->findByUser($user),
        ]);
    }

    #[Route('/goals/new/manual', name: 'app_goals_new_manual', methods: ['GET'])]
    public function newManual(): Response
    {
        return $this->render('goals/new.html.twig', $this->formOptions());
    }

    /**
     * One-click starter goal (onboarding step 2 / the chat page's quick-start
     * strip). The templates are safe by construction (see StarterGoals), so
     * unlike the chat/manual paths there is no warning-acknowledge round trip.
     */
    #[Route('/goals/starter', name: 'app_goals_starter', methods: ['POST'])]
    public function starter(Request $request, StarterGoals $starters): Response
    {
        if (!$this->isCsrfTokenValid('goal-starter', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_new');
        }

        $template = (string) $request->request->get('template');
        if (!$starters->isTemplate($template)) {
            return $this->redirectToRoute('app_goals_new');
        }

        /** @var User $user */
        $user = $this->getUser();

        $goal = $this->factory->create(
            $user,
            $starters->definitionFor($user, $template),
            $this->translator->trans('goal.starter.name_' . $template),
            GoalFrequency::Weekly,
            7,
            18,
            0,
            GoalOutputFormat::Summary,
        );

        $this->addFlash('success', $this->translator->trans('goal.created', [
            'time' => $goal->getNextRunAt() !== null
                ? $this->localeFormatter
                    ->date($goal->getNextRunAt(), 'EEEMMMdjm', new \DateTimeZone($user->getTimezone()))
                : '',
        ]));

        return $this->redirectToRoute('app_goals_show', ['id' => $goal->getId()]);
    }

    #[Route('/goals', name: 'app_goals_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('goal-create', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_new_manual');
        }

        /** @var User $user */
        $user = $this->getUser();

        $definition = $this->definitionFromRequest($request);
        $warnings = $this->safety->validate($definition);
        $acknowledged = $request->request->getBoolean('acknowledge_warnings');

        // Unsafe/odd targets are warned, not forbidden — but the user must confirm.
        if ($warnings !== [] && !$acknowledged) {
            // array_merge (not +): the real warnings/submitted must override the
            // empty defaults in formOptions(), which the union operator would keep.
            return $this->render('goals/new.html.twig', array_merge($this->formOptions(), [
                'warnings'  => $warnings,
                'submitted' => $request->request->all(),
            ]));
        }

        $goal = $this->factory->create(
            $user,
            $definition,
            (string) $request->request->get('name'),
            EnumHelper::resolve(GoalFrequency::class, $request->request->get('frequency'), GoalFrequency::Weekly),
            (int) $request->request->get('day_of_week', 7),
            (int) $request->request->get('hour', 18),
            (int) $request->request->get('minute', 0),
            EnumHelper::resolve(
                GoalOutputFormat::class,
                $request->request->get('output_format'),
                GoalOutputFormat::Summary,
            ),
        );

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

    #[Route('/goals/{id}', name: 'app_goals_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, GoalCheckinRepository $checkins): Response
    {
        $goal = $this->ownedGoal($id);

        /** @var User $user */
        $user = $this->getUser();

        // The Free tier's lifetime check-in allowance (null = unlimited tier).
        // Derived fresh per render — no column, no cache (see CheckinAllowance).
        $allowanceUsed = $this->allowance->isUnlimited($user) ? null : $this->allowance->used($user);

        return $this->render('goals/show.html.twig', [
            'goal'            => $goal,
            'def'             => $goal->getDefinition(),
            'checkins'        => $checkins->findRecentForGoal($goal),
            'maxPromptLen'    => self::MAX_PROMPT_LENGTH,
            'allowanceUsed'   => $allowanceUsed,
            'allowanceLimit'  => CheckinAllowance::FREE_ALLOWANCE,
        ]);
    }

    #[Route('/goals/{id}/prompt', name: 'app_goals_prompt', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editPrompt(int $id, Request $request): Response
    {
        $goal = $this->ownedGoal($id);

        if (!$this->isCsrfTokenValid('goal-prompt-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_show', ['id' => $id]);
        }

        $template = trim((string) $request->request->get('evaluation_prompt'));

        if (mb_strlen($template) > self::MAX_PROMPT_LENGTH) {
            $this->addFlash('error', $this->translator->trans('goal.show.prompt_too_long', [
                'chars' => number_format(mb_strlen($template)),
                'limit' => number_format(self::MAX_PROMPT_LENGTH),
            ]));

            return $this->redirectToRoute('app_goals_show', ['id' => $id]);
        }

        if ($template !== '') {
            $before = $goal->getPromptVersion();
            $goal->revisePromptTemplate($template);
            $this->em->flush();
            $this->addFlash(
                'success',
                $goal->getPromptVersion() > $before
                    ? $this->translator->trans('goal.show.prompt_updated', [
                        'name' => $goal->getName(),
                        'version' => $goal->getPromptVersion(),
                    ])
                    : $this->translator->trans('goal.show.prompt_no_changes'),
            );
        }

        // Return to the goals list so the save is unmistakable (the in-place flash on
        // the detail page is easy to miss after a long prompt edit).
        return $this->redirectToRoute('app_goals');
    }

    #[Route('/goals/{id}/toggle', name: 'app_goals_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $goal = $this->ownedGoal($id);

        if (!$this->isCsrfTokenValid('goal-toggle-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_show', ['id' => $id]);
        }

        // A Free owner whose lifetime allowance is exhausted can't resume — the
        // cron would immediately re-pause without evaluating, and the repeat
        // "paused" notices would read as a bug. The page shows an upgrade CTA
        // instead of the Resume button; this is the server-side backstop.
        /** @var User $user */
        $user = $this->getUser();
        if (!$goal->isActive() && !$this->allowance->isUnlimited($user) && $this->allowance->isExhausted($user)) {
            $this->addFlash('error', $this->translator->trans('goal.show.resume_locked', [
                'limit' => CheckinAllowance::FREE_ALLOWANCE,
            ]));

            return $this->redirectToRoute('app_goals_show', ['id' => $id]);
        }

        $goal->setActive(!$goal->isActive());

        // Re-arm the schedule when re-activating so it doesn't fire immediately for
        // a long-dormant goal; clear it when paused.
        if ($goal->isActive()) {
            $goal->setNextRunAt($this->scheduler->computeNextRun($goal, new \DateTimeImmutable()));
        } else {
            $goal->setNextRunAt(null);
        }

        $this->em->flush();
        $this->addFlash('success', $this->translator->trans(
            $goal->isActive() ? 'goal.show.resumed' : 'goal.show.paused_flash',
        ));

        return $this->redirectToRoute('app_goals_show', ['id' => $id]);
    }

    #[Route('/goals/{id}/delete', name: 'app_goals_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $goal = $this->ownedGoal($id);

        if (!$this->isCsrfTokenValid('goal-delete-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_goals_show', ['id' => $id]);
        }

        // goal_checkin rows cascade (ON DELETE CASCADE on the FK), but their chart
        // PNGs don't — collect the paths first and unlink them once the delete has
        // committed (the account purge already cleans these; per-goal delete didn't).
        $chartPaths = $this->em->getConnection()->fetchFirstColumn(
            'SELECT chart_path FROM goal_checkin WHERE goal_id = ? AND chart_path IS NOT NULL',
            [$id],
        );
        $this->em->remove($goal);
        $this->em->flush();
        foreach ($chartPaths as $chartPath) {
            // Same resolution as EmailGoalDelivery: the stored path's file name
            // inside the charts dir, so a stored path can't point anywhere else.
            $file = $this->chartsDir . '/' . basename((string) $chartPath);
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->addFlash('success', $this->translator->trans('goal.show.deleted'));

        return $this->redirectToRoute('app_goals');
    }

    private function definitionFromRequest(Request $request): GoalDefinition
    {
        $r = $request->request;

        // Map the form's snake_case fields onto the shared builder's camelCase shape.
        return GoalDefinitionFactory::fromArray([
            'metric'               => $r->get('metric'),
            'direction'            => $r->get('direction'),
            'aggregation'          => $r->get('aggregation'),
            'targetValue'          => $r->get('target_value'),
            'evaluationWindowDays' => $r->get('window_days'),
            'targetMax'            => $r->get('target_max'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        /** @var User $user */
        $user = $this->getUser();

        // Reference values + the user's current 30-day average per metric, so the
        // form can hint a sensible target instead of expecting expert knowledge.
        // baselineMap() fetches meals once for all metrics (not one query per metric).
        $references = [];
        foreach (GoalMetric::cases() as $metric) {
            $ref = $this->reference->for($metric);
            // Display copy of the note — DietaryReference itself stays English
            // (it also grounds the goal-chat system prompt until Phase 3).
            $ref['note'] = $this->translator->trans('goal.ref.' . $metric->value);
            $references[$metric->value] = $ref;
        }
        $baselines = $this->calculator->baselineMap($user, 30);

        return [
            'metrics'      => GoalMetric::cases(),
            'directions'   => GoalDirection::cases(),
            'aggregations' => GoalAggregation::cases(),
            'frequencies'  => GoalFrequency::cases(),
            'formats'      => GoalOutputFormat::cases(),
            'references'   => $references,
            'baselines'    => $baselines,
            'warnings'     => [],
            'submitted'    => [],
            // Daily cadence is Loop+ (the form disables the option with an upsell
            // hint; GoalFactory coerces server-side as the backstop).
            'canDaily'     => $this->subscriptions->hasFeature($user, 'daily_checkins'),
        ];
    }

    private function ownedGoal(int $id): NutritionGoal
    {
        $goal = $this->goals->find($id);
        if ($goal === null || $goal->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $goal;
    }
}
