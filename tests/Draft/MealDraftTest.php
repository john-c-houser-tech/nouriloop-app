<?php

declare(strict_types=1);

namespace App\Tests\Draft;

use App\Draft\MealDraft;
use App\Dto\AnalysisResult;
use App\Dto\StagedPhoto;
use App\Entity\NutritionData;
use PHPUnit\Framework\TestCase;

final class MealDraftTest extends TestCase
{
    public function testAStartedDraftRoundTripsUnderItsPreTypedKeys(): void
    {
        $draft = $this->started();

        self::assertSame(
            ['image_path', 'image_web_path', 'image_paths', 'extra_web_paths', 'describe', 'meal_type', 'notes',
                'logged_at', 'context', 'round', 'description', 'questions', 'ingredients', 'nutrition',
                'analysis_tokens'],
            array_keys($draft->toArray()),
        );
        self::assertEquals($draft, MealDraft::fromArray($draft->toArray()));
        self::assertSame('/p/meal-a.jpg', $draft->imagePath);
        self::assertSame(1, $draft->round);
        self::assertSame(1500, $draft->analysisTokens);
    }

    public function testTheLoggedAtTimeKeepsItsWallClockAndOffset(): void
    {
        $data = $this->started()->toArray();

        self::assertSame('2026-10-06T12:30:00-07:00', $data['logged_at']);
        self::assertSame('12:30 -07:00', MealDraft::fromArray($data)->loggedAt->format('H:i P'));
    }

    public function testADraftWhoseLoggedAtDoesNotParseIsRefused(): void
    {
        // Saving one used to pass false into MealEntry::setLoggedAt(): the
        // "?? now" fallback could never fire, as createFromFormat() returns false.
        $data = ['logged_at' => 'not a time'] + $this->started()->toArray();

        self::assertNull(MealDraft::fromArray($data));
    }

    public function testADraftWithNeitherPhotoNorDescriptionIsRefused(): void
    {
        $data = ['image_path' => null, 'describe' => '   '] + $this->started()->toArray();

        self::assertNull(MealDraft::fromArray($data));
    }

    public function testAnOlderDraftWithOnlyTheSinglePathStillLoads(): void
    {
        $data = $this->started()->toArray();
        unset($data['image_paths']);

        self::assertSame(['/p/meal-a.jpg'], MealDraft::fromArray($data)->imagePaths);
    }

    public function testAnExtraPhotoJoinsTheAnalysisAndTheThumbnails(): void
    {
        $extra = new StagedPhoto('/p/meal-extra-x.jpg', 'uploads/meals/meal-extra-x.jpg');
        $draft = $this->started()->withExtraPhoto($extra);

        self::assertSame(['/p/meal-a.jpg', '/p/meal-b.jpg', '/p/meal-extra-x.jpg'], $draft->imagePaths);
        self::assertSame(
            ['uploads/meals/meal-b.jpg', 'uploads/meals/meal-extra-x.jpg'],
            $draft->extraWebPaths,
        );
        self::assertSame('/p/meal-a.jpg', $draft->imagePath, 'the meal photo is unchanged');
    }

    public function testAClarifyRoundReplacesTheEstimateAndCountsTheRound(): void
    {
        $context = [['question' => 'How big?', 'answer' => 'Large']];
        $draft = $this->started()->afterRound($context, $this->analysis('Larger bowl', 800.0, 2000));

        self::assertSame(2, $draft->round);
        self::assertSame($context, $draft->context);
        self::assertSame('Larger bowl', $draft->description);
        self::assertSame(800.0, $draft->nutrition['calories']);
        self::assertSame(2000, $draft->analysisTokens);
        self::assertSame('/p/meal-a.jpg', $draft->imagePath);
    }

    private function started(): MealDraft
    {
        return MealDraft::start(
            ['/p/meal-a.jpg', '/p/meal-b.jpg'],
            'uploads/meals/meal-a.jpg',
            ['uploads/meals/meal-b.jpg'],
            '',
            'lunch',
            'from the deli',
            new \DateTimeImmutable('2026-10-06T12:30:00-07:00'),
            $this->analysis('A bowl of soup', 400.0, 1500),
        );
    }

    private function analysis(string $description, float $calories, int $tokens): AnalysisResult
    {
        return new AnalysisResult(
            NutritionData::fromArray(['calories' => $calories, 'confidenceScore' => 0.8]),
            0.8,
            $description,
            [],
            [['name' => 'soup', 'amount' => '1', 'unit' => 'bowl']],
            $tokens,
            0,
        );
    }
}
