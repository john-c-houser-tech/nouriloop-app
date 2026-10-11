<?php

declare(strict_types=1);

namespace App\Draft;

use App\Dto\AnalysisResult;
use App\Dto\StagedPhoto;

/**
 * A meal under review on the Confirm & Refine screen, before it is logged.
 * Its photos stay staged until then: the first (when there is one) becomes the
 * meal's photo, the rest were analysis aids and are deleted on save.
 */
final readonly class MealDraft implements SessionDraft
{
    /**
     * $imagePaths are on disk and grow when the user adds an extra photo (e.g. a
     * label) while refining; empty for a described meal. $describe is the
     * user's own description in the photo-free flow ('' otherwise).
     *
     * @param list<string>                                             $imagePaths
     * @param list<string>                                             $extraWebPaths
     * @param list<array<string, mixed>>                               $context
     * @param list<string>                                             $questions
     * @param list<array{name: string, amount: string, unit: string}> $ingredients
     * @param array<string, float|null>                               $nutrition
     */
    public function __construct(
        public ?string $imagePath,
        public ?string $imageWebPath,
        public array $imagePaths,
        public array $extraWebPaths,
        public string $describe,
        public string $mealType,
        public ?string $notes,
        public \DateTimeImmutable $loggedAt,
        public array $context,
        public int $round,
        public string $description,
        public array $questions,
        public array $ingredients,
        public array $nutrition,
        public int $analysisTokens,
    ) {
    }

    /**
     * A fresh draft from the first analysis.
     *
     * @param list<string> $imagePaths
     * @param list<string> $extraWebPaths
     */
    public static function start(
        array $imagePaths,
        ?string $imageWebPath,
        array $extraWebPaths,
        string $describe,
        string $mealType,
        ?string $notes,
        \DateTimeImmutable $loggedAt,
        AnalysisResult $result,
    ): self {
        return new self(
            $imagePaths[0] ?? null,
            $imageWebPath,
            $imagePaths,
            $extraWebPaths,
            $describe,
            $mealType,
            $notes,
            $loggedAt,
            [],
            1,
            $result->description,
            $result->questions,
            $result->ingredients,
            $result->nutrition->toArray(),
            $result->totalTokens(),
        );
    }

    /** With a photo added while refining (an analysis aid, deleted on save). */
    public function withExtraPhoto(StagedPhoto $photo): self
    {
        return $this->with(
            imagePaths: [...$this->imagePaths, $photo->path],
            extraWebPaths: [...$this->extraWebPaths, $photo->webPath],
        );
    }

    /**
     * After a clarify round: the new estimate, and the context that produced it.
     *
     * @param list<array<string, mixed>> $context
     */
    public function afterRound(array $context, AnalysisResult $result): self
    {
        return $this->with(
            context: $context,
            round: $this->round + 1,
            description: $result->description,
            questions: $result->questions,
            ingredients: $result->ingredients,
            nutrition: $result->nutrition->toArray(),
            // The latest round's cost: what a favorite saved from this
            // estimate avoids spending on each reuse.
            analysisTokens: $result->totalTokens(),
        );
    }

    /** @param list<array{name: string, amount: string, unit: string}> $ingredients */
    public function withIngredients(array $ingredients): self
    {
        return $this->with(ingredients: $ingredients);
    }

    public function withLoggedAt(\DateTimeImmutable $loggedAt): self
    {
        return $this->with(loggedAt: $loggedAt);
    }

    public static function sessionKey(): string
    {
        return 'meal_analysis';
    }

    /**
     * Null unless the entry can be reviewed: it needs a photo or the user's
     * description, an estimate, and a logged-at time that parses (anything else
     * can only be a crafted or corrupted session).
     */
    public static function fromArray(array $data): ?static
    {
        $loggedAt = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, (string) ($data['logged_at'] ?? ''));
        $imagePath = isset($data['image_path']) && $data['image_path'] !== '' ? (string) $data['image_path'] : null;
        $describe = trim((string) ($data['describe'] ?? ''));
        if (
            $loggedAt === false
            || ($imagePath === null && $describe === '')
            || !is_array($data['nutrition'] ?? null)
            || !isset($data['meal_type'])
        ) {
            return null;
        }

        return new self(
            $imagePath,
            isset($data['image_web_path']) ? (string) $data['image_web_path'] : null,
            // Older drafts carried only the single path.
            array_values((array) ($data['image_paths'] ?? array_filter([$imagePath]))),
            array_values((array) ($data['extra_web_paths'] ?? [])),
            (string) ($data['describe'] ?? ''),
            (string) $data['meal_type'],
            isset($data['notes']) ? (string) $data['notes'] : null,
            $loggedAt,
            array_values((array) ($data['context'] ?? [])),
            (int) ($data['round'] ?? 1),
            (string) ($data['description'] ?? ''),
            array_values((array) ($data['questions'] ?? [])),
            array_values((array) ($data['ingredients'] ?? [])),
            $data['nutrition'],
            (int) ($data['analysis_tokens'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'image_path'      => $this->imagePath,
            'image_web_path'  => $this->imageWebPath,
            'image_paths'     => $this->imagePaths,
            'extra_web_paths' => $this->extraWebPaths,
            'describe'        => $this->describe,
            'meal_type'       => $this->mealType,
            'notes'           => $this->notes,
            'logged_at'       => $this->loggedAt->format(\DateTimeImmutable::ATOM),
            'context'         => $this->context,
            'round'           => $this->round,
            'description'     => $this->description,
            'questions'       => $this->questions,
            'ingredients'     => $this->ingredients,
            'nutrition'       => $this->nutrition,
            'analysis_tokens' => $this->analysisTokens,
        ];
    }

    public function stagedPaths(): array
    {
        return $this->imagePaths;
    }

    /**
     * @param list<string>|null                                             $imagePaths
     * @param list<string>|null                                             $extraWebPaths
     * @param list<array<string, mixed>>|null                               $context
     * @param list<string>|null                                             $questions
     * @param list<array{name: string, amount: string, unit: string}>|null $ingredients
     * @param array<string, float|null>|null                               $nutrition
     */
    private function with(
        ?array $imagePaths = null,
        ?array $extraWebPaths = null,
        ?\DateTimeImmutable $loggedAt = null,
        ?array $context = null,
        ?int $round = null,
        ?string $description = null,
        ?array $questions = null,
        ?array $ingredients = null,
        ?array $nutrition = null,
        ?int $analysisTokens = null,
    ): self {
        return new self(
            $this->imagePath,
            $this->imageWebPath,
            $imagePaths ?? $this->imagePaths,
            $extraWebPaths ?? $this->extraWebPaths,
            $this->describe,
            $this->mealType,
            $this->notes,
            $loggedAt ?? $this->loggedAt,
            $context ?? $this->context,
            $round ?? $this->round,
            $description ?? $this->description,
            $questions ?? $this->questions,
            $ingredients ?? $this->ingredients,
            $nutrition ?? $this->nutrition,
            $analysisTokens ?? $this->analysisTokens,
        );
    }
}
