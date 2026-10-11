<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Favorite;
use App\Entity\User;
use App\Enum\MealType;
use App\Service\FavoriteManager;
use App\Service\PortionScaler;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FavoriteManagerTest extends TestCase
{
    private function manager(): FavoriteManager
    {
        // buildEntry() touches neither the DB nor the filesystem, so the dirs
        // and entity manager are inert here.
        return new FavoriteManager(
            sys_get_temp_dir(),
            sys_get_temp_dir(),
            sys_get_temp_dir(),
            $this->createStub(EntityManagerInterface::class),
            new PortionScaler(),
            new UserTime(),
        );
    }

    private function sampleFavorite(): Favorite
    {
        $favorite = new Favorite();
        $favorite->setUser(new User());
        $favorite->setName('Tuesday oatmeal');
        $favorite->setAiDescription('Oatmeal with berries');
        $favorite->setNutrients([
            'calories'        => 300.0,
            'protein'         => 10.0,
            'carbohydrates'   => 54.0,
            'fat'             => 6.0,
            'iron'            => null,
            'confidenceScore' => 0.9,
        ]);
        $favorite->setIngredients([
            ['name' => 'rolled oats', 'amount' => '80', 'unit' => 'g'],
            ['name' => 'blueberries', 'amount' => 'a handful', 'unit' => ''],
        ]);

        return $favorite;
    }

    public function testDefaultNameUsesFirstClauseOfDescription(): void
    {
        $name = FavoriteManager::defaultName('Oatmeal with berries. Served warm in a bowl.');

        self::assertSame('Oatmeal with berries', $name);
    }

    public function testDefaultNameIsCappedToSixtyChars(): void
    {
        $long = str_repeat('a', 100);

        self::assertSame(60, mb_strlen(FavoriteManager::defaultName($long)));
    }

    public function testDefaultNameFallsBackToFirstIngredient(): void
    {
        $name = FavoriteManager::defaultName('', [
            ['name' => 'grilled chicken breast', 'amount' => '150', 'unit' => 'g'],
        ]);

        self::assertSame('grilled chicken breast', $name);
    }

    public function testDefaultNameFallsBackToGenericLabel(): void
    {
        self::assertSame('Favorite meal', FavoriteManager::defaultName(null));
        self::assertSame('Favorite meal', FavoriteManager::defaultName('   '));
    }

    public function testBuildEntryScalesNutritionAndIngredientsAndLinksFavorite(): void
    {
        $favorite = $this->sampleFavorite();

        $entry = $this->manager()->buildEntry($favorite, 2.0, MealType::Lunch, 'leftovers');

        $nutrition = $entry->getNutritionData();
        self::assertNotNull($nutrition);
        self::assertSame(600.0, $nutrition->getCalories());
        self::assertSame(20.0, $nutrition->getProtein());
        self::assertNull($nutrition->getIron());
        // Uncertainty is inherited, not scaled.
        self::assertSame(0.9, $nutrition->getConfidenceScore());

        // Numeric amounts scale; non-numeric ("a handful") is left alone.
        self::assertSame('160', $entry->getIngredients()[0]['amount']);
        self::assertSame('a handful', $entry->getIngredients()[1]['amount']);

        self::assertSame(MealType::Lunch, $entry->getMealType());
        self::assertSame('leftovers', $entry->getNotes());
        self::assertSame($favorite, $entry->getFavorite());
        self::assertSame($favorite->getUser(), $entry->getUser());
        self::assertSame('Oatmeal with berries', $entry->getAiDescription());
    }

    public function testSavingsAggregatesCallsAndTokensAcrossFavorites(): void
    {
        $a = (new Favorite())->setTokensPerAnalysis(1800);
        $a->recordUse();
        $a->recordUse();           // 2 reuses × 1800 = 3600 tokens

        $b = (new Favorite())->setTokensPerAnalysis(900);
        $b->recordUse();           // 1 reuse × 900 = 900 tokens

        $c = (new Favorite())->setTokensPerAnalysis(0);
        $c->recordUse();
        $c->recordUse();
        $c->recordUse();           // 3 reuses, tokens unknown → 0

        $savings = FavoriteManager::savings([$a, $b, $c]);

        self::assertSame(6, $savings['calls']);
        self::assertSame(4500, $savings['tokens']);
    }

    public function testSavingsIsZeroForNoFavorites(): void
    {
        self::assertSame(['calls' => 0, 'tokens' => 0], FavoriteManager::savings([]));
    }

    public function testReportTotalsSumsPerUserRows(): void
    {
        $rows = [
            ['userId' => 1, 'favoriteCount' => 3, 'calls' => 5, 'tokens' => 9000],
            ['userId' => 2, 'favoriteCount' => 1, 'calls' => 2, 'tokens' => 0],
        ];

        self::assertSame(
            ['users' => 2, 'favorites' => 4, 'calls' => 7, 'tokens' => 9000],
            FavoriteManager::reportTotals($rows),
        );
    }

    public function testReportTotalsIsZeroForNoRows(): void
    {
        self::assertSame(
            ['users' => 0, 'favorites' => 0, 'calls' => 0, 'tokens' => 0],
            FavoriteManager::reportTotals([]),
        );
    }

    public function testBuildEntryAtOneTimesPreservesValues(): void
    {
        $entry = $this->manager()->buildEntry($this->sampleFavorite(), 1.0, MealType::Breakfast, null);

        self::assertSame(300.0, $entry->getNutritionData()->getCalories());
        self::assertSame('80', $entry->getIngredients()[0]['amount']);
    }

    public function testLoggedEntrySurvivesFavoriteReferenceBeingCleared(): void
    {
        // Mirrors the DB-level ON DELETE SET NULL: dropping the favorite link
        // must leave the entry's own copied nutrition fully intact.
        $entry = $this->manager()->buildEntry($this->sampleFavorite(), 1.5, MealType::Dinner, null);
        $caloriesBefore = $entry->getNutritionData()->getCalories();

        $entry->setFavorite(null);

        self::assertNull($entry->getFavorite());
        self::assertSame($caloriesBefore, $entry->getNutritionData()->getCalories());
        self::assertSame(450.0, $caloriesBefore);
    }
}
