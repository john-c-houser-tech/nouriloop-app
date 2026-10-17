---
description: "The self-host package: Dockerfile (FrankenPHP), compose.yaml stack (app, scheduler, MariaDB), docker/ support files, selfhost.env and its init script, the release tarball workflow, and the CI boot test."
paths:
  - "Dockerfile"
  - ".dockerignore"
  - "compose.yaml"
  - "docker/**"
  - "selfhost.env.example"
  - "scripts/selfhost-init.sh"
  - ".github/workflows/release.yml"
  - "docs/self-hosting.md"
---

# Self-host package — behavioral notes

App Development Plan PR 9 (2026-10-14; Transformation Plan §3F-5). The operator-facing guide
is **`docs/self-hosting.md`**; this file is the why. What a self-hosted copy *does*
differently (no billing, branding, hosted-only pages) is `billing.md` and `branding.md`;
this file is only how it's packaged. Update it in the same PR as any change to these files.

## The image (`Dockerfile`)
- **FrankenPHP** (`dunglas/frankenphp:1-php8.4-trixie`): PHP and the Caddy web server in one
  process, with automatic Let's Encrypt HTTPS from `SERVER_NAME`. Chosen so an operator needs
  no nginx and no certbot — the biggest single saving against the under-an-hour target. Caddy
  terminates TLS in the same process as PHP, so **there is no proxy and no trusted-proxies
  setting** (the production tripwire in `nouriloop-ops`' `docs/ops/production.md` applies here too).
- **Debian trixie on purpose:** `libheif-examples` there is 1.19, new enough for modern iPhone
  HEICs; bookworm/Ubuntu 24.04's isn't (`meals.md`).
- Extensions: `intl pdo_mysql gd imagick sodium opcache zip exif`. ⚠️ **`exif` is not in the
  FrankenPHP base**; without it `ExifTimestampService` returns null and every library photo
  silently defaults to "now" (backdating breaks). Copilot catch on #252. `docker/php.ini` sets
  `memory_limit=512M` (production's figure, for health imports), upload limits for 3–5 photos,
  `date.timezone=UTC`, and opcache with `validate_timestamps=0` (code only changes by rebuild).
- **supercronic** is pinned by version **and SHA-1 for both amd64 and arm64** (checked against
  the release's published sums, 2026-10-14). Bumping it means updating both sums.
- Three stages: `base` (runtime), `build` (composer `--no-dev`, `importmap:install`,
  `asset-map:compile`; the kernel boots here with the `.env` defaults, which is fine because
  nothing connects to the database), `app` (runs as the non-root `nouriloop` user; `setcap`
  lets FrankenPHP bind 80/443). `APP_ENV=prod` and `APP_HOSTING_MODE=self` are baked in.
- `docker/entrypoint.sh` refuses to start without `DATABASE_URL`/`APP_SECRET`, waits up to
  2 minutes for the database, runs migrations **only when `RUN_MIGRATIONS=1`** (the `app`
  service — so `app` and `scheduler` never race), then warms the cache.
- `.dockerignore` keeps out `.env.local`, `selfhost.env`, `var/`, `vendor/`, `tests/`, the
  ops scripts, and uploaded photos. ⚠️ A secret file added to the repo root needs a line here,
  or it gets baked into every image. Backups made in the checkout (`*.sql`, `*.tar.gz`,
  `backups/`) are excluded for the same reason: the guide's backup commands write there, and
  the next rebuild would copy users' records and photos into an image layer (Copilot, #252).

## The stack (`compose.yaml`)
- ⚠️ **`compose.yaml` is the self-host stack, not a dev environment.** Flex's Postgres stub
  and `compose.override.yaml` (a Mailpit dev service) were removed: Compose loads an override
  file **automatically**, so it would have published a mail catcher on every operator's
  server. Don't reintroduce one; the maintainer's dev setup doesn't use Docker.
- `name: nouriloop` pins the project name, so volumes are `nouriloop_database`,
  `nouriloop_uploads`, … whatever the folder is called (a release tarball unpacks to a
  versioned folder). The backup commands in the guide depend on that.
- Services: `app` (ports 80/443 + 443/udp for HTTP/3), `scheduler` (same image, command
  `supercronic /app/docker/crontab`), `database` (`mariadb:11.4` LTS, utf8mb4).
  The scheduler waits for `app` to be **healthy**, not merely started: the web server (and so
  the health check) only comes up after the entrypoint's migrations, so no job can run
  against a half-migrated schema.
- ⚠️ The base image ships a `HEALTHCHECK` that probes Caddy's admin port; the scheduler runs
  no Caddy, so compose **replaces** it with "supercronic is process 1" (true only after the
  entrypoint has reached the database and handed over). Don't just disable it:
  `docker compose up --wait` (the CI job) fails on a container with no health check — the
  first CI run of #252 did exactly that.
- Volumes: database, `public/uploads` (shared by app and scheduler — goal-check-in charts are
  written by the scheduler and served by the app), `var/log`, and Caddy's certificates
  (losing those re-requests certificates and can hit Let's Encrypt's rate limits).
- `var/cache` is **not** a volume: each container warms its own on start. Consequence,
  accepted: the rate limiters and the magic-link single-use pool reset on restart.

## Settings (`selfhost.env`)
- ⚠️ **Not `.env`.** Compose reads a file called `.env` for variable substitution, and that
  name is Symfony's committed defaults file. So all settings go in `selfhost.env` via
  `env_file:`, as real environment variables (which override `.env`), and `compose.yaml` uses
  **no `${…}` interpolation at all**. That's also why `docker compose exec app bin/console`
  works with no extra flags: exec inherits the container's environment.
- `scripts/selfhost-init.sh <domain>` writes it from `selfhost.env.example`: a hex
  `APP_SECRET`, a base64 32-byte `USER_SECRETS_KEY`, and one hex database password written
  into both `MARIADB_PASSWORD` and `DATABASE_URL` (hex so it needs no URL escaping). Plain
  `/dev/urandom` + `od`/`base64` — no PHP or openssl on the host. It **refuses to overwrite**
  an existing `selfhost.env` (its secrets protect stored data) and creates it under `umask 077`, so it is owner-only from the moment it exists (a later
  chmod alone left a readable window).
- The example's defaults that would otherwise leak NouriLoop's identity are all overridden
  there: `MAILER_FROM`, `MAILER_REPLY_TO`, `ADMIN_EMAIL` (new-user notices would go to
  NouriLoop's inbox), `OPERATOR_EMAIL`.

## Scheduled jobs (`docker/crontab`)
The four production crons at the same cadence: notifications and goal check-ins **hourly**
(each picks users whose local time has come, so they must stay hourly), purge and upload
clean-up daily at 03:30 / 03:45 UTC. A new scheduled command goes here **and** in
`docs/ops/production.md` in `nouriloop-ops`.

## Release tarball (`.github/workflows/release.yml`)
On a `v*` tag: `git archive` (honours `.gitattributes` `export-ignore`, so `deploy.sh`
stays out) → `nouriloop-app-<tag>.tar.gz` + `.sha256`,
attached to the GitHub release (created with generated notes if it doesn't exist). It runs on
the public repo from the PR 11 sync on (`v3.1`).

## CI (`selfhost` job in `ci.yml`)
Builds the image and boots the full stack on the runner with `localhost` settings
(`docker compose up --wait`), then checks the login page answers over HTTPS, migrations are up
to date, and the scheduler can run a job. A broken Dockerfile, entrypoint or compose file
fails the PR.

## Verified (2026-10-14, OrbStack on the maintainer's Mac)
Fresh volumes → 47 migrations → HTTPS on `localhost` (Caddy's internal CA) with HTTP→HTTPS
redirect → registration auto-signs in → a described meal analysed through Anthropic (an
`ai_call` row) → `app:make-admin` → `/about` and `/pricing` 404 → security.txt names the
copy → `down`/`up` keeps the data → the guide's `mariadb-dump` and uploads-tar backup
commands produce real archives. **Not yet done:** the fresh-VM test with a real domain and a
Let's Encrypt certificate (the plan's acceptance criterion), timed against the guide.
