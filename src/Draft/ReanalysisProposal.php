<?php

declare(strict_types=1);

namespace App\Draft;

use App\Dto\AnalysisResult;

/**
 * A re-analysis result waiting for the user to apply it to the one meal it was
 * made for (see CONTEXT.md). Applying overwrites that meal; it is never a new
 * record, which is why this is not a draft.
 */
final readonly class ReanalysisProposal implements SessionDraft
{
    /**
     * $description is what the user submitted (it becomes the meal's notes on
     * apply); $newPaths are the staged uploads, on disk. With $replacePhoto
     * the first becomes the meal's photo; otherwise they were analysis aids.
     *
     * @param list<string>                                             $newPaths
     * @param list<string>                                             $questions
     * @param list<array{name: string, amount: string, unit: string}> $ingredients
     * @param array<string, float|null>                               $nutrition
     */
    public function __construct(
        public int $mealId,
        public string $description,
        public array $newPaths,
        public bool $replacePhoto,
        public ?string $aiDescription,
        public array $questions,
        public array $ingredients,
        public array $nutrition,
    ) {
    }

    /** @param list<string> $newPaths */
    public static function fromAnalysis(
        int $mealId,
        string $description,
        array $newPaths,
        bool $replacePhoto,
        AnalysisResult $result,
    ): self {
        return new self(
            $mealId,
            $description,
            $newPaths,
            $replacePhoto && $newPaths !== [],
            $result->description,
            $result->questions,
            $result->ingredients,
            $result->nutrition->toArray(),
        );
    }

    public function isFor(int $mealId): bool
    {
        return $this->mealId === $mealId;
    }

    /** The staged photo that becomes the meal's own on apply, if any. */
    public function appliedPhoto(): ?string
    {
        return $this->replacePhoto ? ($this->newPaths[0] ?? null) : null;
    }

    /**
     * Without the staged photos that have vanished from disk (swept while the
     * proposal sat open). Losing them all also drops replacePhoto: there is
     * nothing left to replace the meal's photo with.
     */
    public function withoutMissingPhotos(): self
    {
        $alive = array_values(array_filter($this->newPaths, 'is_file'));

        return new self(
            $this->mealId,
            $this->description,
            $alive,
            $this->replacePhoto && $alive !== [],
            $this->aiDescription,
            $this->questions,
            $this->ingredients,
            $this->nutrition,
        );
    }

    public static function sessionKey(): string
    {
        return 'meal_reanalysis';
    }

    public static function fromArray(array $data): ?static
    {
        if (!is_int($data['meal_id'] ?? null) || !is_array($data['nutrition'] ?? null)) {
            return null;
        }

        return new self(
            $data['meal_id'],
            (string) ($data['description'] ?? ''),
            array_values((array) ($data['new_paths'] ?? [])),
            ($data['replace_photo'] ?? false) === true,
            isset($data['ai_description']) ? (string) $data['ai_description'] : null,
            array_values((array) ($data['questions'] ?? [])),
            array_values((array) ($data['ingredients'] ?? [])),
            $data['nutrition'],
        );
    }

    public function toArray(): array
    {
        return [
            'meal_id'        => $this->mealId,
            'description'    => $this->description,
            'new_paths'      => $this->newPaths,
            'replace_photo'  => $this->replacePhoto,
            'ai_description' => $this->aiDescription,
            'questions'      => $this->questions,
            'ingredients'    => $this->ingredients,
            'nutrition'      => $this->nutrition,
        ];
    }

    public function stagedPaths(): array
    {
        return $this->newPaths;
    }
}
