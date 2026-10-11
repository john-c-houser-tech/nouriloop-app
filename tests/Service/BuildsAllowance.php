<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AnalysisEvent;
use App\Repository\AnalysisEventRepository;
use App\Repository\FavoriteRepository;
use App\Repository\MealEntryRepository;
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
     * @param int $used analyses already spent this month (only matters for a Free user)
     */
    private function allowance(bool $unlimited = true, int $used = 0): AnalysisAllowance
    {
        $subscriptions = $this->createStub(SubscriptionService::class);
        $subscriptions->method('hasFeature')->willReturn($unlimited);

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
            $meals,
            $this->createStub(FavoriteRepository::class),
            $this->createStub(AnalysisEventRepository::class),
            $em,
            new UserTime(),
        );
    }
}
