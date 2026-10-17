---
description: "Favorites (reuse without a Claude call), the shared Free-tier analysis bucket and its ledger, and recipe photo import."
paths:
  - "src/Controller/FavoriteController.php"
  - "src/Service/FavoriteManager.php"
  - "src/Entity/Favorite.php"
  - "src/Repository/FavoriteRepository.php"
  - "src/Controller/RecipeImportController.php"
  - "src/Service/RecipeAnalysisService.php"
  - "src/Dto/RecipeAnalysis.php"
  - "src/Form/RecipePhotoFormType.php"
  - "src/Service/AnalysisAllowance.php"
  - "templates/favorite/**"
  - "templates/recipe/**"
---

# Favorite meals and recipe photo import — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Favorite meals (reuse without re-analysis)
Save a repeated meal with its full post-correction nutrition so it can be logged
again **without another Claude call** — the motivation is cutting token spend on
meals the user eats often. **Ungated — free on every tier** (the never-enforced
`favorites` entitlement key was retired 2026-07-22; see Subscriptions ▸ Tiers):
what Free pays for is the monthly `AnalysisAllowance` that *creates* favorites,
while reuse is deliberately free since it saves API spend.

- **Create** (`FavoriteManager::create`, copies the photo into its own upload dir)
  from two entry points:
  - the **Confirm & Refine** review screen — "Save to log & add to favorites"
    stores the **un-scaled, per-serving** estimate plus the **real analysis token
    cost** (`analysis_tokens` from the session), then logs the meal as usual;
  - the **saved-meal page** (`FavoriteController::new`, CSRF) — "Save as favorite"
    captures the meal exactly as stored; token cost is unknown there, so **0**.
  - Default name (`FavoriteManager::defaultName`) is the first clause of the AI
    description, else the first ingredient.
- **Model:** `Favorite` entity (per-user): name, thumbnail, `aiDescription`,
  `nutrients` (the `NutritionData::toArray()` shape — keyed map **including the
  un-scaled `confidenceScore`**), `ingredients`, `tokensPerAnalysis`, `useCount`,
  `createdAt`/`lastUsedAt`. `recordUse()` bumps the count + timestamp;
  `tokensSaved()` = `useCount × tokensPerAnalysis`.
- **Picker** (`/meal/favorites`, `FavoriteController::index`), reached from the
  Log a Meal page: searchable (JS name filter) + sortable
  (`recent`/`used`/`name`, `FavoriteRepository::SORTS`).
- **Log from favorite** (`/meal/favorite/{id}/log`, `FavoriteManager::logFromFavorite`)
  with a portion multiplier and meal type — **no Claude call**. Scales nutrition via
  `NutritionData::fromArray` (confidence inherited un-scaled) + ingredients via
  `PortionScaler`, copies a fresh thumbnail into the meals dir, links the favorite,
  and records the reuse. The new `MealEntry` holds its **own** copied nutrition.
- **Deleting a favorite keeps past meals:** `MealEntry.favorite` is a nullable FK
  with **`ON DELETE SET NULL`** (migration `Version20260611015440`); meals copied
  their nutrition + thumbnail at log time, so they only lose the back-reference.
- **Manage** on the favorites page: rename + headline-macro edit
  (`/edit` — micros/confidence untouched) and delete (`/delete`, unlinks the
  favorite's thumbnail) — both CSRF + ownership checked. Names are capped at 200
  characters (the column length) in `FavoriteManager::create` for every create path —
  until 2026-09-30 only the edit path capped server-side, so an overlong name from the
  meal page 500'd under strict mode.
- **Cost tracking:** `FoodAnalysisService` surfaces Claude's `usage`
  (`AnalysisResult::totalTokens()`). The user's favorites page shows only
  **analyses skipped** (= total reuses, `FavoriteManager::savings`); the
  **tokens-saved** figure is operator-only on the admin report
  (`/admin/reports/favorites`). Tokens count only where a per-analysis cost was
  captured — favorites made before tracking, or from a stored meal, contribute 0.
- Uploads live in `public/uploads/favorites/` (gitignored; needs the same
  `www-data` ACL as `public/uploads/meals/`).

### Recipe photo import (`/meal/recipe/scan` — a favorite from a recipe photo)
Photograph a recipe — a cookbook page, a printout, or a handwritten card (1–5
photos: front/back, several pages) — and ONE Claude vision call (OCR + whole-
batch nutrition together) turns it into a normal **Favorite** divided down to
one serving. No separate recipe entity for the user to learn; Phase 1 (MVP) of
`NouriLoop-Recipe-Photo-Import-Plan.md` (business folder), shipped 2026-07-22.
Nutrition source is Claude's estimate (the plan's recommended option 1) — no
food-database vendor was added.

- **`RecipeAnalysisService::analyzeImages(User, $paths, $context)` →
  `RecipeAnalysis` DTO** (title, servings-or-null, whole-batch `NutritionData`,
  confidence, questions, ingredients, transcribed `instructions`, tokens) —
  sibling of Food/SupplementAnalysisService: one `AiClient::completeWithImages`
  call, so it shares their image encoding, oversized-image downscale (5 × 20 MB
  uploads must not blow the ~32 MB request cap) and fence-tolerant
  `AiResult::json()` decode. `max_tokens` 4096
  (long ingredient lists + the steps transcription). The prompt asks for
  whole-batch totals, a numeric yield ("serves 4-6" → 5; best guess **plus a
  clarifying question** when unstated), vague quantities kept verbatim ("a
  pinch"), and a faithful transcription of the steps.
  `LocalePromptBuilder::recipeAnalysisJson` localizes title/questions, pins
  ingredient names English (inflammation keyword matching), and has the steps
  transcribed in whatever language the card itself is written in.
- **Shared monthly bucket — `App\Service\AnalysisAllowance`** (Free's, key-less Loop's and
  Loop+'s allowance since PR 7 — `billing.md`; ⚠️ meals logged **from a favorite** don't
  count — `MealEntry::$fromFavorite`, migration `Version20261013041915`, a column rather
  than `favorite IS NULL` because that link is SET NULL when the favorite is deleted;
  before it, every re-log silently spent an analysis, Copilot catch on #247) (extracted from
  FoodAnalysisService; the CheckinAllowance derived-count pattern, no counter
  column **except for re-analyses** — see the mixed-model note at the end of this
  bullet): remaining = `FREE_MONTHLY_LIMIT` (10) − meals logged this month −
  recipe imports this month
  (`FavoriteRepository::countRecipeImportsForUserThisMonth` — favorites with a
  non-null `servingsAtImport`, created this user-local month; the user-local
  month boundaries are converted back to the **server** frame before comparing
  against the server-frame `createdAt` — Copilot catch on PR #171). Both
  analysis services gate through it, so an import spends one monthly analysis
  exactly like a meal photo. **Every analysis runs through
  `AnalysisAllowance::meter(user, ledgerAs, meal, analysis)`** (2026-10-06) — the only
  way in: refuse before any work when a Free user has none left, run the analysis,
  then book a ledger row with the result's tokens **only** when `ledgerAs` is given
  (the check and the booking are private). Which kinds are ledgered is
  `docs/adr/0001-mixed-allowance-ledger.md`, and
  `tests/Service/AnalysisAllowanceMeterTest` pins refuse-before-work,
  book-only-when-ledgered, failed-not-charged, and the kind each entry point books; the scan
  page shows the same allowance counter as the log page
  (`meal.log.allowance_left`). ⚠️ **Accepted tradeoff** (same posture as the
  meal count — an analyzed-but-never-saved meal isn't counted either — and
  CheckinAllowance's goal-delete reset): the marker is the saved favorite, so
  an abandoned confirm or a deleted favorite refunds the count even though the
  Claude call happened. Revisit with a call-time usage ledger only if abuse
  appears.
  ⚠️ **The bucket is now MIXED, and only one path is ledgered.** Meal
  **re-analysis** (Phase 3, 2026-07-29) overwrites an existing meal's numbers and
  creates nothing, so a derived count is structurally blind to it — a Free user
  could re-analyse without limit while their meal count sat still. Those calls
  therefore write an `AnalysisEvent` row (booked by `meter()` once the analysis
  succeeds — `FoodAnalysisService` books it when given `reanalysisOf`) and `remaining()` subtracts
  `AnalysisEventRepository::countForUserThisMonth` as a third term. Consequences,
  both deliberate: a re-analysis slot is **not** refunded by deleting the meal
  (the row survives via `ON DELETE SET NULL`), and re-analysis got the ledger the
  other paths were left without precisely because it is the unbounded-per-artefact
  one. Don't "unify" this by making meals ledgered too without deciding to change
  the documented log-then-delete posture above. **Supplement label scans** (2026-09-30)
  are the second ledgered kind (`supplement_scan`): a scan creates a row only if saved,
  so scan-and-abandon would otherwise be free.
- **Flow (`RecipeImportController`, `GET|POST /meal/recipe/scan` +
  `GET|POST /meal/recipe/confirm`, session-staged like the supplements scan):**
  upload (`RecipePhotoFormType`, ≤5 photos, HEIC-converted) → analyze →
  Confirm screen (editable title / servings / ingredient rows / instructions,
  batch macros + a live per-serving preview, the AI's questions with answer
  fields) → save divides the batch by the confirmed servings
  (`NutritionData::fromArray($batch, 1/$servings)`; numeric ingredient amounts
  via `PortionScaler`, "to taste" untouched) and calls the shared
  `FavoriteManager::create` (extended with optional `instructions` /
  `servingsAtImport` args — every other caller passes nothing). Server-side
  bounds mirror the HTML attrs (title 200 chars; servings must be **finite**
  and > 0 — `INF` from a crafted POST would otherwise reach the division and
  the DOUBLE column). **Re-analysis rounds** (`action=reanalyze` on the
  confirm POST, CSRF `recipe-confirm`, run by `ClarifyRound` like the meal flow's) send
  answered questions + the edited ingredient list back with all the photos;
  the user's typed title/servings/instructions win over the fresh AI values.
  A photo-limit hit at any point → `/pricing` with the
  `meal.log.limit_reached` flash. A malformed reply yields an **empty title**
  (no hardcoded-English fallback — the confirm screen's required field makes
  the user name it in their own language).
- **Files:** uploads are staged by `PhotoStaging` into the **meals** dir (`recipe-` filename stem,
  `bin2hex(random_bytes(16))` like every upload) so the daily
  `app:cleanup-meal-uploads` sweep covers abandoned imports for free; save
  copies the first photo into `uploads/favorites` via FavoriteManager and
  deletes all originals.
- **`Favorite` columns** (migration `Version20260722210000`, both nullable):
  `instructions` (display-only reference text, never re-parsed) and
  `servings_at_import` (the divisor, kept so a mis-typed count could be
  re-derived; non-null doubles as the recipe-import marker for the allowance
  count AND the Phase-2 badge). Both on the favorite row — admin user-delete
  needs no change.
- **Phase 2 (shipped same day, PR #172):** the favorites card shows a subtle
  **"From a recipe" badge** (derived from `servingsAtImport` — the plan's
  optional `source` enum stays unbuilt as unnecessary) and an
  **"Ingredients & steps" collapse** next to Edit: the cook's reference —
  the **whole-batch ingredient quantities** under an "Ingredients — makes N
  servings" heading, then the transcribed steps (auto-escaped user data,
  `white-space: pre-wrap`; there is no per-favorite detail page, so the card
  IS the detail view). Batch quantities come from
  **`Favorite.batch_ingredients`** (migration `Version20260722234500`,
  nullable JSON) — the confirm screen's list saved **verbatim, pre-division**
  (⚠️ the `ingredients` column is the per-serving division the meal pipeline
  logs from — useless for cooking, which is why this column exists); imports
  predating the column **reconstruct per-serving × servingsAtImport at
  display time** in `FavoriteController::index` (2-decimal rounding from the
  import division can drift slightly — e.g. ⅓ cup ÷ 8 × 8 → 0.32). Entry
  points: the favorites page header + its empty state (Phase 1) **and the
  dashboard header** — an outline "Import a recipe" button beside Log Meal.
  Keys `recipe.badge`/`recipe.steps`/`recipe.ingredients_for` (ICU plural)
  in en + all five full catalogs (no en_GB divergence).
- **i18n:** `recipe.*` keys shipped in en + all five full catalogs; en_GB
  overrides only the favourite-spelling strings. es_ES keeps "porciones"
  (checked against the existing catalog) — no Spanish-variant override. The
  form's max-photos constraint message ("…{{ limit }} photos of one recipe.")
  is in all five `validators.<locale>.yaml` catalogs, mirroring the meal
  form's line.
- **Public surface (2026-07-22, same-day follow-up):** the `import-recipe`
  **help topic** (auto-propagates per the Help center section: sitemap,
  JSON-LD `ItemList`/`TechArticle`, NL-assistant corpus incl.
  `help_assistant.keywords.import-recipe` in en + all five catalogs;
  `help_link('import-recipe')` on the scan page header) and **marketing
  mentions**: an `about.logging.items` bullet + about/pricing seo/jsonld
  descriptions, `pricing_page.feature.unlimited_photos` relabeled "Unlimited
  photo & recipe analyses" (retired in PR 7), `pricing_page.free_note` now discloses BOTH Free
  allowances ({limit} check-ins + `{photoLimit}` monthly AI analyses, passed
  from `AnalysisAllowance::FREE_MONTHLY_LIMIT` via Twig `constant()`), and
  one recipe `<li>` each on `/lp/fiber` (photo_items), `/lp/weight-loss`
  (photo_items), and `/lp/diabetes` (one_place_items — condition-word rule
  respected in every locale). `/lp/inflammation` deliberately unchanged (its
  sections are the score mechanics + symptom correlation; no logging feature
  list to extend). All copy in en + the five full catalogs, with en_GB
  (favourite/fibre/personalised) and es_ES overrides where those files
  already override the touched keys.
- Covered by `tests/E2E/RecipeImportTest` (in-process `WebTestCase`: division
  math incl. the "to taste" pass-through, the zero-Claude-call
  `logFromFavorite` round trip, allowance gate firing before any API call AND
  an import consuming the shared bucket, a re-analysis round being exactly one
  more call incl. preserved manual instruction edits, non-finite/non-positive
  servings rejected, sessionless confirm redirect, and the Phase-2 badge /
  steps collapse / dashboard entry point),
  `tests/Service/RecipeAnalysisServiceTest` (parsing incl. null yield,
  prompt-capture: contract + locale rule + context shapes), and a
  `recipeAnalysisJson` case in `LocalePromptBuilderTest`.
  `FakeAnthropicResponder` matches the recipe prompt on its "ONE recipe"
  opener and returns an 8-serving batch with round numbers.
