---
description: "Photo and described meals, PhotoStaging, typed drafts, Confirm & Refine, the clarify-round cap, retry parking, history, manual edits, and AI re-analysis."
paths:
  - "src/Controller/MealController.php"
  - "src/Service/FoodAnalysisService.php"
  - "src/Service/PhotoStaging.php"
  - "src/Service/ClarifyRound.php"
  - "src/Service/PortionScaler.php"
  - "src/Service/NutritionDiff.php"
  - "src/Service/MealDuplicateChecker.php"
  - "src/Service/ApiImageEncoder.php"
  - "src/Service/ExifTimestampService.php"
  - "src/Service/HeicConverterService.php"
  - "src/Service/IngredientList.php"
  - "src/Service/AnalysisAllowance.php"
  - "src/Command/CleanupMealUploadsCommand.php"
  - "src/Draft/**"
  - "src/Form/Meal*.php"
  - "src/Entity/MealEntry.php"
  - "src/Entity/NutritionData.php"
  - "src/Entity/AnalysisEvent.php"
  - "src/Enum/MealType.php"
  - "src/Enum/AnalysisKind.php"
  - "src/Enum/StagingPurpose.php"
  - "src/Dto/AnalysisResult.php"
  - "src/Dto/NutritionDiffRow.php"
  - "src/Dto/StagedPhoto.php"
  - "templates/meal/**"
---

# Meal logging, history and editing — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Meal logging (photo → Claude → review)
- **Upload** (`MealController::log`, `/meal/log`): **1–3 photos of ONE meal**
  (`MealEntryFormType::MAX_PHOTOS`, `multiple` file field — so the dish + its
  nutrition label go through **one** Claude call instead of an upload + re-analyze
  round; field name is now `meal_entry_form[image][]`), each HEIC-converted →
  `FoodAnalysisService` (Claude vision) → `AnalysisResult` (nutrition + ingredients
  + confidence + questions). The **first** photo is the meal's thumbnail; photos
  2..n join the review's `extra_web_paths` (analysis aids, deleted on save like
  refine-round extras). EXIF timestamp (of the first photo) sets `loggedAt`, but the
  **Confirm & Refine** screen exposes an editable date/time (`logged_at`, future
  blocked) so a meal can be **logged retroactively** — `MealController::parseLoggedAt`
  applies it on save, falling back to the EXIF/now default. Stored filenames (the
  meal photo and the review's extra photos) use a **random stem** —
  `bin2hex(random_bytes(16))`, not `uniqid()` — because `public/uploads/` is
  web-served without auth, so the name's entropy is the only thing keeping a
  stranger from fetching someone's food photos (same pattern as `FavoriteManager`;
  supplements + goal charts match).
- **Staged photos go through `App\Service\PhotoStaging` (2026-10-06).** Every upload
  for a draft or re-analysis proposal — the log form, the retry, the clarify extra photo,
  a re-analysis, a recipe scan, a supplement scan — is staged by
  `stage(files, StagingPurpose)`: validated (20 MB, the image MIME list), written under
  the purpose's prefix (`meal-`, `meal-extra-`, `meal-reanalysis-`, `recipe-`, `supp-`)
  plus the random stem, HEIC-converted, and returned as `StagedPhoto`s (path + web path).
  **All or nothing:** if any file in a batch fails, the files already written are removed
  and it throws `PhotoStagingFailed`. That exception keeps the two causes apart: a file
  that isn't an acceptable image carries the validator's translated message (`invalidFileMessage()`),
  which the flash shows; only a failed HEIC conversion shows `common.heic_failed`. (Until
  2026-10-06 an invalid extra or re-analysis photo was reported as a HEIC failure.) Any
  other error, such as an unwritable directory, is rethrown after the rollback.
  `discard()` is the one delete for staged files, and
  `webPath()`/`path()` convert between the two forms the session still stores. It replaced
  five copies of the upload loop and the `storeExtraPhoto`/`cleanupFiles`/`dropPaths`
  helpers. Covered by `tests/Service/PhotoStagingTest`.
- **Drafts are typed (`src/Draft/`, 2026-10-06).** Everything a user is part-way
  through lives in the session as a typed value implementing `SessionDraft`:
  `MealDraft` (the Confirm & Refine review, key `meal_analysis`), `RetryParking`
  (`meal_analysis_retry`), `ReanalysisProposal` (`meal_reanalysis`), `RecipeDraft`
  (`recipe_import`) and `SupplementDraft` (`supplement_scan`). `DraftSession` is the
  only thing that touches those keys: `load()` (a malformed or stale entry comes back
  null and is dropped), `store()` and `clear(keep:)`. It also owns their staged photos:
  **storing a draft deletes the photos the draft it replaces held that it doesn't**,
  so starting a new log, scan or re-analysis over an open one cleans up at once
  instead of leaving the old photos for the 48-hour sweep, and `clear()` deletes them
  all except those handed on to a saved record. ⚠️ The session still holds **plain
  arrays under the same keys and array keys as before**, not serialized objects, so a
  draft open across a deploy still loads; keep `toArray()` key names stable, or make
  `fromArray()` accept the old ones. A `MealDraft` whose `logged_at` doesn't parse is
  refused: the old save's `createFromFormat(...) ?? now` fallback never fired
  (`createFromFormat()` returns false) and would have passed false to
  `setLoggedAt()`. Covered by `tests/Draft/`.
- **Photo-free logging ("describe your meal", 2026-07-21):** the log page's "No
  photo? Describe your meal instead" toggle reveals an optional description
  textarea (≤1000 chars); the photo becomes optional and the controller requires
  **either** photos **or** text (`meal.log.need_photo_or_text` form error).
  Text-only submissions run **`FoodAnalysisService::analyzeDescription`** — the
  text-only sibling of `analyzeImage` sharing the whole prompt via the private
  `buildPrompt()`/`requestAnalysis()` split (same JSON contract, same locale/notes/
  context handling, same Free-tier gate, cheaper call) — and land on the same
  Confirm & Refine review, which shows the user's own words (`meal.clarify.
  described_as` card) alongside/instead of the photo. Session state carries
  `describe`; re-analysis rounds re-run `analyzeDescription`, and any flow with
  both a photo AND a description folds the description into the notes via
  `MealController::combinedNotes` — on the **initial** photo analysis, on every
  re-analyze, and through the retry state (Copilot catch on PR #164: the first
  estimate must not silently ignore a description submitted with a photo;
  pinned by a `MealLogPathsTest` case that puts the failure trigger in the
  description). Saved photo-free meals have
  `imagePath = null` (already nullable; dashboard/show templates already guard it;
  `MealDuplicateChecker` no-ops on null). A failed text-only analysis just
  re-renders the form (nothing on disk to park — the retry-card flow is
  photo-only).
- **Allowance counter** (`templates/_allowance_counter.html.twig` since PR 7, shared by the
  log, re-analyse, recipe-scan and supplement-scan pages; its wording and link follow
  `AllowanceKind` — `billing.md`): the log page shows Free users an ICU-plural
  "N of {limit} free AI analyses left this month" (`meal.log.allowance_left` —
  plural-branched in every catalog per the countable-message rule; fr/ru needed
  real agreement branches) via `AnalysisAllowance::remaining()` and
  `FREE_MONTHLY_LIMIT` (amber + a pricing link at ≤3; read directly since 2026-10-06 —
  `FoodAnalysisService`'s pass-through alias and method were deleted) and
  `MealController::renderLog`, the shared render helper for every log-page path —
  so the limit is visible before it's hit rather than a surprise redirect.
  Key-less Loop and Loop+ users see their own counters; a paid user with their own key
  (and every self-hosted account) sees nothing.
- **Camera capture + client-side downscale (mobile):** a `d-md-none` "Take a photo
  now" button triggers a hidden `capture="environment"` input; captured shots are
  **downscaled client-side** (canvas, longest edge 2000px, JPEG 0.85, only files
  > 3 MB) before merging into the main input. Independently, the **server
  downscales oversized images for the API request only** (`App\Service\ApiImageEncoder`
  — GD, > `MAX_BYTES` = 4 MB re-encodes to JPEG q85, longest edge ≤ 2000 px; the stored
  original is untouched; one shared helper since 2026-09-30, and since 2026-10-06 called
  only from `AnthropicClient::completeWithImages`, which every vision analysis goes through —
  it was a private method copied into the meal and recipe analyzers): 3 × 20 MB uploads
  would blow past the Messages API's ~32 MB request cap once base64-encoded
  (Copilot catch on PR #164), and Claude gains nothing from >2000 px food
  photos anyway. Undecodable/no-win images are sent as-is. Pinned by an
  oversized-noise-JPEG request-capture case in `AnthropicClientTest`. ⚠️ Downscaling is **deliberately
  limited to camera captures**: canvas strips EXIF, and library photos' EXIF dates
  drive the intentional backdating feature (see Meal EXIF default below) — a fresh
  camera shot's EXIF is "now", which is the fallback anyway. Chrome can't decode
  HEIC in canvas — the code falls back to uploading the original (server converts).
- **Confirm & Refine is progressively disclosed (2026-07-21):** the confident
  common case reads photo → nutrition + servings (now the FIRST card) → Save. The
  secondary sections collapse to slim rows (`.js-toggle` + `d-none`, hidden inputs
  still submit): the **ingredients table** collapses to an ICU-plural count row
  (auto-open in refinement mode = not confident AND questions remain), the
  **extra-photo card** collapses unless extras exist or a question mentions a
  label/packaging/brand stem — matched in **every supported language** (questions
  come back localized, so the stem list in `clarify.html.twig` covers
  en/es/de/fr/ru/hi; a heuristic where a miss just means one extra tap, not a
  contract — Copilot catch on PR #164), the **date card** collapses to a one-line
  "Logging for {datetime} · change" summary (auto-open when backdated — and the
  `#backdate-warning` deliberately sits OUTSIDE the collapsible body so it's
  visible while the row is collapsed; `MealFlowTest` depends on that), the
  **favorite-name field** hides behind a "Name this favorite (optional)" link
  (the default name applies otherwise), and **prior Q&A** is a native `<details>`.
  ⚠️ Form-widget ids in these templates must be overridden **top-level**
  (`form_widget(f, {id: 'x'})`) — `attr.id` is silently ignored (this had left the
  log page's drop-zone preview JS dead; same trap as the register timezone field).
- **A nutrient Claude couldn't put a number on is no estimate (2026-10-06).** A
  non-numeric value in the reply (`"~20"`, `"unknown"`) maps to **null** — hidden on the
  meal page, skipped by goals and averages — not 0.0. Meals used to store 0.0 (a real
  "0.0 g" row that dragged goal averages down) while recipe imports already used null;
  the meal side was brought in line. Pinned by `FoodAnalysisServiceTest`. The rule
  can't drift again because the reply is read in **one** place for every analysis:
  `NutritionData::nutrientsFromClaudeJson()` (snake_case reply key → field, derived
  from the entity's field list, numeric values only) and `fromClaudeJson()` (a new
  row + confidence) serve the meal and recipe analyses, the supplement scan builds its
  nutrient map from the same method, and `IngredientList::fromClaudeJson()` normalises
  the ingredient list for meals and recipes (`tests/Entity/NutritionDataClaudeJsonTest`,
  `tests/Service/IngredientListTest`).
- **Meal notes reach the AI** (fixed 2026-07-21 — they were stored but never sent):
  `FoodAnalysisService::analyzeImage(User, $paths, $context, ?$notes)` prepends a
  block telling Claude to treat the user's notes as **authoritative over visual
  guesses** (dish name, restaurant, ingredients, preparation, serving size). Passed
  from the initial `log()` analysis **and** every `clarify()` re-analyze round (the
  notes live in the session state). Notes are **bounded** since they feed a billable
  prompt: `FoodAnalysisService::NOTES_MAX_LENGTH` (1000) backs both the form's
  `Length` constraint/`maxlength` attr and a defensive `mb_substr` in the service.
  The form placeholder now sets that expectation
  ("Tell the AI anything the photo doesn't show…"). Pinned by prompt-capture tests in
  `FoodAnalysisServiceTest` (notes present / blank leaves the prompt untouched).
- **Meal type preselects by time of day** — `MealType::defaultForHour(int)` (4–10
  breakfast, 11–15 lunch, 16–21 dinner, else snack) applied in `MealController::log`
  from the user's `UserTime` local hour; a POST overrides it as usual. Unit-tested in
  `tests/Enum/MealTypeTest`. **The favorites picker preselects the same way**
  (`FavoriteController::index` passes `defaultMealType`; fixed 2026-07-21 with Wave 3 —
  it had been left defaulting to Breakfast when the log form was fixed in Wave 1;
  covered by `tests/E2E/FavoritePickerDefaultTest`, which uses two users 12 h apart so
  a hardcoded default can't pass).
- **Analysis failure keeps the upload + offers one-click retry** (2026-07-21;
  previously the photo was deleted and the user had to re-pick the file): a generic
  Claude failure in `log()` parks `{image_path, image_web_path, meal_type, notes}` in
  the session (a `RetryParking` draft) — PRG back to `/meal/log`, which renders a retry card
  (parked thumbnail + **Retry analysis** button → `POST /meal/log/retry`, CSRF
  `meal-retry`). Retry re-runs the analysis with the kept (already HEIC-converted)
  photo + original type/notes and proceeds to the normal Confirm & Refine on success.
  Orphan-safety: parking a new failure deletes a previously parked photo, a fresh
  successful analysis clears the parked state, and stale state whose file vanished
  self-clears (`RetryParking::fromArray()` refuses it). The photo-limit path still
  deletes + redirects to `/pricing` (no point parking an analysis that can't run).
  **Abandonment backstop** (Copilot catch on PR #163): a user who walks away — or
  a session that expires — leaves the parked photo (and, pre-existing, any
  in-review upload) with no reference outside the session, so
  **`app:cleanup-meal-uploads`** (`CleanupMealUploadsCommand`, `--dry-run`,
  `--older-than-hours=48`) sweeps `public/uploads/meals` files that are older
  than the threshold AND unreferenced by any `meal_entry.image_path`; run daily
  from cron (see Deployment). A file that **fails to unlink** (ACL regression)
  is surfaced and fails the run (`Command::FAILURE`) rather than being counted
  as deleted — these are privacy-sensitive photos, so the cron log must not
  claim removals that didn't happen. ⚠️ Its test constructs the command with a scratch
  dir, never the container's `%uploads_dir%` — that parameter is the real
  dev-shared uploads dir even in the `test` env (only the DB name gets `_test`),
  and the referenced-set comes from the test DB, so a container-wired run sweeps
  the dev environment's own files (happened once).
  Covered by `tests/E2E/MealAnalysisRetryTest` (failure→park→retry-succeeds,
  still-failing keeps the card, fresh success drops the parked file) — failures are
  simulated via **`FakeAnthropicResponder`'s trigger markers in the meal notes**
  (`FAIL_ONCE`/`FAIL_ALWAYS` consts; notes flow into the prompt, so these tests also
  prove the notes wiring end-to-end). Since the multi-photo change the retry state
  carries `image_paths` (all parked photos, retried together).
- The Wave-2 paths are covered by `tests/E2E/MealLogPathsTest` (in-process: two
  photos → one analysis + extra thumbnail on review + extra dropped on save;
  described meal saves with null imagePath and its page renders; neither photo nor
  text rejected; Free user sees the allowance counter, comped user doesn't) and
  `analyzeDescription` prompt-capture cases in `FoodAnalysisServiceTest`.
- **Reused/older photos — backdate warning + duplicate guard.** The EXIF default is
  deliberate (lets several days of meals be logged in one batch, e.g. while
  traveling) — but a **future** EXIF time (camera clock set ahead, wrong zone) falls
  back to now (`MealController::exifOrNow`, 2026-09-30; before, it stuck, because the
  save path rejects a future posted time and keeps the default —
  `tests/E2E/MealExifDefaultTest`). So to stop a *silent* backdate: when the prefilled `logged_at` isn't
  today, the Confirm screen shows a prominent "this will be logged for `<date>`, not
  today" warning (server-rendered in `clarify.html.twig` + kept live by JS as the
  date field changes). And **`MealDuplicateChecker`** (called from `saveFromState`)
  flags a likely duplicate: the **same photo** — compared by **md5 of the stored
  image**, since each save copies to a unique filename so paths never match — already
  logged at the **same `loggedAt`** for that user. On a match it logs a `warning`
  (channel `app`, so it persists via the prod `warnings` handler) **and** the saved
  meal's page renders a "Possible duplicate" callout with a one-click **Delete Entry**
  action (`templates/meal/show.html.twig`, driven by a dedicated `meal_duplicate`
  flash). Non-blocking — the meal still saves; the user decides. (`MealDuplicateChecker`
  takes `$uploadsDir` from `services.yaml`; the photo-save path only, not log-from-favorite.)
- **Every meal** then routes through the **Confirm & Refine** review
  (`/meal/clarify`, `templates/meal/clarify.html.twig`) — not just low-confidence
  ones. The estimate (nutrition + ingredients) is stored in the **session**.
- **Portion multiplier:** the review has a "how many servings did you eat?" control.
  **Save** scales the stored nutrition **and** numeric ingredient amounts by it and
  persists — **no re-analysis**, so what was reviewed is what's saved (and no extra
  Claude call). `NutritionData::toArray()` / `fromArray($data, $scale)` round-trip
  through the session and apply the multiplier (confidence is not scaled).
- **Re-analyze with corrections** re-runs Claude with edited ingredients / answered
  questions, updating the session estimate; the user drives this (no auto-loop). The
  review can also **attach a second photo** (e.g. the nutrition-facts label the AI
  asked for): `FoodAnalysisService::analyzeImage` takes a **list of paths** and sends
  all images for the same meal in one request (with a note to combine them);
  `MealController::clarify` moves + HEIC-converts the uploaded `extra_photo`, appends
  it to the session's `image_paths`, and re-analyzes with every image. Extras persist
  across rounds (`extra_web_paths`, shown as thumbnails) and are **deleted from disk
  on save** — only the food photo is the meal's thumbnail. (Adding a real label
  typically lifts confidence sharply, e.g. ~0.71 → ~0.90.)
- **Primary button follows the state:** the review leads with **Save to log** when
  the estimate is confident (`ConfidenceLevel::isConfident`, ≥ 0.75) **or** the AI
  returned no more questions — once it stops asking, another re-analysis can't help,
  so Re-analyze is demoted to a secondary action (and the helper copy adapts). Only
  while questions remain *and* confidence is low does Re-analyze lead.
- **Round cap (2026-09-30):** refinement stops at `ClarifyRound::MAX_ROUNDS`
  = **3** (the first analysis is round 1), shared with the recipe import. Each round is a
  billable call the derived allowance can't see (the meal isn't saved yet), and the
  docs had long claimed this cap without it existing. At the cap the Re-analyze
  buttons, question inputs, and extra-photo card disappear, a `meal.clarify.round_limit`
  note says why, and a stale/crafted re-analyze POST just redirects back without a
  call. Covered by `tests/E2E/MealClarifyRoundCapTest`.
- **One clarify-round protocol for meals and recipes — `App\Service\ClarifyRound`
  (2026-10-06).** Both refine screens post the same fields (`answers[i]` for the AI's
  questions, `ing_name[]`/`ing_amount[]`/`ing_unit[]` for the ingredient table) and
  share the rules: `canContinue(round)` for the cap, `postedIngredients()` (blank names
  dropped; names ≤ 200 and amounts/units ≤ 50 characters, since they reach a billable
  prompt), and `nextContext()`, which appends this round's answered questions and the
  table as a **correction only when it differs** from the draft's list. Before, the
  recipe confirm posted `answer_i`, resent the unchanged table as a correction every
  round, and the meal review had no length limits. Covered by
  `tests/Service/ClarifyRoundTest` plus prompt checks in `MealLogPathsTest` and
  `RecipeImportTest` (the test fake records `lastAnalysisPrompt()`).
- **Saving keeps the ingredient table as the user left it (2026-10-06).** Before, an
  ingredient edit on the meal review counted only through a re-analysis: pressing Save
  stored the AI's original list, so correcting "fried chicken" to "grilled chicken" and
  saving changed nothing, including for the inflammation score, which matches on
  ingredient names. Save (and Save & favorite) now store the posted table, scaled by
  the portion multiplier as before. **Nutrition stays as estimated**, the recipe import's
  posture; changing the numbers is still a re-analysis. Pinned by
  `MealLogPathsTest::testSavingKeepsTheIngredientEditsWithoutAReanalysis`.
- **Portion math** (`PortionScaler` service) is shared by the photo-save and the
  favorite-log paths: `normalize()` coerces the posted multiplier (non-finite / ≤ 0 → 1,
  capped at `MAX_PORTIONS` = 100, the meal edit form's rescale cap — a posted `1e999` is
  `INF`, which a bare `> 0` check let into every stored nutrient); `scaleIngredients()`
  scales numeric amounts (non-numeric like "a pinch" untouched). Nutrition scaling
  itself stays in `NutritionData::fromArray($data, $scale)`.

## Meal history & editing (Phases 1–3 — view, favorite, edits, and AI re-analysis)
Implements the **whole** of `NouriLoop-Meal-History-Edit-Favorites-Plan.md` (business
folder; Phase 1 approved 2026-07-22 from a beta-tester request, Phase 2 built 2026-07-24,
Phase 3 built 2026-07-29). Phases 1–2 touch no downstream aggregate — every consumer
(dashboard, trends, inflammation, correlation, Predict, digests) reads meal rows live, so
every edit (including nutrient corrections and cross-day moves) is correct everywhere on
the next page load with no recompute step. **Phase 3 is the only part that costs an
Anthropic call**, and it is the reason Phase 2 records nutrition provenance.

- **History list** — `GET /meal/history` (`app_meal_history`, `MealController::history`):
  the user's meals newest-first via `MealEntryRepository::historyPage`/`countHistory`
  (shared private query builder so page + total can't disagree; nutrition fetch-joined —
  a to-one join, so `setMaxResults` paginates safely). Filters: `?from=`/`?to=`
  (inclusive `Y-m-d` bounds — `to` compares `< to+1 day`; values compare directly
  against the stored wall-clock `loggedAt`, no tz conversion) and `?type=`
  (`MealType`), all junk-tolerant (ignored, not errors) + `?page=` clamped, 25/page —
  the admin user list's GET-param idiom, incl. filters carried across page links
  (`filterParams|merge({page})`). Template `templates/meal/history.html.twig`
  distinguishes "no meals yet" (Log CTA) from "no match" (Clear filters).
- **Detail view / favorite-from-history**: `app_meal_show` and the "Save as favorite"
  form (`app_favorite_new`, builds the `Favorite` from the persisted meal's stored
  nutrition — zero Claude calls) **already existed**; Phase 1 added the history entry
  points + test coverage pinning the no-API-call property, and fixed
  `templates/meal/show.html.twig` so a **photo-free (described) meal still renders its
  AI description/ingredients/notes** (they were wrapped in `{% if entry.imagePath %}`).
  Unhiding the ingredient list exposed a latent strict-variables 500: stored ingredient
  rows aren't guaranteed an `amount`/`unit` key (the demo seeder omits `unit`), so the
  template now `|default('')`s them (found live-testing with the demo account; pinned
  by a unit-less ingredient row in `MealHistoryTest`'s seed helper).
- **The edit screen** — `GET|POST /meal/{id}/edit` (`app_meal_edit`, `MealEditFormType`,
  ownership-404 like show/delete): meal type, notes (same `NOTES_MAX_LENGTH` bound as
  logging), **date & time**, **manual nutrient corrections**, and a **one-shot portion
  rescale**. The Phase-1 time-only field became a full `DateTimeType` (single_text,
  string model `Y-m-d H:i:s` — the html5 widget reads/writes `Y-m-d\TH:i`, so seconds
  round to `:00` on save); the controller re-parses it in the user's zone (the
  `parseLoggedAt` idiom) and rejects a future instant (`meal.edit.future_time`, 422).
  - **Cross-day moves need a confirmation** (the plan's one deliberate speed bump — a
    move silently rewrites both days' totals/scores/charts): a posted date differing
    from the stored day without the `confirm_day_change` checkbox is a 422
    (`meal.edit.day_change_required`); the warning + checkbox block is revealed by
    inline JS as soon as the date input leaves the stored day (server-enforced
    regardless), and server-rendered open on the error re-render (`dayChangeNeeded`).
  - **Manual nutrient edits** cover `MealEditFormType::NUTRIENT_FIELDS` (calories,
    protein, carbohydrates, fat, fiber, sugar, sodium — headline macros + the three
    most consequential secondary values; micros stay AI-only, same posture as the
    favorite edit). Unmapped, prefilled; **blank keeps the stored value** (never nulls
    it), and only a genuinely different number counts as an edit, so re-saving the
    prefilled form is a no-op. Labels reuse `nutrient_full.*`. The nutrient + portion
    fields are **omitted entirely for a meal with no NutritionData row**
    (`include_nutrition` form option — the `include_sms` idiom; Copilot catch on
    PR #177: hidden-but-present fields would surface via `form_end()`'s leftover dump).
  - **Portion rescale** (`portions`, blank = none): multiplies the whole stored meal
    once on save — nutrition via the new **`NutritionData::scaleBy()`** (in-place
    sibling of `fromArray($data, $scale)`; confidence untouched) + numeric ingredient
    amounts via the existing `PortionScaler` (non-numeric amounts pass through).
  - **Edit provenance** (migration `Version20260724150000`, both nullable wall-clock
    datetimes on `meal_entry`): **`editedAt`** is stamped on any save that actually
    changed something — the plan §6 audit-trail marker, shown as a small "Edited
    {date}" line on the meal page header; **`nutritionEditedAt`** is stamped when a
    nutrient/portion edit changed the numbers — they're user-corrected, no longer the
    AI estimate, so the show page **replaces the AI-confidence bar** with an
    "Edited by you · {date}" marker, and Phase 3's re-analysis must diff-before-apply
    against any entry carrying it (the plan §4 rule). Columns live on `meal_entry`,
    so admin user-delete needs no change.
  - **Delete/allowance posture confirmed (plan §3's gaming question):** the Free
    monthly allowance stays **derived from meals logged this month**
    (`AnalysisAllowance` — no call-time ledger), so log-then-delete refunds the slot
    even though the Claude call happened. **Accepted deliberately** — identical to the
    recipe-import and analyzed-but-never-saved postures already documented under
    Recipe photo import; revisit with a usage ledger only if abuse appears. Pinned
    (as documentation, not as a guarantee) by a `MealEditPhase2Test` case. ⚠️ Phase 3's
    re-analyses are the **one** path that did get a call-time ledger — see below for why
    that isn't an inconsistency.
- **Phase 3 — AI re-analysis (`GET|POST /meal/{id}/reanalyze` → `…/reanalyze/review`)**:
  re-estimate an already-logged meal from a new/replacement photo, an edited
  description, or both. The plan's two cost-bearing edits, built as one flow because
  they differ only in what goes into the prompt.
  - **Two steps, always.** Step 1 (`MealReanalyzeFormType`, deliberately NOT bound to
    `MealEntry`) buys exactly one Claude call and writes **nothing** to the meal; the
    result is parked in the session (a `ReanalysisProposal`, key `meal_reanalysis`) and step 2 renders the diff.
    Only its Apply writes. ⚠️ The review guards the **meal id**, not merely the presence
    of state — a proposal parked for one meal must never be applied to another.
  - ⚠️ **The plan's §4 rule is enforced, not just displayed:** when the entry carries
    `nutritionEditedAt` (the user typed those numbers), Apply without the
    `confirm_overwrite` checkbox is a **422** — the same server-enforced speed bump as a
    cross-day move. The warning also appears on step 1, *before* the call is spent:
    there's no point charging someone for an estimate they'll then be told they can't
    apply.
  - **Provenance flips, in BOTH directions.** Applying stamps `MealEntry.reanalyzedAt`
    (migration `Version20260729215328`) and **clears `nutritionEditedAt`**: the numbers are
    a fresh AI estimate again, so the confidence bar is meaningful once more and renders
    with an "AI re-analyzed {date}" note. ⚠️ **The Phase-2 edit path had to be changed to
    match** — a manual nutrient/portion edit now also **clears `reanalyzedAt`**. The two
    stamps are mutually exclusive answers to "where did these numbers come from" and must
    never both be set; without that line a re-analysed-then-hand-corrected meal carried
    both, and the only reason it wasn't user-visible is that the show template's
    `nutritionEditedAt` branch happens to win. Found live-testing (2026-07-29), not by the
    suite — pinned now by the `testAManualNutrientEditAfterwardsClearsTheReanalysisStamp`
    case.
  - ⚠️ **Applying replaces the estimate wholesale** — all 26 nutrients (via the new
    `NutritionData::applyArray()`, the in-place sibling of `fromArray` that keeps the
    OneToOne row's identity instead of orphaning it, and which **nulls a field absent
    from the payload** rather than letting the previous run's figure mix with the new
    one), plus ingredients and `aiDescription`. The submitted description is stored as
    the meal's **notes**, so the next re-analysis starts from it — which also finally
    persists a photo-free meal's own wording, something the log flow only ever folded
    into the prompt and never saved.
  - **The diff is a real service, not template logic:** `App\Service\NutritionDiff` +
    `App\Dto\NutritionDiffRow`, pure and DB-free (the PortionScaler pattern) so the
    comparison the user is asked to approve is unit-testable without a controller or a
    Claude call. `NutritionDiff::ROWS` is the field→unit map (macros, then minerals, then
    vitamins) and covers **every** persisted nutrient on purpose: showing a subset while
    overwriting the rest would hide changes being approved. Rows where neither side has a
    value are dropped; unchanged rows are **kept**, so the table reads as a full statement
    of what applying would do rather than a highlight reel. `added`/`removed` are distinct
    from `up`/`down` (a nutrient the new run can't estimate must not read as a fall to
    zero), and a rise from 0 has a **null** percentage rather than "+100%".
    `hasChanges()` drives an explicit "the AI came back with the same numbers" notice —
    the call still cost a slot, so an empty-looking diff gets said out loud. *(Building
    this surfaced the missing `nutrient_full.phosphorus` key — now added in all six
    catalogs and pinned by a test asserting every compared field has a label, since the
    review table would otherwise print a raw translation key.)*
    ⚠️ **The table needs its mobile override** (a scoped `<style>` in the template, the
    `trends/index.html.twig` idiom): it has four columns and the LAST one carries the
    change, so at 375px the stock `.table-sm` padding pushed exactly that column past the
    `.table-responsive` edge — the page didn't scroll sideways, but the delta was the one
    thing scrolled out of sight. Below the `sm` breakpoint the cells tighten and the
    percentage stacks under the delta (`.diff-pct`). Found live-testing on mobile; if a
    fifth column is ever added, re-check this at 375px.
  - ⚠️ **A parked upload can be swept before it's applied, and applying blind LOSES the
    meal's real photo.** Those files are unreferenced by any `meal_entry.image_path` until
    apply — which is exactly what `app:cleanup-meal-uploads` deletes — and a session can
    outlive its 48 h threshold. `pruneMissingParkedPhotos()` therefore drops vanished paths
    (clearing `replace_photo` when nothing is left) on every review request, so apply can't
    repoint the meal at a missing file *and* `unlink()` its previous valid photo on the way.
    The estimate still applies (it was paid for); only the photo half is abandoned, with a
    warning flash and a `warning` log line. `applyReanalysis()` keeps a redundant `is_file`
    guard because it is the one place that deletes the existing photo. Copilot catch on
    PR #186, pinned by a test that unlinks the parked file mid-flow.
  - ⚠️ **The diff table is deliberately COLOUR-NEUTRAL.** A first draft painted every rise
    red and every fall green, which asserts a good/bad meaning nutrients don't have (more
    protein or fibre is usually wanted, more sodium usually isn't; "newly estimated" is
    neither) — on an approval screen that would read as dietary advice, which this app
    doesn't give. The ▲/▼ arrow carries direction and nothing else does. Copilot catch on
    PR #186; a test asserts the table's HTML contains no `text-danger`/`text-success`.
  - ⚠️ **Confidence renders on `is not null`, never truthiness.** `0.0` is valid under the
    analysis contract and "the AI has no confidence in this" is the reading most worth
    showing, but a truthy check hid the whole line. Same for the *previous* confidence in
    that sentence. (The diff rows were already explicit-null and were never affected.)
    `FakeAnthropicResponder::ZERO_CONFIDENCE` is the trigger marker that pins it.
  - **Photos:** uploads are capped at `MealEntryFormType::MAX_PHOTOS` and land in the
    meals dir with a `meal-reanalysis-` stem. ⚠️ **Leaving the upload empty on a meal that
    HAS a photo is not a description-only run** — the existing photo is still sent, so the
    help text is conditional (`photo_help_reuse` vs `photo_help`); the single string
    promised description-only and the controller never did that (Copilot catch on PR #186). A **`replace_photo`** tick (offered only
    when there IS a photo to replace — with none, an upload simply becomes the meal's, so
    a control would have one possible answer) makes the first upload the meal's own photo
    on apply and deletes the old file; otherwise every upload is an analysis aid and is
    deleted on apply — the log flow's `extra_web_paths` posture. The analysis sends new
    photos **first** (newest information), then the meal's own if it's being kept, sliced
    to `MAX_PHOTOS`; a photo dropped from the *analysis* is still on disk and can still
    become the thumbnail. Abandoned parked photos are swept by the existing
    `app:cleanup-meal-uploads` (unreferenced + old), and starting a second re-analysis
    drops the first one's uploads (`DraftSession::store()` does this for every draft).
  - **Cost + gating:** no new tier gate (the plan's §6 recommendation) — it draws on the
    shared monthly `AnalysisAllowance`, and the **`AnalysisEvent` call-time ledger**
    (`App\Entity\AnalysisEvent` + `App\Enum\AnalysisKind`) is what makes that true, since
    a re-analysis creates no countable row for a derived count to see. Booked only
    **after** the call succeeds (a failed analysis isn't charged — the retry posture) and
    for every tier (the row is also the cost record). Since 2026-10-06 the controller
    books nothing itself: it passes the meal to `FoodAnalysisService` as `reanalysisOf`,
    and `AnalysisAllowance::meter()` books `meal_reanalysis_photo` or
    `meal_reanalysis_description` against it. See Favorite meals ▸ Shared
    Free-tier bucket for the mixed-model warning and the deliberate
    no-refund-on-meal-delete asymmetry. ⚠️ `analysis_event` must be deleted **before**
    `meal_entry` in `AccountDeletionService::deleteUserRows` (and in `DemoDataSeeder`'s
    reset): its meal FK is `SET NULL`, so the rows would otherwise survive as user-scoped
    orphans and then block the `user` delete on their own non-nullable `user_id`.
  - **A meal with no `NutritionData` row gains one** from the re-analysis — the diff
    reads as all-additions, and it's the first estimate that meal has ever had.
  - The AI's questions are shown **read-only**: a single-shot re-analysis has no
    answer-and-refine round (that would be a second billable call), so they explain where
    the confidence sits rather than offering an action.
  - Entry points: a dedicated card on the meal page (its own card, not a peer of
    Edit/Delete, because it's the only meal action that costs a call) and a link from the
    edit page, whose `meal.edit.scope_note` flipped from "isn't supported yet" to a
    pointer at it.
  - Covered by `tests/Service/NutritionDiffTest` (the diff semantics above) and
    `tests/E2E/MealReanalyzeTest`: description round trip → propose → apply (incl. the
    dashboard live-read and notes persistence), manual corrections refused then
    confirmed + the provenance flip, discard leaves the meal alone but still counts,
    replace-vs-aid photo handling with on-disk assertions, the allowance spend +
    exhausted-redirect to /pricing, **delete does not refund a re-analysis slot**, a
    failed call charges nothing, nothing-to-analyze 422, cross-meal proposal refused,
    ownership 404s on both steps, the no-nutrition-row case, the provenance-stamp
    exclusivity round trip, the swept-parked-photo guard, zero confidence still rendering,
    and the colour-neutral change column.
- **Entry points:** navbar **More ▸ Meal History** (`chrome.nav.history`; the Log nav
  item's active-state excludes `app_meal_history` so two tabs don't light up), a
  "View meal history" link on the dashboard's Today's Meals heading **and** in its
  empty state (nothing today ≠ nothing ever), and history rows → detail view.
- **i18n:** `meal.history.*` / `meal.edit.*` / `meal.show.edit` /
  `dashboard.meals.history_link` / `chrome.nav.history` + compact macro abbreviations
  `macro.abbr_{protein,carbs,fat}` (per-locale letters: es P/C/G, de E/K/F, fr P/G/L,
  ru Б/У/Ж) shipped in en + all five full catalogs; the en strings deliberately avoid
  en_GB-divergent words (no "favorite"/"analyze"), so no override was needed. Phase 2
  reshaped `meal.edit.*` in en + all five full catalogs (dropped `time_label`/
  `time_help`; added `when_label`/`when_help`, the three `day_change_*` keys,
  `nutrition_heading`/`nutrition_hint`, `portion_label`/`portion_help`; re-texted
  `lead`/`scope_note`/`future_time`) and added `meal.show.edited`/`nutrition_edited`
  — again with no en_GB-divergent words ("analysis" is spelled the same; the verb
  forms were avoided).
  ⚠️ **Phase 3 broke that streak, unavoidably, and needed the first en_GB overrides
  in this area.** A feature named "Re-analyze" can't dodge the `-yze/-yse` split, so
  the whole `meal.reanalyze.*` block plus four `meal.show.reanalyze*` keys,
  `meal.edit.reanalyze_link` and `nutrient_full.phosphorus` went into en + all five
  full catalogs, and exactly the **seven** keys whose text contains a `-yze` verb form
  (`meal.reanalyze.title`/`heading`/`submit`, `meal.show.reanalyzed`/`reanalyze`/
  `reanalyze_cta`, `meal.edit.reanalyze_link`) got en_GB twins. Copy that says
  "analysis"/"re-analysis" (the noun) deliberately did **not**, since that spelling is
  shared — which is why the override list is seven keys and not forty.
- Covered by `tests/E2E/MealHistoryTest` (in-process `WebTestCase`: own-meals-only list
  + ordering + detail/edit ownership 404s, type/date filters + junk tolerance,
  pagination split + filters surviving the page boundary, favorite-from-history with
  a **zero-requests assertion on the mocked Anthropic client**, edit round-trip incl.
  date-unchanged + dashboard live-read, future-time 422 + unchanged row) and
  `tests/E2E/MealEditPhase2Test` (nutrient edit + provenance stamps + confidence-bar
  swap + dashboard live-read, blank-keeps-value / untouched-resave-stamps-nothing,
  portion rescale incl. amount-less ingredient pass-through + unscaled confidence,
  cross-day move refused without the confirmation then — confirmed — rebucketing
  the plan's two named boundary subsystems (`GoalMetricCalculator::dailyValues` and
  `InflammationScoreService::dailyScores`, the `CorrelationAnalyzer` score-by-date
  input, both keyed by local day), and the delete-refunds-allowance posture pin).
