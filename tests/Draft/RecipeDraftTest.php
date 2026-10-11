<?php

declare(strict_types=1);

namespace App\Tests\Draft;

use App\Draft\RecipeDraft;
use App\Dto\RecipeAnalysis;
use App\Dto\StagedPhoto;
use App\Entity\NutritionData;
use PHPUnit\Framework\TestCase;

final class RecipeDraftTest extends TestCase
{
    public function testAScannedDraftRoundTripsThroughTheSessionArray(): void
    {
        $draft = RecipeDraft::fromScan($this->analysis('Lentil soup', 6.0), [
            new StagedPhoto('/srv/public/uploads/meals/recipe-a.jpg', 'uploads/meals/recipe-a.jpg'),
            new StagedPhoto('/srv/public/uploads/meals/recipe-b.jpg', 'uploads/meals/recipe-b.jpg'),
        ]);

        self::assertSame('uploads/meals/recipe-a.jpg', $draft->imageWeb);
        self::assertSame(['uploads/meals/recipe-b.jpg'], $draft->extraWebPaths);
        self::assertSame(
            ['/srv/public/uploads/meals/recipe-a.jpg', '/srv/public/uploads/meals/recipe-b.jpg'],
            $draft->stagedPaths(),
        );
        self::assertSame(1, $draft->round);
        self::assertEquals($draft, RecipeDraft::fromArray($draft->toArray()));
    }

    public function testTheSessionArrayKeepsItsPreTypedKeys(): void
    {
        self::assertSame(
            ['title', 'servings', 'confidence', 'ingredients', 'nutrients', 'instructions', 'questions',
                'tokens', 'image_web', 'extra_web_paths', 'image_paths', 'context', 'round'],
            array_keys(RecipeDraft::fromScan($this->analysis('Soup', 4.0), [])->toArray()),
        );
    }

    public function testAfterARoundTheUsersTypedFieldsWinAndThePhotosStay(): void
    {
        $draft = RecipeDraft::fromScan($this->analysis('AI title', 4.0), [
            new StagedPhoto('/p/a.jpg', 'uploads/meals/a.jpg'),
        ]);

        $context = [['question' => 'q', 'answer' => 'a']];
        $next = $draft->afterRound($this->analysis('New AI title', 8.0), $context, 'My title', 5.0, 'My steps');

        self::assertSame('My title', $next->title);
        self::assertSame(5.0, $next->servings);
        self::assertSame('My steps', $next->instructions);
        self::assertSame(2, $next->round);
        self::assertSame(['/p/a.jpg'], $next->imagePaths);
        self::assertSame([['question' => 'q', 'answer' => 'a']], $next->context);

        $untouched = $draft->afterRound($this->analysis('New AI title', 8.0), [], '', 0.0, '');
        self::assertSame('New AI title', $untouched->title);
        self::assertSame(8.0, $untouched->servings);
    }

    public function testAnEntryWithoutNutrientsIsNotADraft(): void
    {
        self::assertNull(RecipeDraft::fromArray(['title' => 'x']));
    }

    private function analysis(string $title, float $servings): RecipeAnalysis
    {
        return new RecipeAnalysis(
            title: $title,
            servings: $servings,
            nutrition: NutritionData::fromArray(['calories' => 1200.0]),
            confidence: 0.8,
            questions: ['How much oil?'],
            ingredients: [['name' => 'lentils', 'amount' => '2', 'unit' => 'cups']],
            instructions: 'Simmer.',
            inputTokens: 1000,
            outputTokens: 200,
        );
    }
}
