<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MealType;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MealEntryRepository::class)]
// Every hot path (dashboard, trends, history, allowance counts, glucose insights)
// filters on user + a loggedAt range; the FK's single-column user_id index made
// MariaDB read all of a user's meals and filter the dates row by row.
#[ORM\Index(name: 'idx_meal_entry_user_logged', columns: ['user_id', 'logged_at'])]
class MealEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mealEntries')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $aiDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(enumType: MealType::class)]
    private ?MealType $mealType = null;

    #[ORM\Column]
    private \DateTimeImmutable $loggedAt;

    #[ORM\OneToOne(targetEntity: NutritionData::class, mappedBy: 'mealEntry', cascade: ['persist', 'remove'])]
    private ?NutritionData $nutritionData = null;

    /**
     * The favorite this entry was logged from, if any. SET NULL on delete:
     * removing a favorite must not delete the meals logged from it — they keep
     * their own copied nutrition and just lose the back-reference.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Favorite $favorite = null;

    /**
     * Logged from a favorite: a copy, no AI call, so AnalysisAllowance doesn't count
     * it. Its own column because {@see $favorite} is SET NULL when the favorite is
     * deleted, which would turn the meal back into a counted analysis.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $fromFavorite = false;

    /**
     * @var array<array{name: string, amount: string, unit: string}>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array $ingredients = [];

    /**
     * Stamped (user-local wall-clock, like loggedAt) whenever an edit actually
     * changes the entry — the "edited on {date}" trust marker on the detail
     * page. Null = never edited since logging.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    /**
     * Provenance flag for the nutrition numbers: set when the user manually
     * corrected a nutrient or applied a portion rescale, meaning the stored
     * values are user-corrected, no longer the AI estimate (so the AI
     * confidence bar stops rendering for them). Phase 3's re-analysis must
     * diff-before-apply against any entry carrying this stamp.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nutritionEditedAt = null;

    /**
     * Provenance flag for the other direction (Phase 3): set when an AI
     * re-analysis was applied, meaning the numbers are a FRESH AI estimate —
     * so the confidence bar is meaningful again and describes this run, not the
     * original logging. Applying a re-analysis therefore clears
     * nutritionEditedAt, and a later manual correction clears this: the two are
     * mutually exclusive answers to "where did these numbers come from".
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reanalyzedAt = null;

    public function __construct()
    {
        $this->loggedAt = new \DateTimeImmutable();
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

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getMealType(): ?MealType
    {
        return $this->mealType;
    }

    public function setMealType(MealType $mealType): static
    {
        $this->mealType = $mealType;

        return $this;
    }

    public function getLoggedAt(): \DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(\DateTimeImmutable $loggedAt): static
    {
        $this->loggedAt = $loggedAt;

        return $this;
    }

    public function getNutritionData(): ?NutritionData
    {
        return $this->nutritionData;
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

    public function getFavorite(): ?Favorite
    {
        return $this->favorite;
    }

    public function setFavorite(?Favorite $favorite): static
    {
        $this->favorite = $favorite;
        if ($favorite !== null) {
            $this->fromFavorite = true;
        }

        return $this;
    }

    public function isFromFavorite(): bool
    {
        return $this->fromFavorite;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function setEditedAt(?\DateTimeImmutable $editedAt): static
    {
        $this->editedAt = $editedAt;

        return $this;
    }

    public function getNutritionEditedAt(): ?\DateTimeImmutable
    {
        return $this->nutritionEditedAt;
    }

    public function setNutritionEditedAt(?\DateTimeImmutable $nutritionEditedAt): static
    {
        $this->nutritionEditedAt = $nutritionEditedAt;

        return $this;
    }

    public function getReanalyzedAt(): ?\DateTimeImmutable
    {
        return $this->reanalyzedAt;
    }

    public function setReanalyzedAt(?\DateTimeImmutable $reanalyzedAt): static
    {
        $this->reanalyzedAt = $reanalyzedAt;

        return $this;
    }

    public function setNutritionData(?NutritionData $nutritionData): static
    {
        if ($nutritionData === null && $this->nutritionData !== null) {
            $this->nutritionData->setMealEntry(null);
        }

        if ($nutritionData !== null && $nutritionData->getMealEntry() !== $this) {
            $nutritionData->setMealEntry($this);
        }

        $this->nutritionData = $nutritionData;

        return $this;
    }
}
