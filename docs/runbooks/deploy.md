# Runbook: deploy ke VPS (production)

Satu VPS, Docker Compose. Production = `docker-compose.yml` + `docker-compose.prod.yml` (kode ada di dalam image, hanya nginx
yang membuka port 80/443). Untuk mempersingkat, di VPS:

```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
```

## Aturan

- Jangan mencetak atau menempelkan isi `.env`, token, atau kunci backup (CLAUDE.md aturan 6). Ubah `.env` lewat editor.
- Jangan pakai `docker compose down -v` (menghapus volume database dan file laporan). Pakai `down` tanpa `-v`.
- Jangan menjalankan `migrate:fresh` di production.

## A. Deploy pertama

Prasyarat (tugas manusia, lihat `go-live-checklist.md`): VPS, domain dengan DNS A/AAAA ke VPS, port 22/80/443 terbuka.

1. Ambil kode: `git clone <repo> reportflow && cd reportflow`.
2. Siapkan `.env` dari `.env.example`, isi: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (`php artisan key:generate --show`
   dari container sekali pakai, atau `openssl rand -base64 32` diberi awalan `base64:`), `APP_URL=https://<domain>`,
   `APP_DOMAIN=<domain>`, `DB_PASSWORD`, `REDIS_PASSWORD`, `TELEGRAM_*` (token, secret, `TELEGRAM_WEBHOOK_PATH` ≥ 32 karakter),
   `TELEGRAM_CLIENT=http`, `AI_PROVIDER=deepseek`, `DEEPSEEK_API_KEY`, `PDF_RENDERER=gotenberg`, `OPS_PROBE=real`,
   `ADMIN_TELEGRAM_USER_ID`, `BACKUP_REMOTE`. Hapus `DEV_USER_*`. `chmod 600 .env`.
3. Build (urutan penting: image app dulu, nginx menyalin `public/` darinya):
   ```bash
   dc build app
   dc build nginx
   ```
4. Data store dan migrasi:
   ```bash
   dc up -d postgres redis gotenberg
   dc run --rm app php artisan migrate --force
   ```
5. Sertifikat TLS (butuh DNS sudah mengarah; coba `--staging` dulu agar tidak kena batas Let's Encrypt):
   ```bash
   scripts/tls/issue.sh you@example.com --staging
   scripts/tls/issue.sh you@example.com
   ```
6. Jalankan semuanya: `dc up -d` (nginx, app, worker, worker-reports, scheduler, certbot).
7. Pengguna pertama (whitelist Telegram): `dc exec app php artisan reportflow:user:create <telegram_id> --name="..."`.
8. Telegram: `dc exec app php artisan telegram:set-webhook` lalu `dc exec app php artisan telegram:sync-commands`.
   Di @BotFather: `/setdomain` ke `<domain>` (Login Widget dashboard).
9. Backup: siapkan kunci dan rclone (lihat `backup-restore.md`), lalu `dc --profile backup up -d --build backup`.
10. Verifikasi: bagian "Pemeriksaan sesudah deploy" di bawah, lalu lanjutkan `go-live-checklist.md`.

## B. Update (deploy rutin)

```bash
git pull --ff-only
dc --profile backup run --rm backup /scripts/backup.sh     # cadangan sebelum migrasi
docker tag reportflow-app:latest reportflow-app:previous    # untuk rollback
docker tag reportflow-nginx:latest reportflow-nginx:previous
dc build app && dc build nginx
dc run --rm app php artisan migrate --force
dc up -d
```

`up -d` membuat ulang container yang imagenya berubah; worker menyelesaikan pekerjaan yang berjalan dulu (SIGTERM),
job yang belum selesai diulang karena semua job idempotent.

## Pemeriksaan sesudah deploy

```bash
dc ps                                                   # semua healthy
curl -fsS https://<domain>/health                       # {"status":"ok"}
curl -fsS https://<domain>/health/deep                  # {"status":"ok"} (503 = ada yang mati)
curl -sI  http://<domain>/ | head -3                    # 301 ke https
dc exec app php artisan ops:check                       # tanpa alert baru
```

Dashboard: buka `https://<domain>/admin`, login lewat Telegram, buka halaman **Kesehatan**. Kirim satu catatan di Telegram dan
pastikan terproses.

## Kalau gagal

`rollback.md`. Jangan menghapus volume untuk "memulai ulang".
