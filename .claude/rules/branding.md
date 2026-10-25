---
description: "Configurable branding: Brand (name, logo, legal links, operator, source), {app_name} in translations, brand.name in templates, hosted-only company pages and analytics, the self-hosted footer, legal pages, security.txt and manifest."
paths:
  - "src/Hosting/**"
  - "templates/_brand_mark.html.twig"
  - "templates/privacy/**"
  - "templates/base.html.twig"
  - "src/Controller/PrivacyController.php"
  - "src/Controller/SecurityTxtController.php"
  - "src/Controller/ManifestController.php"
  - "src/Controller/AboutController.php"
  - "src/Controller/LandingPageController.php"
  - "src/Controller/RegistrationController.php"
  - "src/Twig/AnalyticsGlobalsExtension.php"
  - "src/Twig/DisclaimerExtension.php"
  - "config/packages/translation.yaml"
  - "config/packages/twig.yaml"
  - "translations/**"
---

# Configurable branding — behavioral notes

App Development Plan PR 6b (2026-10-12; decisions Q5 and Q11a–d). NouriLoop's name, logo,
company pages, legal documents and analytics belong to NouriLoop LLC (the README's
trademark note). The hosted service keeps them; a **self-hosted copy**
(`APP_HOSTING_MODE=self`, see `billing.md`) carries its operator's. Update this file in
the same PR as any change to it.

## The rule for new text
- ⚠️ **Never write "NouriLoop" in in-app text.** In a catalog string write `{app_name}`;
  in a template write `{{ brand.name }}`; in PHP that builds user-facing text use
  `Brand::name()`. The literal name belongs only in: the hosted-only company pages and
  their catalog sections (`lp:`, `about:`, `pricing_page:`, `templates/lp|about|subscription`,
  the privacy/terms templates), NouriLoop LLC's welcome letter, attribution ("Runs on
  NouriLoop…", where `{software}` is always the literal), code comments, and AI prompts
  that use it only as background context.
- A catalog value that **starts** with `{app_name}` must be quoted in YAML (a plain
  scalar starting with `{` is a flow mapping).
- `CatalogConsistencyTest` enforces that every full catalog uses `{app_name}` in the same
  keys as English — a translation that drops the name fails CI.

## `App\Hosting\Brand` — what this instance is called and who runs it
- **Name** (`Brand::resolveName`, Q11a): `APP_NAME`, else **"NouriLoop"** on the hosted
  service, else the copy's **own domain** (host of `DEFAULT_URI`, `www.` dropped).
- **Logo** (`APP_LOGO_URL`): rendered by **`templates/_brand_mark.html.twig`**
  (`variant: auth|navbar|email`, `size`, `class`, `absolute`) — the operator's logo when set,
  else NouriLoop's own asset of the right size on the hosted service (`auth-logo.png`,
  `navbar-logo.png`, `icon-192x192.png`), else a neutral `bi-egg-fried` icon on pages and
  nothing in email.
- **NouriLoop's mark (2026-10-24)** is a bowl with a coil of steam seen from the side, gold
  (`#f1c24d`) on slate (`#1f2937`). It replaced the gold infinity-loop-leaf-head mark, which
  blurred at favicon size and sat in a crowd of look-alike wellness logos (the search record is
  in the business folder, `logo/HANDOFF-logo-favicon.md`). One vector source, kept in the business
  folder (`logo/app-n1-steam-surface.svg`); every PNG in `public/` is rendered from it:
  `navbar-logo.png` is the bare gold mark on transparent; `auth-logo.png` and the favicons are the
  mark on a rounded slate tile; `apple-touch-icon.png`, `icon-192x192.png` and `icon-512x512.png`
  are full-bleed slate (the manifest marks them maskable). `public/images/og-card.svg` carries the
  same tile inline.
- **Lockup rule** (John, 2026-10-24): beside its name at navbar size, NouriLoop's mark is not
  centred on the text — the text baseline sits on the bottom edge of the bowl's rim line
  (`.brand-lockup` in `base.html.twig`: at 32 px the image hangs 12.7 px below the baseline;
  `lp/_layout.html.twig` does the same at 28 px). An operator's own logo keeps the centred flex
  lockup, since the rule is about this drawing. In the expanded bar (≥ 992 px) the container
  aligns its items by baseline when the lockup is present, so "NouriLoop" and "Today" share a
  baseline (they were ~1 px apart under Bootstrap's centring); the bar's height does not change.
- **`OPERATOR_EMAIL`, `PRIVACY_URL`, `TERMS_URL`, `SOURCE_URL`** (default
  `Brand::DEFAULT_SOURCE_URL`, the public repo).
- Templates: the **`brand`** Twig global (`brand.name`, `brand.isNouriLoop`,
  `brand.logoUrl`, `brand.sourceUrl`, `brand.operatorEmail`) and **`hosting`**
  (`hosting.hosted` / `hosting.selfHosted`).
- Translations: **`{app_name}`** is a translator **global parameter**
  (`framework.translator.globals.app_name` in `translation.yaml`) fed by
  **`%env(brand_name:APP_NAME)%`** — `BrandNameEnvVarProcessor` runs `Brand::resolveName`, so
  templates and translations can't disagree, and it resolves at runtime in web requests and
  console commands (scheduled emails) alike. No translator decorator; a call's own
  parameters override the global.

## What changes on a self-hosted copy
- **Name everywhere**: page titles (`— {{ brand.name }}`), the navbar, ~72 in-app strings per
  catalog, the help pages and their summaries (`HelpController::topicsFor()`, used by the help
  pages, the help assistant's manifest and fallback, and the help pages' JSON-LD), email
  headers, team-facing mail subjects, the SMS consent text (English, the parked TCPA rule —
  it must name the actual sender), the footer disclaimers (`DisclaimerExtension`), and the
  help assistant's persona ("You are the {name} help assistant" — its answers are shown).
  The other AI prompts keep "NouriLoop" as context.
- **Company pages are hosted-only**: `/about` and `/lp/{slug}` (and their locale twins) carry
  `condition: "service('hosting').isHosted()"` and 404; the sitemap leaves out about and
  pricing; the login page's "Learn more" link and `/about`'s plans sentence hide.
- **Analytics**: `google_analytics_id` / `meta_pixel_id` come from
  `App\Twig\AnalyticsGlobalsExtension` (moved out of `twig.yaml`), which returns **''** unless
  this is NouriLoop's own service — a copied production `.env.local` can't load NouriLoop's
  trackers.
- **Head**: no `og:image` (the og-card is NouriLoop's), favicons replaced by the operator's
  logo or `public/images/neutral-icon.svg`, and the manifest is **generated**
  (`ManifestController`, `/app.webmanifest`, `PUBLIC_ACCESS`; the static
  `public/site.webmanifest` was removed). Its icons follow the same rule as the favicon
  (operator logo → NouriLoop's icons when hosted → neutral). ⚠️ The **hosted** manifest is
  pinned field-for-field to the old static file, `"purpose": "any maskable"` included
  (`SelfHostedBrandingTest::HOSTED_MANIFEST`) — Copilot catch on #246; don't let the install
  metadata drift. Residue: browsers that ignore `<link rel=icon>`
  still fetch `public/favicon.ico` (NouriLoop's) — an operator can replace that file.
- **Footer** (Q11d): hosted "© NouriLoop LLC · Source code · Send us feedback"; self-hosted
  "**Runs on NouriLoop, open-source software · Source code** · Send us feedback"
  (`chrome.footer.runs_on` / `chrome.footer.source`). AGPL-3.0 §13: a modified copy run for
  others must offer its own source — `SOURCE_URL`. ⚠️ Ad landing pages stay CTA-only, so the
  source and feedback links both sit behind `not chrome_suppressed`.
- **Legal pages** (Q11c): `/privacy/health`, `/privacy/sms`, `/privacy/sms-optin` →
  `PRIVACY_URL`, `/privacy/terms` → `TERMS_URL` (302); unset → `privacy/operator.html.twig`
  ("run by its operator, not by NouriLoop LLC", `OPERATOR_EMAIL`, attribution + source
  link; `legal.operator.*` in every catalog).
- **security.txt**: names `OPERATOR_EMAIL` with `Policy:` still pointing at the software's
  SECURITY.md (for flaws in NouriLoop itself); **404 without `OPERATOR_EMAIL`** — a contact
  that reaches no one is worse than none.
- **Welcome email** (Q11b): registration sends the existing, translated **verification
  email** instead of John's personal letter.
- **Settings**: the hub's lead drops "billing" (`settings.index.lead_self_hosted`); the admin
  user list shows no Upgrade/Downgrade plan actions.

## Tests
`tests/E2E/SelfHostedBrandingTest` sets the env per test before booting the kernel: hosted
identity intact; a self-hosted copy named by its domain in titles, navbar, translations,
help prose and the manifest, with no NouriLoop logo, og card, favicons, trackers (even with
IDs set) or LLC copyright, and company pages 404; operator name/logo/fork used when set;
legal pages redirect or show the operator page; security.txt 404/operator; registration
sends the verification email ("Confirm your food.example.org email" — which also proves the
translator global resolves) instead of the welcome letter.
