# Runbook: rollback

Kapan: sesudah deploy ada error baru, health `degraded`, atau perilaku bot salah, dan perbaikannya tidak jelas dalam beberapa menit.

## 1. Kembalikan kode (image)

Jangan mengubah riwayat git di `master`. Ada dua cara; pilih yang tercepat:

**a. Image sebelumnya** (deploy rutin menyimpan tag `:previous`):
```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
docker tag reportflow-app:previous reportflow-app:latest
docker tag reportflow-nginx:previous reportflow-nginx:latest
dc up -d --no-build
```

**b. Commit penebus (revert)**: `git revert <sha>` di workstation, push, lalu deploy rutin (`deploy.md` B).

## 2. Migrasi database

Migrasi dibuat maju-saja dan aman bila kode lama berjalan di atas skema baru (kolom/tabel baru tidak merusak kode lama).
Karena itu rollback kode **tidak** memutar mundur migrasi. Jangan menjalankan `migrate:rollback` di production kecuali
sebuah migrasi terbukti salah dan Anda sudah membaca method `down()`-nya.

Jika skema atau data rusak (migrasi yang merusak data):
1. Hentikan penulis: `dc stop worker worker-reports scheduler app`.
2. Pulihkan ke database baru dari backup terbaru sebelum deploy (`backup-restore.md`, bagian "Pemulihan bencana"),
   arahkan `DB_DATABASE` ke database itu, jalankan kembali.
3. Catatan yang masuk setelah backup itu hilang. Telegram menyimpan pesan terakhir user; minta user mengirim ulang.

## 3. Sesudah rollback

- `dc ps`, `/health/deep`, `ops:check` seperti di `deploy.md`.
- Tulis penyebab dan perbaikannya di `docs/DECISIONS.md` atau issue, lalu perbaiki ke depan dengan commit baru.
