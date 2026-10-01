# Security review (PRD §56) — M9e

Review of the MVP against PRD §56 and the rules in `CLAUDE.md`. Each row says what protects the point, where it is
tested, and what is left to the operator. Findings made during the review are listed at the end with their fixes.

## Checklist

| PRD §56 requirement | Status | How / where |
|---|---|---|
| Whitelist by `telegram_user_id` | ✅ | Webhook handler rejects unknown ids; users only created by `reportflow:user:create`. Tests: `tests/Feature/Telegram/*` |
| Bot token only in `.env` | ✅ | Never logged or committed; `RedactLogs` tap on every log channel; `TestIsolationTest`, arch tests; `.env` excluded from images (`.dockerignore`) and read into containers through `env_file` |
| Webhook `secret_token` verified | ✅ | `VerifyTelegramSecret` (`hash_equals`), runs before the rate limiter. nginx additionally drops requests without the header |
| Unguessable webhook path, HTTPS only | ✅ | Path ≥ 32 chars or the route is not registered; `RequireSecureInProduction`; production nginx serves it as one exact POST path on 443 and does not log it (`docker/nginx/prod.conf.template`) |
| API keys not in DB or source | ✅ | `.env` only; fake credentials in tests are assembled at runtime (`FakeSecrets`) |
| PostgreSQL / Redis / Gotenberg not public | ✅ | On `reportflow-internal` (`internal: true`); only nginx publishes ports in production (`ProductionStackTest`, `NginxTunnelTest`) |
| Report files on private storage | ✅ | `reports` disk is private; downloads via signed, expiring URL + `throttle:30,1` + owner check (`ReportDownloadController`) |
| AI data minimisation | ✅ | Candidate lists and facts only; redaction before storage and before AI (`RedactionService`) |
| Redaction layer | ✅ | Patterns for keys, passwords, private keys, connection strings; raw text never logged |
| Third-party AI processing | ⚠ operator | Review DeepSeek's retention terms against client NDAs before real client work (go-live checklist) |
| Telegram Login verified server side | ✅ | `TelegramLoginVerifier`: HMAC, constant-time compare, fresh `auth_date`, single use |
| HTTPS mandatory | ✅ | Dashboard and auth routes 404 over HTTP in production; nginx redirects 80 → 443; HSTS; `URL::forceScheme('https')` |
| CSRF and session timeout | ✅ | Laravel defaults; `SESSION_LIFETIME=120`; production forces `secure`, `http_only`, `same_site=lax` (`hardenProduction`) |
| Signed URLs with limited life | ✅ | `SignedDownload` (`REPORT_DOWNLOAD_TTL_MINUTES`) |

## Hardening added in M9e

- **Production settings cannot be loosened by the `.env`:** `app.debug=false`, secure + HttpOnly session cookies, `same_site` lax/strict, https URLs (`AppServiceProvider::hardenProduction`; `ProductionEnvironmentTest`).
- **Host header:** production trusts only the host of `APP_URL` (`trustHosts`, anchored regex); anything else gets 400. nginx additionally refuses the TLS handshake for any name that is not `APP_DOMAIN`, and the port-80 redirect targets `APP_DOMAIN`, never the request's Host.
- **TLS:** TLS 1.2/1.3 only, AEAD ciphers, no session tickets, OCSP stapling, HSTS (1 year, `includeSubDomains`, no preload).
- **Headers:** `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, set `always` at server level only (a header inside a location would replace them; a test guards that).
- **Rate limits:** nginx: 20 req/s per IP (burst 60), 10/min on `/auth/*` and `/admin/login` (burst 5), 30 connections per IP; Laravel throttles stay (webhook, auth, downloads, health).
- **Attack surface:** only `index.php` executes PHP; other `.php` → 404; dotfiles denied; body ≤ 2 MB (webhook ≤ 1 MB); no landing page (the `/` route redirects to `/admin`).
- **Images:** production image has no dev dependencies, no `.env`, no tests' eval data, and runs as `www-data`; opcache never re-reads files.
- **Alerts and backups:** M9a–c (`ops:check`, encrypted off-site backup with a tested restore).

## Not done on purpose (and why)

- **Content-Security-Policy:** Filament/Livewire rely on inline scripts and styles; a CSP that works would need `unsafe-inline`, which gives little protection. Revisit when Filament ships nonce support.
- **WAF / fail2ban:** out of MVP scope; nginx rate limits plus the single-user whitelist cover the realistic threats. Revisit with more users.
- **Container hardening beyond defaults** (read-only root filesystems, seccomp profiles, dropping capabilities): PHP-FPM and nginx need writable paths; worth doing once the stack is stable on the VPS.
- **Encrypting the database at rest:** relies on disk-level encryption by the VPS provider; backups are encrypted by us.

## Findings and fixes

| # | Finding | Fix |
|---|---|---|
| 1 | The dev nginx config served plain HTTP with only two headers | Separate production config with TLS and the full header set; dev config unchanged (loopback only) |
| 2 | `APP_DEBUG=true` in a production `.env` would print stack traces and configuration | Forced off in production regardless of `.env` |
| 3 | Session cookie flags depended on `.env` | Forced `secure`/`http_only`, `same_site` limited to lax/strict in production |
| 4 | Any `Host` header reached the application | `trustHosts` + nginx default server that refuses the handshake |
| 5 | Landing page rendered a Vite asset that does not exist in the image (500) | `/` redirects to `/admin` |
| 6 | Scheduler had no outbound route, so `ops:check` alerts could not reach Telegram | Scheduler joined `reportflow-public` (M9c) |
| 7 | Backup key or rclone config could end up in git or an image | `/secrets/` gitignored and dockerignored; mounted read-only at runtime (`BackupTest`) |

## Operator duties (cannot be done from the repository)

- Real certificate through `scripts/tls/issue.sh`; confirm renewal with `docker compose ... logs certbot`.
- Firewall: allow only 22 (preferably key-only SSH), 80, 443. Docker publishes ports around `ufw`; the compose files publish nothing else, which is the real guard.
- Keep the VPS patched; rebuild images monthly (`docker compose ... build --pull`).
- Rotate secrets as in `docs/runbooks/rotate-secrets.md` after any suspected exposure.
