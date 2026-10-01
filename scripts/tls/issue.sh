#!/usr/bin/env bash
# First Let's Encrypt certificate for APP_DOMAIN (docs/runbooks/deploy.md). Run on the VPS, in the project directory,
# after DNS for APP_DOMAIN points at this server and ports 80/443 are open.
#   scripts/tls/issue.sh you@example.com [--staging]
#
# nginx cannot start with TLS before a certificate exists, and certbot cannot validate before nginx answers on port 80.
# So: a throw-away self-signed certificate lets nginx start, certbot replaces it with the real one, nginx reloads.
set -euo pipefail

email="${1:?usage: issue.sh EMAIL [--staging]}"
staging="${2:-}"
compose=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

set -a; . ./.env; set +a
: "${APP_DOMAIN:?APP_DOMAIN is not set in .env}"
[[ "$APP_DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]] || { echo "APP_DOMAIN looks wrong" >&2; exit 1; }

live="/etc/letsencrypt/live/$APP_DOMAIN"

echo "1/4 creating a temporary self-signed certificate"
"${compose[@]}" run --rm --no-deps --entrypoint sh certbot -c "
  mkdir -p '$live' &&
  if [ ! -s '$live/fullchain.pem' ]; then
    apk add --no-cache openssl >/dev/null &&
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 -subj '/CN=$APP_DOMAIN' -keyout '$live/privkey.pem' -out '$live/fullchain.pem' &&
    cp '$live/fullchain.pem' '$live/chain.pem'
  fi"

echo "2/4 starting nginx"
"${compose[@]}" up -d nginx

echo "3/4 asking Let's Encrypt${staging:+ (staging)}"
"${compose[@]}" run --rm --no-deps --entrypoint sh certbot -c "
  rm -rf '/etc/letsencrypt/live/$APP_DOMAIN' '/etc/letsencrypt/archive/$APP_DOMAIN' '/etc/letsencrypt/renewal/$APP_DOMAIN.conf' &&
  certbot certonly --webroot -w /var/www/certbot -d '$APP_DOMAIN' --email '$email' --agree-tos --no-eff-email --non-interactive ${staging:+--staging}"

echo "4/4 reloading nginx"
"${compose[@]}" exec nginx nginx -s reload
echo "done: https://$APP_DOMAIN"
