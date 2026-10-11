<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SymptomEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One symptom's 0–10 level for a given day. Unique per (day, type). Both FKs
 * cascade on delete, so removing a day or a symptom type takes its entries with
 * it.
 */
#[ORM\Entity(repositoryClass: SymptomEntryRepository::class)]
#[ORM\Table(name: 'symptom_entry')]
#[ORM\UniqueConstraint(name: 'uq_symptom_entry', columns: ['symptom_day_id', 'symptom_type_id'])]
class SymptomEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'symptom_day_id', nullable: false, onDelete: 'CASCADE')]
    private ?SymptomDay $symptomDay = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'symptom_type_id', nullable: false, onDelete: 'CASCADE')]
    private ?SymptomType $symptomType = null;

    /** 0 – 10. */
    #[ORM\Column(type: 'smallint')]
    private int $level = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymptomDay(): ?SymptomDay
    {
        return $this->symptomDay;
    }

    public function setSymptomDay(?SymptomDay $symptomDay): static
    {
        $this->symptomDay = $symptomDay;

        return $this;
    }

    public function getSymptomType(): ?SymptomType
    {
        return $this->symptomType;
    }

    public function setSymptomType(?SymptomType $symptomType): static
    {
        $this->symptomType = $symptomType;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = max(0, min(10, $level));

        return $this;
    }
}
