<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\UnitSystem;
use App\Service\LocaleFormatter;
use App\Service\UnitConverter;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * View-time unit conversion for canonically-stored glucose values (mg/dL) per
 * the current user's Settings ▸ Units preference — the template-side twin of
 * the controller-side UnitConverter pattern (TrendsController,
 * HealthMetricsController). Added for the glucose-response surfaces
 * (meal detail, dashboard tile, /insights/glucose) whose DTOs deliberately
 * stay canonical because the math (iAUC ratios, thresholds) runs on mg/dL.
 *
 * - glucose_display(v)      — locale-formatted string in display units
 *                             (mg/dL: whole numbers; mmol/L: 1 decimal).
 * - glucose_value(v)        — converted *number*, for JS/JSON contexts.
 * - glucose_series_value(s) — converts a [{minutes, value}, …] curve series.
 * - glucose_unit()          — 'mg/dL' or 'mmol/L' for labels.
 * - glucose_area_display(v) — iAUC (mg/dL·min) converted by the same linear
 *                             factor; pair with glucose_unit() ~ '·min'.
 */
final class UnitExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly UnitConverter $units,
        private readonly LocaleFormatter $localeFormatter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('glucose_display', $this->glucoseDisplay(...)),
            new TwigFunction('glucose_value', $this->glucoseValue(...)),
            new TwigFunction('glucose_series_value', $this->glucoseSeriesValue(...)),
            new TwigFunction('glucose_unit', $this->glucoseUnit(...)),
            new TwigFunction('glucose_area_display', $this->glucoseAreaDisplay(...)),
        ];
    }

    public function glucoseDisplay(float|int $canonical): string
    {
        $system = $this->unitSystem();

        return $this->localeFormatter->number(
            $this->units->toDisplay(HealthMetricType::BloodGlucose, (float) $canonical, $system),
            $system === UnitSystem::Metric ? 1 : 0,
        );
    }

    public function glucoseValue(float|int $canonical): float
    {
        return $this->units->toDisplay(HealthMetricType::BloodGlucose, (float) $canonical, $this->unitSystem());
    }

    /**
     * @param list<array{minutes: int, value: float}> $series
     *
     * @return list<array{minutes: int, value: float}>
     */
    public function glucoseSeriesValue(array $series): array
    {
        return array_map(
            fn (array $p): array => ['minutes' => $p['minutes'], 'value' => $this->glucoseValue((float) $p['value'])],
            $series,
        );
    }

    public function glucoseUnit(): string
    {
        return $this->units->displayUnit(HealthMetricType::BloodGlucose, $this->unitSystem());
    }

    public function glucoseAreaDisplay(float|int $canonicalArea): string
    {
        // iAUC scales by the same linear mg/dL→mmol/L factor; whole numbers
        // read fine in both systems (mmol/L·min areas land around 20–100).
        return $this->localeFormatter->number(
            $this->units->toDisplay(HealthMetricType::BloodGlucose, (float) $canonicalArea, $this->unitSystem()),
            0,
        );
    }

    private function unitSystem(): UnitSystem
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getUnitSystem() : UnitSystem::Metric;
    }
}
