<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnalysisEvent;
use App\Entity\UserAiSettings;
use App\Enum\SubscriptionTier;
use App\Hosting\HostingMode;
use App\Repository\AllowanceTopUpRepository;
use App\Repository\AnalysisEventRepository;
use App\Repository\FavoriteRepository;
use App\Repository\MealEntryRepository;
use App\Repository\UserAiSettingsRepository;
use App\Service\AnalysisAllowance;
use App\Service\SubscriptionService;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A real AnalysisAllowance over stubs, for unit tests of the analyses.
 * Ledger rows it books are collected in $booked instead of a database.
 */
trait BuildsAllowance
{
    /** @var list<AnalysisEvent> */
    private array $booked = [];

    /**
     * @param bool $unlimited shorthand: true = a paid user with their own key, false = Free
     * @param int $used analyses already spent this month (only matters with an allowance)
     * @param SubscriptionTier|null $tier overrides $unlimited's tier
     * @param bool|null $ownKey overrides $unlimited's key
     */
    private function allowance(
        bool $unlimited = true,
        int $used = 0,
        ?SubscriptionTier $tier = null,
        ?bool $ownKey = null,
        string $hosting = 'hosted',
        int $toppedUp = 0,
    ): AnalysisAllowance {
        $subscriptions = $this->createStub(SubscriptionService::class);
        $subscriptions->method('effectiveTier')
            ->willReturn($tier ?? ($unlimited ? SubscriptionTier::LoopPlus : SubscriptionTier::Free));

        $aiSettings = $this->createStub(UserAiSettingsRepository::class);
        $aiSettings->method('findForUser')
            ->willReturn(($ownKey ?? $unlimited) ? $this->createStub(UserAiSettings::class) : null);

        $topUps = $this->createStub(AllowanceTopUpRepository::class);
        $topUps->method('analysesForUserThisMonth')->willReturn($toppedUp);

        $meals = $this->createStub(MealEntryRepository::class);
        $meals->method('countForUserThisMonth')->willReturn($used);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof AnalysisEvent) {
                $this->booked[] = $entity;
            }
        });

        return new AnalysisAllowance(
            $subscriptions,
            new HostingMode($hosting),
            $aiSettings,
            $topUps,
            $meals,
            $this->createStub(FavoriteRepository::class),
            $this->createStub(AnalysisEventRepository::class),
            $em,
            new UserTime(),
        );
    }
}
