<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What an AI call is for. Callers name the feature, never a model: which model
 * serves it is the client's configuration (and, later, the user's choice), so the
 * same call works against Anthropic, OpenRouter or a user's own Ollama server.
 */
enum AiFeature: string
{
    case MealAnalysis = 'meal_analysis';
    case RecipeAnalysis = 'recipe_analysis';
    case SupplementScan = 'supplement_scan';
    case GoalSetup = 'goal_setup';
    case GoalCheckin = 'goal_checkin';
    case HelpAssistant = 'help_assistant';
    case Predict = 'predict';
    case GlucoseCoach = 'glucose_coach';
    /** Settings ▸ AI provider's "Test connection": one tiny call with the user's own key. */
    case ConnectionTest = 'connection_test';
    /** One photo of the vision benchmark ({@see VisionBenchmark}): reads images, never light. */
    case VisionBenchmark = 'vision_benchmark';

    /**
     * Light features get the instance's cheaper, faster model. Only the help
     * assistant today: short answers grounded in pages it is handed.
     */
    public function isLight(): bool
    {
        return $this === self::HelpAssistant;
    }

    /** Features that send photos, so they need a model that can read images. */
    public function needsVision(): bool
    {
        return match ($this) {
            self::MealAnalysis, self::RecipeAnalysis, self::SupplementScan, self::VisionBenchmark => true,
            default => false,
        };
    }
}
