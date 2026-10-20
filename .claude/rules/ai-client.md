---
description: "The AiClient seam every AI call goes through: AiFeature, the provider config (Anthropic or any OpenAI-compatible endpoint), the two clients, AiResult, the per-call cost record (ai_call, MeteredAiClient, ModelPrices, app:ai-costs), users' own keys (Settings > AI provider, ResolvingAiClient, UserSecretBox), the vision benchmark, and how tests fake it."
paths:
  - "src/Ai/**"
  - "src/Entity/AiCall.php"
  - "src/Repository/AiCallRepository.php"
  - "src/Command/AiCostsCommand.php"
  - "src/Entity/UserAiSettings.php"
  - "src/Repository/UserAiSettingsRepository.php"
  - "src/Security/UserSecretBox.php"
  - "src/Controller/AiProviderSettingsController.php"
  - "templates/settings/ai.html.twig"
  - "resources/vision-benchmark/**"
  - "src/Command/AiBenchmarkCommand.php"
  - "src/Twig/AiProviderExtension.php"
  - "src/Dto/BenchmarkCaseResult.php"
  - "tests/Ai/**"
  - "tests/Http/FakeAnthropicResponder.php"
  - "config/services.yaml"
  - "config/services_test.yaml"
  - ".env"
---

# AI client — behavioral notes

Owns the AI-call seam (ADR 0003). Update this file in the same PR as any change to it.

## The `AiClient` seam (`src/Ai/`)
- **Every AI call depends on `App\Ai\AiClient`**, never on a provider class (2026-10-12,
  App Development Plan PR 1). Two shapes of call: `complete()` for a text conversation
  (system prompt + alternating turns: goal setup and evaluation, the help assistant, the
  insight narratives) and `completeWithImages()` for one user turn of images then prompt
  with **no system prompt** (meal, recipe and supplement analyses).
- **Callers name an `AiFeature` and the `?User`, never a model** (PR 2, user added in
  PR 3). Every call's first argument is
  what it is for — `MealAnalysis`, `RecipeAnalysis`, `SupplementScan`, `GoalSetup`,
  `GoalCheckin`, `HelpAssistant`, `Predict`, `GlucoseCoach` — and the client picks the
  model: **light** features (`isLight()`, only `HelpAssistant`) get the light model,
  everything else the main one. `needsVision()` marks the three photo features (for the
  benchmark gate to come). Per-user model choice (PR 4) and the per-call cost record
  (PR 3) key on the same enum. A test that matches `complete()` arguments by position
  therefore starts with `self::identicalTo(AiFeature::…), self::isInstanceOf(User::class)`,
  which also pins the feature. The user is null only for an anonymous help question.
- **The provider is instance configuration** — the `app/ai-provider` block in `.env`,
  read by `AiClientFactory` (wired as `AiClient`'s factory in `services.yaml`):
  - `AI_PROVIDER=anthropic` (the default, and what a blank value means) →
    **`AnthropicClient`**, keyed by `ANTHROPIC_API_KEY` as before. `AI_MODEL` /
    `AI_MODEL_LIGHT`, when set, replace `AnthropicClient::MODEL` (`claude-sonnet-4-6`) /
    `LIGHT_MODEL` (`claude-haiku-4-5-20251001`); left blank, the hosted service runs
    exactly the models it always has.
  - `AI_PROVIDER=openai_compatible` → **`OpenAiCompatibleClient`**: any
    `{AI_BASE_URL}/chat/completions` endpoint — OpenRouter, OpenAI, or a self-hosted
    Ollama `/v1`. `AI_BASE_URL` and `AI_MODEL` are required (the constructor throws
    otherwise); `AI_API_KEY` is optional (blank sends no `Authorization` header, which
    is what a local Ollama wants); `AI_MODEL_LIGHT` falls back to `AI_MODEL`. The system
    prompt becomes a `role: system` first message; images go as `image_url` parts with
    base64 data URIs, then the prompt as a `text` part; replies are read from
    `choices[0].message.content` (a list of parts is joined) with
    `usage.prompt_tokens` / `completion_tokens`. Verified live against a local Ollama
    (`qwen2.5:7b`, text) on 2026-10-12.
  - Anything else is an `InvalidArgumentException` naming the two valid values.
  - ⚠️ **The factory receives Anthropic as a `!service_closure`**, so an instance on
    another provider never builds `AnthropicClient` and never needs `ANTHROPIC_API_KEY`
    — which `.env` deliberately doesn't define. Don't "simplify" it to a plain service
    argument.
  - A real `AI_API_KEY` goes in `.env.local`, never `.env`.
- **`App\Ai\AnthropicClient`** is the Messages API implementation (moved from
  `App\Service\ClaudeClient`, behavior unchanged): raw HTTP via Symfony HttpClient,
  **no SDK**; a whole-call cap of 120 s so a stuck upstream can't pin a php-fpm worker
  (`OpenAiCompatibleClient` uses the same cap).
- **`App\Ai\ImageInputs::load()`** is the photo half both clients share: blank,
  missing and **unreadable** paths are dropped (a file that exists but can't be read used
  to make the encoder throw and sink the readable photos sent with it — Copilot catch on
  PR #241, pinned by `ImageInputsTest`), a call with none left throws **before any
  request** (nothing is charged), and each image comes back as bytes + MIME through
  `ApiImageEncoder` (oversized photos downscaled for the request only). Only the wire
  format differs per provider.
- **`App\Ai\AiResult`** (moved from `App\Dto\ClaudeResult`) carries the reply text and
  input/output tokens; `json()` is the shared tolerant decoder (code fences and
  surrounding prose are stripped back to the outermost `{ … }`). A reply cut off by
  `max_tokens` fails here as invalid JSON, which every caller already treats as a
  failed analysis.
- **Lazy injection works on the interface.** `HelpAssistantService`,
  `GlucoseInsightsService` and `PredictiveInsightService` take
  `#[Autowire(lazy: true)] AiClient`, so a page that never makes a call never runs the
  factory, builds a client, or resolves a key.
- **Tests:** unit tests stub or mock `AiClient` directly; tests that need the real
  request shape build the client over a recording `MockHttpClient`
  (`tests/Ai/AnthropicClientTest`, `OpenAiCompatibleClientTest`, `AiClientFactoryTest`).
  In the `test` env the provider is Anthropic and `config/services_test.yaml` gives
  `AnthropicClient` a `MockHttpClient` whose responses come from `FakeAnthropicResponder`,
  which routes on the request shape — so every AI surface in the E2E suite is faked by
  that one swap.

## The cost record (`ai_call`, PR 3, 2026-10-12)
- **Every AI call that comes back writes one `ai_call` row**: user (null for an anonymous
  help question), `AiFeature`, provider (`anthropic`, or the OpenAI-compatible endpoint's
  host such as `openrouter.ai` or `127.0.0.1:11434`), the model sent, input and output
  tokens, an estimated `cost_micros`, and `occurred_at` in the **server frame** (operator
  data compared across users, like the scheduling instants in `timezones.md`). The
  clients stamp provider and model on `AiResult`; nothing else supplies them.
- **`MeteredAiClient` decorates `AiClient`** (`decorates:` in `services.yaml`), so no
  caller can skip recording and the E2E suite records for real. Only calls that return
  are recorded: a call that throws used nothing to bill. ⚠️ It inserts with **plain
  DBAL**, never the ORM, so recording can't flush a caller's half-finished unit of work
  (a check-in mid-evaluation, a meal mid-save); the `AiCall` entity exists for the
  schema and for reading. A failed insert is logged (`Could not record AI call cost`)
  and swallowed: losing a cost row must never turn a paid-for answer into an error.
- **It is measurement, not the allowance** (ADR 0003): the Free allowance still counts
  per ADR 0001 (derived counts + the `analysis_event` ledger), and nothing reads
  `ai_call` to refuse a call.
- **`ModelPrices::PER_MILLION_TOKENS`** is keyed by **provider, then exact model id**,
  and holds Anthropic list prices under `anthropic` (Sonnet 4.6 $3/$15, Haiku 4.5 $1/$5,
  plus the current Sonnet 5.5 and Opus 5.5). ⚠️ Provider first on purpose: an
  OpenAI-compatible proxy or router can pass an Anthropic model id through at its own
  price, and pricing by model id alone gave those calls Anthropic's rate (Copilot catch
  on #242, pinned in `ModelPricesTest` and `MeteredAiClientTest`).
  `costMicros()` = input × input price + output × output price, in micro-dollars so a
  sub-cent call stays an exact integer. ⚠️ A model **not** listed (an OpenRouter route,
  a local Ollama model) gets `cost_micros = NULL`, never a guess; the report counts
  those calls separately. Update the table when a price changes — the Full tier's
  at-cost price is checked against it.
- **`php bin/console app:ai-costs [--month=YYYY-MM]`** (`AiCostsCommand`, default the
  current server month): calls, distinct users, tokens and estimated cost per feature,
  provider and model, biggest cost first, with a total and a note when unpriced calls
  are left out. Query: `AiCallRepository::totals()`, half-open `[from, to)`.
  The instance's own rows and total are labelled with `Brand::name()` ("NouriLoop" when
  hosted, the operator's name or domain on a self-hosted copy), never a literal.
- **Account deletion anonymizes, it doesn't delete** (John's call, 2026-10-12): `user_id` is `ON DELETE SET NULL`
  (migration `Version20261013010232`), the deliberate exception recorded in
  `settings-and-account.md`.
- Covered by `tests/Ai/MeteredAiClientTest` (recorded with cost, unpriced → null,
  recording failure swallowed, a thrown call records nothing), `ModelPricesTest`,
  provider/model stamping cases in both client tests, `tests/Command/AiCostsCommandTest`,
  and `HelpAssistantTest::testAnAnsweredQuestionIsRecordedAsAnAnonymousAiCall` (the whole
  path, factory → decorator → DBAL → table).

## Users' own keys (Settings ▸ AI provider, PR 4, 2026-10-12)
- **`/settings/ai`** (`AiProviderSettingsController`, Security & API category of the hub,
  `settings/ai.html.twig`): "NouriLoop's AI (default)", **my Anthropic key**, **my
  OpenRouter key**, or **another OpenAI-style service** (base URL + key). Two model slots
  (John, Q8b): **text** (every text feature, help included) and **photo** (meal, recipe,
  supplement); blank = "Let NouriLoop choose" — **one** model for every feature, help
  included (on Anthropic too: not the instance's Haiku; Copilot catch on #243)
  (`UserAiProvider::defaultModel()`:
  `claude-sonnet-4-6` on Anthropic, `anthropic/claude-sonnet-4.6` on OpenRouter — checked
  against OpenRouter's live model list, reads images); a blank photo slot is the text
  model; a custom service has no default, so its text model is required. **Open to every
  user** (John, Q8a). Since the tier rework (PR 7, `billing.md`) a saved key also **lifts a
  paid user's monthly analysis allowance** (`AnalysisAllowance::kind()` asks the same
  `findForUser()` this client does) — Loop is the $2 bring-your-own-key plan; a Free user's
  key runs their calls but leaves Free's allowance in place.
  Choosing "NouriLoop's AI" deletes the row. "Test connection" (`POST /settings/ai/test`,
  CSRF `settings-ai-test`, `ai_connection_test` limiter 10/hour/user) makes one tiny call
  through the full `AiClient` chain (`AiFeature::ConnectionTest`), so it tests exactly
  what real calls will use and is recorded like them; 401/403 → "rejected", transport
  failure (incl. the private-network guard) → "couldn't reach", else a generic failure.
- **`UserAiSettings`** (table `user_ai_settings`, migration `Version20261013012902`,
  unique per user, FK `ON DELETE CASCADE`): provider, base URL (custom only), encrypted
  key, `key_hint` (last four), text/vision model, `updatedAt`. "Start fresh" keeps it
  (like API tokens); an account purge removes it through the cascade.
- **The key is write-only.** `App\Security\UserSecretBox` encrypts with libsodium
  secretbox under **`USER_SECRETS_KEY`** (base64 of 32 bytes, `.env.local`; deliberately
  not `APP_SECRET`), stored as `v1:` + base64(nonce ‖ ciphertext). The page shows only
  "•••• abcd"; a blank key field on save keeps the stored key **only while the form still
  names the same service** (`sameService()`: same provider and, for a custom service, the
  same address, trailing slash and case aside). ⚠️ Switching provider **or pointing a
  custom service at a new address** requires a new key, so a saved secret is never sent
  to a service it wasn't issued for (Copilot catch on #243); the "leave blank to keep"
  hint and the •••• placeholder follow the same rule (`keyOnFile`). Nothing logs a key (the test-connection log line carries the
  exception class and status only). ⚠️ **Without `USER_SECRETS_KEY` the page says the
  feature isn't available** rather than failing — set it on prod before announcing it,
  and **never change it once keys are stored** (old keys would stop decrypting). If it
  does go missing while users have keys saved, their AI calls fail (no fallback, by
  design), so the page then still offers a **"Use NouriLoop's AI"** button (`#ai-reset`)
  that removes their settings — never strand a user without a way back.
- **Resolution — `ResolvingAiClient`** decorates `AiClient` at `decoration_priority: 10`,
  i.e. *inside* `MeteredAiClient`: a call for a user with settings is built by
  `UserAiClientBuilder` against their provider and key and comes back
  `AiResult::withUserKey()`; anything else (no user, no settings) goes to the instance
  client unchanged. ⚠️ **A failing user key fails the call — it never falls back to
  NouriLoop's key**, which would bill us for a user who chose to pay their own way.
- **`ai_call.user_key`** marks those calls; `app:ai-costs` lists them with "Paid by: user"
  and keeps them **out of NouriLoop's total** (the number the Full price is checked
  against), with a note giving their count.
- ⚠️ **Custom endpoints are an SSRF surface, guarded twice** (John, Q8c): on save the URL
  must be **https** (the key rides in every request) and must not name `localhost` or a
  private/loopback/link-local IP literal (`IpUtils::PRIVATE_SUBNETS`, which since
  symfony/http-foundation 7.4.21 also covers the RFC 5737/3849 documentation ranges, so tests
  use ordinary public literals as their "public" example); at request time the
  custom client goes through Symfony's **`NoPrivateNetworkHttpClient`**, which resolves
  the host itself, refuses private and reserved addresses, and pins the request to the
  address it checked (DNS rebinding included). The save-time check is UX; the runtime
  guard is the protection — `AiProviderSettingsTest` bypasses the form to prove it.
  Instance-level config (`AI_BASE_URL`, operator-set) is not wrapped, so a self-hoster
  can still use `http://localhost:11434/v1`.
- **Clients gained a vision slot**: both take `$visionModel` ('' = main model), and
  `needsVision()` features use it; light features keep the light model.
- **Tests:** `FakeAnthropicResponder` now also answers **chat-completions** requests
  (system message lifted out, `image_url` parts treated as images, reply in the OpenAI
  envelope) and returns **401 for `REJECTED_KEY`** in any auth header. Covered by
  `tests/E2E/AiProviderSettingsTest` (encrypted at rest + hint only, calls use the user's
  key and record `user_key`, rejected key never falls back, OpenRouter default model, the
  https/private/model save rules, the runtime guard with the form bypassed, blank keeps /
  switch needs a key, a custom service's new address needs a new key, a failed switch
  doesn't promise to keep the key, the way back when keys can't be read, removal + purge,
  hub link), `ResolvingAiClientTest`,
  `UserAiClientBuilderTest` (defaults, slots, private address refused before any
  request), `UserSecretBoxTest` (round trip, tamper, wrong key, unconfigured) and the
  `user_key` case in `AiCostsCommandTest`.

## The vision benchmark (PR 5, 2026-10-12)
- **`App\Ai\VisionBenchmark`**: five reference photos in **`resources/vision-benchmark/`**,
  John's own logged meals (Plan Q9), published with **all metadata stripped**
  (re-oriented, downscaled to 1600 px, EXIF/GPS/XMP removed, the pizza photo cropped to
  drop a strip of clothing). ⚠️ `VisionBenchmarkTest::testTheFixturesExistAndCarryNoMetadata`
  fails if a replacement photo brings GPS or camera data back — strip any new one with
  `magick in.jpg -auto-orient -resize '1600x1600>' -strip -quality 85 out.jpg`.
  - Three **label** cases (pizza 1/3-pizza serving, protein bar photographed sideways,
    gumbo container): calories, protein, carbs, fat, fiber and sodium each within **10 %**
    of the printed value, with absolute floors (15 kcal, 2 g, fiber 1.5 g, 60 mg) so small
    lines don't fail on rounding (10 % really is every nutrient: fiber was 20 % until a
    Copilot catch on #244, pinned by `testEveryLabelNutrientUsesTheDocumentedTenPercent`). Values were read from the photos themselves, not from
    the logged meals (those include servings eaten: the pizza was logged as 990 kcal =
    three servings). The gumbo's carbs line is half hidden; 32 g is inferred from "12% DV".
  - Two **estimate** cases (blueberries — USDA 84 kcal per 148 g cup; lox bagel — the
    logged 620 kcal estimate): calories within **40 %**.
  - A model **passes when every case passes**. `check()` is pure; `runCase()` makes one
    call through `FoodAnalysisService::analyzeForBenchmark()` — the **real base meal
    prompt**, one photo, English, no notes/context, `AiFeature::VisionBenchmark` (a vision
    feature, recorded like any call) and **no allowance spend** (it's the user checking
    their own key, not a meal being logged).
- **Results live on `user_ai_settings`** (`benchmark_model`, `benchmark_results` JSON keyed
  by case, `benchmark_ran_at`; migration `Version20261013021703`), valid only for the
  photo model they were run against: `effectiveVisionModel()` = photo slot, else text slot,
  else the provider default. Changing it makes old results disappear (`getBenchmarkResults()`
  returns nothing for another model). `benchmarkPassed()` is null until every case has a
  result.
- **When it runs** (John, Q10): **automatically when a save changes the photo model or the
  service it runs on** (provider, custom address, effective photo model — saving only a
  key never triggers it; the save sets an `ai_benchmark_start` flash and the page's inline
  JS starts the run), and from **"Check my photo model"**. ⚠️ **One photo per request**
  (`POST /settings/ai/benchmark/{index}`, JSON, CSRF `settings-ai-benchmark`): five model
  calls in one request can outlast php-fpm/nginx timeouts on a slow model. The JS runs them
  in turn with "N of 5" progress and reloads; `POST /settings/ai/benchmark` is the
  no-JavaScript fallback that runs all five at once. ⚠️ **Photo 0 starts a fresh run**
  (clears stored results) on both routes, so a rerun that stops partway can never yield a
  verdict assembled from two runs (Copilot catch on #244). The progress line is a
  `role="status"` / `aria-live="polite"` region, so screen readers hear "N of 5". Limiter `ai_benchmark`: 20 photos an
  hour per user. Both routes need the user's own settings (404 / redirect otherwise, so it
  can never spend NouriLoop's key).
- **A failure warns, never blocks** (John, Q10): the settings card lists each missed
  nutrient ("Calories: expected 330 kcal, got 990 kcal"), and `photo_model_failed_benchmark()`
  (`App\Twig\AiProviderExtension`) shows `#photo-model-warning` on the meal review
  (`meal/clarify.html.twig`). Photo analysis keeps working.
- **`php bin/console app:ai-benchmark`** runs the five cases against the **instance's** photo
  model (AI_PROVIDER / AI_MODEL, no user) and exits non-zero on a failure. ⚠️ **Run it on
  prod before telling users about the check**: the thresholds were set from the labels, not
  calibrated against a live model (no key on the dev box), and NouriLoop's own model should
  pass its own gate.
- **Tests:** `FakeAnthropicResponder` recognizes a benchmark photo by the SHA-1 of its bytes
  (both wire shapes; chat-completions `image_url` data now survives the translation) and
  answers with that case's reference values, or **50 % high when the model name contains
  `BENCHMARK_FAIL_MODEL`**. Covered by `tests/Ai/VisionBenchmarkTest` (tolerances, floors,
  estimates, blank nutrient, fixture metadata guard), `tests/Entity/UserAiSettingsBenchmarkTest`
  (photo-model fallback, verdict, stale results) and `tests/E2E/AiBenchmarkFlowTest` (auto-start
  on a new photo model but not on a key change, pass and fail through the JSON route, the
  meal-review warning present/absent, the no-JS fallback, no-settings 404, and the operator
  command passing on the instance model).
