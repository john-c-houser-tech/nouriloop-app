<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Entity\GoalCheckin;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sends a check-in over whichever transports the goal owner's notification channel
 * (`User::getNotificationChannel()`) selects.
 * A failure in one transport is logged and swallowed so it can't abort the run or
 * block the other channels (mirrors the notifications digest's best-effort sends).
 */
class GoalDeliveryDispatcher
{
    /**
     * @param iterable<GoalDeliveryInterface> $deliveries
     */
    public function __construct(
        #[AutowireIterator('app.goal_delivery')]
        private readonly iterable $deliveries,
        private readonly LoggerInterface $logger,
        private readonly \Symfony\Component\Translation\LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function dispatch(GoalCheckin $checkin): void
    {
        $user = $checkin->getGoal()?->getUser();
        $channel = $user?->getNotificationChannel();
        if ($user === null || $channel === null) {
            return;
        }

        // Check-ins run from cron (no request locale): every transport's
        // user-visible text renders in the goal OWNER's locale.
        $this->localeSwitcher->runWithLocale(
            $user->getLocale()->value,
            fn () => $this->deliverAll($checkin, $channel),
        );
    }

    private function deliverAll(GoalCheckin $checkin, \App\Enum\NotificationChannel $channel): void
    {
        foreach ($this->deliveries as $delivery) {
            if (!$delivery->supports($channel)) {
                continue;
            }
            try {
                $delivery->deliver($checkin);
            } catch (\Throwable $e) {
                $this->logger->error('Goal check-in delivery failed via {transport}: {error}', [
                    'transport' => $delivery::class,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }
}
