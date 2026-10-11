<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationType;
use App\Repository\NotificationLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records that a given notification type was emailed to a user, so the daily
 * command can honour per-type cooldowns and avoid resending.
 */
#[ORM\Entity(repositoryClass: NotificationLogRepository::class)]
#[ORM\Index(name: 'idx_user_type_sent', columns: ['user_id', 'type', 'sent_at'])]
class NotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 32, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(User $user, NotificationType $type, ?\DateTimeImmutable $sentAt = null)
    {
        $this->user = $user;
        $this->type = $type;
        $this->sentAt = $sentAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
