#!/usr/bin/env bash
# Proves the tunnel-facing port (127.0.0.1:8081) exposes nothing but the webhook path.
# Every case must answer 404. The secret path is read from .env and sent through `curl -K -`
# (config on stdin) so it never appears in argv/ps or in this script's output.
set -u
cd "$(dirname "$0")/.."

BASE="http://127.0.0.1:8081"
PATH_SECRET=$(grep '^TELEGRAM_WEBHOOK_PATH=' .env | head -1 | cut -d= -f2- | tr -d "\"' \r")
fail=0

if [ "${#PATH_SECRET}" -lt 32 ]; then
  echo "TELEGRAM_WEBHOOK_PATH is missing or shorter than 32 characters; aborting."
  exit 2
fi

# expect_404 LABEL METHOD URLPATH [extra curl-config lines...]  (URLPATH may contain the secret; never echoed)
expect_404() {
  local label=$1 method=$2 urlpath=$3; shift 3
  local cfg body code
  cfg=$(printf 'url = "%s%s"\nrequest = "%s"\nsilent\nmax-time = 10\nwrite-out = "\\n%%{http_code}"\n' "$BASE" "$urlpath" "$method")
  for extra in "$@"; do cfg+=$'\n'"$extra"; done
  body=$(curl -K - <<<"$cfg" 2>/dev/null)
  code=${body##*$'\n'}
  if [ "$code" = "404" ]; then
    printf 'ok    %-52s 404\n' "$label"
  else
    printf 'FAIL  %-52s %s\n' "$label" "$code"; fail=1
  fi
  LAST_BODY=${body%$'\n'*}
}

for p in /admin /admin/login /livewire/update /livewire/livewire.js /health /up / /index.php /.env /storage/x /telegram/webhook /favicon.ico /robots.txt; do
  expect_404 "GET  $p" GET "$p"
done
expect_404 "POST /livewire/update" POST /livewire/update 'data = "{}"' 'header = "Content-Type: application/json"'
expect_404 "POST /admin/login" POST /admin/login 'data = "{}"'
expect_404 "POST / (root)" POST / 'data = "{}"'
expect_404 "GET  <path>" GET "/${PATH_SECRET}"
expect_404 "HEAD <path>" HEAD "/${PATH_SECRET}"
expect_404 "POST <path> without secret header" POST "/${PATH_SECRET}" 'data = "{}"' 'header = "Content-Type: application/json"'
expect_404 "POST <path>/ (trailing slash)" POST "/${PATH_SECRET}/" 'data = "{}"' 'header = "X-Telegram-Bot-Api-Secret-Token: wrong"'
expect_404 "POST <path>x (longer)" POST "/${PATH_SECRET}x" 'data = "{}"' 'header = "X-Telegram-Bot-Api-Secret-Token: wrong"'
expect_404 "POST <path> (uppercased)" POST "/$(printf '%s' "$PATH_SECRET" | tr a-z A-Z)" 'data = "{}"' 'header = "X-Telegram-Bot-Api-Secret-Token: wrong"'
expect_404 "POST wrong path + secret header" POST "/not-the-path" 'data = "{}"' 'header = "X-Telegram-Bot-Api-Secret-Token: wrong"'

# Right path, POST, header present but WRONG: nginx lets it through, Laravel must answer 404 itself.
expect_404 "POST <path> wrong secret (answered by Laravel)" POST "/${PATH_SECRET}" 'data = "{}"' 'header = "Content-Type: application/json"' 'header = "X-Telegram-Bot-Api-Secret-Token: wrong"'
if printf '%s' "$LAST_BODY" | grep -qi '<center>nginx</center>'; then
  echo "FAIL  wrong-secret response came from nginx, not Laravel (PHP not reached)"; fail=1
else
  echo "ok    wrong-secret response did not come from nginx (PHP reached, request rejected)"
fi

# Published ports: only nginx, only 127.0.0.1, only 80 and 8081.
PS_JSON=$(docker compose ps --format json 2>/dev/null)
export PS_JSON
bad=$(python3 - <<'PY'
import json, os
bad = []
for line in os.environ["PS_JSON"].splitlines():
    line = line.strip()
    if not line:
        continue
    data = json.loads(line)
    for c in (data if isinstance(data, list) else [data]):
        for p in c.get("Publishers") or []:
            if p.get("PublishedPort"):
                ok = c["Service"] == "nginx" and p.get("URL") == "127.0.0.1" and p["PublishedPort"] in (80, 8081)
                if not ok:
                    bad.append("%s:%s:%s" % (c["Service"], p.get("URL"), p["PublishedPort"]))
print(" ".join(bad))
PY
)
if [ -z "$bad" ]; then echo "ok    published ports: nginx only, 127.0.0.1 only (80, 8081)"; else echo "FAIL  unexpected published ports: $bad"; fail=1; fi

[ $fail -eq 0 ] && echo "ALL OK" || echo "SOMETHING IS EXPOSED"
exit $fail
