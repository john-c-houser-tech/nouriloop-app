<?php

declare(strict_types=1);

namespace App\Service\Delivery;

use App\Entity\GoalCheckin;
use App\Enum\NotificationChannel;

/**
 * One transport for delivering a goal check-in to the user. Email is the baseline
 * implementation; SMS and in-app are stubbed behind this same interface so they can
 * be filled in later without touching the runner. Implementations are tagged
 * (`app.goal_delivery`) and collected by GoalDeliveryDispatcher.
 */
interface GoalDeliveryInterface
{
    /** Whether this transport participates for a goal set to the given channel. */
    public function supports(NotificationChannel $channel): bool;

    public function deliver(GoalCheckin $checkin): void;
}
