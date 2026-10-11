<?php

declare(strict_types=1);

namespace App\Service\Chart;

use App\Entity\NutritionGoal;

/**
 * Renders a goal's progress as a PNG (daily values + target line/band) from data
 * the app computed — Claude never draws the chart, it only captions it. GD is the
 * current implementation; this interface keeps that choice swappable (e.g. Imagick)
 * without touching the evaluation or delivery code.
 */
interface GoalChartRenderer
{
    /**
     * Writes a chart image and returns its public web path (e.g.
     * "/uploads/goal-charts/foo.png"), or null if it couldn't be produced.
     *
     * @param array<string, float> $daily per-day values keyed 'Y-m-d'
     * @param string               $stem  filename stem (no extension/path)
     */
    public function render(NutritionGoal $goal, array $daily, string $stem): ?string;
}
