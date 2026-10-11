<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TokenUsage;
use App\Entity\AnalysisEvent;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\AnalysisKind;
use App\Exception\PhotoLimitExceededException;
use App\Repository\AnalysisEventRepository;
use App\Repository\FavoriteRepository;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Free-tier monthly AI-analysis allowance — ONE bucket shared by every
 * billable analysis path: meal photos, described meals, recipe photo imports,
 * meal re-analyses, and supplement label scans. Paid tiers have `unlimited_photos` and are never
 * counted.
 *
 * Extracted from FoodAnalysisService when the recipe import shipped, so the
 * Claude entry points can't drift on gating. Every analysis runs through
 * {@see meter()}, which is the only way in: check, run, then book if ledgered.
 *
 * ⚠️ The count is DERIVED wherever the analysis leaves a row behind (the
 * CheckinAllowance pattern — no counter column): meals logged this month +
 * recipe-import favorites created this month. That is why log-then-delete
 * refunds a slot, a documented and accepted tradeoff.
 *
 * ⚠️ Meal RE-analysis is the one path that cannot work that way, and the
 * difference is not cosmetic: re-analysing overwrites an existing meal's
 * numbers in place and creates nothing, so a derived count cannot see it at
 * all — a Free user could re-analyse without limit while their meal count sat
 * still. Those calls therefore write an {@see AnalysisEvent} ledger row and are
 * counted from it. Two consequences follow, both
 * intended: a re-analysis slot is NOT refunded by deleting the meal (the call
 * happened), and re-analysis is the abuse-prone path precisely because it is
 * unbounded per meal — which is why it got the ledger the meal path was
 * deliberately left without.
 *
 * Supplement label scans are ledgered for a related reason: the scan's row (a
 * Supplement) only exists if the user saves it, so a scan-and-abandon loop would
 * otherwise be free. Booked after the call succeeds, like re-analysis.
 */
final class AnalysisAllowance
{
    /** Free-tier allowance: AI analyses per calendar month (user-local). */
    public const FREE_MONTHLY_LIMIT = 10;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly MealEntryRepository $meals,
        private readonly FavoriteRepository $favorites,
        private readonly AnalysisEventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly UserTime $userTime,
    ) {
    }

    /**
     * Analyses left this month, or null for unlimited (paid tiers). Drives the
     * allowance counters shown before the limit is hit.
     */
    public function remaining(User $user): ?int
    {
        if ($this->subscriptionService->hasFeature($user, 'unlimited_photos')) {
            return null;
        }

        $used = $this->meals->countForUserThisMonth($user)
            + $this->favorites->countRecipeImportsForUserThisMonth($user)
            + $this->events->countForUserThisMonth($user);

        return max(0, self::FREE_MONTHLY_LIMIT - $used);
    }

    /**
     * Runs one analysis against the allowance: refuses before any work when a
     * Free user has none left, runs $analysis, then — only when $ledgerAs is
     * given — books a ledger row with the result's token cost. Nothing is booked
     * if $analysis throws (a failed analysis isn't charged, the log flow's retry
     * posture). Which kinds are ledgered is a policy decision recorded in
     * docs/adr/0001-mixed-allowance-ledger.md; pass null for analyses the
     * derived count already sees.
     *
     * @template T of TokenUsage
     *
     * @param callable(): T $analysis
     *
     * @return T
     *
     * @throws PhotoLimitExceededException when a Free user's allowance is exhausted
     */
    public function meter(User $user, ?AnalysisKind $ledgerAs, ?MealEntry $meal, callable $analysis): TokenUsage
    {
        $this->assertAvailable($user);

        $result = $analysis();

        if ($ledgerAs !== null) {
            $this->record($user, $ledgerAs, $meal, $result->totalTokens());
        }

        return $result;
    }

    /**
     * @throws PhotoLimitExceededException when a Free user's allowance is exhausted
     */
    private function assertAvailable(User $user): void
    {
        if ($this->remaining($user) === 0) {
            throw new PhotoLimitExceededException(sprintf(
                'Free tier limit of %d photo analyses per month reached.',
                self::FREE_MONTHLY_LIMIT,
            ));
        }
    }

    /**
     * Books a billable analysis that leaves no countable row of its own, so the
     * allowance can see it.
     *
     * Written for every tier, not just Free: the row is also the cost-reporting
     * record, and a comped user who later drops to Free must not carry an
     * invisible history.
     */
    private function record(User $user, AnalysisKind $kind, ?MealEntry $meal, int $tokens): AnalysisEvent
    {
        $event = new AnalysisEvent();
        $event->setUser($user);
        $event->setMealEntry($meal);
        $event->setKind($kind);
        $event->setOccurredAt($this->userTime->now($user));
        $event->setTokens($tokens);

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }
}
