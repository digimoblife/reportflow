# Go-live checklist

Centang berurutan. Bagian A–C adalah tugas manusia yang tidak bisa dikerjakan dari repository; D–F memakai runbook lain.
Jangan melewati bagian: setiap item ada karena sebuah risiko di `docs/IMPLEMENTATION_PLAN.md` §5 atau `docs/SECURITY_REVIEW.md`.

## A. Sebelum menyentuh server

- [ ] Kebijakan data DeepSeek (retensi, lokasi pemrosesan) sudah dibaca dan **sesuai perjanjian kerahasiaan dengan klien** (PRD §56).
      Jika ragu, mulai hanya dengan project internal.
- [ ] Token bot dibuat di @BotFather; **bukan token yang pernah muncul di chat, log, atau dokumen** (PRD §56). Bila pernah: `/revoke`.
- [ ] VPS (≥ 4 GB RAM, ada swap), akses SSH hanya dengan kunci, firewall hanya 22/80/443, pembaruan otomatis keamanan aktif.
- [ ] Domain, DNS A/AAAA ke VPS, sudah menyebar (`dig +short <domain>`).
- [ ] Lokasi backup di luar VPS (bucket/penyimpanan cloud) dengan kredensial yang hanya boleh menulis ke sana; rclone remote disiapkan.
- [ ] Kunci backup dibuat **dan disalin ke tempat lain** (password manager).
- [ ] Harga token DeepSeek diisi di `config/ai.php` (`pricing`) bila ingin estimasi biaya di halaman Kesehatan; kosong = hanya token.
- [ ] Uptime monitor eksternal (UptimeRobot/BetterStack/dsb.) ke `https://<domain>/health/deep`, peringatan ke ponsel Anda.

## B. Deploy

- [ ] `deploy.md` bagian A selesai tanpa `--staging`; `https://<domain>/` redirect ke `/admin`, sertifikat valid (`curl -vI https://<domain>`).
- [ ] `http://<domain>` → 301 ke https; `curl -sk https://<IP-VPS>/` ditolak (handshake gagal).
- [ ] `docker compose ... ps`: semua healthy; hanya port 80/443 terbuka (`ss -tlnp` di VPS, dan `nmap <ip>` dari luar: 5432/6379/3000 tertutup).
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `chmod 600`, tidak ada `DEV_USER_*`.
- [ ] `reportflow:user:create` untuk Anda; login dashboard lewat Telegram berhasil (`/setdomain` sudah diatur di @BotFather).
- [ ] `telegram:set-webhook` dan `telegram:sync-commands` berhasil; `getWebhookInfo` (via perintah, bukan token di chat) tanpa error terakhir.

## C. Bukti bahwa pengaman bekerja

- [ ] Backup manual pertama berhasil; arsip muncul di remote (`rclone ls`); `status.json` `last_status=ok`.
- [ ] **Restore test berhasil** (`backup-restore.md`), juga dari arsip di remote saja. Ini syarat DoD M9.
- [ ] Alert uji: hentikan Redis sebentar (`dc stop redis`), tunggu ≤ 5 menit sampai pesan "Redis tidak bisa dihubungi" masuk, nyalakan lagi
      (`dc start redis`), pastikan pesan pulih masuk.
- [ ] Alert backup: dengan `BACKUP_REMOTE` kosong sementara, backup manual harus menandai gagal dan alert datang (kembalikan nilainya).
- [ ] Rate limit: 15 permintaan cepat ke `/admin/login` menghasilkan 429 sebagian.
- [ ] Kirim catatan sungguhan di Telegram: terkonfirmasi, tampil di dashboard; `/undo` bekerja.
- [ ] Buat satu laporan uji (project internal), unduh PDF + MD lewat tombol (signed URL), buka PDF.
- [ ] `reportflow:purge` dry run pada data uji menampilkan jumlah yang masuk akal (jangan `--force` pada data nyata).

## D. Operasional

- [ ] Anda tahu cara membaca halaman **Kesehatan** (target ✓/✗) dan alert (`incident.md`).
- [ ] `rollback.md` dibaca; tag `:previous` ada setelah deploy kedua.
- [ ] Jadwal tetap: restore test tiap bulan (alert `restore_overdue` mengingatkan), rotasi secret berkala (`rotate-secrets.md`).
- [ ] Rebuild image bulanan (`build --pull`) untuk patch keamanan; `composer audit` hijau di CI.

## E. Dogfooding

- [ ] Satu siklus laporan bulanan penuh dengan data nyata — `dogfooding.md`. Selesai dan lolos sebelum mengerjakan Phase 3.

## F. Belum dicakup (diketahui)

- Prompt laporan/instruksi/koreksi belum punya eval offline (hanya ekstraksi worklog); pantau lewat User Correction Rate.
- Tanpa CSP dan tanpa WAF (alasan di `docs/SECURITY_REVIEW.md`).
- Satu user (MVP); semua query sudah di-scope per `user_id` agar multi-user tidak butuh perombakan.
