---
description: "The deterministic inflammation score, Trends page framing, the opt-in symptom loop, mood sliders, and the correlation analyzer."
paths:
  - "src/Service/InflammationScoreService.php"
  - "src/Service/DietSymptomCorrelationService.php"
  - "src/Service/CorrelationAnalyzer.php"
  - "src/Service/InsightSummaryService.php"
  - "src/Service/SymptomTypeManager.php"
  - "src/Service/NauseaTriggerService.php"
  - "src/Controller/SymptomController.php"
  - "src/Controller/TrendsController.php"
  - "src/Controller/DashboardController.php"
  - "src/Entity/Symptom*.php"
  - "src/Enum/SymptomTypeKey.php"
  - "src/Content/SymptomMood.php"
  - "src/Twig/SymptomMoodExtension.php"
  - "src/Dto/CorrelationResult.php"
  - "templates/symptoms/**"
  - "templates/trends/**"
---

# Inflammation score, symptoms and Trends — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Dietary inflammation score
A deterministic daily estimate of how pro-/anti-inflammatory the logged food was —
**an educational wellness signal, not a medical measure** (framed that way in the
UI). Motivated by diet↔symptom feedback (e.g. osteoarthritis flares).

- **`InflammationScoreService`** — pure heuristic, **no Claude call**, so it scores
  every already-logged meal retroactively. Two blended signals:
  - **nutrient backbone** (from `NutritionData::toArray()`): saturated fat / sugar /
    sodium push *pro*, fiber + antioxidant micros (A/C/D/E, B6, folate, B12, Mg, Zn,
    Se, normalized to %RDA, capped at 1 RDA) push *anti*. Directionality follows the
    Dietary Inflammatory Index (Shivappa et al.).
  - **food-category adjustments** — keyword match on `MealEntry.ingredients[].name`
    (e.g. processed meat / fried / sugary drinks → pro; fatty fish, olive oil, leafy
    greens, berries, turmeric → anti), capped per meal.
  - `scoreMeal()` returns a signed score + ranked factors (for tooltips);
    `dailyScores($user,$from,$to)` sums per **local day**, clamps to **−10…+10**
    (`MIN`/`MAX`), and tags a `band()` (strong-anti…strong-pro). Weights are
    documented, tunable constants; tests assert direction + clamping, not magnitudes.
- **Surfaced on** Trends (`TrendsController` → Chart.js line, band-colored points,
  factor tooltips) and the Dashboard (today's score badge). Meals pulled via
  `MealEntryRepository::findWithNutritionForRange` (fetch-join, no N+1); the
  Dashboard passes its already-loaded meals to `dailyScoresForMeals()` instead of
  re-querying them. **Dashboard query budget (2026-09-30):** today's meals are loaded
  once with nutrition fetch-joined and reused by calories, the inflammation score,
  glucose responses, and GLP-1 protein (a dead 23-column average
  call was dropped), so the page's query count is flat in the number of meals —
  pinned by `tests/E2E/DashboardQueryCountTest` (profiler query count, 1 meal vs 5).
  Every such range query is covered by `idx_meal_entry_user_logged` (`user_id,
  logged_at`, migration `Version20260930213006`); the FK's `user_id`-only index left
  the date filter to a row scan.
- **Page framing (`templates/trends/index.html.twig`):** this page is titled
  **"Insights & Trends"** (heading + nav) to signal it's an **over-a-period** view, not
  a today view (that's the Dashboard / "Today"). A subtitle states the active window
  ("Patterns across your last N days — not just today") and an inline legend explains
  the tile **arrows** = *direction over the period* (rising/falling/steady) while the
  tile **color** = good/watch/attention — they're independent (a falling arrow isn't
  inherently bad). The arrow direction itself is computed by `InsightSummaryService::trend()`
  (later-half vs earlier-half mean of the window).

### Symptom feedback loop (opt-in, multi-symptom)
Closes the diet→symptom loop: a daily self-reported check-in across one or more
**opt-in** symptoms, each correlated against the inflammation score. Generalized
from the original joint pain/stiffness-only model (migration `Version20260613173103`
replaced the old single `symptom_log` row/day; see git history).

- **Model — three tables (per-user):**
  - **`SymptomType`** — the user's symptom catalog: `typeKey` (slug), `label`,
    `isBuiltin`, **`isEnabled`** (the opt-in switch), **`higherIsBetter`** (positive
    symptoms like energy/acuity, where a higher 0–10 is *good* — flips the slider
    end-labels and the mood-emoji orientation, below), `position`. Unique
    `uq_symptom_type_user_key` `(user_id, type_key)`.
  - **`SymptomDay`** — one row/day holding the day's `note`, upsertable via
    **`uq_symptom_day`** `(user_id, logged_date)` (carried over from the old model).
  - **`SymptomEntry`** — one `level` (0–10) per symptom per day, unique
    `uq_symptom_entry` `(symptom_day_id, symptom_type_id)`. Both FKs are **`ON DELETE
    CASCADE`**.
- **Built-ins** live in the **`SymptomTypeKey`** enum (6: joint pain, joint
  stiffness, bloating/digestive, headache/migraine, **energy**, **mental acuity** —
  the last two `higherIsBetter`). **`SymptomTypeManager::ensureDefaultsFor($user)`**
  idempotently seeds them **disabled** (opt-in) for users created after the migration
  (called from `SymptomController::index`). The migration seeded existing users and
  **enabled joint pain/stiffness for prior loggers** to preserve their behavior.
- **`SymptomController`** (`/symptoms`): per-enabled-type sliders (prefilled from the
  day's entries) + a **Manage symptoms** panel (toggle on/off, add/delete custom —
  mirrors the Supplements self-management UI, CSRF + ownership). `/symptoms/save`
  upserts the `SymptomDay` + a `SymptomEntry` per posted `level_<typeId>` (CSRF
  `symptom-save`, `redirect=dashboard` for the quick card; the note is only touched
  when submitted, so the dashboard can't clear it). Manage routes:
  `app_symptom_type_create`/`_toggle`/`_delete` (custom only — built-ins disable, not
  delete). The **Dashboard** card renders only when **≥1 symptom is enabled**, so no
  user is forced to answer (a brand-new user sees no card).
- **Slider UX — mood emoji + middle default.** Each slider shows a **mood emoji**
  (not a colored number) to its right, driven by *goodness* rather than the raw value:
  `App\Content\SymptomMood::forLevel(level, higherBetter)` maps the level onto an
  11-step worst→best ramp (😩…😐…😁 — the "Gentle & warm" set), flipping for
  `higherIsBetter` so the face means the same thing for every symptom (joint pain 0 → 😁,
  energy 0 → 😩). This replaced the old chip whose **color was fixed by the symptom type**
  and so contradicted the value (green energy chip at 0, red joint-pain chip at 0).
  The ramp endpoints were softened (2026-06-27): the screaming 😱 worst and the
  hard-to-read star-struck 🤩 best were dropped for a gentle 😩 / clear grin 😁, since the
  user base skews older — and the face is sized up via a single **`.symptom-mood`** rule
  in `base.html.twig` (~2rem, replacing the old Bootstrap `fs-4`) for the same reason. The
  Dashboard card lays out label · slider · emoji in one row; the **slider + emoji are
  bound together in their own non-wrapping inner flex group** so the emoji can never wrap
  off onto its own line (only the label drops above on a very narrow screen) — otherwise
  the orphaned emoji landed at the far right below the grown slider. A
  **`.form-range[data-mood-slider] { display: block }`** rule kills the range input's
  inline-block baseline descender gap so the slider track centres on the row with the
  label and emoji (it sat a few px high otherwise).
  Rendered server-side via the **`symptom_emoji()`** Twig fn and kept live as the slider
  moves by a shared inline script (`templates/symptoms/_mood_script.html.twig`, seeded
  from **`symptom_mood_ramp()`** so JS and PHP can't drift); the numeric value stays in
  `title`/`aria-label`. Unset sliders **default to the middle (5 → neutral 😐)**, not 0
  — honest "nothing reported yet" without biasing the diet↔symptom correlation. Used by
  both the `/symptoms` page and the Dashboard card. Covered by `tests/Content/SymptomMoodTest`.
- **`DietSymptomCorrelationService::summarize($scoreByDate, $levelByDate)`** —
  symptom-agnostic, **reused unchanged**: avg level on pro-inflammatory (score ≥
  `PRO_THRESHOLD` = 1.0) vs other days, plus a Pearson `r`, **same-day and next-day**
  (inflammation can lag). Called **once per enabled symptom**; its pro-/other-day
  framing is kept verbatim under each symptom's diet chart on Trends.
- **Per-symptom relationship panels (Trends).** Each enabled+logged symptom gets its
  **own** insight tile (`symptom-<id>`) opening its **own** panel (no more shared panel
  + chip selector), with a **Diet** stack (symptom vs protein/carbs/fat/fiber +
  inflammation score) and a **Health** stack (vs steps/glucose/weight/sleep). Charts
  build lazily when the panel opens (`PANEL_INIT['symptom-'~id]`, registered by
  `setupSymptomPanels()`).
- **Symptom charts are small multiples, not one overlaid multi-line chart.**
  Six-plus normalized lines sharing one axis read as noise (real user complaint); each
  symptom panel is now a vertical stack of compact mini charts — one bar mini-panel for
  the symptom itself (0–10, own axis) and one line mini-panel per factor, **each in its
  own real units** (no more min–max normalizing everything onto a shared 0–1 "relative"
  axis). All panels share the same date columns so scanning straight down a stack shows
  whether a factor moved with the symptom on a given day. Built by
  `setupSymptomPanels()`'s `buildMultiples()` (replaces the old `buildChart()`); markup
  is a `.symptom-multiples` container (`templates/trends/index.html.twig`) that JS fills
  with `.symptom-mini-panel` rows, not a static `<canvas>`.
  - **Alignment gotcha:** Chart.js sizes each mini chart's y-axis label area from its
    own tick text, so e.g. `"20,000"` (steps) vs `"10"` (symptom) would otherwise start
    their plot areas at different x-offsets, breaking the "scan down a column" premise.
    Fixed with a fixed-width y-axis (`afterFit` forces `scale.width = MINI_Y_AXIS_WIDTH`
    on every mini chart) **plus** abbreviating large tick values (`formatMiniTick`, e.g.
    `12000` → `"12k"`) so the fixed width doesn't clip. The bottom-most panel in each
    stack shows the date axis; rotated/long tick labels there reserve extra overflow
    room that silently un-aligns just that one panel, so it uses horizontal, abbreviated
    date labels (`formatMiniDateTick`, e.g. `"Jun 1"`) instead of the app's usual rotated
    ISO-date `sharedXAxis`. Only the bottom panel of a stack ever shows x-axis ticks —
    every other panel uses a fully hidden x-axis and relies on the shared date columns.
- **Trends chart sizing.** The other detail-panel **time-series** charts (inflammation,
  health, calories, sleep) render inside a fixed-height wrapper
  (`.insight-chart-wrap` 340px, 260px on mobile) with **`maintainAspectRatio: false`**,
  so they get vertical room instead of being squashed into a ~100–150px band. The
  symptom small-multiples stacks size by content instead (each mini panel ~56–84px tall
  × however many factors exist). The **macro doughnut** self-sizes and is left alone.
- **`App\Service\CorrelationAnalyzer` + `App\Dto\CorrelationResult`** compute the
  significance shown **below each chart**. For every factor it runs a Pearson `r` at
  **same-day and next-day** lags and a **two-tailed Student-t test** (p from the
  regularized incomplete beta), surfacing only results with **n ≥ `MIN_N` (7)** matched
  days **and p < 0.05**, strongest |r| first. `TrendsController` runs it over the diet
  macros and the health metrics per symptom (`$symptomCorrelations`); inflammation is
  excluded here since it keeps the `DietSymptomCorrelationService` framing above. Copy
  is polarity-neutral ("rose and fell together with" / "moved opposite to"). Covered by
  `tests/Service/CorrelationAnalyzerTest` (known r/p, lag, clamp, significance gate) and
  `tests/E2E/SymptomCorrelationPanelTest` (planted correlation renders end-to-end).
