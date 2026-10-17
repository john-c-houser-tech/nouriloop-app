---
description: "The supplement label scan and its cost controls, adequacy and gaps, and DailyIntake — the one module that adds up a day's intake."
paths:
  - "src/Controller/SupplementsController.php"
  - "src/Service/SupplementAnalysisService.php"
  - "src/Service/SupplementRecommendationService.php"
  - "src/Entity/Supplement.php"
  - "src/Form/SupplementPhotoFormType.php"
  - "src/Dto/SupplementAnalysis.php"
  - "src/Intake/**"
  - "src/Repository/NutritionDataRepository.php"
  - "src/Service/Glp1ProteinService.php"
  - "templates/supplements/**"
---

# Supplements and daily intake — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Supplements (photo scan + adequacy)
Photo→Claude pipeline (sibling of the meal flow) for supplements the user takes.

- **Scan flow** (`SupplementsController`): `/supplements/scan` uploads **1–3**
  label photos (`SupplementPhotoFormType::MAX_PHOTOS`; reuses `HeicConverterService`) —
  multiple shots handle wrap-around labels. `SupplementAnalysisService::analyzeImages(User, …)`
  sends them all to Claude in one request (oversized images downscaled via
  `ApiImageEncoder`) (prompted to combine across photos) and reads **per-serving** nutrients in
  the app's units (handles `%DV`, IU→mass). The first photo is kept as the thumbnail,
  extras discarded (filenames use a random `bin2hex(random_bytes(16))` stem — see the
  unguessable-filename note under Meal logging). Then `/supplements/confirm`
  review/edit form → save. Missing photo files are dropped and a scan whose
  photos are all missing fails **before** any call, so nothing is charged (2026-10-06 —
  the shared `AiClient` rule the meal and recipe analyzers already followed; before,
  the image encoder threw partway through). Parsing, the nutrient-key mapping and the
  ledger booking are unit-tested in `tests/Service/SupplementAnalysisServiceTest`.
- **Cost controls (2026-09-30 — scans had none):** a scan draws on the shared Free-tier
  **`AnalysisAllowance`** like meal photos and recipe imports (John's call: share the
  10, not a separate cap) — `AnalysisAllowance::meter()` refuses before the call (exhausted → `/pricing`
  with `meal.log.limit_reached`, uploads cleaned up, **no API call**), and the scan page
  shows the same `meal.log.allowance_left` counter. Because a scan only leaves a row if
  the user saves it, it's booked on the **call-time ledger** (`AnalysisEvent`, kind
  `supplement_scan`, with the response's token count) after the call succeeds — so an
  abandoned scan still counts, for every tier (the row is also the cost record). Covered
  by `tests/E2E/SupplementScanAllowanceTest`.
- **Model:** standing **daily** supplement, not per-day logging. `Supplement` entity
  (per-user): name, photo, **per-serving nutrients as JSON** (keyed by RDA key),
  `servingsPerDay`, `isActive`. `dailyNutrients()` = per-serving × servings/day.
  (`servingsPerDay`'s `#[ORM\Column]` default is the **string** `'1'`, not int `1`:
  MariaDB introspects a `DOUBLE` default back as `'1'`, so a string keeps
  `doctrine:schema:validate` from perpetually flagging the column — DB form unchanged.)
- **Adequacy integration:** `SupplementRecommendationService::getRecommendations()`
  reads the 7-day average **intake** (`DailyIntake::average()->totals()`: food plus each
  **active** supplement's daily contribution), so gaps/suggestions reflect what the
  user already supplements. Disable → drops out. ⚠️ **No meals in the window means no
  gaps at all, supplements included** (ADR 0002; until 2026-10-06 a supplement on its
  own produced gaps): `average()` is null, `computeGaps([])` is `[]`, and the page shows
  its no-data state. `computeGaps` also treats an all-zero average as "no data", which
  used to flag every nutrient for brand-new users (fixed 2026-07-22, new-user Wave 4;
  see Onboarding ▸ First-run polish).
- **Trends "Micronutrients" tile is driven by the same source.** The Insights/Trends
  overview tile (`InsightSummaryService::microsCard`) reads `getRecommendations()`'s
  supplement-inclusive gap list (passed in via `TrendsController`'s `microGaps` ctx,
  same window), **not** raw food averages — so it can't disagree with this page. The
  tile headline is the gap count, and it **links to `/supplements`** instead of
  opening an on-page chart; the old food-only "Daily Averages vs RDA" panel was
  removed. **Both pages offer the same `7/14/30/90`-day windows** (`SupplementsController`
  was widened to 90 to match Insights), and the tile links over the **exact** period
  selected — so the count and the page always agree, no clamping.
- **Manage** on `/supplements`: list, edit servings, enable/disable, delete
  (CSRF + ownership checked). Posted servings-per-day must be finite (a crafted `1e999`
  casts to `INF`, which `max(0.0, …)` passes; non-finite now stores 1) — the recipe
  import's `is_finite` guard, applied to confirm + update. Nutrient label/units come from
  `SupplementRecommendationService::nutrientMeta()` (the public view of the RDA map).
- Saved thumbnails live in `public/uploads/supplements/` (gitignored; needs the same
  `www-data` ACL as `public/uploads/meals/`). ⚠️ **A scan's photos are staged in
  `public/uploads/meals/` like every other draft's (2026-10-06)**, and the confirm save
  moves the kept thumbnail across with `PhotoStaging::keep()`. Before, they were written
  straight into `uploads/supplements/`, which `app:cleanup-meal-uploads` never sweeps, so
  every abandoned scan left its photo there for good. (Files orphaned before the change
  are still there; nothing removes them.) If the staged photo was swept while the
  confirm page sat open for 48 h+, the supplement saves without a thumbnail. Pinned by
  `tests/E2E/SupplementPhotoStagingTest`.

## Daily intake (`App\Intake\DailyIntake`, 2026-10-06)
Every screen that adds up what a user took in per day reads it from **one module**:
Trends, the Dashboard, the inflammation score, supplement recommendations and the
supplements page, Predict, goals (`GoalMetricCalculator`), the GLP-1 protein card,
and the evening calorie digest. A day exists only if a meal **with nutrition** was
logged on it (local `DATE(logged_at)`); the active supplements' daily amounts are
added on top. Three ways in: `days(user, from, to)` (one `IntakeDay` per meal day in
a half-open range, one SQL query for every nutrient via
`NutritionDataRepository::dailyNutrientTotals`), `forMeals(user, meals)` (the same from
meals already loaded, so the Dashboard's query budget stays flat) and
`average(user, from, to)` (an `IntakeAverage`: food averaged over the meal days, every
nutrient key present, and the supplements kept apart like an `IntakeDay`, with
`totals()` for the two together; null when there are no meal days). An `IntakeDay` keeps `food` and `supplements` apart, with
`total(nutrient)` for the sum, because the inflammation score labels supplement
factors and Predict subtracts the stack.
⚠️ **One rule, every screen** (`docs/adr/0002-supplements-count-on-meal-days.md`,
2026-10-06): supplements count on every day with a meal logged, and **never on their
own**. What that changed: goals and the GLP-1 protein card now include supplements
(they ignored them); the Dashboard no longer counts supplement calories on a day with
no meals; the Trends macros tile and pie, Predict's displayed baseline, weight and
narrative numbers, and the evening calorie digest now include supplements; and a
window with no meals shows no gaps even with an active supplement. "A day with meals"
means one with a meal that has nutrition (the Dashboard used to count any meal).
⚠️ **A supplement counts only from the local day it was added** (its `createdAt`,
2026-10-07; `Supplement::setCreatedAt()` exists only so the demo seeder can backdate
its stack to the start of the 60-day history): adding one today doesn't lift last
week's goal check-ins or redraw past Trends. An average counts it only on the meal days
since. Switching one **off** still removes it from past days, since supplements keep no
stop history. Covered by
`tests/Intake/DailyIntakeTest`, the supplement cases in `GoalMetricCalculatorTest` and
`Glp1ProteinServiceTest`, and `tests/E2E/DailyIntakeRuleTest`.
