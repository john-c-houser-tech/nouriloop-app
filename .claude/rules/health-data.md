---
description: "Health-metric ingestion (every source through HealthMetricIngestion), the import page, the API and token auth, units, repair commands, and the Connect your CGM wizard."
paths:
  - "src/Service/HealthMetric*.php"
  - "src/Service/Health*Importer.php"
  - "src/Service/HealthJsonImportResolver.php"
  - "src/Service/HealthUnitNormalizer.php"
  - "src/Service/AppleHealthXmlImporter.php"
  - "src/Service/ApiTokenManager.php"
  - "src/Service/CgmSetupStatusService.php"
  - "src/Controller/HealthMetricsController.php"
  - "src/Controller/ConnectCgmController.php"
  - "src/Controller/Api/HealthMetricApiController.php"
  - "src/Dto/Reading.php"
  - "src/Dto/CgmSetupStatus.php"
  - "src/Enum/HealthMetricType.php"
  - "src/Enum/MetricSummary.php"
  - "src/Entity/HealthMetric.php"
  - "src/Entity/ApiToken.php"
  - "src/Command/FixGlucoseUnitsCommand.php"
  - "src/Command/FixSleepOutliersCommand.php"
  - "scripts/apple-health-sync.php"
  - "templates/health_metrics/**"
  - "templates/connect_cgm/**"
---

# Health metrics and the CGM wizard — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Health metrics (import + ingestion API)
Seven metric types (weight, blood glucose, BP systolic/diastolic, heart rate, body
fat %, **steps**) plus five sleep stages, stored in `health_metric`. Dedup is enforced by the
**`uq_health_metric`** unique constraint (`user_id, metric_type, recorded_at`) via
**`INSERT IGNORE`**, so every ingestion path is safely re-runnable.

- **One way in — `App\Service\HealthMetricIngestion` (2026-10-06).** Every source
  (Apple Health XML, the health-export JSON apps, the ingestion API, CSV) parses its format
  into `App\Dto\Reading`s (type, value **in the source's own unit**, that unit or `''`,
  the source's wall-clock time, source name) and hands them to
  `ingest(User, iterable<Reading>, ?UnitSystem)`. Ingestion owns everything about
  storing them: canonical units (`HealthUnitNormalizer`), the stored precision
  (`HealthUnitNormalizer::round()`: steps whole, everything else 2 decimals; before
  this the XML import and the API stored whatever was sent), the 100-character source
  cap (characters, not bytes), and the wall-clock format. It also **refuses implausible
  readings for every source** (counted as skipped, with a "Skipped {type} at {time}:
  {reason}." message): a negative value, a zero for anything but steps (a zero-step day
  is real; a zero weight or heart rate is a missing reading), a sleep row over 960
  minutes (16 h), and a time more than a day past the user's local now. Before
  2026-10-06 the only bound anywhere was the XML import's 0 < segment ≤ 720 minutes
  (skipped silently), and the API took anything, negative sleep included; the XML cap
  rose to 960 because a Health Export in-bed total can pass 12 h on a long night.
  **"Skipped" means a reading that should have been stored and wasn't**: a bad value or
  date, an implausible reading, or a duplicate. Data ignored on purpose is not a skip:
  types NouriLoop doesn't track, workouts, Awake sleep segments, and steps in an Apple
  Health export. (Until 2026-10-06 the XML import counted all of those, so a typical
  export reported thousands of "skipped" records.) In a CSV, a type or column NouriLoop
  doesn't track and a blank columnar cell are ignored, while a value that isn't a
  number is a skip reported with its row (and column); before, the row format skipped
  untracked types silently, and the columnar format dropped bad cells uncounted. The API still reports an unknown
  `type` as a skip with a reason, because there it is a malformed request, not extra data.
  `HealthMetricWriter` is now its private batch insert
  (500 rows per `INSERT IGNORE`). Readings are consumed lazily, so a huge import
  streams (never materialised in memory). The `UnitSystem` argument is the unit an
  **unlabelled** weight is assumed to be in, and only a source with no units of its own
  passes it (CSV). The importers are parsers and nothing more. ⚠️ CSV used to skip the
  writer with its own one-row-per-statement `INSERT IGNORE`, and counted a duplicate
  row as **imported**; since it moved onto ingestion it batches like every other source
  and a duplicate is a skip. Covered by `tests/Service/HealthMetricIngestionTest` and
  the parser tests (`AppleHealthXmlImporterTest`, `HealthMetricCsvImporterTest`, the
  two JSON importer tests, `HealthMetricApiParserTest`).

- **Web import** (`HealthMetricsController::import`, `/health-metrics/import`):
  **upload-only** — Apple Health XML (`AppleHealthXmlImporter`), **health-export JSON**
  (any registered `HealthJsonImporter`, routed by `HealthJsonImportResolver`), or CSV
  (`HealthMetricCsvImporter`). ⚠️ It's a hand-rolled multipart form, not the Form
  component, so it carries its own CSRF token (`health-import`, checked first; a miss
  flashes `common.session_expired` and re-renders) — until 2026-09-30 it was the only
  state-changing POST with no CSRF check at all. The broad format is auto-detected by extension/content
  (`detectFormat`), and a JSON file's specific app is auto-detected with an optional
  **"Source app"** dropdown override (`json_app`). The upload is bounded by nginx
  `client_max_body_size` (prod: 100M) and PHP `upload_max_filesize`/`post_max_size`;
  a real `export.xml` (often 100s of MB) **won't fit** — it's silently rejected at
  nginx (413, before PHP), so app logs stay empty. Use the API + sync script below
  for large histories. (The old **"local file path"** option — which read a file off
  the *server* filesystem, a single-user local-Mac convenience — was **removed**: in
  a multi-user deployment it let any account read arbitrary server-readable files.
  Import runs with a finite `memory_limit` of 512M, not unlimited.)
- **Ingestion API** (`POST /api/health-metrics`, `HealthMetricApiController`):
  bearer-token JSON for mobile apps / scripts. Body `{ "metrics": [ {type, value,
  unit?, recordedAt, source?}, … ] }`; `type` ∈ `HealthMetricType` values,
  `recordedAt` ISO-8601, optional `unit` (see Unit normalization below). `HealthMetricApiParser` validates per
  record (bad rows skipped with a reason, not a whole-batch failure), max 1000/req.
  Returns `{imported, skipped, errors}`.
- **Health-export JSON ingestion** (`POST /api/health-metrics/health-export`,
  `HealthMetricApiController::healthExport`): accepts a health-export app's JSON
  **verbatim** (5 MB cap). `HealthJsonImportResolver` picks the importer by
  auto-detection, or by an explicit `?app=<key>` override (the CLI's `--app=`); on no
  match it 422s asking for the app. Returns `{imported, skipped, errors}`.
- **Two ways to feed this endpoint:** (1) the **sync CLI/cron** posts an exported
  file (works for every supported app via auto-detect — the portable path); (2)
  **Health Auto Export's built-in REST API automation** POSTs straight from the phone
  — no Mac/cron. Configure the automation with URL
  `https://app.nouriloop.com/api/health-metrics/health-export`, header
  `Authorization: Bearer <token>`, **JSON** format, on a schedule. **Summarize Data is the
  lever that matters for glucose: OFF sends every reading** (the wizard's documented working
  config, verified against the live "NouriLoop Blood Glucose" automation 2026-07-03: JSON,
  v2, Date Range Default, Summarize off, Batch Requests on, once-daily cadence); **ON**
  collapses to grouped summaries (the Time Grouping row only appears while it's on) — those
  import fine but break postprandial curves (see the CGM data caveat under Postprandial
  glucose, and the Connect your CGM wizard, which detects exactly this misconfiguration). The importer also accepts disaggregated heart-rate (`Avg`→`qty`
  fallback). HAE's "Batch Requests" splits large payloads into several POSTs — each is
  a valid envelope and dedup makes overlaps safe.
- **Pluggable importers** — each app is one `HealthJsonImporter` implementation
  (`key()`/`label()`/`supports()`/`import()`), auto-tagged via `_instanceof`
  (`app.health_json_importer` in `services.yaml`) and collected by the resolver, so
  **adding an app is a single drop-in class** with no other changes. Each yields
  `Reading`s to `HealthMetricIngestion`, which gives every importer units, rounding,
  dedup and batching for free.
  - **`HealthAutoExportJsonImporter`** (`key: health-auto-export`) — the "Health Auto
    Export – JSON+CSV" app: `data.metrics[]` list, each `{name, units, data[]}`. Maps
    `blood_glucose` (mg/dL; mmol/L→mg/dL), `weight_body_mass` (lb→kg), `heart_rate`
    (uses `Avg`; `resting_heart_rate` ignored to avoid a same-timestamp collision),
    `step_count`→steps, `blood_pressure_systolic`/`_diastolic`, and `sleep_analysis`
    (hours→minutes: inBed + Core/Deep/REM, awake/legacy-asleep excluded). Dates are
    local-with-offset → wall-clock stored (offset dropped, like the XML importer);
    sleep `recorded_at` is `sleepStart`. Workouts + untracked metrics ignored.
  - **`HealthExportJsonImporter`** (`key: health-export`) — the "Health Export" iPhone
    app: root `exportInfo` envelope + camelCase metric keys, each a list of
    `{date, unit, value}` daily records (`sleepTime` carries per-stage minutes). Maps
    `weight` (lb→kg via the record's `unit`), `bodyFat`→body fat % (0–1 fraction scaled
    up), `restingHeartRate`→heart rate, `stepCount`→steps, and `sleepTime.stages`→
    SleepInBed (sum incl. awake) + Core/Deep/REM (minutes; awake excluded, no
    synthesized Asleep). Dates are local, date-only → stored at **noon local** for a
    stable dedup key. Unmapped types (calories, BMI, VO₂max, dietary — and **no blood
    glucose/pressure**, absent from this exporter) are ignored.
- **Auth:** a stateless **`^/api`** firewall (`config/packages/security.yaml`, listed
  before `main`) using Symfony's `access_token` authenticator + `ApiTokenHandler`,
  which hashes the bearer token and looks it up. **`ApiToken`** (per-user, revocable)
  stores only the **SHA-256 hash**; the plaintext (`nutri_<64 hex>`) is shown once.
  Manage at **Settings ▸ API tokens** (`/settings/api-tokens`, `SettingsController`)
  — create/copy-once/revoke; `lastUsedAt` is stamped on auth (≤1 write/min).
- **Sync CLI** (`scripts/apple-health-sync.php`): standalone PHP CLI (no app deps),
  auto-detects the input format from `--file`. **XML** → streams `export.xml` and
  POSTs batches to `/api/health-metrics` (mirrors the XML importer's mapping,
  `--batch` ≤1000), bypassing the upload limit. **JSON** → posts the Health Export
  file **verbatim** to `…/health-export` (server owns the mapping); `--file` may be a
  directory, in which case the newest `*.json` is picked — handy for a nightly cron
  pointed at the app's Dropbox/iCloud export folder. `--app=<key>` forces a source app
  (else auto-detected). Common flags: `--url= --token=` (or `NOURILOOP_TOKEN`, with
  `NUTRILOG_TOKEN` still accepted as a fallback), `--dry-run`. Server dedups, so
  re-running is safe.
- **Display** (`HealthMetricsController::index`) and Trends use bucketed aggregate
  queries (`HealthMetricRepository::getAggregates`); `uq_health_metric` doubles as their
  covering index. **Admin user-delete** removes `health_metric` rows too (FK-safe order).
- **Per-type bucket summary (`App\Enum\MetricSummary`, via `HealthMetricType::summary()`).**
  `getAggregates` summarizes each type per its `MetricSummary` rather than always
  averaging: **Steps → `Total`** (SUM — a *daily total*, correct even with multiple
  samples/day; min/max collapse so no range band — fixes steps previously shown as an
  average), **weight / body fat → `Reading`** (the day's measurement), **glucose / heart
  rate / blood pressure → `Average`** (mean + a meaningful min/max range). The same enum
  drives the card sublabel (`bucketLabel()` → "daily totals" / "daily readings" / "daily
  averages, range shaded"), so the number and its label can't drift. Because Trends shares
  `getAggregates`, its **Steps** Insights tile now reads the daily total too.
- **Sleep storage & nightly totals.** Sleep is stored as **per-segment rows in minutes**
  (one `value` per stage; `recorded_at` = segment start), separate from the displayed
  total. `HealthMetricRepository::getSleepNightlyData` buckets segments into nights on a
  **noon boundary** (`recorded_at − 12h`), sums by **tier priority stages
  (core+deep+rem) → asleep → in-bed** via `COALESCE` (so a night with both granular
  stages *and* an InBed record isn't double-counted), `/60` → hours, dropping nights
  < 0.5 h. Surfaced on Trends only.
- **Unit normalization — `App\Service\HealthUnitNormalizer` (2026-09-30).** Every
  ingestion path (web XML/CSV/JSON, the API, and so the sync CLI) converts to the
  canonical storage units through this one static helper: weight lb/lbs/pounds → kg;
  glucose `mmol…` → mg/dL (×18.0182 — **prefix** match, because Apple's `export.xml`
  labels it `mmol<180.1558800000541>/L`); an **unlabelled** glucose value below
  `GLUCOSE_MMOL_CEILING` (35) is taken as mmol/L (real mg/dL readings are ~40–600,
  mmol/L ~2–33); body fat ≤ 1.5 is a 0–1 fraction and is scaled to percent (Apple /
  HealthKit report fractions — the XML path stored them raw before). Before this,
  each importer had its own copy and the API normalizer + XML importer converted only
  lb, so **mmol/L glucose was stored as mg/dL**, poisoning postprandial curves and
  rankings. `UnitConverter` is the display-side inverse, sharing the factors.
  - **CSV** has no units of its own, so in order: a `unit` column (row format), a unit
    in the column header (`weight (lb)`, `glucose_mmol`, `weight lbs`, …; underscores
    in headers are now read as spaces, so `blood_glucose`/`heart_rate` columns map too),
    else the user's `UnitSystem` for an unlabelled **weight** (imperial → lb — the
    old behaviour stored a US user's 180 lb as 180 kg). CSV also maps **`steps`** /
    `step count` columns now.
  - ⚠️ **The Apple XML importer deliberately still skips steps.** `export.xml` holds
    per-device step *samples* (iPhone and Watch both record the same walk), and Steps
    are summarized by SUM — importing them would double-count every Watch wearer's
    day. Apple dedups across sources itself; we'd have to re-implement its source
    priority. HAE/Health Export send per-day totals, which is why their steps are fine.
  - Covered by `tests/Service/HealthUnitNormalizerTest`, a mmol case in
    `HealthMetricApiParserTest`, and `tests/E2E/HealthImportUnitsTest` (CSV unit
    column / header units / imperial default / steps; XML Apple mmol string + body-fat
    fraction; the repair command below).
- **Data repair — `app:fix-glucose-units`** (`--user=<email>`, `--apply`): repairs
  glucose rows stored in mmol/L as if mg/dL before the fix above. **Reports by default**
  (per-user row count, min/max, sources); `--apply` multiplies rows in
  [`MIN_CONVERTIBLE` 1.95, 35) by 18.0182. The 1.95 floor keeps `--apply` idempotent (a
  converted row always lands ≥ 35, so a second run finds nothing); rows below it are
  implausible in either unit and are reported, never touched. John's call (2026-09-30):
  a report-then-apply command, run on prod after reviewing the report. ⚠️ Body-fat rows
  the XML path stored as fractions (e.g. 0.25) are **not** repaired by it.
- **Data repair — `app:fix-sleep-outliers <email>`** (`--threshold=10` hours, `--dry-run`):
  fixes implausibly long nights left by early mis-configured imports. Computes the user's
  **average nightly hours from the nights *below* the threshold**, then for each
  over-threshold night **deletes that night's sleep segments** and writes a single
  `sleep_asleep` row (`source='corrected-avg'`) equal to the average at the night's onset —
  which is exactly what `getSleepNightlyData` reads back. Transactional; errors out if
  there's no normal night to average from. Covered by `tests/E2E/FixSleepOutliersTest`
  (in-process `WebTestCase` + `CommandTester`: the **apply** path replaces the outlier &
  leaves normal nights, dry-run writes nothing, all-bad → failure).

## Connect your CGM wizard (`/connect-cgm`)
A guided "Connect your Lingo/Stelo" setup (roadmap item elevated 2026-07-03: CGM correlation
is the #1 differentiator, but the HAE + token + REST-automation setup was a conversion cliff
for the 45–70 audience). **One long page, all steps visible** (`ConnectCgmController`,
`templates/connect_cgm/index.html.twig`): intro (what you need, honest about HAE possibly
needing its own paid tier) → install HAE (App Store link + QR) → create token → configure the
REST automation (exact values with copy buttons) → send a first export → **live verification
ladder**. **Available to all tiers** — importing glucose is free; correlation features keep
their existing Loop+ gates (Free users get an upsell on success). No new entity/migration —
everything is derived. Fully deterministic, **no Claude call**.

  The step-3 endpoint URL is **this instance's configured address** — `DEFAULT_URI` (the
  `public_base_url` Twig global) + `/api/health-metrics/health-export`, so a self-hosted copy
  shows its own address (2026-10-12, PR 6a; it was hardcoded `https://nouriloop.com` before).
  Still **not** `url()`-derived: it's the only address a user should paste into HAE, and
  `url()` echoes whatever host the request came in on. Prod's `.env.local` sets
  `DEFAULT_URI=https://app.nouriloop.com` (the hosted service moved off the root domain at the
  Linode move, 2026-10-18; nouriloop.com is now the LLC's business site), and `.env.test` pins
  the same value, so the committed help screenshot shows the production address. The `connect-cgm` help topic uses the same global.
- **Routes** (catch-all `^/` auth, no security.yaml change): `GET /connect-cgm`
  (`app_connect_cgm`), `POST /connect-cgm/token` (`app_connect_cgm_token`, CSRF
  `connect-cgm-token` — mints an `ApiToken` named `ConnectCgmController::TOKEN_NAME`
  ("Health Auto Export") via `ApiTokenManager::create` and reuses Settings' one-shot
  `api_token_new` flash; the plaintext renders once in the step-2 copy card **and** woven into
  step 3's `Bearer …` copy value; a return visit gets a "just create a fresh one" card), and
  `GET /connect-cgm/status` (`app_connect_cgm_status`, JSON — polled every 10 s by inline
  vanilla JS, paused on `document.hidden`, stopped on success; deliberately un-rate-limited).
- **`CgmSetupStatusService::check(User) → CgmSetupStatus` DTO** — the stateless ladder:
  `no_token` → `token_unused` → `waiting` (a token's `lastUsedAt` within
  `RECENT_WINDOW_HOURS` = 48 h of server now — `ApiTokenHandler` stamps it on every bearer
  auth, so it means "the phone reached us") → `sparse`/`dense` from glucose recency + density
  (`HealthMetricRepository::findRecentGlucoseTimestamps`, window base in the user's frame via
  `UserTime`). **Dense** = ≥ `DENSE_MIN_READINGS` (12) distinct recent timestamps AND median
  gap ≤ `DENSE_MAX_MEDIAN_GAP_MIN` (20 min) — separates a real per-reading CGM feed (~5-min
  cadence) from HAE's Summarize-Data-on daily summaries (`sparse`, the wizard's amber "turn
  Summarize Data off" message) and from hourly summaries (also `sparse`; curves need
  `MIN_READINGS_FOR_CURVE` = 6 in 180 min). Glucose evidence trumps the token rungs (a
  file-upload user still completes); recency windows mean stale/mock data can't fake success.
  Reaching `dense` marks the onboarding `health` step from the status GET — an idempotent
  materialization of derived truth (`markStep` no-ops once onboarding is resolved).
- **Entry points:** the onboarding health step's primary CTA (`then=connect`), a
  no-glucose callout on `/health-metrics`, the Glucose Insights empty state + Loop+ upsell,
  a hint on Settings ▸ API tokens, and the public `connect-cgm` help topic (which is the
  prose twin of the wizard — deliberately duplicated copy, see both file headers).
- **Images:** the HAE iPhone screenshots (`public/images/help/connect-cgm-hae-*.png`) and the
  App Store QR are **hand-captured/committed, not regenerated** by
  `app:capture-help-screenshots` (which does regenerate the topic's lead shot of the wizard
  itself); every `<img>` self-hides via `onerror` until its PNG lands.
- Covered by `tests/E2E/ConnectCgmTest` (in-process `WebTestCase`: anonymous redirect, all
  steps render, token copy-once + step-3 weave, the full ladder incl. stale-glucose,
  Day-aggregation and hourly → `sparse`, dense → success + onboarding mark + idempotence,
  tokenless dense) and an `OnboardingTest` case for the `then=connect` CTA.
