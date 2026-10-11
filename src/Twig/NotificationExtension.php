<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Powers the navbar notification bell:
 *   - `unread_notification_count()` → the badge number
 *   - `recent_notifications(5)`     → the dropdown list
 * Both return empty/zero when nobody is logged in, and are memoized per request so a
 * page render hits the DB at most once each (the same lazy pattern as `has_feature`).
 */
class NotificationExtension extends AbstractExtension
{
    private ?int $unreadCount = null;
    /** @var list<Notification>|null */
    private ?array $recent = null;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notification_count', $this->unreadCount(...)),
            new TwigFunction('recent_notifications', $this->recentNotifications(...)),
        ];
    }

    public function unreadCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->unreadCount ??= $this->notifications->countUnread($user);
    }

    /** @return list<Notification> */
    public function recentNotifications(int $limit = 5): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->recent ??= $this->notifications->findRecent($user, $limit);
    }
}
