# Runbook: insiden dan alert

`ops:check` (tiap 5 menit) mengirim alert ke Telegram admin (`ADMIN_TELEGRAM_USER_ID`, default user terdaftar pertama), sekali per
kondisi, lalu pesan "pulih". Alert berulang tiap 24 jam bila kondisi bertahan. Alert hanya memuat kode dan angka.

`alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'`. Log aplikasi tidak memuat teks catatan. Access log nginx untuk path webhook
dimatikan, tetapi error log nginx bisa memuat request line: jangan menempel log nginx ke tempat umum.

## Langkah pertama untuk apa pun

```bash
dc ps                                   # siapa yang tidak healthy
curl -s https://<domain>/health/deep    # ok / degraded
dc exec app php artisan ops:check       # kondisi aktif sekarang
dc logs --since 30m worker app | tail -100
```

## Tabel alert

| Alert (kunci) | Arti | Tindakan |
|---|---|---|
| `database` | PostgreSQL tidak terjangkau | `dc ps postgres`, `dc logs postgres`. Disk penuh? (lihat `disk`). `dc restart postgres`. Data ada di volume `postgres_data`; jangan hapus volume |
| `redis` | Redis tidak terjangkau | `dc logs redis`, `dc restart redis`. Queue (AOF) bertahan; sesi login mungkin hilang |
| `gotenberg` | Pembuat PDF mati | `dc restart gotenberg`. Laporan menunggu; job render dicoba ulang. Memori ~500 MB: cek `docker stats` |
| `disk` | Disk > 80% | `docker system df`; `docker image prune -f`; periksa `storage/logs`, volume `backup_data`, file laporan. Jangan menghapus volume data |
| `queue_backlog` | > 50 job menumpuk | Worker hidup? (`worker_*`). `dc logs worker`. Sering akibat AI lambat; lihat halaman Kesehatan (latensi/gagal AI) |
| `failed_jobs` | Ada job gagal 24 jam terakhir | `dc exec app php artisan queue:failed`; baca kelas exception (bukan teks). Perbaiki penyebab, lalu `queue:retry <id>` (job idempotent) |
| `worker_default` / `worker_reports` | Worker berhenti berdetak | `dc restart worker` / `worker-reports`; `dc logs`. Memori worker-reports dibatasi 320 MB, restart otomatis oleh `--max-time` |
| `backup_failed` | Backup terakhir gagal | `cat storage/app/backup/status.json` (`last_error` berisi kode: `database_dump`, `storage_archive`, `encrypt`, `offsite_copy`, `offsite_size_mismatch`, `no_offsite_remote`, `precondition`). Perbaiki dan jalankan manual `backup.sh` |
| `backup_stale` | Backup > 26 jam | Container `backup` jalan? (`dc --profile backup ps`). Cron di dalamnya: `BACKUP_CRON`. Jalankan manual |
| `restore_overdue` | Restore test > 35 hari | Jalankan `restore-test.sh` (`backup-restore.md`) |

Scheduler berhenti: `/health/deep` menjadi `degraded` dan pengingat/`ops:check` tidak jalan. Hanya bisa terdeteksi dari luar:
pasang uptime monitor eksternal ke `https://<domain>/health/deep` (200 = sehat, 503 = bangunkan saya) dengan interval 1–5 menit.

## Bot tidak membalas

1. `curl https://<domain>/health` → web hidup?
2. Apakah Telegram sampai ke kita? `dc logs --since 10m app | tail -50` (log aplikasi tidak memuat teks pesan). Jangan menjalankan perintah
   yang mencetak `.env`.
3. Telegram menolak kita? Token dicabut/diganti → `rotate-secrets.md`. Webhook hilang → `dc exec app php artisan telegram:set-webhook`.
4. Pesan ada di dashboard tapi tidak terproses: `inbound_messages` berstatus `received`/`failed` disimpan; worker `default` harus hidup.
   Pesan tidak hilang walau AI gagal (aturan 7); setelah worker pulih, pesan berstatus `failed` dapat diproses ulang dari dashboard.

## Dugaan kebocoran

Token/kunci terlihat di tempat yang salah: `rotate-secrets.md` segera (cabut dulu, rapikan kemudian). Data klien terkirim ke tempat yang
salah: hentikan (`dc stop nginx`) bila masih berlangsung, catat apa/kapan/ke siapa, beri tahu klien sesuai perjanjian, dan gunakan
`reportflow:purge` bila ada data yang harus dihapus (dry run dulu).

## Sesudah insiden

Catat singkat (kapan, penyebab, tindakan, pencegahan) di `docs/DECISIONS.md` bila mengubah keputusan teknis, atau di issue.
