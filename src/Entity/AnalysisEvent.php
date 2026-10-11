<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AnalysisKind;
use App\Repository\AnalysisEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One billable AI analysis that produced no countable row of its own — a
 * call-time ledger entry, written immediately after the Claude call succeeds.
 *
 * Every other analysis path in the app is counted from its artefact (a
 * MealEntry for a logged meal, a recipe Favorite for an import), so the
 * allowance stays derived and no ledger is needed. Re-analysing an existing
 * meal creates nothing — it overwrites numbers in place — so without this row
 * the call would be free and unbounded. See {@see \App\Service\AnalysisAllowance}.
 *
 * Two consequences of being call-time rather than derived, both deliberate:
 * deleting the meal does NOT refund the slot (the call happened), and the row
 * survives the meal via a nullable SET NULL link.
 */
#[ORM\Entity(repositoryClass: AnalysisEventRepository::class)]
#[ORM\Index(name: 'idx_user_occurred', columns: ['user_id', 'occurred_at'])]
class AnalysisEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * The meal this analysis was for. SET NULL on delete: the ledger row
     * records that the call was made and must outlive the meal, or deleting the
     * meal would silently refund a slot the user already spent.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MealEntry $mealEntry = null;

    #[ORM\Column(length: 40, enumType: AnalysisKind::class)]
    private ?AnalysisKind $kind = null;

    /**
     * Stamped in the user's local wall-clock, like MealEntry::loggedAt — the
     * allowance buckets by the user's local calendar month, so the stored value
     * has to be in that same frame.
     */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** Total tokens the call cost, for cost reporting. 0 when unknown. */
    #[ORM\Column]
    private int $tokens = 0;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getMealEntry(): ?MealEntry
    {
        return $this->mealEntry;
    }

    public function setMealEntry(?MealEntry $mealEntry): static
    {
        $this->mealEntry = $mealEntry;

        return $this;
    }

    public function getKind(): ?AnalysisKind
    {
        return $this->kind;
    }

    public function setKind(AnalysisKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getTokens(): int
    {
        return $this->tokens;
    }

    public function setTokens(int $tokens): static
    {
        $this->tokens = $tokens;

        return $this;
    }
}
