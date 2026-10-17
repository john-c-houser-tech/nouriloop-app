<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AllowanceTopUpRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One paid allowance top-up (App Development Plan PR 7b, decision Q3): extra AI
 * analyses for one user-local calendar month, bought through a one-off Stripe
 * Checkout. Loop+ only — {@see \App\Service\AnalysisAllowance} adds the month's
 * top-ups to the included allowance, and they lapse when the month ends.
 *
 * The Checkout session id is unique, so the webhook and the success page's fast
 * path can both try to credit the same payment and only one row is ever written.
 */
#[ORM\Entity(repositoryClass: AllowanceTopUpRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_allowance_top_up_session', columns: ['stripe_session_id'])]
#[ORM\Index(name: 'idx_allowance_top_up_user_month', columns: ['user_id', 'month'])]
class AllowanceTopUp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The user-local month it applies to, "YYYY-MM" (fixed when checkout started). */
    #[ORM\Column(length: 7)]
    private string $month;

    #[ORM\Column]
    private int $analyses;

    #[ORM\Column(length: 255)]
    private string $stripeSessionId;

    #[ORM\Column]
    private \DateTimeImmutable $creditedAt;

    public function __construct(
        User $user,
        string $month,
        int $analyses,
        string $stripeSessionId,
        \DateTimeImmutable $creditedAt,
    ) {
        $this->user = $user;
        $this->month = $month;
        $this->analyses = $analyses;
        $this->stripeSessionId = $stripeSessionId;
        $this->creditedAt = $creditedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMonth(): string
    {
        return $this->month;
    }

    public function getAnalyses(): int
    {
        return $this->analyses;
    }

    public function getStripeSessionId(): string
    {
        return $this->stripeSessionId;
    }

    public function getCreditedAt(): \DateTimeImmutable
    {
        return $this->creditedAt;
    }
}
