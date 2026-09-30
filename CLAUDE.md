# ReportFlow AI (bot: Pak Carik)

Asisten worklog & laporan. User mencatat pekerjaan lewat Telegram atau Web Dashboard, AI menafsirkan dan
menghubungkannya ke task yang sudah ada, lalu laporan (PDF + Markdown) disusun dari data tersimpan.

- **Sumber kebenaran produk:** `docs/PRD.md` (v1.4). Baca section yang relevan SEBELUM mengerjakan fitur.
  Sebutkan nomor section PRD di pesan commit / deskripsi PR (contoh: `feat(tasks): transition matrix (PRD §14)`).
- **Rencana kerja:** `docs/IMPLEMENTATION_PLAN.md`. Kerjakan per milestone, urut. Jangan lompat milestone.
- Jika PRD ambigu atau bertentangan dengan dirinya sendiri, cek daftar "Keputusan Terbuka" di rencana.
  Jika belum tercakup, **tanya user**, jangan menebak.

## Stack

Laravel (versi stabil terbaru, dikunci di `composer.lock`), PostgreSQL, Redis (queue), Laravel Filament (Livewire),
Gotenberg (Chromium, PDF), DeepSeek di belakang `AIService`, Telegram Bot API (webhook).
Semua berjalan di satu VPS via Docker Compose: `app`, `worker`, `scheduler`, `postgres`, `redis`, `gotenberg`, `nginx`.
PostgreSQL, Redis, Gotenberg hanya di network internal Docker.

## Aturan yang tidak boleh dilanggar (PRD §82)

1. **AI proposes, backend validates, database owns the truth.** AI tidak pernah menulis ke DB secara langsung.
2. **Satu jalur proses.** Telegram dan dashboard sama-sama masuk ke `inbound_messages` → queue → `WorklogService`.
   Dilarang membuat logic tulis data yang khusus satu channel.
3. **AI hanya boleh memilih `task_id` dari candidate list** yang disiapkan backend. Backend menolak id di luar list.
4. **Status task hanya berubah lewat transition matrix (PRD §14).** Setiap perubahan tercatat di `task_events`.
5. **Setiap write dari AI harus bisa di-undo.** Undo = `task_events` baru + catatan di `corrections`. Riwayat tidak dihapus.
6. **Redaction sebelum simpan dan sebelum kirim ke AI.** Secret/credential tidak pernah disimpan, dikirim ke AI,
   di-log, atau muncul di test fixture. Jangan membaca atau mencetak isi `.env`.
7. **Raw input diawetkan** (`inbound_messages.text` = teks setelah redaction). Kegagalan AI tidak boleh menghilangkan pesan.
8. **Pesan bot memakai template tetap** di `lang/id` dan `lang/en` (persona Pak Carik), bukan buatan AI.
   Persona hanya untuk Telegram dan teks UI dashboard. **Laporan PDF/MD selalu formal dan netral.**
9. **Laporan dibuat per section dari snapshot** (`data_snapshot_at`, `source_activity_ids`). AI tidak boleh mengarang.
   Fakta baru saat edit laporan disimpan sebagai activity dulu (`source = report_edit`).
10. **Setiap PDF selalu disertai file Markdown.** Download hanya lewat signed URL; file di private disk.
11. **Scope semua query per `user_id`** sejak awal walau MVP single user.

## Konvensi kode

- Kode, identifier, komentar, commit message: **bahasa Inggris**. String untuk user: lewat `lang/id` + `lang/en`.
- Simpan waktu dalam UTC; tampilkan sesuai `users.timezone` (default `Asia/Jakarta`).
- Status, tipe activity, dsb: PHP backed enum di `app/Enums`. Nilainya ikut PRD (§14, §15, §29, dst).
- Struktur: `app/Services/{Worklog,Ai,Redaction,Report,Reminder,Telegram}`, `app/Jobs`, `app/Filament`.
  Controller/webhook tipis; logic di service.
- Semua job idempotent dan aman di-retry.
- Semua panggilan AI lewat `AIService`; catat ke `ai_interactions` (termasuk `prompt_version`).
- Prompt disimpan sebagai file versi: `resources/prompts/<nama>/v<N>.md`. Ubah prompt = naikkan versi + jalankan eval
  (lihat skill `prompt-eval`).
- Test memakai `FakeAiProvider`; **tidak ada test yang memanggil DeepSeek atau Telegram sungguhan.**
- Tulis test bersama fitur. Prioritas test: transition matrix, redaction, validator AI output, idempotency,
  undo, optimistic locking, pemilihan activity per periode laporan.

## Perintah (dibuat pada M0; sesuaikan bila berbeda)

```
docker compose up -d
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app php artisan eval:run        # tersedia sejak M3
```

Definition of Done tiap task: test hijau, pint + phpstan bersih, migrasi bisa `migrate:fresh --seed`, tidak ada
secret di diff, dan PRD section terkait sudah dicek ulang.

## Cara bekerja

- Mulai milestone baru dengan **Plan Mode**; tunjukkan rencana sebelum menulis kode.
- Satu sesi = satu milestone atau satu kelompok task kecil. Commit kecil dan sering.
- Jangan mengubah file di luar scope task. Jangan menambah dependency besar tanpa bertanya.
- Jangan pernah commit `.env`, token bot, API key, atau dataset evaluasi berisi data klien asli
  (`tests/Eval/data/` masuk `.gitignore`; gunakan versi anonim untuk contoh).
- Jangan mengerjakan fitur Phase 3+ (weekly report, incident, search, voice, DOCX, reminder lanjutan, template per project).

## Di luar MVP

Lihat PRD §4 (Non-Goals) dan §72 Phase 3–5. Jika user meminta fitur di daftar itu, ingatkan bahwa itu di luar MVP
dan tanyakan apakah scope sengaja diperluas.
