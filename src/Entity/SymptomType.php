<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SymptomTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A per-user symptom in the user's catalog — either a built-in (seeded from
 * SymptomTypeKey, toggle-only) or a custom one the user added (deletable). Each
 * is tracked on a 0–10 scale; `higherIsBetter` flips the slider's meaning for
 * positive symptoms (energy, mental acuity). `isEnabled` is the opt-in switch:
 * only enabled types appear on the dashboard, the check-in, and Trends.
 */
#[ORM\Entity(repositoryClass: SymptomTypeRepository::class)]
#[ORM\Table(name: 'symptom_type')]
#[ORM\UniqueConstraint(name: 'uq_symptom_type_user_key', columns: ['user_id', 'type_key'])]
class SymptomType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /** Stable slug: a SymptomTypeKey value for built-ins, or a slugged label for custom. */
    #[ORM\Column(name: 'type_key', length: 64)]
    private string $typeKey = '';

    #[ORM\Column(length: 100)]
    private string $label = '';

    #[ORM\Column]
    private bool $isBuiltin = false;

    #[ORM\Column]
    private bool $isEnabled = false;

    /** Whether a higher 0–10 value is good (energy/acuity) rather than worse. */
    #[ORM\Column]
    private bool $higherIsBetter = false;

    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getTypeKey(): string
    {
        return $this->typeKey;
    }

    public function setTypeKey(string $typeKey): static
    {
        $this->typeKey = $typeKey;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isBuiltin(): bool
    {
        return $this->isBuiltin;
    }

    public function setBuiltin(bool $isBuiltin): static
    {
        $this->isBuiltin = $isBuiltin;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function isHigherBetter(): bool
    {
        return $this->higherIsBetter;
    }

    public function setHigherIsBetter(bool $higherIsBetter): static
    {
        $this->higherIsBetter = $higherIsBetter;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Slider label for the 0 end. */
    public function lowLabel(): string
    {
        return $this->higherIsBetter ? 'Low' : 'None';
    }

    /** Translation key for the 0-end slider label (i18n). */
    public function lowLabelKey(): string
    {
        return $this->higherIsBetter ? 'symptom_page.slider.low' : 'symptom_page.slider.none';
    }

    /** Translation key for the 10-end slider label (i18n). */
    public function highLabelKey(): string
    {
        return $this->higherIsBetter ? 'symptom_page.slider.high' : 'symptom_page.slider.worst';
    }

    /** Slider label for the 10 end. */
    public function highLabel(): string
    {
        return $this->higherIsBetter ? 'High' : 'Worst';
    }
}
