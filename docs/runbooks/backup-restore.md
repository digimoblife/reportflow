# Runbook: backup dan restore (PRD §84)

Backup harian terenkripsi ke luar VPS, dan restore test yang benar-benar dijalankan. Backup yang tidak pernah diuji
dianggap tidak ada.

`alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'` (di dev cukup `docker compose`).

## Isi dan perlindungan

- Isi arsip: dump PostgreSQL (`pg_dump -Fc`), file laporan (`storage/app/private`, beserta manifest SHA-256),
  salinan `docker-compose*.yml` dan `.env`. Semuanya dienkripsi AES-256-CBC (PBKDF2, 600 000 iterasi) dengan **kunci yang tidak
  ikut disimpan bersama backup**.
- Jadwal: `BACKUP_CRON` (UTC, default `0 19 * * *` = 02:00 WIB). Retensi 7 harian + 4 mingguan (Minggu), lokal dan di remote.
- Tanpa `BACKUP_REMOTE`, backup ditandai **gagal** (`no_offsite_remote`) dan Anda diberi alert: arsip di server yang sama bukan backup.
- Status ada di `storage/app/backup/status.json`; `ops:check` memberi alert bila backup gagal, lebih tua dari 26 jam, atau restore test
  lebih tua dari 35 hari.

## Menyiapkan (sekali)

1. Kunci (di VPS, jangan di repo; `secrets/` ada di `.gitignore`/`.dockerignore`):
   ```bash
   mkdir -p secrets/rclone && chmod 700 secrets
   head -c 48 /dev/urandom | base64 > secrets/backup.key && chmod 600 secrets/backup.key
   ```
   **Simpan salinan kunci di tempat lain** (password manager). Tanpa kunci, semua arsip tidak bisa dibuka.
2. Remote rclone: `rclone config` di mesin Anda (S3/B2/Drive/dsb.), salin `rclone.conf` ke `secrets/rclone/rclone.conf`
   (`chmod 600`). Isi `.env`: `BACKUP_REMOTE=namaremote:bucket/reportflow`. Gunakan kredensial yang hanya boleh menulis ke bucket itu.
3. Jalankan: `dc --profile backup up -d --build backup`.
4. Backup pertama manual dan lihat hasilnya:
   ```bash
   dc --profile backup run --rm backup /scripts/backup.sh
   cat storage/app/backup/status.json
   ```

## Restore test (bulanan, wajib sebelum go-live)

```bash
dc --profile backup run --rm backup /scripts/restore-test.sh
```

Skrip: memeriksa checksum, membuka enkripsi, mencocokkan file dengan manifest, memulihkan dump ke database sementara
`restore_test_<epoch>` (dihapus sesudahnya), mencocokkan jumlah tabel dengan database hidup, dan mencetak jumlah baris tabel kunci.
Database dan file hidup tidak disentuh. Lolos → `last_restore_verified_at` terisi di status. Jika gagal, jangan menunggu bulan depan:
cari tahu penyebabnya sekarang.

Uji juga satu kali dari remote saja (hapus arsip lokal di volume `backup_data`, atau jalankan dari mesin lain dengan kunci dan rclone yang sama):
skrip mengambil arsip terbaru dari `BACKUP_REMOTE` bila lokal kosong.

## Pemulihan bencana (VPS hilang atau data rusak)

1. VPS baru (atau hentikan penulis: `dc stop worker worker-reports scheduler app`).
2. Pasang kembali `secrets/backup.key` dan `secrets/rclone/` dari salinan Anda; ambil arsip dari remote:
   `rclone copy namaremote:bucket/reportflow/daily/<arsip>.tar.enc ./` (dan `.sha256`).
3. Pulihkan ke database dan direktori **baru**:
   ```bash
   dc --profile backup run --rm -v "$PWD:/restore" backup /scripts/restore.sh \
       --archive /restore/<arsip>.tar.enc --database reportflow_restored --files /restore/restored-files --yes
   ```
   Skrip menolak menimpa database hidup atau direktori yang tidak kosong. Salinan `.env`/compose ada di `restored-files/config/`.
4. Arahkan aplikasi ke hasil pulih: `DB_DATABASE=reportflow_restored` di `.env`, salin isi `restored-files/private` ke volume
   `app_storage` di `storage/app/private`, lalu `dc up -d --force-recreate`.
5. Periksa: `ops:check`, halaman Kesehatan, dan satu laporan lama bisa diunduh.
6. Telegram: kirim pengumuman singkat bila ada catatan setelah titik backup yang perlu dikirim ulang.

## Penghapusan data klien

Backup menyimpan data lama. `reportflow:purge` menghapus dari database hidup saja; arsip lama habis sendiri sesuai retensi (maks. ~5 minggu).
Jika klien meminta penghapusan total, catat tanggal permintaan, jalankan purge, dan pastikan arsip yang masih memuatnya sudah lewat retensi
atau hapus arsip itu secara manual dari remote.
