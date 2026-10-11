<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates in-app notifications (the navbar bell + /notifications inbox). The single
 * write path used by both the goal check-in delivery (InAppGoalDelivery) and the
 * digest evaluator (NotificationService), so every in-app row is shaped the same way.
 * Persists and flushes immediately — each notification is an independent event.
 */
class InAppNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function notify(
        User $user,
        NotificationCategory $category,
        string $title,
        ?string $body = null,
        ?string $linkPath = null,
        ?\DateTimeImmutable $createdAt = null,
    ): Notification {
        $notification = new Notification($user, $category, $title, $body, $linkPath, $createdAt);
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }
}
