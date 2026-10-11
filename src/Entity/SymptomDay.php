<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SymptomDayRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One self-reported check-in per user per day, holding the day's note and a
 * collection of per-symptom level entries. The unique (user, logged_date)
 * constraint makes the daily entry upsertable (carried over from SymptomLog).
 */
#[ORM\Entity(repositoryClass: SymptomDayRepository::class)]
#[ORM\Table(name: 'symptom_day')]
#[ORM\UniqueConstraint(name: 'uq_symptom_day', columns: ['user_id', 'logged_date'])]
class SymptomDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $loggedDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SymptomEntry> */
    #[ORM\OneToMany(mappedBy: 'symptomDay', targetEntity: SymptomEntry::class, orphanRemoval: true)]
    private Collection $entries;

    public function __construct()
    {
        $this->loggedDate = new \DateTimeImmutable('today');
        $this->updatedAt  = new \DateTimeImmutable();
        $this->entries    = new ArrayCollection();
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

    public function getLoggedDate(): \DateTimeImmutable
    {
        return $this->loggedDate;
    }

    public function setLoggedDate(\DateTimeImmutable $loggedDate): static
    {
        $this->loggedDate = $loggedDate;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, SymptomEntry> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addEntry(SymptomEntry $entry): static
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
            $entry->setSymptomDay($this);
        }

        return $this;
    }

    /**
     * Level keyed by symptom_type id, for prefilling the check-in form.
     *
     * @return array<int, int>
     */
    public function levelsByTypeId(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $type = $entry->getSymptomType();
            if ($type !== null && $type->getId() !== null) {
                $out[$type->getId()] = $entry->getLevel();
            }
        }

        return $out;
    }
}
