# Runbook: rotasi secret

Aturan: nilai baru dibuat dan ditulis langsung ke `.env` lewat editor; jangan ditempel di chat, issue, atau argumen perintah
(terlihat di `ps` dan riwayat shell). Setelah mengubah `.env`, container harus **dibuat ulang** agar membaca nilai baru
(`restart` tidak cukup): `dc up -d --force-recreate app worker worker-reports scheduler`.

`alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'`

| Secret | Kapan | Langkah | Dampak |
|---|---|---|---|
| `TELEGRAM_BOT_TOKEN` | Bocor, atau berkala | @BotFather `/revoke` → token baru → `.env` → recreate → `dc exec app php artisan telegram:set-webhook` | Bot diam beberapa detik; tidak ada data hilang |
| `TELEGRAM_BOT_SECRET_TOKEN` | Bocor | Nilai acak baru di `.env` → recreate → `telegram:set-webhook` | Webhook lama ditolak sampai didaftarkan ulang |
| `TELEGRAM_WEBHOOK_PATH` | Bocor (ada di log nginx/tunnel yang tersebar) | Path acak ≥ 32 karakter di `.env` → `dc up -d --force-recreate nginx app` → `telegram:set-webhook` | Path lama langsung 404 |
| `DEEPSEEK_API_KEY` | Bocor, atau berkala | Buat kunci baru di konsol DeepSeek → `.env` → recreate worker/app → cabut kunci lama | Catatan baru diproses setelah recreate; yang gagal tersimpan dan dicoba ulang |
| `DB_PASSWORD` | Bocor | `dc exec postgres psql -U reportflow -c "ALTER USER reportflow PASSWORD '...'"` (ketik di prompt, jangan di argumen) → `.env` → recreate semua | Downtime singkat |
| `REDIS_PASSWORD` | Bocor | `.env` → `dc up -d --force-recreate redis app worker worker-reports scheduler` | Sesi login dan cache hilang; queue AOF tetap |
| `APP_KEY` | Bocor | Lihat peringatan | Semua sesi login tidak sah; signed URL lama tidak berlaku |
| Kunci backup (`secrets/backup.key`) | Bocor, atau berkala | Kunci baru; simpan salinan di luar server; arsip lama tetap butuh kunci lama (simpan keduanya selama retensi 7+4 hari/minggu) | Backup baru memakai kunci baru |
| Konfigurasi rclone (`secrets/rclone`) | Bocor kredensial cloud | Cabut kredensial di penyedia, buat baru, ganti file, jalankan backup manual | Backup berhenti jika lupa |

**`APP_KEY`:** aplikasi tidak menyimpan data terenkripsi di database, jadi mengganti `APP_KEY` hanya memutus sesi dan signed URL.
Jika suatu saat kolom terenkripsi ditambahkan, rotasi harus memakai `APP_PREVIOUS_KEYS`; tinjau dulu.

## Sesudah rotasi

1. `dc exec app php artisan ops:check` dan `curl https://<domain>/health/deep`.
2. Kirim satu catatan uji di Telegram.
3. Jika yang bocor adalah token bot atau kunci AI: periksa penggunaan abnormal di konsol penyedia untuk periode kebocoran.
4. Bersihkan tempat kebocoran (riwayat chat, log yang tersebar). Isi `.env` lama jangan disimpan.
