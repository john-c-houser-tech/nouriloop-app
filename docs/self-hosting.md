# Self-hosting

Run your own copy of the app on a server you control. A self-hosted copy has every
feature for every account, no billing, and your own name and logo. You bring an AI
key (Anthropic, OpenRouter, or your own Ollama) and an email provider.

Plan on **under an hour** the first time, most of it waiting for DNS.

## What you need

- A Linux server with **2 GB of memory** and 20 GB of disk (any cloud provider; Ubuntu
  24.04 or Debian 12/13 are what this guide assumes), reachable on ports **80 and 443**.
- A **domain or subdomain** you can point at it, e.g. `nutrition.example.com`.
- **Docker** with the Compose plugin.
- An **AI key**: [Anthropic](https://console.anthropic.com/), or [OpenRouter](https://openrouter.ai/),
  or the address of your own Ollama server with a model that can read images.
- An **SMTP email provider** (Postmark, Resend, Amazon SES, Mailgun, your domain host's
  mailbox…). Home servers and most cloud servers can't send email directly. Without
  email the app still works, but password resets and sign-in links can't be sent.

## 1. Point your domain at the server

Create an `A` record (and `AAAA` if the server has IPv6) for your domain, pointing at the
server's address. Do this first: it can take a few minutes to spread, and the web server
needs it to get your HTTPS certificate.

## 2. Install Docker

On Ubuntu or Debian:

```bash
curl -fsSL https://get.docker.com | sh
```

Then check it works: `docker compose version`.

## 3. Get the code

```bash
git clone https://github.com/john-c-houser-tech/nouriloop-app.git
cd nouriloop-app
```

(Or download a release `.tar.gz` from the repository's Releases page and unpack it.)

## 4. Create your settings

```bash
scripts/selfhost-init.sh nutrition.example.com
```

This writes `selfhost.env` with fresh secrets. Open it in an editor and fill in:

- **AI**: `ANTHROPIC_API_KEY`, or switch to `AI_PROVIDER=openai_compatible` and set
  `AI_BASE_URL`, `AI_API_KEY` and `AI_MODEL` (the file shows both).
- **Email**: `MAILER_DSN`, `MAILER_FROM` (an address your provider lets you send as),
  `MAILER_REPLY_TO`, and `ADMIN_EMAIL` (where new-registration notices go).
- **Identity**: `APP_NAME` (blank uses your domain name), `OPERATOR_EMAIL`, and, if you have
  them, `PRIVACY_URL` / `TERMS_URL`.

⚠️ **Keep a copy of `selfhost.env` somewhere safe.** `USER_SECRETS_KEY` encrypts the AI keys
your users save in Settings; without it a restored backup can't read them. Never change it
once people have saved keys.

## 5. Start it

```bash
docker compose up -d --build
```

The first build takes a few minutes. When it finishes, open `https://your-domain` (the
certificate is issued on the first visit, which can take a few seconds).

Check everything is running:

```bash
docker compose ps        # app, scheduler, database: all "Up"
docker compose logs app  # migrations ran; no errors
```

## 6. Make yourself the admin

Register an account on the site, then:

```bash
docker compose exec app bin/console app:make-admin you@example.com
```

This also marks your email address as verified, so the "confirm your email" banner goes
away even if you haven't set up email. The admin pages (`/admin/users`) let you see
accounts, disable or delete them.

## 7. Check the AI works

```bash
docker compose exec app bin/console app:ai-benchmark
```

This sends five reference meal photos through your photo model and reports whether its
estimates are close enough. It costs a few cents. A model that fails still works, but its
meal estimates will be less reliable.

Then log a meal in the app.

## Running it

**Scheduled jobs** run in the `scheduler` container: the evening reminder digest and goal
check-ins (hourly; each user gets theirs at their own local time), account deletions whose
30-day grace period has ended, and clean-up of abandoned photo uploads (daily). See
`docker compose logs scheduler`.

**Updating:**

```bash
git pull
docker compose up -d --build
```

Database changes apply automatically when the new version starts.

**Backups.** Everything that matters is in two Docker volumes and one file:

```bash
# The database
docker compose exec database sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > backup.sql
# Uploaded photos
docker run --rm -v nouriloop_uploads:/uploads -v "$PWD":/out debian:stable-slim tar czf /out/uploads.tar.gz -C /uploads .
```

…plus `selfhost.env`. Copy all three off the server, and test a restore once.

**Logs:** `docker compose logs -f app`, and the app's own log files in the `logs` volume
(`docker compose exec app ls var/log`).

**Text messages (optional):** set `TWILIO_DSN` to your own Twilio account. US numbers need
A2P 10DLC registration with Twilio before carriers deliver.

**Behind your own reverse proxy:** set `SERVER_NAME=:80` so the app serves plain HTTP,
change the `ports` in `compose.yaml` to publish only port 80 (or nothing, if the proxy is
on the same Docker network), and add `SYMFONY_TRUSTED_PROXIES=` with your proxy's address to
`selfhost.env`. Don't set trusted proxies without a proxy: anyone could then fake their IP
address and bypass the login rate limits.

## Troubleshooting

- **The site doesn't load / certificate error:** DNS isn't pointing at the server yet, or
  ports 80/443 are blocked by a firewall. `docker compose logs app | grep -i tls` shows the
  certificate attempts.
- **"The database didn't answer":** `docker compose logs database`. The first start takes
  longer while MariaDB creates its files.
- **No emails arrive:** check `MAILER_DSN` and that `MAILER_FROM` is an address your provider
  allows; `docker compose logs app | grep -i mail`.
- **Meal analysis fails:** check the AI settings, then run `app:ai-benchmark` (step 7); it
  prints the provider's error.
- **Changed `selfhost.env`?** Run `docker compose up -d` to apply it.

## Your obligations

The code is licensed under the AGPL v3. If you change it and let other people use your copy,
you must offer them your changed source: put its address in `SOURCE_URL`. The name
"NouriLoop" and its logo are NouriLoop LLC's trademarks; give your copy its own name with
`APP_NAME`. Your users' health data is your responsibility: publish a privacy policy
(`PRIVACY_URL`) that says who runs the copy and how to reach you.
