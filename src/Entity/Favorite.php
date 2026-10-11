<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A meal the user eats repeatedly, saved with its full post-correction
 * nutritional data so it can be logged again without another Claude analysis.
 *
 * Nutrients are stored as the NutritionData::toArray() shape (keyed nutrient
 * map including the un-scaled confidenceScore), so logging from a favorite is a
 * NutritionData::fromArray() round-trip scaled by the chosen portion.
 */
#[ORM\Entity(repositoryClass: FavoriteRepository::class)]
class Favorite
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
    private ?string $thumbnailPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiDescription = null;

    /**
     * Post-correction nutrition in NutritionData::toArray() shape.
     *
     * @var array<string, float|null>
     */
    #[ORM\Column(type: 'json')]
    private array $nutrients = [];

    /**
     * @var array<array{name: string, amount: string, unit: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $ingredients = [];

    /**
     * Token cost of the analysis that produced this favorite — what each reuse
     * avoids spending. 0 when unknown (e.g. favorited before token tracking).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $tokensPerAnalysis = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $useCount = 0;

    /**
     * Preparation steps transcribed at recipe import — display-only reference
     * text, never re-parsed. Null for favorites created any other way.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $instructions = null;

    /**
     * How many servings the imported recipe made — the divisor applied to the
     * batch nutrition/ingredients at import, kept so a mis-typed count can be
     * re-derived later. Non-null also marks the favorite as a recipe import
     * (the Free-tier analysis allowance counts these; see AnalysisAllowance).
     */
    #[ORM\Column(nullable: true)]
    private ?float $servingsAtImport = null;

    /**
     * The recipe's ingredient list at WHOLE-BATCH quantities, exactly as
     * confirmed at import — the amounts a cook needs ("2 cups lentils"), as
     * opposed to $ingredients, which is the per-serving division the meal
     * pipeline logs from. Display-only reference next to the instructions.
     * Null for non-imports (and for imports predating this column — those
     * reconstruct per-serving × servingsAtImport at display time).
     *
     * @var array<array{name: string, amount: string, unit: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $batchIngredients = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

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

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): static
    {
        $this->thumbnailPath = $thumbnailPath;

        return $this;
    }

    public function getAiDescription(): ?string
    {
        return $this->aiDescription;
    }

    public function setAiDescription(?string $aiDescription): static
    {
        $this->aiDescription = $aiDescription;

        return $this;
    }

    /**
     * @return array<string, float|null>
     */
    public function getNutrients(): array
    {
        return $this->nutrients;
    }

    /**
     * @param array<string, float|null> $nutrients
     */
    public function setNutrients(array $nutrients): static
    {
        $this->nutrients = $nutrients;

        return $this;
    }

    /**
     * @return array<array{name: string, amount: string, unit: string}>
     */
    public function getIngredients(): array
    {
        return $this->ingredients;
    }

    /**
     * @param array<array{name: string, amount: string, unit: string}> $ingredients
     */
    public function setIngredients(array $ingredients): static
    {
        $this->ingredients = $ingredients;

        return $this;
    }

    public function getTokensPerAnalysis(): int
    {
        return $this->tokensPerAnalysis;
    }

    public function setTokensPerAnalysis(int $tokensPerAnalysis): static
    {
        $this->tokensPerAnalysis = $tokensPerAnalysis;

        return $this;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function setUseCount(int $useCount): static
    {
        $this->useCount = $useCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function setInstructions(?string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function getServingsAtImport(): ?float
    {
        return $this->servingsAtImport;
    }

    /**
     * @return array<array{name: string, amount: string, unit: string}>|null
     */
    public function getBatchIngredients(): ?array
    {
        return $this->batchIngredients;
    }

    /**
     * @param array<array{name: string, amount: string, unit: string}>|null $batchIngredients
     */
    public function setBatchIngredients(?array $batchIngredients): static
    {
        $this->batchIngredients = $batchIngredients;

        return $this;
    }

    public function setServingsAtImport(?float $servingsAtImport): static
    {
        $this->servingsAtImport = $servingsAtImport;

        return $this;
    }

    /**
     * Records one reuse: bumps the count and stamps the time, for the
     * most-recently/most-used picker ordering and the savings tally.
     */
    public function recordUse(?\DateTimeImmutable $at = null): static
    {
        $this->useCount++;
        $this->lastUsedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Total tokens this favorite has saved across all reuses (0 when the
     * per-analysis cost is unknown).
     */
    public function tokensSaved(): int
    {
        return $this->useCount * $this->tokensPerAnalysis;
    }
}
