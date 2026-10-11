<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a ledgered (call-time counted) AI analysis was: the paths whose Claude
 * call leaves no countable row of its own. Meal re-analyses overwrite an
 * existing meal; supplement label scans only create a row if the user saves
 * them. Every other billable analysis path is counted from the row it creates
 * (a meal, a recipe favorite) — see {@see \App\Service\AnalysisAllowance}.
 */
enum AnalysisKind: string
{
    /** Re-analysis of an existing meal driven by a photo (new and/or its own). */
    case MealReanalysisPhoto = 'meal_reanalysis_photo';

    /** Re-analysis of an existing meal from the user's edited description. */
    case MealReanalysisDescription = 'meal_reanalysis_description';

    /** A supplement label scan (/supplements/scan), saved or not. */
    case SupplementScan = 'supplement_scan';
}
