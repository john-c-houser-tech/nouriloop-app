---
description: "The unit and E2E suites, the test DB, the Claude mock, CI jobs, Dependabot triage, SECURITY.md and security.txt."
paths:
  - "tests/**"
  - "phpunit.dist.xml"
  - "phpunit.e2e.xml.dist"
  - ".github/**"
  - "config/services_test.yaml"
  - "SECURITY.md"
  - "src/Controller/SecurityTxtController.php"
---

# Testing, CI and security policy — behavioral notes

Moved verbatim out of the root `CLAUDE.md` on 2026-10-12 so it loads when you touch
the files above. The root file's rule applies here unchanged: update this file in the
same PR as any change to the behavior it describes.

## Testing & CI
- **Unit / functional** (`tests/`, `phpunit.dist.xml`): `vendor/bin/phpunit` — fast, no
  browser; **excludes `tests/E2E`**.
- **Browser E2E** (`tests/E2E/`, `phpunit.e2e.xml.dist`): Symfony **Panther** + headless
  Chrome — `vendor/bin/phpunit -c phpunit.e2e.xml.dist`. Covers login/auth, invite
  registration, dashboard, meal photo→clarify→save (+ portions), favorites, supplements,
  health-metric CSV import, trends, symptoms, goal setup (chat→confirm), settings,
  API-token ingestion, and admin access control. `tests/E2E/E2ETestCase` truncates + seeds
  a per-test user and clears cookies between tests (Panther reuses one browser).
  - The suite **also** holds a few **in-process `WebTestCase`** tests (not Panther —
    KernelBrowser/CommandTester) that need the **real DB** but no browser, kept here so
    they run in the DB-having e2e job and stay out of the DB-less unit job:
    `WelcomeEmailTest` (mail), `BetaGrandfatheringTest` (command + invite redemption +
    admin form), `AccountDeletionTest` (grace-period request/cancel + banner, "start
    fresh" data wipe, full account purge), `PurgeDeletedAccountsCommandTest`
    (dry-run/apply/cutoff), `AttributionTest` (UTM capture + the admin-only counts
    endpoint, incl. bearer-token calls), `SettingsHubTest` (hub renders every category,
    collapsed navbar link), and `AdminUsersListTest` (search narrows the list,
    pagination splits across pages, the plan/activity/status filters narrow correctly
    — incl. effective-tier where a comped-Loop+/stored-Free user files under Loop+ —
    filters survive a page boundary, and a dropdown row action still round-trips). They
    reuse `tests/E2E/DbResetTrait` for the schema reset.
- **Config tests** (`tests/Config/`): plain `TestCase`s that parse a YAML config file and
  assert an invariant no runtime test can reach, because the container under test is
  `test` and the config that matters is `prod`. Today that's
  `MonologHandlerPathsTest` — no two handlers in an env block may share a `path` (see
  **Logging (prod)**).
- **Dedicated test DB `nutrition_test`:** the `when@test` block in
  `config/packages/doctrine.yaml` appends `_test`, so `.env.test.local`'s `DATABASE_URL`
  keeps base name `nutrition`.
- **Claude is mocked** in `test`: `tests/Http/FakeAnthropicResponder` (a `MockHttpClient`
  response factory wired in `config/services_test.yaml`) replaces the HTTP client in
  `AnthropicClient` — the one module that calls the Messages API, so that single swap
  covers every Claude surface — deterministic,
  zero tokens, no network. It can also **simulate upstream failures**: putting its
  `FAIL_ALWAYS` / `FAIL_ONCE` const markers in the meal **notes** (which flow into the
  analysis prompt) makes the vision call 500 — every time, or only the first call after
  `resetFailureTrigger()` — used by `MealAnalysisRetryTest`.
- **chromedriver:** fetched by `vendor/bin/bdi detect drivers` into a gitignored
  `./drivers/` (version-matched to the installed Chrome; Panther auto-finds it there).
  Don't `brew install chromedriver` (it drifts from the Chrome version).
- **CI** (`.github/workflows/ci.yml`, on push/PR to `main`): a **`unit`** job (cs-check,
  `lint:container`, unit phpunit) + an **`e2e`** job (MariaDB 10.11 service, Chrome + bdi
  driver, migrate the test DB, run the Panther suite). Branch protection on `main` is
  **not** enabled — it's GitHub-Pro-gated on private repos.
- **Dependency security (Dependabot + scheduled audit, added 2026-07-03):** the repo's
  GitHub security **alerts** were already enabled; **`.github/dependabot.yml`** turns them
  into weekly update **PRs** (composer + github-actions ecosystems; routine minor/patch
  bumps grouped into one PR, security fixes always individual) that normal CI validates.
  A separate scheduled workflow (**`.github/workflows/security-audit.yml`**, Mondays +
  `workflow_dispatch`) runs **`composer audit --locked`** against `main` — deliberately
  **not** a PR gate, so a brand-new advisory in an unrelated package can't block a feature
  PR; on failure GitHub emails the workflow's last committer, and the fix is usually just
  merging Dependabot's PR. (Server-side OS/PHP patching is apt's territory — verifying
  `unattended-upgrades` on the VPS is a roadmap item, not repo process.)
  - **Version-update PRs target `dev` (2026-09-18):** both ecosystems carry
    `target-branch: dev`, so the weekly batch goes through the dev → main flow instead of
    around it. ⚠️ Dependabot reads its config from the DEFAULT branch, so this only takes
    effect once it reaches `main`; until then retarget a stray PR with
    `gh pr edit <n> --base dev`. **Security-update PRs always target `main` regardless.**
    CI's `pull_request` trigger was `[main]` only, so a PR into `dev` ran no checks at
    all — it is now `[main, dev]`, because green CI is the gate for the batch (step 3).
  - **This repo only, not the public mirror (2026-10-11):** `.github/dependabot.yml` is
    `export-ignore`d, so `nouriloop-app` has no Dependabot config. Its vulnerability alerts
    are off and `security-audit.yml` is disabled there, so all dependency work happens
    here and reaches the mirror with the next sync.
  - **Weekly triage process for the Dependabot batch** (~15 min, manual — branch
    protection is GitHub-Pro-gated on this repo, so there's no auto-merge-on-green):
    1. **Triage by ecosystem.** `github-actions` PRs are CI-only — no prod impact, no
       deploy. `composer` PRs change the app runtime and **don't reach prod until the
       next deploy**, so plan one after merging.
    2. **Security-labeled PRs first** (always individual, never grouped). Merge promptly
       once CI is green — these are what the Monday `composer audit` would start
       failing on.
    3. **Grouped minor/patch composer PR:** green CI (full unit + e2e suites) is the
       gate; skim the bump list for awareness and merge. No local testing for
       patch-level bumps.
    4. **Major bumps:** for *actions*, green CI is usually sufficient evidence because
       a PR's workflow run executes the PR's own workflow file — the bumped action
       already ran inside the passing check. **Exception:** steps gated on
       `if: failure()` (the `upload-artifact` Panther-screenshot step) are *not*
       exercised by a green run — skim those release notes instead of trusting the
       check. For *composer* majors, read the upgrade notes and run the e2e suite
       locally first; treat as a small project, not a rubber stamp.
    5. Merge in any order; if a PR falls behind after another merges, comment
       `@dependabot rebase` on it.
  - ⚠️ **An alert does not guarantee a PR — triage the alert list, not the PR list**
    (learned 2026-08-10, `squizlabs/php_codesniffer` CVE-2026-67434). Both repo toggles
    were on (`automated-security-fixes` `enabled`, `vulnerability-alerts` 204), the alert
    was raised 2026-08-08, and **no Dependabot PR ever appeared** — two days later the fix
    was still a manual `composer update squizlabs/php_codesniffer` on a hand-cut branch.
    Step 2 above ("merge the security PR") silently does nothing when there is no PR, so
    the alert would have sat until the Monday `composer audit` failed. The authoritative
    list is the alerts endpoint, not the PR page:
    ```
    gh api "/repos/john-c-houser-tech/nouriloop/dependabot/alerts?state=open"
    ```
    Suspected cause (unconfirmed): the manifest constraint already admitted the patched
    version (`^3.13.5` → 3.13.6), making it a **lock-only** fix. Treat any advisory whose
    fix is inside the existing constraint as one Dependabot may not raise for you.
- **Security policy (`SECURITY.md`, added 2026-08-10):** supported-version statement
  (the release deployed to nouriloop.com and, since PR 10 on 2026-10-15, the latest release on a
  self-hosted copy — never a backport; software flaws found on a self-hosted copy come here, a
  copy's own server/settings/data go to its operator via its security.txt; the self-hosting
  package is in scope), the `info@nouriloop.com`
  reporting path with timelines a solo developer can actually honor, scope in/out, and
  testing rules written around the fact that **real users' health data is in the system**
  (own-account-only, stop-on-incidental-access, minimum-proof).
  - ⚠️ **`SECURITY.md` isn't where the researchers it addresses will look.** This repo is
    private; since 2026-10-11 the file is also published in the public `nouriloop-app`
    mirror, but someone probing nouriloop.com has no reason to find either. The file
    documents the process; it is **not** the main way for an outsider to find the contact
    address.
- **On a self-hosted copy** `security.txt` names the operator (`OPERATOR_EMAIL`) or 404s
  without one (`branding.md`); everything below describes the hosted service's file.
- **RFC 9116 `security.txt` (`SecurityTxtController`, added 2026-08-10)** — served at
  `/.well-known/security.txt`, `PUBLIC_ACCESS` in `security.yaml`, covered by
  `tests/E2E/SecurityTxtTest`. ⚠️ **This route, not `SECURITY.md`, is NouriLoop's
  primary disclosure path** (see above for why). A controller rather than a static
  `public/` file for RobotsController's reasons: firewall-covered, testable, and
  `Canonical` stays correct across environments. It carries the testing rules **inline**
  in the comment block, because whoever reads it may read nothing else we've written —
  a contact address with no rules attached invites the "I dumped your user table to
  prove it" report.
  - ⚠️ **`Expires` is a fixed date, and `SecurityTxtTest::testExpiresIsNotAboutToLapse`
    fails 30 days before it.** RFC 9116 treats a lapsed file as stale, and an expired
    security contact reads as an abandoned project — worse than publishing none. A
    rolling `now + 1 year` would never expire, which defeats the field's purpose *and*
    means nothing ever prompts a review. So the staleness is made **loud**: CI goes red
    a month out, and the fix is to bump `EXPIRES` and re-confirm `info@` reaches a human.
    **That failing test is the entire annual review** — same principle as the daily
    digest sending on quiet days. Widening the window instead of bumping the date
    defeats the whole mechanism.
  - **`Policy:` links `SECURITY.md` in the public `nouriloop-app` mirror** (added
    2026-10-11; omitted before because a 404 inside a security.txt is worse than an absent
    optional field). The mirror trails the private repo between re-exports, which is why
    the inline rules stay. If the mirror ever moves, update `POLICY` (pinned by
    `SecurityTxtTest::testPolicyPointsAtThePublicSecurityMd`).
