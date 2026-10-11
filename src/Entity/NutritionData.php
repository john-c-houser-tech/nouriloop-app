<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NutritionDataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NutritionDataRepository::class)]
class NutritionData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'nutritionData')]
    #[ORM\JoinColumn(nullable: false)]
    private ?MealEntry $mealEntry = null;

    // Macronutrients (grams)
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $calories = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $protein = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $carbohydrates = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $fat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $saturatedFat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $fiber = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $sugar = null;

    // Vitamins (mg, except B12/D/K/folate in mcg stored as-is)
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminA = null; // mcg RAE

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminB1 = null; // mg (thiamine)

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminB2 = null; // mg (riboflavin)

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminB3 = null; // mg (niacin)

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminB6 = null; // mg

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminB9 = null; // mcg (folate)

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminB12 = null; // mcg

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminC = null; // mg

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminD = null; // mcg

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminE = null; // mg

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vitaminK = null; // mcg

    // Minerals (mg)
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $calcium = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $iron = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $magnesium = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $phosphorus = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $potassium = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $sodium = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $zinc = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $selenium = null; // mcg

    // Confidence score from AI analysis (0.0–1.0)
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $confidenceScore = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCalories(): ?float
    {
        return $this->calories;
    }

    public function setCalories(?float $calories): static
    {
        $this->calories = $calories;

        return $this;
    }

    public function getProtein(): ?float
    {
        return $this->protein;
    }

    public function setProtein(?float $protein): static
    {
        $this->protein = $protein;

        return $this;
    }

    public function getCarbohydrates(): ?float
    {
        return $this->carbohydrates;
    }

    public function setCarbohydrates(?float $carbohydrates): static
    {
        $this->carbohydrates = $carbohydrates;

        return $this;
    }

    public function getFat(): ?float
    {
        return $this->fat;
    }

    public function setFat(?float $fat): static
    {
        $this->fat = $fat;

        return $this;
    }

    public function getSaturatedFat(): ?float
    {
        return $this->saturatedFat;
    }

    public function setSaturatedFat(?float $saturatedFat): static
    {
        $this->saturatedFat = $saturatedFat;

        return $this;
    }

    public function getFiber(): ?float
    {
        return $this->fiber;
    }

    public function setFiber(?float $fiber): static
    {
        $this->fiber = $fiber;

        return $this;
    }

    public function getSugar(): ?float
    {
        return $this->sugar;
    }

    public function setSugar(?float $sugar): static
    {
        $this->sugar = $sugar;

        return $this;
    }

    public function getVitaminA(): ?float
    {
        return $this->vitaminA;
    }
    public function setVitaminA(?float $v): static
    {
        $this->vitaminA = $v;
        return $this;
    }

    public function getVitaminB1(): ?float
    {
        return $this->vitaminB1;
    }
    public function setVitaminB1(?float $v): static
    {
        $this->vitaminB1 = $v;
        return $this;
    }

    public function getVitaminB2(): ?float
    {
        return $this->vitaminB2;
    }
    public function setVitaminB2(?float $v): static
    {
        $this->vitaminB2 = $v;
        return $this;
    }

    public function getVitaminB3(): ?float
    {
        return $this->vitaminB3;
    }
    public function setVitaminB3(?float $v): static
    {
        $this->vitaminB3 = $v;
        return $this;
    }

    public function getVitaminB6(): ?float
    {
        return $this->vitaminB6;
    }
    public function setVitaminB6(?float $v): static
    {
        $this->vitaminB6 = $v;
        return $this;
    }

    public function getVitaminB9(): ?float
    {
        return $this->vitaminB9;
    }
    public function setVitaminB9(?float $v): static
    {
        $this->vitaminB9 = $v;
        return $this;
    }

    public function getVitaminB12(): ?float
    {
        return $this->vitaminB12;
    }
    public function setVitaminB12(?float $v): static
    {
        $this->vitaminB12 = $v;
        return $this;
    }

    public function getVitaminC(): ?float
    {
        return $this->vitaminC;
    }
    public function setVitaminC(?float $v): static
    {
        $this->vitaminC = $v;
        return $this;
    }

    public function getVitaminD(): ?float
    {
        return $this->vitaminD;
    }
    public function setVitaminD(?float $v): static
    {
        $this->vitaminD = $v;
        return $this;
    }

    public function getVitaminE(): ?float
    {
        return $this->vitaminE;
    }
    public function setVitaminE(?float $v): static
    {
        $this->vitaminE = $v;
        return $this;
    }

    public function getVitaminK(): ?float
    {
        return $this->vitaminK;
    }
    public function setVitaminK(?float $v): static
    {
        $this->vitaminK = $v;
        return $this;
    }

    public function getCalcium(): ?float
    {
        return $this->calcium;
    }
    public function setCalcium(?float $v): static
    {
        $this->calcium = $v;
        return $this;
    }

    public function getIron(): ?float
    {
        return $this->iron;
    }
    public function setIron(?float $v): static
    {
        $this->iron = $v;
        return $this;
    }

    public function getMagnesium(): ?float
    {
        return $this->magnesium;
    }
    public function setMagnesium(?float $v): static
    {
        $this->magnesium = $v;
        return $this;
    }

    public function getPhosphorus(): ?float
    {
        return $this->phosphorus;
    }
    public function setPhosphorus(?float $v): static
    {
        $this->phosphorus = $v;
        return $this;
    }

    public function getPotassium(): ?float
    {
        return $this->potassium;
    }
    public function setPotassium(?float $v): static
    {
        $this->potassium = $v;
        return $this;
    }

    public function getSodium(): ?float
    {
        return $this->sodium;
    }
    public function setSodium(?float $v): static
    {
        $this->sodium = $v;
        return $this;
    }

    public function getZinc(): ?float
    {
        return $this->zinc;
    }
    public function setZinc(?float $v): static
    {
        $this->zinc = $v;
        return $this;
    }

    public function getSelenium(): ?float
    {
        return $this->selenium;
    }
    public function setSelenium(?float $v): static
    {
        $this->selenium = $v;
        return $this;
    }

    public function getConfidenceScore(): ?float
    {
        return $this->confidenceScore;
    }
    public function setConfidenceScore(?float $v): static
    {
        $this->confidenceScore = $v;
        return $this;
    }

    /** Nutrient fields, camelCase (excludes confidenceScore, which is not a quantity to scale). */
    public const FIELDS = [
        'calories', 'protein', 'carbohydrates', 'fat', 'saturatedFat', 'fiber', 'sugar',
        'vitaminA', 'vitaminB1', 'vitaminB2', 'vitaminB3', 'vitaminB6', 'vitaminB9', 'vitaminB12',
        'vitaminC', 'vitaminD', 'vitaminE', 'vitaminK', 'calcium', 'iron', 'magnesium',
        'phosphorus', 'potassium', 'sodium', 'zinc', 'selenium',
    ];

    /**
     * The nutrients in a Claude analysis reply, keyed by field. The reply uses
     * each field's snake_case name (`saturated_fat`, `vitamin_b12`); this is the
     * one place that mapping lives, for the meal, recipe and supplement analyses
     * alike. A value Claude couldn't put a number on ("~20", "unknown") is no
     * estimate and is left out.
     *
     * @param array<mixed> $reply
     *
     * @return array<string, float>
     */
    public static function nutrientsFromClaudeJson(array $reply): array
    {
        $nutrients = [];
        foreach (self::FIELDS as $field) {
            $value = $reply[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field))] ?? null;
            if ($value !== null && is_numeric($value)) {
                $nutrients[$field] = (float) $value;
            }
        }

        return $nutrients;
    }

    /**
     * A new row from a Claude analysis reply: every nutrient, plus the reply's
     * `confidence` as the confidence score (null when it isn't a number).
     *
     * @param array<mixed> $reply
     */
    public static function fromClaudeJson(array $reply): self
    {
        $confidence = $reply['confidence'] ?? null;

        return (new self())->applyArray(self::nutrientsFromClaudeJson($reply) + [
            'confidenceScore' => is_numeric($confidence) ? (float) $confidence : null,
        ]);
    }

    /**
     * @return array<string, float|null>
     */
    public function toArray(): array
    {
        $data = ['confidenceScore' => $this->confidenceScore];
        foreach (self::FIELDS as $field) {
            $data[$field] = $this->{'get' . ucfirst($field)}();
        }

        return $data;
    }

    /**
     * Multiplies every nutrient in place (confidence untouched — it is not a
     * quantity). fromArray() covers the build-a-new-row paths (photo save,
     * log-from-favorite, recipe division); this covers rescaling an
     * already-persisted row, i.e. the edit screen's portion adjustment.
     */
    public function scaleBy(float $scale): static
    {
        foreach (self::FIELDS as $field) {
            $value = $this->{'get' . ucfirst($field)}();
            if ($value !== null) {
                $this->{'set' . ucfirst($field)}($value * $scale);
            }
        }

        return $this;
    }

    /**
     * Overwrites every nutrient (and the confidence) from toArray() output, in
     * place — the counterpart to fromArray() for a row that must keep its
     * identity. Applying a re-analysis to a persisted meal can't swap the
     * OneToOne for a fresh row without orphaning the old one.
     *
     * ⚠️ A field absent from $data is set to NULL, not left alone: the payload
     * is a complete replacement estimate, so a nutrient the new analysis
     * couldn't estimate must stop reporting the previous run's figure rather
     * than silently mixing two estimates.
     *
     * @param array<string, float|null> $data
     */
    public function applyArray(array $data): static
    {
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $this->{'set' . ucfirst($field)}($value !== null ? (float) $value : null);
        }
        $this->setConfidenceScore($data['confidenceScore'] ?? null);

        return $this;
    }

    /**
     * Rebuilds a NutritionData from toArray() output, multiplying each nutrient by $scale.
     *
     * @param array<string, float|null> $data
     */
    public static function fromArray(array $data, float $scale = 1.0): self
    {
        $nutrition = new self();
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $nutrition->{'set' . ucfirst($field)}($value !== null ? $value * $scale : null);
        }
        $nutrition->setConfidenceScore($data['confidenceScore'] ?? null);

        return $nutrition;
    }
}
