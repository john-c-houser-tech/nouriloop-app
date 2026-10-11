<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use registration invitation. The admin mints a 32-char code, good for
 * 48 hours; a new user must supply a matching unused, unexpired code to register.
 * Unlike ApiToken, the code is stored in plaintext because it's displayed back to
 * the admin (in the outstanding list and on the redeeming user's row).
 */
#[ORM\Entity(repositoryClass: InvitationCodeRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_invitation_code', columns: ['code'])]
class InvitationCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Plaintext 32-char code shown to the admin and entered at registration. */
    #[ORM\Column(length: 32)]
    private string $code = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** The admin who minted the code (audit only). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** The user who redeemed the code; single-use, so at most one. */
    #[ORM\OneToOne(inversedBy: 'invitationCode')]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?User $usedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * When true, the user who redeems this code is granted a complimentary
     * beta-tester subscription (a time-limited Loop+ comp from registration — see
     * SubscriptionService::BETA_COMP_DURATION) —
     * SubscriptionService::grantBetaTesterComp. A plain code grants nothing.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $betaTester = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUsedBy(): ?User
    {
        return $this->usedBy;
    }

    public function setUsedBy(?User $usedBy): static
    {
        $this->usedBy = $usedBy;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;

        return $this;
    }

    public function isBetaTester(): bool
    {
        return $this->betaTester;
    }

    public function setBetaTester(bool $betaTester): static
    {
        $this->betaTester = $betaTester;

        return $this;
    }

    public function isUsed(): bool
    {
        return $this->usedBy !== null;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->isExpired($now);
    }
}
