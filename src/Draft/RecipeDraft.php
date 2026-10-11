<?php

declare(strict_types=1);

namespace App\Draft;

use App\Dto\RecipeAnalysis;
use App\Dto\StagedPhoto;

/**
 * A photographed recipe under review on the confirm screen, before it is saved
 * as a favorite. Its photos stay staged until then, so clarify rounds can resend
 * every one.
 */
final readonly class RecipeDraft implements SessionDraft
{
    /**
     * $nutrients are the whole batch's (NutritionData::toArray() shape);
     * $imagePaths are on disk, the first being the thumbnail.
     *
     * @param list<array{name: string, amount: string, unit: string}> $ingredients
     * @param array<string, float|null>                               $nutrients
     * @param list<string>                                             $questions
     * @param list<string>                                             $extraWebPaths
     * @param list<string>                                             $imagePaths
     * @param list<array<string, mixed>>                               $context
     */
    public function __construct(
        public string $title,
        public ?float $servings,
        public float $confidence,
        public array $ingredients,
        public array $nutrients,
        public string $instructions,
        public array $questions,
        public int $tokens,
        public ?string $imageWeb,
        public array $extraWebPaths,
        public array $imagePaths,
        public array $context,
        public int $round,
    ) {
    }

    /** @param list<StagedPhoto> $photos */
    public static function fromScan(RecipeAnalysis $result, array $photos): self
    {
        $webPaths = array_map(static fn(StagedPhoto $p): string => $p->webPath, $photos);

        return new self(
            $result->title,
            $result->servings,
            $result->confidence,
            $result->ingredients,
            $result->nutrition->toArray(),
            $result->instructions,
            $result->questions,
            $result->totalTokens(),
            $webPaths[0] ?? null,
            array_slice($webPaths, 1),
            array_map(static fn(StagedPhoto $p): string => $p->path, $photos),
            [],
            1,
        );
    }

    /**
     * The draft after a clarify round. The user's typed title, servings and
     * instructions win over the fresh AI values: they own those fields once
     * touched.
     *
     * @param list<array<string, mixed>> $context
     */
    public function afterRound(
        RecipeAnalysis $result,
        array $context,
        string $title,
        float $servings,
        string $instructions,
    ): self {
        return new self(
            $title !== '' ? $title : $result->title,
            $servings > 0 ? $servings : $result->servings,
            $result->confidence,
            $result->ingredients,
            $result->nutrition->toArray(),
            $instructions !== '' ? $instructions : $result->instructions,
            $result->questions,
            $result->totalTokens(),
            $this->imageWeb,
            $this->extraWebPaths,
            $this->imagePaths,
            $context,
            $this->round + 1,
        );
    }

    public static function sessionKey(): string
    {
        return 'recipe_import';
    }

    public static function fromArray(array $data): ?static
    {
        if (!is_array($data['nutrients'] ?? null)) {
            return null;
        }
        $servings = $data['servings'] ?? null;

        return new self(
            (string) ($data['title'] ?? ''),
            is_numeric($servings) ? (float) $servings : null,
            (float) ($data['confidence'] ?? 0.5),
            array_values((array) ($data['ingredients'] ?? [])),
            $data['nutrients'],
            (string) ($data['instructions'] ?? ''),
            array_values((array) ($data['questions'] ?? [])),
            (int) ($data['tokens'] ?? 0),
            isset($data['image_web']) ? (string) $data['image_web'] : null,
            array_values((array) ($data['extra_web_paths'] ?? [])),
            array_values((array) ($data['image_paths'] ?? [])),
            array_values((array) ($data['context'] ?? [])),
            (int) ($data['round'] ?? 1),
        );
    }

    public function toArray(): array
    {
        return [
            'title'           => $this->title,
            'servings'        => $this->servings,
            'confidence'      => $this->confidence,
            'ingredients'     => $this->ingredients,
            'nutrients'       => $this->nutrients,
            'instructions'    => $this->instructions,
            'questions'       => $this->questions,
            'tokens'          => $this->tokens,
            'image_web'       => $this->imageWeb,
            'extra_web_paths' => $this->extraWebPaths,
            'image_paths'     => $this->imagePaths,
            'context'         => $this->context,
            'round'           => $this->round,
        ];
    }

    public function stagedPaths(): array
    {
        return $this->imagePaths;
    }
}
