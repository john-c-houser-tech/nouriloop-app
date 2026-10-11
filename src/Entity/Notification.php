<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationCategory;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One in-app notification in a user's notification center (the navbar bell + the
 * /notifications inbox). Created alongside — but independently of — the email/SMS
 * digest and goal check-in deliveries, so the inbox is the single in-app home for
 * everything NouriLoop surfaces. `readAt` null means unread (drives the bell badge).
 *
 * Removed with its user in the admin user-delete flow (and ON DELETE CASCADE as a
 * backstop).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'idx_notification_user_created', columns: ['user_id', 'created_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: NotificationCategory::class)]
    private NotificationCategory $category;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    /** Deep link (relative path) the row points at, e.g. /goals/12. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $linkPath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        User $user,
        NotificationCategory $category,
        string $title,
        ?string $body = null,
        ?string $linkPath = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->user = $user;
        $this->category = $category;
        $this->title = $title;
        $this->body = $body;
        $this->linkPath = $linkPath;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCategory(): NotificationCategory
    {
        return $this->category;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getLinkPath(): ?string
    {
        return $this->linkPath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(?\DateTimeImmutable $at = null): static
    {
        $this->readAt ??= $at ?? new \DateTimeImmutable();

        return $this;
    }
}
