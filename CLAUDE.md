# NouriLoop — Developer Notes (CLAUDE.md)

> **⚠️ Keep this file current — it is the project's source of truth and change/roadmap
> record (Claude Cowork and other agents read it to track the system).** Every
> behavioral or structural change — feature, entity/column, route, service, config,
> migration, deploy/infra — **must** update the relevant section here, or in the
> `.claude/rules/` file that owns the area (see Where the detail lives), **in the same PR**
> as the change. "Did I update CLAUDE.md?" is part of done, alongside tests and cs-check.

Symfony web app for nutrition tracking: food-photo analysis (Claude vision),
daily logs, trend charts, supplement recommendations, health-metric imports, and
scheduled email & SMS notifications. Deployed to a production VPS
(**https://nouriloop.com**); multi-user with full auth.

> **Status: publicly launched — `v1.0.0`, soft-launch 2026-06-28.** Registration is
> **open to the public** (Free tier; beta codes still grant Loop+ — see Registration),
> **Stripe billing is live in production** (real keys + products/prices/webhook configured
> in the server `.env.local`; see Subscriptions / billing), and the footer shows the
> release version. Growing users slowly / gathering feedback before marketing.

> **In progress (decided 2026-10-12):** the open-source tier rework — self-host,
> hosted bring-your-own-key, hosted Full; one AI provider interface; every paid tier
> full-featured. The decision is `docs/adr/0003-ai-providers-and-tiers.md`. Built on `dev`:
> the `AiClient` seam, cost record, users' own keys and vision benchmark (`ai-client.md`),
> self-host mode and configurable branding (`billing.md`, `branding.md`), and the new
> plans — Loop $2/month bring-your-own-key, Loop+ $13.50/month AI included, monthly only
> (`billing.md`, PR 7), and the Loop+ $1.75 top-up (PR 7b). **All of that is live in
> production as `v3.0` (2026-10-14)**, with the old Stripe prices archived (PR 8). The
> self-host package (PR 9, `self-hosting.md`) and the public-repo presentation (PR 10:
> README, `CONTRIBUTING.md`, issue forms, `CHANGELOG.md`, `SECURITY.md` covering self-hosted
> copies) are on `dev`; next is the merge into the public repo. Outside contributions come in
> under the same AGPL, with no CLA (John, 2026-10-15). Keep `CHANGELOG.md`'s *Unreleased*
> section current: add a line when a PR changes something a user or self-hoster can see.

## Keeping docs in sync

This file and its `.claude/rules/` files are the canonical source for NouriLoop's
implementation state — other planning docs
(`FEATURES.md`, the Task Tracker, the Marketing Plan, the Roadmap) link back here rather than
re-describing shipped features in their own words. Full rules + the cross-folder doc map live
in the private business folder's `DOCS-INDEX.md` (added 2026-07-12, after a sync
review found several planning docs had drifted). Short version: update this file in the same
PR as any behavioral/structural/infra change (already stated above), and if you add a status
claim to `FEATURES.md` or any business-folder doc, make sure it agrees with what's here.

## Stack
- **Framework:** Symfony 7.4 (PHP ≥ 8.2), Doctrine ORM, Twig, Bootstrap 5 (CDN).
- **DB:** MariaDB (local). Migrations in `migrations/`.
- **Standards:** PSR-12, enforced by PHP_CodeSniffer (`phpcs.xml.dist`). Every PHP
  file uses `declare(strict_types=1)`.

## Common commands
- Run dev server: `symfony serve` (or `php -S 127.0.0.1:8000 public/index.php`)
- Migrations: `php bin/console doctrine:migrations:migrate`
- Generate migration from entity diff: `php bin/console doctrine:migrations:diff`
- Lint PSR-12: `composer cs-check` · auto-fix: `composer cs-fix`
- Lint container / routes: `php bin/console lint:container` · `debug:router`
- Send notifications: `php bin/console app:send-notifications` (`--dry-run`, `--user=<email>`)
- Run goal check-ins: `php bin/console app:run-goal-checkins` (`--dry-run`, `--goal=<id>`)
- Tests: `vendor/bin/phpunit` (unit/functional) · `vendor/bin/phpunit -c phpunit.e2e.xml.dist` (browser E2E) — see **Testing & CI**
- Self-host stack (Docker): `scripts/selfhost-init.sh localhost && docker compose up -d --build` — see `docs/self-hosting.md` and `.claude/rules/self-hosting.md`

**Local setup.** Any PHP ≥ 8.2 with `intl` and `imagick`, MariaDB, and a mail catcher
(Mailpit on `127.0.0.1:1025`) will run the app; put `APP_SECRET`, `DATABASE_URL` and
(optionally) `ANTHROPIC_API_KEY` in an untracked `.env.local`. To use OpenRouter or a
local Ollama instead of Anthropic, set the `AI_PROVIDER` block (see `.env` and
`.claude/rules/ai-client.md`). A **self-hosted** copy sets `APP_HOSTING_MODE=self` (no
billing, every feature for every account — `.claude/rules/billing.md`) and `DEFAULT_URI` to
its own https address. The Docker package for that (`Dockerfile`, `compose.yaml`,
`selfhost.env.example`) is described in `.claude/rules/self-hosting.md`; ⚠️ `compose.yaml`
is the **self-host** stack, not a dev environment. The maintainer's own
machines are described in `docs/ops/local-machines.md` in the private `nouriloop-ops` repo.

## Where the detail lives

This file holds what applies **everywhere**. Subsystem detail moved out verbatim on
2026-10-12 into path-scoped rules files under `.claude/rules/`, which Claude Code loads
automatically when you read or edit a matching path (the pattern Campaigner has used since
2026-08-03). Every old section of this file kept its heading, so a code comment that says
"see CLAUDE.md ▸ Timezones" now means the rules file listed against that heading below.
The doc-sync rule above is unchanged: **update this file or the rules file that owns the
area, in the same PR as the change.** Status, decisions and blockers stay here; subsystem
behavior lives in its rules file.

| File | Loads when you touch | Sections (old `CLAUDE.md` headings) |
|---|---|---|
| `.claude/rules/auth-and-mail.md` | `src/Security/**`, `src/Controller/SecurityController.php`, `src/Controller/LoginLinkController.php`, … | Auth; Password reset; Email delivery; Forms & CSRF |
| `.claude/rules/onboarding.md` | `src/Service/OnboardingService.php`, `src/Controller/OnboardingController.php`, `src/Twig/OnboardingExtension.php`, … | Onboarding flow |
| `.claude/rules/help-center.md` | `src/Controller/HelpController.php`, `src/Service/HelpAssistantService.php`, `src/Service/HelpContentExtractor.php`, … | Help center; Natural-language assistant |
| `.claude/rules/insights-and-coaching.md` | `src/Controller/PredictiveInsightController.php`, `src/Service/PredictiveInsightService.php`, `src/Dto/ScenarioRequest.php`, … | Predictive Help; Postprandial glucose response & insights; AI voice & tone |
| `.claude/rules/timezones.md` | `src/Service/UserTime.php`, `src/Service/TimezoneChanger.php`, `src/Twig/TimeExtension.php`, … | Timezones |
| `.claude/rules/i18n.md` | `translations/**`, `src/Enum/SupportedLocale.php`, `src/EventSubscriber/LocaleSubscriber.php`, … | Internationalization; Phase 4; Translation catalogs; Phase 3; Phase 2 |
| `.claude/rules/registration.md` | `src/Controller/RegistrationController.php`, `src/Form/RegistrationFormType.php`, `src/Service/InvitationCodeManager.php`, … | Registration; Registration attribution; Feedback form |
| `.claude/rules/admin.md` | `src/Controller/AdminUserController.php`, `src/Controller/AdminReportController.php`, `src/Dto/AdminUserFilters.php`, … | Admin / user management |
| `.claude/rules/settings-and-account.md` | `templates/base.html.twig`, `src/Controller/SettingsController.php`, `src/Form/ProfileFormType.php`, … | Navbar; Settings hub; Profile; Account deletion |
| `.claude/rules/notifications.md` | `src/Service/NotificationService.php`, `src/Command/SendNotificationsCommand.php`, `src/Outbound/**`, … | Email & SMS notifications; In-app notification center |
| `.claude/rules/health-data.md` | `src/Service/HealthMetric*.php`, `src/Service/Health*Importer.php`, `src/Service/HealthJsonImportResolver.php`, … | Health metrics; Connect your CGM wizard |
| `.claude/rules/meals.md` | `src/Controller/MealController.php`, `src/Service/FoodAnalysisService.php`, `src/Service/PhotoStaging.php`, … | Meal logging; Meal history & editing |
| `.claude/rules/favorites-and-recipes.md` | `src/Controller/FavoriteController.php`, `src/Service/FavoriteManager.php`, `src/Entity/Favorite.php`, … | Favorite meals; Recipe photo import |
| `.claude/rules/supplements-and-intake.md` | `src/Controller/SupplementsController.php`, `src/Service/SupplementAnalysisService.php`, `src/Service/SupplementRecommendationService.php`, … | Supplements; Daily intake |
| `.claude/rules/inflammation-and-symptoms.md` | `src/Service/InflammationScoreService.php`, `src/Service/DietSymptomCorrelationService.php`, `src/Service/CorrelationAnalyzer.php`, … | Dietary inflammation score; Symptom feedback loop |
| `.claude/rules/ai-client.md` | `src/Ai/**`, `tests/Ai/**`, `tests/Http/FakeAnthropicResponder.php`, … | The `AiClient` seam (from Nutrition goals, 2026-10-12); the cost record; users' own keys (Settings ▸ AI provider); the vision benchmark |
| `.claude/rules/branding.md` | `src/Hosting/**`, `templates/_brand_mark.html.twig`, `templates/privacy/**`, … | Configurable branding: name, logo, company pages, legal pages, analytics on a self-hosted copy (PR 6b, 2026-10-12) |
| `.claude/rules/goals.md` | `src/Service/Goal*.php`, `src/Service/StarterGoals.php`, `src/Service/CheckinAllowance.php`, … | Nutrition goals; Goal & notification tiering |
| `.claude/rules/billing.md` | `src/Service/SubscriptionService.php`, `src/Enum/SubscriptionTier.php`, `src/Controller/SubscriptionController.php`, … | Subscriptions / billing |
| `.claude/rules/testing-and-security.md` | `tests/**`, `phpunit.dist.xml`, `phpunit.e2e.xml.dist`, … | Testing & CI |
| `.claude/rules/self-hosting.md` | `Dockerfile`, `compose.yaml`, `docker/**`, `selfhost.env.example`, … | The self-host package: image, compose stack, scheduler, settings, release tarball (PR 9, 2026-10-14) |
| `.claude/rules/public-pages.md` | `src/Controller/AboutController.php`, `src/Controller/LandingPageController.php`, `src/Controller/RobotsController.php`, … | Campaigner breadcrumb; Ad landing pages; SEO & social meta; Structured data / JSON-LD; Analytics; Meta Pixel |

A new subsystem gets its own rules file with a `paths:` list, and a row here.

## Cross-cutting rules

Each of these is owned by the rules file named; they are listed here because they bite in
code that file's paths don't cover.

- **Interactive JS is inline vanilla `<script>`.** This app never renders `importmap()`, so
  Stimulus and everything under `assets/controllers/` never run (`auth-and-mail.md` ▸
  Forms & CSRF, `timezones.md`).
- **Per-user data is stored as the user's own wall-clock**, and every "today" or range
  boundary goes through `App\Service\UserTime`. Day ranges are half-open `[from, to)`
  (`timezones.md`).
- **New user-facing text ships in every full catalog in the same PR**;
  `CatalogConsistencyTest` fails CI otherwise (`i18n.md`).
- **Gate features through `SubscriptionService`**, never by reading `subscriptionTier`
  (`billing.md`).
- **Every AI call goes through the `AiClient` interface**, never a provider class;
  `FakeAnthropicResponder` fakes it in tests (`ai-client.md`).
- **Uploads are web-served without auth**, so stored file names use a random
  `bin2hex(random_bytes(16))` stem (`meals.md`).
- **A new per-user table needs a delete in `AccountDeletionService`**
  (`settings-and-account.md`; `ai_call` is the one deliberate exception, anonymized instead).
- **Health data never reaches GA4 or the Meta Pixel, and the Pixel never loads on an
  authenticated page** (`public-pages.md`).
- **Never write "NouriLoop" in in-app text**: `{app_name}` in catalogs, `{{ brand.name }}`
  in templates, `Brand::name()` in PHP. A self-hosted copy carries its operator's name; the
  literal belongs only to the hosted-only company pages and attribution (`branding.md`).

## Secrets / env
- `.env` holds non-secret defaults; real secrets live in the **untracked**
  `.env.local` (`APP_SECRET`, `DATABASE_URL`, `ANTHROPIC_API_KEY` or `AI_API_KEY`,
  `TWILIO_DSN`, and `USER_SECRETS_KEY`, which encrypts the AI keys users store and must
  never change once keys exist — see `ai-client.md`).
  Never commit secrets to `.env`/`.env.dev`. `.env.test` carries a throwaway test
  secret.

## Deployment (production)
Production runs on a single Ubuntu VPS (Nginx + PHP-FPM + MariaDB, cron for the scheduled
commands, Postfix relaying mail). The host layout, the `scripts/deploy.sh` procedure
("Deploying an update"), prod logging, SSH hardening, backups and the hard-won gotchas live
in **`docs/ops/production.md`** in the private `nouriloop-ops` repo (`scripts/deploy.sh`
itself stays here until production deploys from the public repo at the VPS move).

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Uses the five default triage labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root, created only when first needed. See `docs/agents/domain.md`.
