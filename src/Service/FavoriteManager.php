<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Favorite;
use App\Entity\MealEntry;
use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\MealType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates favorites from a reviewed estimate (the Confirm & Refine screen) or
 * from an already-saved meal, and logs new meals from a favorite without any
 * Claude call. Photos are copied between upload dirs so favorites and the meals
 * logged from them each own an independent thumbnail file.
 */
final class FavoriteManager
{
    public function __construct(
        private readonly string $favoritesDir,
        private readonly string $mealsDir,
        private readonly string $publicDir,
        private readonly EntityManagerInterface $em,
        private readonly PortionScaler $portionScaler,
        private readonly UserTime $userTime,
    ) {
    }

    /**
     * Persists a new favorite and returns it.
     *
     * The trailing args are set only by the recipe photo import (the
     * transcribed steps, the servings divisor applied at import, and the
     * whole-batch ingredient list as confirmed — the quantities a cook
     * needs); every other caller leaves them null.
     *
     * @param array<string, float|null>                                      $nutrients   NutritionData::toArray() shape
     * @param array<array{name: string, amount: string, unit: string}>       $ingredients per-serving
     * @param array<array{name: string, amount: string, unit: string}>|null  $batchIngredients
     */
    public function create(
        User $user,
        string $name,
        array $nutrients,
        array $ingredients,
        ?string $aiDescription,
        ?string $sourceImageWebPath,
        int $tokensPerAnalysis,
        ?string $instructions = null,
        ?float $servingsAtImport = null,
        ?array $batchIngredients = null,
    ): Favorite {
        $favorite = new Favorite();
        $favorite->setUser($user);
        // Bounded here, not just by each form's maxlength: every create path (meal
        // page, Confirm & Refine, recipe import) passes posted text, and a longer
        // name would 500 on the VARCHAR(200) column under strict mode.
        $favorite->setName(mb_substr(trim($name), 0, 200) ?: 'Favorite meal');
        $favorite->setNutrients($nutrients);
        $favorite->setIngredients($ingredients);
        $favorite->setAiDescription($aiDescription);
        $favorite->setTokensPerAnalysis(max(0, $tokensPerAnalysis));
        $favorite->setInstructions($instructions);
        $favorite->setServingsAtImport($servingsAtImport);
        $favorite->setBatchIngredients($batchIngredients);
        $favorite->setThumbnailPath(
            $this->copyImage($sourceImageWebPath, $this->favoritesDir, 'uploads/favorites', 'fav'),
        );

        $this->em->persist($favorite);
        $this->em->flush();

        return $favorite;
    }

    /**
     * Logs a new meal from a favorite: builds the scaled entry, copies a fresh
     * thumbnail into the meals dir, records the reuse, and persists. Returns the
     * new entry. No Claude call — the favorite's stored analysis is reused.
     */
    public function logFromFavorite(
        Favorite $favorite,
        float $portions,
        MealType $mealType,
        ?string $notes,
    ): MealEntry {
        $entry = $this->buildEntry($favorite, $portions, $mealType, $notes);
        $entry->setImagePath(
            $this->copyImage($favorite->getThumbnailPath(), $this->mealsDir, 'uploads/meals', 'meal'),
        );

        $favorite->recordUse();

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * Builds (does not persist) a meal entry from a favorite, scaling the stored
     * nutrition and numeric ingredient amounts by the portion multiplier. The
     * nutrition is a fresh copy (NutritionData::fromArray), so the entry is fully
     * independent of the favorite — deleting the favorite can't alter it.
     */
    public function buildEntry(
        Favorite $favorite,
        float $portions,
        MealType $mealType,
        ?string $notes,
    ): MealEntry {
        $entry = new MealEntry();
        $entry->setUser($favorite->getUser());
        $entry->setMealType($mealType);
        $entry->setNotes($notes);
        $entry->setLoggedAt($this->userTime->now($favorite->getUser()));
        $entry->setAiDescription($favorite->getAiDescription());
        $entry->setIngredients($this->portionScaler->scaleIngredients($favorite->getIngredients(), $portions));
        $entry->setNutritionData(NutritionData::fromArray($favorite->getNutrients(), $portions));
        $entry->setFavorite($favorite);

        return $entry;
    }

    /**
     * Deletes a favorite and its thumbnail. Meals logged from it are unaffected:
     * the FK is ON DELETE SET NULL and each owns a separate thumbnail copy.
     */
    public function delete(Favorite $favorite): void
    {
        $thumb = $favorite->getThumbnailPath();
        if ($thumb !== null && $thumb !== '') {
            $file = $this->publicDir . '/' . ltrim($thumb, '/');
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->em->remove($favorite);
        $this->em->flush();
    }

    /**
     * Aggregate reuse savings across a set of favorites: API calls avoided (one
     * per reuse) and tokens saved (0 where the per-analysis cost is unknown).
     *
     * @param  Favorite[] $favorites
     * @return array{calls: int, tokens: int}
     */
    public static function savings(array $favorites): array
    {
        $calls = 0;
        $tokens = 0;
        foreach ($favorites as $favorite) {
            $calls += $favorite->getUseCount();
            $tokens += $favorite->tokensSaved();
        }

        return ['calls' => $calls, 'tokens' => $tokens];
    }

    /**
     * Totals across the admin report's per-user rows (see
     * FavoriteRepository::savingsByUser).
     *
     * @param  array<array{favoriteCount?: int, calls?: int, tokens?: int}> $rows
     * @return array{users: int, favorites: int, calls: int, tokens: int}
     */
    public static function reportTotals(array $rows): array
    {
        $totals = ['users' => 0, 'favorites' => 0, 'calls' => 0, 'tokens' => 0];
        foreach ($rows as $row) {
            $totals['users']++;
            $totals['favorites'] += (int) ($row['favoriteCount'] ?? 0);
            $totals['calls'] += (int) ($row['calls'] ?? 0);
            $totals['tokens'] += (int) ($row['tokens'] ?? 0);
        }

        return $totals;
    }

    /**
     * A sensible default favorite name derived from the analysis: the first
     * clause of the AI description, else the first ingredient, capped short.
     *
     * @param array<array{name?: string}> $ingredients
     */
    public static function defaultName(?string $description, array $ingredients = []): string
    {
        $description = trim((string) $description);
        if ($description !== '') {
            $first = trim((string) (preg_split('/[.\n]/', $description)[0] ?? $description));

            return mb_substr($first, 0, 60);
        }

        foreach ($ingredients as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                return mb_substr($name, 0, 60);
            }
        }

        return 'Favorite meal';
    }

    /**
     * Copies an upload (web path like "uploads/meals/x.jpg") into $destDir,
     * returning the new web path ("$destWebPrefix/<name>") — or null if there's
     * no usable source. The copy keeps each owner's thumbnail independent.
     */
    private function copyImage(
        ?string $sourceImageWebPath,
        string $destDir,
        string $destWebPrefix,
        string $namePrefix,
    ): ?string {
        if ($sourceImageWebPath === null || $sourceImageWebPath === '') {
            return null;
        }

        $source = $this->publicDir . '/' . ltrim($sourceImageWebPath, '/');
        if (!is_file($source)) {
            return null;
        }

        if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return null;
        }

        $ext = pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = $namePrefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!@copy($source, $destDir . '/' . $filename)) {
            return null;
        }

        return $destWebPrefix . '/' . $filename;
    }
}
