<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Kind of in-app notification, driving the icon shown in the bell dropdown and the
 * inbox. The digest categories mirror NotificationType; goal_checkin covers scheduled
 * goal evaluations. Kept separate from NotificationType (the email/SMS dedup log) so
 * the in-app surface can evolve independently.
 */
enum NotificationCategory: string
{
    case GoalCheckin = 'goal_checkin';
    case NoMealsToday = 'no_meals_today';
    case StaleMetrics = 'stale_metrics';
    case CalorieRange = 'calorie_range';
    case Supplements = 'supplements';

    /** Bootstrap-icon class for the row's leading icon. */
    public function icon(): string
    {
        return match ($this) {
            self::GoalCheckin  => 'bi-bullseye',
            self::NoMealsToday => 'bi-camera',
            self::StaleMetrics => 'bi-heart-pulse',
            self::CalorieRange => 'bi-graph-up',
            self::Supplements  => 'bi-capsule',
        };
    }

    /** Maps a digest NotificationType onto its in-app category. */
    public static function fromNotificationType(NotificationType $type): self
    {
        return match ($type) {
            NotificationType::NoMealsToday => self::NoMealsToday,
            NotificationType::StaleMetrics => self::StaleMetrics,
            NotificationType::CalorieRange => self::CalorieRange,
            NotificationType::Supplements  => self::Supplements,
        };
    }
}
