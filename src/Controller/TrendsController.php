<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\NutritionData;
use App\Entity\User;
use App\Enum\HealthMetricType;
use App\Enum\SymptomTypeKey;
use App\Intake\DailyIntake;
use App\Intake\IntakeDay;
use App\Repository\HealthMetricRepository;
use App\Repository\MealEntryRepository;
use App\Repository\SymptomDayRepository;
use App\Repository\SymptomTypeRepository;
use App\Service\CorrelationAnalyzer;
use App\Service\DietSymptomCorrelationService;
use App\Service\InflammationScoreService;
use App\Service\InsightSummaryService;
use App\Service\LocaleFormatter;
use App\Service\NauseaTriggerService;
use App\Service\SupplementRecommendationService;
use App\Service\UnitConverter;
use App\Service\UserTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\SymptomLabeler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class TrendsController extends AbstractController
{
    #[Route('/trends', name: 'app_trends')]
    public function index(
        Request $request,
        DailyIntake $intake,
        MealEntryRepository $mealRepo,
        HealthMetricRepository $healthRepo,
        InflammationScoreService $inflammation,
        SymptomTypeRepository $symptomTypeRepo,
        SymptomDayRepository $symptomDayRepo,
        SupplementRecommendationService $supplementRecommendations,
        DietSymptomCorrelationService $correlationService,
        CorrelationAnalyzer $correlationAnalyzer,
        NauseaTriggerService $nauseaTriggers,
        InsightSummaryService $insightSummary,
        UnitConverter $units,
        UserTime $userTime,
        TranslatorInterface $translator,
        SymptomLabeler $symptomLabeler,
        LocaleFormatter $localeFormatter,
    ): Response {
        $days = (int) $request->query->get('days', 30);
        $days = in_array($days, [7, 14, 30, 90], true) ? $days : 30;

        $user = $this->getUser();
        \assert($user instanceof User);
        $unitSystem = $user->getUnitSystem();
        $glucoseUnit = $units->displayUnit(HealthMetricType::BloodGlucose, $unitSystem);
        $from = $userTime->startOfDaysAgo($user, $days);
        $to   = $userTime->startOfTomorrow($user);

        // ── Nutrition ────────────────────────────────────────────────────
        // Every figure here is intake: meal days with the active supplements on
        // top, and no supplement-only days (ADR 0002).
        $intakeDays = $intake->days($user, $from, $to);
        $calorieTotals = array_values(array_map(
            static fn (IntakeDay $d): array => ['date' => $d->date, 'calories' => $d->total('calories') ?? 0.0],
            $intakeDays,
        ));
        $dailyMacros = array_values(array_map(static fn (IntakeDay $d): array => [
            'date'          => $d->date,
            'protein'       => $d->total('protein') ?? 0.0,
            'carbohydrates' => $d->total('carbohydrates') ?? 0.0,
            'fat'           => $d->total('fat') ?? 0.0,
            'fiber'         => $d->total('fiber') ?? 0.0,
        ], $intakeDays));
        $averages = $intake->average($user, $from, $to)?->totals() ?? array_fill_keys(NutritionData::FIELDS, 0.0);

        // ── Dietary inflammation score (per local day) ───────────────────
        $inflammationDaily = array_values($inflammation->dailyScores($user, $from, $to));

        // ── Symptoms + diet↔symptom correlation (per enabled symptom) ────
        $scoreByDate = [];
        foreach ($inflammationDaily as $row) {
            $scoreByDate[$row['date']] = $row['score'];
        }

        $enabledTypes = $symptomTypeRepo->findEnabledByUserOrdered($user);
        $levelsByType = $symptomDayRepo->getLevelsByTypeForRange($user, $from, $to);

        // One Chart.js-ready series + one correlation summary per enabled type.
        $symptomColors = ['#dc3545', '#6f42c1', '#0d6efd', '#fd7e14', '#20c997', '#d63384'];
        $symptomSeriesByType = [];
        $correlations = [];
        foreach ($enabledTypes as $i => $type) {
            $levels = $levelsByType[$type->getId()] ?? [];
            $symptomSeriesByType[$type->getId()] = [
                'label'          => $symptomLabeler->label($type),
                'color'          => $symptomColors[$i % count($symptomColors)],
                'higherIsBetter' => $type->isHigherBetter(),
                'data'           => $levels,
            ];
            if ($levels !== []) {
                $correlations[] = [
                    'id'             => $type->getId(),
                    'label'          => $symptomLabeler->label($type),
                    'higherIsBetter' => $type->isHigherBetter(),
                    'data'           => $correlationService->summarize($scoreByDate, $levels),
                ];
            }
        }

        // ── GLP-1 nausea-trigger flagging (only in GLP-1 mode, nausea enabled) ──
        // Which foods coincide with higher nausea over the window. Reuses the
        // per-day nausea levels already loaded above + meals with ingredients.
        $nauseaTriggerInsights = null;
        $nauseaTypeId = null;
        if ($user->isGlp1Mode()) {
            $nauseaType = null;
            foreach ($enabledTypes as $type) {
                if ($type->getTypeKey() === SymptomTypeKey::Nausea->value) {
                    $nauseaType = $type;
                    break;
                }
            }
            $nauseaTypeId = $nauseaType?->getId();
            $nauseaLevels = $nauseaType !== null ? ($levelsByType[$nauseaType->getId()] ?? []) : [];
            if ($nauseaLevels !== []) {
                // Only ingredients + loggedAt are needed, so skip the nutrition fetch-join.
                $mealsForTriggers = $mealRepo->findByUserAndDateRange($user, $from, $to);
                $nauseaTriggerInsights = $nauseaTriggers->analyze($mealsForTriggers, $nauseaLevels);
            }
        }

        // ── Non-sleep health metrics (daily avg + min/max bands) ─────────
        $healthAggByType = [];
        $metricMeta      = [];
        foreach (HealthMetricType::cases() as $type) {
            if ($type->isSleepType()) {
                continue; // sleep shown separately; uses SUM not AVG
            }
            $agg = $healthRepo->getAggregates($user, $type, $from, $to, 'day');
            if (!empty($agg)) {
                $healthAggByType[$type->value] = $units->convertAggregateRows($type, $agg, $unitSystem);
                $metricMeta[$type->value]      = [
                    'label' => $translator->trans($type->transKey()),
                    // Units are symbols (kg, mg/dL…) except 'steps', which is a
                    // word — translate that one (metric_unit.steps).
                    'unit'  => $type === HealthMetricType::Steps
                        ? $translator->trans('metric_unit.steps')
                        : $units->displayUnit($type, $unitSystem),
                ];
            }
        }

        // ── Health factors for the Symptoms ▸ "Health & symptoms" chart ──
        // Reuse the daily aggregates already computed above; keep just the three
        // metrics that pair meaningfully with symptoms, in the order shown.
        $symptomHealthFactors = [];
        foreach (['steps', 'blood_glucose', 'weight'] as $key) {
            if (isset($healthAggByType[$key])) {
                $symptomHealthFactors[$key] = $healthAggByType[$key];
            }
        }

        // ── Sleep ────────────────────────────────────────────────────────
        $sleepFrom = $from->modify('-12 hours');
        $sleepData = $healthRepo->getSleepNightlyData($user, $sleepFrom, $to);

        // ── Per-symptom significant correlations (diet macros + health metrics) ──
        // The chart overlays all factors; here we compute Pearson r + a two-tailed
        // significance test for each and keep only the significant ones to list under
        // the chart. Inflammation keeps its own pro-/other-day framing ($correlations),
        // so it's excluded from the diet factors here.
        // Labels match the chart legends exactly so a factor never appears under two
        // names in one panel ('Carbs' as in the diet chart; the metric labels as in
        // the health chart, from HealthMetricType::label() via $metricMeta).
        $dietMacroLabels = [
            'protein'       => $translator->trans('macro.protein'),
            'carbohydrates' => $translator->trans('macro.carbs'),
            'fat'           => $translator->trans('macro.fat'),
            'fiber'         => $translator->trans('nutrient.fiber'),
        ];
        $dietFactorSeries = [];
        foreach ($dietMacroLabels as $key => $label) {
            $dietFactorSeries[$key] = ['label' => $label, 'data' => array_column($dailyMacros, $key, 'date')];
        }
        $healthFactorSeries = [];
        foreach (['steps', 'blood_glucose', 'weight'] as $key) {
            if (isset($healthAggByType[$key])) {
                $healthFactorSeries[$key] = [
                    'label' => $metricMeta[$key]['label'] ?? ucfirst($key),
                    'data'  => array_column($healthAggByType[$key], 'avg', 'bucket'),
                ];
            }
        }
        if ($sleepData !== []) {
            $healthFactorSeries['sleep'] = [
                'label' => $translator->trans('metric.sleep_asleep'),
                'data'  => array_column($sleepData, 'total_hours', 'sleep_date'),
            ];
        }

        $symptomCorrelations = [];
        foreach ($enabledTypes as $type) {
            $levels = $levelsByType[$type->getId()] ?? [];
            if ($levels === []) {
                continue;
            }
            $symptomCorrelations[$type->getId()] = [
                'diet'   => $correlationAnalyzer->significant($levels, $dietFactorSeries),
                'health' => $correlationAnalyzer->significant($levels, $healthFactorSeries),
            ];
        }

        // ── Glucose windows ──────────────────────────────────────────────
        $eveningBgRows   = $healthRepo->getEveningGlucoseByDate($user, $from, $to);
        $overnightBgRows = $healthRepo->getOvernightGlucoseByDate($user, $sleepFrom, $to);

        // ── Dinner summary ───────────────────────────────────────────────
        $dinnerRows = $mealRepo->getDinnerSummary($user, $from, $to);

        // ── Build per-night correlation dataset ──────────────────────────
        $sleepByDate     = array_column($sleepData, null, 'sleep_date');
        $dinnerByDate    = array_column($dinnerRows, null, 'date');
        $eveningBgByDate = array_column($eveningBgRows, null, 'date');
        $overnightByDate = array_column($overnightBgRows, null, 'night_date');

        $nightlyData = [];
        foreach ($sleepData as $sleep) {
            $date   = $sleep['sleep_date'];
            $dinner = $dinnerByDate[$date] ?? null;
            $evBg   = $eveningBgByDate[$date] ?? null;
            $ovBg   = $overnightByDate[$date] ?? null;

            $gapHours = null;
            if ($dinner !== null) {
                $diff = strtotime($sleep['onset_time']) - strtotime($dinner['dinner_time']);
                if ($diff >= 1800 && $diff <= 28800) {
                    $gapHours = round($diff / 3600, 1);
                }
            }

            $nightlyData[] = [
                'date'                => $date,
                'dinner_time'         => $dinner !== null
                    ? $localeFormatter->date($dinner['dinner_time'], 'jm')
                    : null,
                'dinner_calories'     => $dinner !== null && $dinner['calories'] !== null
                    ? (int) $dinner['calories']
                    : null,
                // Canonical mg/dL values drive the (clinical) color thresholds; the
                // *_display values are converted to the user's unit for rendering.
                'evening_glucose_peak' => $evBg !== null ? (int) round($evBg['peak']) : null,
                'evening_glucose_display' => $evBg !== null
                    ? round($units->toDisplay(HealthMetricType::BloodGlucose, round($evBg['peak']), $unitSystem), 1)
                    : null,
                'gap_hours'           => $gapHours,
                'overnight_glucose'   => $ovBg !== null ? (int) round($ovBg['avg']) : null,
                'overnight_glucose_display' => $ovBg !== null
                    ? round($units->toDisplay(HealthMetricType::BloodGlucose, round($ovBg['avg']), $unitSystem), 1)
                    : null,
                'overnight_glucose_min' => $ovBg !== null ? (int) round($ovBg['min']) : null,
                'sleep_hours'         => $sleep['total_hours'],
            ];
        }

        // Rebuild sleepGapData from nightlyData for the existing sleep chart JS
        $sleepGapData = array_values(array_filter(array_map(
            static fn(array $n) => $n['gap_hours'] !== null
                ? ['date' => $n['date'], 'gap' => $n['gap_hours']]
                : null,
            $nightlyData,
        )));

        // ── Dashboard status tiles (reduce the raw arrays above to headlines) ──
        $insightCards = $insightSummary->build([
            'inflammationDaily' => $inflammationDaily,
            'sleepData'         => $sleepData,
            'healthAggByType'   => $healthAggByType,
            'metricMeta'        => $metricMeta,
            'calorieTotals'     => $calorieTotals,
            'averages'          => $averages,
            // Micronutrient gaps from the same intake average: the exact conclusion
            // the Supplements page draws over the same window (both offer
            // 7/14/30/90), so the tile links there with no clamping and costs no
            // extra queries.
            'microGaps'         => $supplementRecommendations->computeGaps($averages),
            'days'              => $days,
            'symptomSeriesByType' => $symptomSeriesByType,
            'today'             => $userTime->startOfToday($user),
            'unitSystem'        => $unitSystem,
            'glucoseUnit'       => $glucoseUnit,
        ]);

        return $this->render('trends/index.html.twig', [
            'days'            => $days,
            'glucoseUnit'     => $glucoseUnit,
            'insightCards'    => $insightCards,
            'calorieTotals'   => $calorieTotals,
            'averages'        => $averages,
            'healthAggByType' => $healthAggByType,
            'metricMeta'      => $metricMeta,
            'sleepData'       => $sleepData,
            'sleepGapData'    => $sleepGapData,
            'nightlyData'     => $nightlyData,
            'inflammationDaily'   => $inflammationDaily,
            'hasEnabledSymptoms'  => $enabledTypes !== [],
            'symptomSeriesByType' => $symptomSeriesByType,
            'correlations'        => $correlations,
            'symptomCorrelations' => $symptomCorrelations,
            'correlationMinDays'  => CorrelationAnalyzer::MIN_N,
            'dailyMacros'         => $dailyMacros,
            'symptomHealthFactors' => $symptomHealthFactors,
            'symptomHealthMeta'   => $metricMeta,
            'hasHealthFactors'    => $symptomHealthFactors !== [] || $sleepData !== [],
            'nauseaTriggers'      => $nauseaTriggerInsights,
            'nauseaTypeId'        => $nauseaTypeId,
        ]);
    }
}
