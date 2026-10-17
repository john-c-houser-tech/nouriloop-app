<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AllowanceStatus;
use App\Dto\TokenUsage;
use App\Entity\AnalysisEvent;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Enum\AllowanceKind;
use App\Enum\AnalysisKind;
use App\Enum\SubscriptionTier;
use App\Exception\PhotoLimitExceededException;
use App\Hosting\HostingMode;
use App\Repository\AllowanceTopUpRepository;
use App\Repository\AnalysisEventRepository;
use App\Repository\FavoriteRepository;
use App\Repository\MealEntryRepository;
use App\Repository\UserAiSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The monthly AI-analysis allowance — ONE bucket shared by every billable
 * analysis path: meal photos, described meals, recipe photo imports, meal
 * re-analyses, and supplement label scans.
 *
 * Who has one ({@see limit()}, App Development Plan PR 7, ADR 0003): a Free user
 * gets the starter allowance; Loop (bring your own key) gets the same until a key
 * is saved in Settings ▸ AI provider; Loop+ gets the analyses its price includes.
 * A paid user with their own key, and everyone on a self-hosted copy, is never
 * counted. A Free user's own key does NOT lift the limit — the allowance is what
 * Free is, and their calls still run on their key.
 *
 * Loop+ can buy a one-off top-up for the current month (PR 7b, decision Q3):
 * {@see TOP_UP_ANALYSES} more, added to the limit through {@see \App\Entity\AllowanceTopUp}
 * rows. Only analyses pause when it runs out; the other AI features keep working
 * (John, 2026-10-12).
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
    /** Free (and key-less Loop) allowance: AI analyses per calendar month (user-local). */
    public const FREE_MONTHLY_LIMIT = 10;

    /**
     * Loop+ allowance: about John's own monthly use, which its price was set from to
     * cover cost (ADR 0003).
     */
    public const INCLUDED_MONTHLY_LIMIT = 120;

    /** One Loop+ top-up: this many more analyses for the current month… */
    public const TOP_UP_ANALYSES = 60;

    /** …for this price in US cents ($1.75: the AI they buy plus Stripe's fee, no margin). */
    public const TOP_UP_PRICE_CENTS = 175;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly HostingMode $hosting,
        private readonly UserAiSettingsRepository $aiSettings,
        private readonly AllowanceTopUpRepository $topUps,
        private readonly MealEntryRepository $meals,
        private readonly FavoriteRepository $favorites,
        private readonly AnalysisEventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly UserTime $userTime,
    ) {
    }

    /**
     * Which allowance the user is on, or null for none (unlimited).
     */
    public function kind(User $user): ?AllowanceKind
    {
        if ($this->hosting->isSelfHosted()) {
            return null;
        }

        $tier = $this->subscriptionService->effectiveTier($user);
        if ($tier === SubscriptionTier::Free) {
            return AllowanceKind::Free;
        }
        if ($this->hasOwnKey($user)) {
            return null;
        }

        return $tier === SubscriptionTier::LoopPlus ? AllowanceKind::Included : AllowanceKind::KeyPending;
    }

    /** Analyses per month on the user's allowance, or null for unlimited. */
    public function limit(User $user): ?int
    {
        return $this->status($user)?->limit;
    }

    /** Whether the user can buy a top-up: Loop+ on our key. */
    public function canTopUp(User $user): bool
    {
        return $this->kind($user) === AllowanceKind::Included;
    }

    /**
     * Analyses left this month, or null for unlimited.
     */
    public function remaining(User $user): ?int
    {
        return $this->status($user)?->remaining;
    }

    /**
     * The allowance counters' view of the user, or null for unlimited (no counter).
     */
    public function status(User $user): ?AllowanceStatus
    {
        $kind = $this->kind($user);
        if ($kind === null) {
            return null;
        }
        $limit = $kind === AllowanceKind::Included
            ? self::INCLUDED_MONTHLY_LIMIT + $this->topUps->analysesForUserThisMonth($user)
            : self::FREE_MONTHLY_LIMIT;

        $used = $this->meals->countForUserThisMonth($user)
            + $this->favorites->countRecipeImportsForUserThisMonth($user)
            + $this->events->countForUserThisMonth($user);

        return new AllowanceStatus($kind, $limit, max(0, $limit - $used));
    }

    /**
     * Runs one analysis against the allowance: refuses before any work when the
     * user has none left, runs $analysis, then — only when $ledgerAs is
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
     * @throws PhotoLimitExceededException when the user's allowance is exhausted
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
     * @throws PhotoLimitExceededException when the user's allowance is exhausted
     */
    private function assertAvailable(User $user): void
    {
        $status = $this->status($user);
        if ($status !== null && $status->remaining === 0) {
            throw new PhotoLimitExceededException($status->kind, $status->limit);
        }
    }

    /** The same test ResolvingAiClient uses to send a call to the user's own key. */
    private function hasOwnKey(User $user): bool
    {
        return $user->getId() !== null && $this->aiSettings->findForUser($user) !== null;
    }

    /**
     * Books a billable analysis that leaves no countable row of its own, so the
     * allowance can see it.
     *
     * Written for every user, counted or not: the row is also the cost-reporting
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
