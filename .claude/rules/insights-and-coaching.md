---
description: "Predictive Help scenarios, postprandial glucose response and cross-meal insights, the AI Pattern Coach, and the per-user tone dials."
paths:
  - "src/Controller/PredictiveInsightController.php"
  - "src/Service/PredictiveInsightService.php"
  - "src/Dto/ScenarioRequest.php"
  - "src/Dto/PredictiveInsight.php"
  - "src/Enum/ScenarioType.php"
  - "src/Service/PostprandialResponseService.php"
  - "src/Service/GlycemicImpactEstimator.php"
  - "src/Service/GlucoseInsightsService.php"
  - "src/Controller/GlucoseInsightsController.php"
  - "src/Dto/PostprandialResponse.php"
  - "src/Dto/GlycemicImpact.php"
  - "src/Dto/GlucoseInsights.php"
  - "src/Dto/AnalyzedMeal.php"
  - "src/Service/TonePromptBuilder.php"
  - "templates/insights/**"
  - "templates/settings/tone.html.twig"
---

# Predict, glucose insights, and AI voice — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

### Predictive Help (Phase 3, `GET/POST /insights/predict`, paid tiers)
A "what if" scenario engine that runs NouriLoop's own deterministic scoring functions
**entirely in memory** against a user's 30-day baseline — no scenario DB, no data copy.
Gated by `SubscriptionService::hasFeature($user, 'predictive_help')` (both paid tiers since PR 7);
non-subscribers are redirected to `/pricing`. The POST is **CSRF-protected**
(`isCsrfTokenValid('predict-run', …)`, checked before the rate limit / Claude call) and
**rate-limited**: `predictive_insight` 10/hour per user (`config/packages/rate_limiter.yaml`).

- **Scenario types** (`App\Enum\ScenarioType`): `favorite_daily` (eat a saved favorite
  every day), `supplement_add`, `supplement_remove`, `free_text` (described change,
  Claude reasons qualitatively). Window: 1, 2, or 4 weeks. Dimensions: inflammation,
  nutrition, weight, symptoms (all four are posted as `dimensions[]`). Validation
  ownership-checks the favorite/supplement, and enforces **add ⇒ inactive supplement /
  remove ⇒ active supplement** (the baseline only folds in active supplements, so the
  opposite would double-count).
- **`PredictiveInsightService::compute(User, ScenarioRequest): PredictiveInsight`** —
  calls `fetchBaseline()` (real DB, read-only: 30-day food averages, active supplement
  daily totals, inflammation daily scores, latest weight, enabled symptom averages).
  The food averages come from
  `DailyIntake::average()` (see Daily intake), which is a **true daily average
  over days that actually have logged meals** (`SUM(nutrient) / COUNT(DISTINCT
  DATE(logged_at))`, raw SQL) — **not** a per-meal `AVG()` and **not** divided by N
  calendar days. (Fixed 2026-06-27: the old per-meal `AVG()` mislabeled a single meal's
  size as a whole day's intake, so the Predict baseline showed an unrealistically low
  daily calorie figure; the same method also backs Trends + supplement-gap recs, all of
  which want a per-day average.) Then projects in memory:
  - **`favorite_daily`:** models the favorite *replacing* an average meal (displacement),
    not piling on a 4th meal — so both nutrition and inflammation subtract one average
    meal before adding the favorite. `avgMealsPerDay` (meals ÷ days-with-meals, from
    `MealEntryRepository`) sets the per-meal share; for inflammation the day's supplement
    contribution is subtracted out first (the favorite swaps a *food* meal, not the stack)
    before dividing the food portion across meals. Falls back to a plain add when there's
    no baseline meal data.
  - **`supplement_add`:** projected supplement stack = current + supplement's
    `dailyNutrients()`; inflammation delta via `scoreMeal(suppNutrients, [])`.
  - **`supplement_remove`:** same but subtract (clamp keys ≥ 0); the supplement IS
    active and baked into baseline, so subtracting is correct.
  - **`free_text`:** no in-memory math — Claude reasons from baseline + description.
  - **Weight** (when selected & a weight reading exists): projected over the *selected*
    window using ~7,700 kcal/kg (`KCAL_PER_KG`) on the daily calorie delta — weight is
    stored in **kg**, and the horizon follows `durationWeeks` (not a fixed 4 weeks).
  - The baseline food average covers **every** nutrient (`DailyIntake::average()`), incl.
    saturated fat, sugar and phosphorus, which the old 23-column query left out.
  - All concrete scenarios project food and the supplement stack separately, then read
    the two together (`intake`: what every displayed row, the weight projection, the
    narrative and `computeGaps(projectedIntake)` use; supplements on meal days only,
    so a baseline with no meal days has no supplements either, ADR 0002). Before
    2026-10-06 the displayed baseline, weight and narrative numbers were food-only. The gap-count row is **omitted when the baseline
    has no nutrition data** (`SupplementRecommendationService::hasNutritionData`) —
    an empty baseline's gap list is computeGaps' no-data `[]`, and "0 → N gaps"
    would misread as the scenario making nutrition worse. Claude writes a 3–5 sentence narrative from the
    computed numbers (the main model — `claude-sonnet-4-6` on Anthropic, ≤`NARRATIVE_MAX_TOKENS` = **600** tokens — up
    from 400, which could truncate the required closing "educational estimate, not medical
    advice" sentence at chattier tone settings); falls back to a deterministic
    summary on Claude failure.
- **`PredictiveInsightController`** (`GET /insights/predict` → form, `POST /insights/predict/run`
  → result). Same template serves both (result section hidden when `insight` is null).
  Validation: ownership-checks on `favoriteId`/`supplementId`, enum check on type,
  whitelist on `duration_weeks` and `dimensions`, 300-char cap on `free_text`.
- **DTOs:** `App\Dto\ScenarioRequest` (type, durationWeeks, dimensions, ids, freeText),
  `App\Dto\PredictiveInsight` (narrative, beforeAfter rows, confidenceNote,
  isFreeTextEstimate). `beforeAfter` rows carry `direction` (`better|worse|neutral`)
  so the template can color-code without re-computing.
- **Template** (`templates/insights/predict.html.twig`): inline JS (no Stimulus —
  follows the app's pattern), Bootstrap 5. JS hides/shows sub-fields by type, filters
  favorites by name, character-counts the free-text area, shows a spinner on submit,
  scrolls to results on render.
- **Nav**: More ▸ "Predict 'What If'" — shows the link for Loop+ users, a locked badge
  linking to `/pricing` for others.
- **Claude is mocked in tests** (`FakeAnthropicResponder` returns a canned narrative when
  the system prompt names the "what if" scenario or a "hypothetical dietary change").
  Covered by `tests/E2E/PredictiveInsightTest` (in-process `WebTestCase`: Free user →
  `/pricing` gate, Loop+ form renders, favorite-daily projection renders the before/after
  table + narrative, free-text flags the qualitative estimate, missing/foreign favorite
  rejected).

## Postprandial glucose response & insights (CGM ↔ meals)
Turns blood-glucose readings (e.g. a Lingo/CGM sensor imported as `blood_glucose`
health metrics) into per-meal response curves, a measured-vs-expected check against
the meal's nutrition, and cross-meal rankings. **All deterministic — no Claude call,
no persistence; recomputed at view time**, so results improve as CGM data back-fills.
Educational wellness signals, **not** medical measures (framed that way in the UI).

- **Per-meal response** (`PostprandialResponseService` → `PostprandialResponse` DTO):
  pulls glucose in a window around the meal (`BASELINE_WINDOW_MIN` = 15 min before →
  `RESPONSE_WINDOW_MIN` = 180 min after) via
  `HealthMetricRepository::findGlucoseInWindow()` (lightweight DBAL, covered by
  `uq_health_metric`). Computes baseline (mean of pre-meal readings, else first
  at/after-meal reading), peak, time-to-peak, delta, **incremental AUC** (trapezoidal,
  above baseline) and return-to-baseline. Needs `MIN_READINGS_FOR_CURVE` = 6 post-meal
  readings for a curve (`hasCurve`); confidence high/moderate/low. Meal `loggedAt` and
  glucose `recorded_at` share the user's wall-clock frame, so the window is plain time
  arithmetic (no tz conversion). Surfaced on the **meal-detail page**
  (`MealController::show`, Chart.js curve + metric chips + narrative); degrades to a
  "limited data" note, hidden entirely when the user has no glucose at all.
- **Measured vs. expected** (`GlycemicImpactEstimator` → `GlycemicImpact` DTO): a proxy
  glycemic-load model over `NutritionData` — available carbs (carbs − fiber) × a
  sugar-weighted proxy GI (`GI_BASE` 55 → +`GI_SUGAR_PREMIUM` 30 at all-sugar), damped
  by fat and protein (rates/floors are tunable consts), → eGL → expected iAUC
  (`IAUC_PER_GL` 55) → ratio vs. the measured iAUC → 5 bands (much_lower…much_higher).
  Returns null for essentially zero-carb meals (< 1 g available). Shown as the "vs.
  Nutritional Estimate" block inside the meal-detail glucose card.
- **Cross-meal insights** (`GlucoseInsightsService` → `GlucoseInsights` /
  `AnalyzedMeal` DTOs) at **`/insights/glucose`** (`GlucoseInsightsController`, day
  window 7/14/30/90, **More ▸ Glucose Insights** nav). Reuses the two services above
  per meal (`findWithNutritionForRange`), so a row equals exactly what the meal page
  shows. Ranks **biggest rises**, **gentlest**, and **spiked-more-than-predicted**
  (ratio > 1, the Phase-2 moat ranked), each capped at `TOP_N` = 5, plus headline
  averages. `analyzeMeals()` is also reused by the **Dashboard** "Today's glucose
  responses" card (shown to anyone with glucose data via
  `HealthMetricRepository::userHasGlucose()`). Glucose is fetched **once per call**
  (`PostprandialResponseService::forMeals`, 2026-09-30 — it was one query per meal,
  ~360 on a 90-day window): one query spanning every meal's window, then each meal
  gets exactly the rows its own `forMeal()` query would (same inclusive bounds,
  compared as the same `Y-m-d H:i:s` strings), so results are identical — pinned by
  `PostprandialResponseServiceTest::testForMealsMatchesForMealWithOneQuery`.
- **Higher-cost AI tier (AI Pattern Coach):** the page's deterministic rankings are
  always on and free; the **AI Pattern Coach** deep-dive is a *separate*, token-spending
  tier. It is **dual-gated** — it runs only when BOTH the global
  **`GLUCOSE_AI_INSIGHTS_ENABLED`** env flag (`.env` default `0`, a master kill switch,
  bound to `GlucoseInsightsService::$aiDeepDiveEnabled` in `services.yaml`) **and** the
  **Loop+ `ai_pattern_coach` feature** are on. (The old third gate — the per-user
  `User.glucoseAiEnabled` admin toggle — was **retired 2026-07-24**, column dropped in
  `Version20260724220000`: services follow the tier, and admins adjust the tier via the
  Upgrade/Downgrade actions instead. Consequence: every Loop+ user, including the
  permanently-comped beta cohort and the demo account, gets the coach wherever the env
  flag is on — the per-user cache + ≤600-token cap bound the spend. ⚠️ Prod's
  `.env.local` must set `GLUCOSE_AI_INSIGHTS_ENABLED=1` for anyone to get it.)
  When both gates pass and the window has data,
  `GlucoseInsightsService::forUser()` calls `AiClient` (the main model — `claude-sonnet-4-6` on Anthropic,
  ≤600 tokens) to generate `GlucoseInsights::$coachingNarrative` from the user's own
  ranked meals — try/catch with a null fallback (logged), like `GoalEvaluationService`.
  The narrative is **cached** (`cache.app`, key = user + a hash of the prompt input, so
  it changes only when the meals/window do; ~1-day TTL; **failures aren't cached**), so
  refreshes, back/forward, and the day-window tabs don't re-bill tokens. `AiClient`
  is injected **lazily** (`#[Autowire(lazy: true)]`) — since this service also backs the
  Dashboard, a non-AI page load (kill switch off / user not entitled) never instantiates
  the client or resolves `ANTHROPIC_API_KEY`, keeping the kill switch a true no-op.
  The template renders the narrative when present, the locked "Premium / Coming soon"
  upsell when the user isn't entitled, and a "Coach unavailable" notice when entitled
  but generation failed. Educational wellness signal, not medical advice.
- **CGM data caveat:** glucose must arrive at full resolution. Health Auto Export's
  **"Day" aggregation collapses glucose to one daily point** and breaks curves — use a
  per-reading export (the real Lingo feed samples ~every 5 min). Single-reading "days"
  in the data are typically `source='mock'` test rows, not a real ingestion problem.
  The **Connect your CGM wizard** (see its section) detects exactly this shape via its
  density check and tells the user to flip the aggregation setting.

## AI voice & tone (per-user)
Users tune how the AI **coaching narratives** sound on **Settings ▸ Voice & tone**
(`/settings/tone`, `SettingsController::tone`, custom slider form + CSRF `settings-tone`,
nav under the Settings dropdown). Three independent **1–5 dials** on `User`
(`tone_humor`, `tone_warmth`, `tone_education`, smallint default **3** = today's neutral
voice; setters clamp to 1–5; migration `Version20260626202813`). The template
(`templates/settings/tone.html.twig`) shows the humorous end-labels ("If I loosened my
tie, I might hyperventilate" … "Robin Williams on crack") with a live mid-point descriptor
as you slide.

- **`App\Service\TonePromptBuilder`** turns the dials into a compact instruction
  appended to the system prompt: `forUser(User)` / `build(h,w,e)` (level→phrase maps,
  clamped) and `signature(User)` for cache keys. It always ends with a **hard guardrail**
  — *style only; never change the facts, the plain-prose/no-Markdown rule, or the
  educational-not-medical-advice framing* — so the funny labels stay UI-only and the
  safety/format rules always win. Level 3 still emits a line (the neutral voice), one
  consistent mechanism.
- **Wired into the three coaching narratives only:** `GoalEvaluationService` (check-in),
  `GlucoseInsightsService` (Pattern Coach — the tone `signature()` is folded into the
  narrative **cache key** so changing your voice busts it), and `PredictiveInsightService`
  (both the computed and free-text narratives). The **functional** AI surfaces (help Q&A
  assistant, goal-setup chat) stay neutral for clarity. Available to all users (only
  affects narratives they can already access; no tier gate).
- Covered by `tests/Service/TonePromptBuilderTest` (mapping, defaults, guardrail, clamp),
  a tone-reaches-the-prompt assertion in `GlucoseInsightsServiceTest`, and
  `tests/E2E/ToneSettingsTest` (render + persist + clamp).
