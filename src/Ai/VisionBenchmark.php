<?php

declare(strict_types=1);

namespace App\Ai;

use App\Dto\AnalysisResult;
use App\Dto\BenchmarkCaseResult;
use App\Entity\User;
use App\Service\FoodAnalysisService;

/**
 * Five reference photos a photo model must read well enough (ADR 0003, App Development
 * Plan PR 5). Each runs through the real meal-analysis prompt, one photo per call, and
 * its answer is checked against values that are known, not another model's guess:
 *
 *  - three **label** cases (the nutrition-facts panel is in the photo): each listed
 *    nutrient must be within 10 % of the printed per-serving value, with a small floor so
 *    a 1 g fiber line doesn't fail on rounding;
 *  - two **estimate** cases (plain food, no label): calories within 40 % of the
 *    reference, a band any competent model lands in and a weak one misses.
 *
 * A model passes when every case passes. A failure only warns (John, Plan Q10): it never
 * stops photo analysis. Photos are John's own meals, published with all metadata stripped
 * (Plan Q9); label values were read from the photos, the blueberry reference is USDA's
 * value for one cup (148 g), and the bagel's is NouriLoop's own logged estimate.
 */
final class VisionBenchmark
{
    public const DIR = 'resources/vision-benchmark';

    /** Display units for the nutrients a case can check. */
    public const UNITS = [
        'calories' => 'kcal', 'protein' => 'g', 'carbohydrates' => 'g',
        'fat' => 'g', 'fiber' => 'g', 'sodium' => 'mg',
    ];

    /**
     * Relative tolerance and absolute floor per nutrient, for label cases: 10 % for every
     * nutrient, as documented; the floors only stop small lines failing on rounding.
     */
    public const LABEL_TOLERANCE = [
        'calories' => [0.10, 15.0],
        'protein' => [0.10, 2.0],
        'carbohydrates' => [0.10, 2.0],
        'fat' => [0.10, 2.0],
        'fiber' => [0.10, 1.5],
        'sodium' => [0.10, 60.0],
    ];

    /** Relative tolerance on calories for estimate cases. */
    private const ESTIMATE_TOLERANCE = 0.40;

    /**
     * @var list<array{name: string, file: string, kind: 'label'|'estimate',
     *     expected: array<string, float>}>
     */
    public const CASES = [
        [
            'name' => 'pizza_label',
            'file' => 'pizza-label.jpg',
            'kind' => 'label',
            // One serving = 1/3 pizza; the panel also shows 980 kcal per whole pizza.
            'expected' => [
                'calories' => 330, 'protein' => 13, 'carbohydrates' => 37,
                'fat' => 14, 'fiber' => 2, 'sodium' => 780,
            ],
        ],
        [
            'name' => 'protein_bar_label',
            'file' => 'protein-bar-label.jpg',
            'kind' => 'label',
            // The label is photographed sideways: reading it is part of the test.
            'expected' => [
                'calories' => 200, 'protein' => 20, 'carbohydrates' => 17,
                'fat' => 7, 'fiber' => 1, 'sodium' => 200,
            ],
        ],
        [
            'name' => 'gumbo_label',
            'file' => 'gumbo-label.jpg',
            'kind' => 'label',
            // One container is one serving. Carbohydrate is half hidden by the curve
            // ("…2g (12% DV)"); 12 % of the 275 g daily value is 32-33 g.
            'expected' => [
                'calories' => 290, 'protein' => 22, 'carbohydrates' => 32,
                'fat' => 8, 'fiber' => 3, 'sodium' => 1460,
            ],
        ],
        [
            'name' => 'blueberries',
            'file' => 'blueberries.jpg',
            'kind' => 'estimate',
            'expected' => ['calories' => 84],
        ],
        [
            'name' => 'lox_bagel',
            'file' => 'lox-bagel.jpg',
            'kind' => 'estimate',
            'expected' => ['calories' => 620],
        ],
    ];

    public function __construct(
        private readonly FoodAnalysisService $food,
        private readonly string $projectDir,
    ) {
    }

    public static function caseCount(): int
    {
        return \count(self::CASES);
    }

    /**
     * Runs one case: one call to the photo model the user (or, with no user, the
     * instance) has configured.
     *
     * @throws \OutOfRangeException for an index that is not a case
     */
    public function runCase(?User $user, int $index): BenchmarkCaseResult
    {
        $case = self::CASES[$index] ?? throw new \OutOfRangeException('No benchmark case ' . $index);

        try {
            $analysis = $this->food->analyzeForBenchmark($user, $this->path($case['file']));
        } catch (\Throwable $e) {
            // The model couldn't answer at all (or answered with something unreadable).
            return new BenchmarkCaseResult($case['name'], false, [], error: true);
        }

        return self::check($index, $analysis);
    }

    /** Checks one analysis against case $index. Pure: no call, no I/O. */
    public static function check(int $index, AnalysisResult $analysis): BenchmarkCaseResult
    {
        $case = self::CASES[$index] ?? throw new \OutOfRangeException('No benchmark case ' . $index);
        $values = $analysis->nutrition->toArray();

        $misses = [];
        foreach ($case['expected'] as $nutrient => $expected) {
            $got = isset($values[$nutrient]) && \is_numeric($values[$nutrient]) ? (float) $values[$nutrient] : null;
            [$relative, $floor] = $case['kind'] === 'label'
                ? self::LABEL_TOLERANCE[$nutrient]
                : [self::ESTIMATE_TOLERANCE, 0.0];
            $allowed = max($expected * $relative, $floor);
            if ($got === null || abs($got - $expected) > $allowed) {
                $misses[] = ['nutrient' => $nutrient, 'expected' => (float) $expected, 'got' => $got];
            }
        }

        return new BenchmarkCaseResult($case['name'], $misses === [], $misses);
    }

    public function path(string $file): string
    {
        return $this->projectDir . '/' . self::DIR . '/' . $file;
    }
}
