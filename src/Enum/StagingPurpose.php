<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a photo is being staged. Decides the file-name prefix (handy when reading
 * a directory listing; one test pins the re-analysis one).
 */
enum StagingPurpose: string
{
    /** A photo submitted with a meal log; the first becomes the meal's photo. */
    case MealPhoto = 'meal-';
    /** A photo added while refining a meal draft (e.g. its nutrition label). */
    case MealExtra = 'meal-extra-';
    /** A photo for a re-analysis proposal; may become the meal's photo on apply. */
    case MealReanalysis = 'meal-reanalysis-';
    case Recipe = 'recipe-';
    case Supplement = 'supp-';

    public function prefix(): string
    {
        return $this->value;
    }

    /**
     * Directory under public/ every staged file is written to, whatever its
     * purpose. It is swept daily by app:cleanup-meal-uploads (old files no
     * meal references), so an abandoned draft's photos cost nothing to clean
     * up; a photo that outlives its draft somewhere else is moved out with
     * PhotoStaging::keep().
     */
    public function webDir(): string
    {
        return 'uploads/meals';
    }
}
