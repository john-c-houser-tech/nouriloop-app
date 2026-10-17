#!/bin/sh
# Creates selfhost.env from selfhost.env.example with fresh secrets.
#
#   scripts/selfhost-init.sh your.domain.example
#
# Uses only what every Linux server and Mac has (no PHP, no openssl). Refuses to
# overwrite an existing selfhost.env: its secrets protect data already stored.
set -eu

cd "$(dirname "$0")/.."

domain="${1:-}"
if [ -z "$domain" ]; then
    echo "Usage: scripts/selfhost-init.sh <domain>   (or 'localhost' for a local trial)" >&2
    exit 2
fi
case "$domain" in
    http://*|https://*|*/*|*" "*)
        echo "Give just the domain name, e.g. nutrition.example.com" >&2
        exit 2 ;;
esac

if [ -e selfhost.env ]; then
    echo "selfhost.env already exists; not overwriting it." >&2
    echo "Its secrets protect data already stored. Edit it instead." >&2
    exit 1
fi

# Owner-only from the moment the file exists, not after a later chmod.
umask 077

hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
b64() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }

app_secret=$(hex 32)
user_secrets_key=$(b64 32)   # libsodium key: exactly 32 bytes, base64
db_password=$(hex 24)        # hex, so it is safe inside DATABASE_URL

sed -e "s|__SERVER_NAME__|$domain|g" \
    -e "s|__APP_SECRET__|$app_secret|" \
    -e "s|__USER_SECRETS_KEY__|$user_secrets_key|" \
    -e "s|__DB_PASSWORD__|$db_password|g" \
    selfhost.env.example > selfhost.env
chmod 600 selfhost.env

echo "Wrote selfhost.env for $domain."
echo "Next: add your AI key and email settings to selfhost.env, then run"
echo "    docker compose up -d --build"
echo "Keep a copy of selfhost.env somewhere safe: without it, a restored backup"
echo "can't read the AI keys your users saved."
