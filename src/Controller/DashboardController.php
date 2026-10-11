<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Intake\DailyIntake;
use App\Repository\HealthMetricRepository;
use App\Repository\MealEntryRepository;
use App\Repository\SymptomDayRepository;
use App\Repository\SymptomTypeRepository;
use App\Service\GlucoseInsightsService;
use App\Service\Glp1ProteinService;
use App\Service\InflammationScoreService;
use App\Service\UserTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        MealEntryRepository $mealRepo,
        InflammationScoreService $inflammation,
        SymptomTypeRepository $symptomTypeRepo,
        SymptomDayRepository $symptomDayRepo,
        HealthMetricRepository $healthRepo,
        DailyIntake $intake,
        GlucoseInsightsService $glucoseInsights,
        Glp1ProteinService $glp1Protein,
        UserTime $userTime,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $hour = $userTime->localHour($user);
        $greeting = match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default => 'evening',
        };

        $today    = $userTime->startOfToday($user);
        $tomorrow = $userTime->startOfTomorrow($user);

        // Nutrition fetch-joined: every consumer below (calories, inflammation,
        // glucose impact, GLP-1 protein) reads it, so a lazy load was one query
        // per meal. The same rows feed the inflammation score instead of it
        // re-querying them.
        $todayMeals = $mealRepo->findWithNutritionForRange($user, $today, $tomorrow);

        // Today's intake from the meals already loaded: nothing until a meal is
        // logged, then the active supplements count on top (ADR 0002).
        $todayIntake = $intake->forMeals($user, $todayMeals)[$today->format('Y-m-d')] ?? null;
        $dailyCalories = $todayIntake?->total('calories') ?? 0.0;

        $inflammationScores = $inflammation->dailyScoresForMeals($user, $todayMeals);
        $inflammationToday  = $inflammationScores[$today->format('Y-m-d')] ?? null;

        // Symptom quick check-in: only the symptoms the user opted into. A user
        // who enabled nothing (incl. brand-new users) sees no card at all.
        $enabledSymptomTypes = $symptomTypeRepo->findEnabledByUserOrdered($user);
        $symptomDay = $enabledSymptomTypes !== []
            ? $symptomDayRepo->findOneByUserAndDate($user, $today)
            : null;
        $symptomLevels = $symptomDay !== null ? $symptomDay->levelsByTypeId() : [];

        // Health-data freshness: most recent imported reading + how many local
        // days ago, so the dashboard can confirm syncs. recordedAt is stored as
        // the user's local wall-clock (offset dropped at import) and $today is
        // already the user's local day via UserTime, so comparing their date
        // strings is a local-frame delta — no zone conversion (that would shift
        // the wall-clock). %r%a is a signed delta; clamp a future reading to 0.
        $lastHealthSync    = $healthRepo->findLatestRecordedAt($user);
        $healthSyncDaysAgo = null;
        if ($lastHealthSync !== null) {
            $lastDate  = new \DateTimeImmutable($lastHealthSync->format('Y-m-d'));
            $todayDate = new \DateTimeImmutable($today->format('Y-m-d'));
            $healthSyncDaysAgo = max(0, (int) $lastDate->diff($todayDate)->format('%r%a'));
        }

        // Today's per-meal glucose responses — shown to anyone tracking glucose, even
        // before a meal has enough readings to chart (the card then nudges them on).
        $hasGlucose      = $healthRepo->userHasGlucose($user);
        $glucoseMealsToday = $hasGlucose ? $glucoseInsights->analyzeMeals($todayMeals) : [];

        // GLP-1 protein focus: today's protein vs a weight-scaled target. Only
        // computed (and rendered) when the user has opted into GLP-1 mode.
        $proteinFocus = $user->isGlp1Mode() ? $glp1Protein->summary($user, $todayMeals) : null;

        return $this->render('dashboard/index.html.twig', [
            'greeting'           => $greeting,
            'today'              => $today,
            'todayMeals'         => $todayMeals,
            'dailyCalories'      => $dailyCalories,
            'inflammationToday'  => $inflammationToday,
            'enabledSymptomTypes' => $enabledSymptomTypes,
            'symptomLevels'       => $symptomLevels,
            'lastHealthSync'     => $lastHealthSync,
            'healthSyncDaysAgo'  => $healthSyncDaysAgo,
            'hasGlucose'         => $hasGlucose,
            'glucoseMealsToday'  => $glucoseMealsToday,
            'proteinFocus'       => $proteinFocus,
        ]);
    }
}
