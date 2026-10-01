# Runbook: uji langsung reminder harian (M6)

Pastikan webhook aktif dan user terdaftar (`telegram-live-test.md`), container `scheduler` dan `worker` berjalan:

```bash
docker compose ps scheduler worker
docker compose logs --tail=20 scheduler   # harus ada "reminders:dispatch" tiap menit
```

## Skenario
1. **Atur jam beberapa menit ke depan** (zona waktu user, hari ini harus hari kerja; atau tambahkan hari ini lewat Pengaturan di dashboard):
   `/reminder daily 14:07` → bot membalas jam baru. `/reminder` menampilkan status.
2. **Pesan tiba tepat pada jamnya** (±1 menit) dengan tiga tombol: Tambah catatan / Tidak ada hari ini / Ingatkan 1 jam lagi.
3. **Tidak ada duplikat:** tunggu 2–3 menit, tidak ada pesan kedua.
4. **Tombol:** ulangi dengan jam baru tiap tombol: *Tambah catatan* (bubble berubah jadi ajakan menulis), *Tidak ada hari ini* (tidak diingatkan lagi hari itu), *Ingatkan 1 jam lagi* (pesan kedua muncul 60 menit kemudian; untuk uji cepat ubah `SNOOZE_MINUTES` hanya di lingkungan lokal, jangan di commit).
5. **Sudah mencatat:** catat sesuatu hari ini (tanggal hari ini), lalu atur jam beberapa menit ke depan: tidak ada pesan. Catatan untuk kemarin tidak menahan reminder.
6. **Off/on:** `/reminder off` lalu jam lewat: tidak ada pesan; `/reminder on` mengaktifkan lagi.
7. **Scheduler mati:** `docker compose stop scheduler`, lewati jam reminder lebih dari 2 jam, nyalakan lagi: reminder hari itu tidak dikirim (kedaluwarsa).

Lihat hasil di database (hanya baca):
```bash
docker compose exec -T postgres sh -c 'PGOPTIONS="-c default_transaction_read_only=on" psql -U "$POSTGRES_USER" -d reportflow -c "select id, reminder_date, status, send_count, snooze_count, action_taken from reminder_instances order by id desc limit 5"'
```
