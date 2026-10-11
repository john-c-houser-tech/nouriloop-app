<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SupplementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A supplement the user takes on a standing daily basis. Per-serving nutrient
 * amounts are stored as a JSON map keyed by the RDA nutrient keys (e.g.
 * "vitaminA"); the daily contribution is that amount times servingsPerDay.
 */
#[ORM\Entity(repositoryClass: SupplementRepository::class)]
class Supplement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imagePath = null;

    // String default so the mapping matches the value MariaDB stores/introspects
    // for a DOUBLE default (it normalises to '1'); avoids a perpetual DBAL
    // comparator false-positive (string '1' vs int 1). DB form is unchanged.
    #[ORM\Column(options: ['default' => '1'])]
    private float $servingsPerDay = 1.0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Per-serving nutrient amounts, keyed by RDA nutrient key (e.g. 'vitaminC').
     *
     * @var array<string, float>
     */
    #[ORM\Column(type: 'json')]
    private array $nutrients = [];

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getServingsPerDay(): float
    {
        return $this->servingsPerDay;
    }

    public function setServingsPerDay(float $servingsPerDay): static
    {
        $this->servingsPerDay = $servingsPerDay;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * @return array<string, float>
     */
    public function getNutrients(): array
    {
        return $this->nutrients;
    }

    /**
     * @param array<string, float> $nutrients
     */
    public function setNutrients(array $nutrients): static
    {
        $this->nutrients = $nutrients;

        return $this;
    }

    /**
     * When the supplement was added. It counts toward intake from that local day
     * on, never before (DailyIntake): supplements keep no other history.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Backdates when the supplement was added (the demo seeder's 60-day history). */
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Per-day nutrient contribution (per-serving amount × servings/day).
     *
     * @return array<string, float>
     */
    public function dailyNutrients(): array
    {
        return array_map(fn (float $v): float => $v * $this->servingsPerDay, $this->nutrients);
    }
}
