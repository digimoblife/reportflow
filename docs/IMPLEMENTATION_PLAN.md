# ReportFlow AI — Rencana Implementasi (untuk Claude Code)

Berdasarkan PRD v1.4. Ukuran task memakai skala relatif (S / M / L / XL), bukan estimasi waktu.

---

## 1. Analisis PRD

### Yang sudah kuat
- **Batas tanggung jawab AI vs backend jelas** (§55, §82): AI hanya mengusulkan, backend memvalidasi. Ini membuat hampir seluruh sistem bisa diuji tanpa AI sungguhan.
- **MVP dipangkas realistis** (§72): Phase 1 (worklog) + Phase 2 (laporan bulanan). Reminder lanjutan, incident, search, voice ditunda.
- **Correction/Undo masuk MVP** (§21) dan tabel `corrections` memberi data untuk menyetel threshold.
- **Satu jalur proses untuk dua channel** (§23), lengkap dengan idempotency, optimistic locking, snapshot.
- **Traceability laporan** (§44): per section, `source_activity_ids`, `data_snapshot_at`.
- Cocok dikerjakan Claude Code: schema, state machine, dan acceptance criteria sudah eksplisit.

### Ketidakkonsistenan / celah yang ditemukan
| # | Temuan | Rekomendasi |
|---|---|---|
| 1 | `/update` ada di daftar command §18 tetapi tidak ada di daftar BotFather §20, padahal §20 mewajibkan keduanya sinkron. | Putuskan: buang `/update` (natural language sudah cukup) atau tambahkan ke BotFather. Buat satu sumber daftar command di kode dan daftarkan via `setMyCommands`. |
| 2 | Rule 4 (raw input diawetkan) vs Rule 13 (secret tidak pernah disimpan). | Definisikan "raw" = teks setelah redaction (sesuai `inbound_messages.text`). Catat di CLAUDE.md. |
| 3 | §81 menaruh Evaluation Dataset di urutan #4, tetapi §75 menyebut dataset harus ada sebelum prompt dikembangkan, dan dataset butuh pelabelan manual. | Mulai mengumpulkan dan melabel dataset sejak M0, paralel dengan coding. Ini item dengan lead time terpanjang dan tidak bisa didelegasikan ke Claude Code. |
| 4 | `tasks.type` dan `tasks.priority` ada di schema (§49) tetapi nilai enum-nya tidak didefinisikan (activity type ada di §15). | Tentukan enum di M1 (usulan: `type` = tipe pekerjaan sederhana, `priority` = low/normal/high). |
| 5 | Telegram Login Widget mewajibkan domain publik HTTPS yang didaftarkan lewat `/setdomain` di BotFather. Webhook Telegram juga butuh HTTPS publik. | Siapkan domain + tunnel untuk dev (mis. cloudflared/ngrok) sejak M0. Alternatif dev: long polling untuk bot, login dashboard dev via mode khusus. |
| 6 | VPS 4 GB RAM harus menampung PHP-FPM, worker, Postgres, Redis, dan Chromium (Gotenberg). | Batasi konkurensi Gotenberg (1 proses PDF sekaligus), pasang swap, pasang limit memory per container, pantau saat M7. |
| 7 | Data Deletion (§57) "via administrative operation" tanpa bentuk konkret. | Buat artisan command `reportflow:purge` (activity/task/project/report/raw message) di M9. |
| 8 | Model `deepseek-flash` (DeepSeek V4.1-Flash) dan dukungan JSON mode/kebijakan retensi datanya perlu diverifikasi saat implementasi. | Verifikasi ke dokumentasi DeepSeek di awal M3. `AIService` sudah abstrak, jadi risikonya rendah. Kebijakan retensi tetap perlu dicek sebelum dipakai untuk data klien (§56). |
| 9 | Dataset evaluasi berisi nama klien nyata. | Jangan commit ke git. Simpan di `tests/Eval/data/` (gitignored) dan sediakan versi anonim kecil untuk CI. |

### Keputusan Terbuka (jawab sebelum milestone terkait)
1. Framework test: **Pest** (default di rencana ini) atau PHPUnit? (M0)
2. Versi PHP/Laravel: ikuti stabil terbaru saat M0. (M0)
3. Domain untuk dashboard + webhook. (M0/M2)
4. Nasib `/update`. (M2)
5. Enum `tasks.type` dan `tasks.priority`. (M1)
6. Lokasi backup di luar VPS: snapshot provider, rsync, atau rclone ke cloud? (M9)
7. Apakah kirim data ke DeepSeek disetujui untuk semua project (perjanjian kerahasiaan klien)? (M3)

---

## 2. Cara bekerja dengan Claude Code

### Struktur repo yang disarankan
```
reportflow/
├── CLAUDE.md
├── docs/
│   ├── PRD.md                    ← salin PRD_ReportFlow_AI_v1_4.md
│   └── IMPLEMENTATION_PLAN.md    ← dokumen ini
├── .claude/skills/
│   ├── prompt-eval/SKILL.md
│   └── pak-carik-messages/SKILL.md
├── app/ ... resources/prompts/ ... lang/{id,en}/ ...
├── tests/Eval/                   ← harness eval; data/ di-gitignore
└── docker/ ...
```

### Alur kerja per milestone
1. Mulai sesi baru, **Plan Mode**: minta Claude Code membaca section PRD yang tercantum di milestone dan mengusulkan rencana.
2. Review rencana, koreksi, lalu izinkan eksekusi.
3. Kerjakan task satu per satu; test hijau dulu sebelum lanjut; commit kecil.
4. Di akhir milestone jalankan checklist Definition of Done milestone tersebut, lalu tutup sesi.
5. Jika ada temuan baru (bug PRD, keputusan baru), catat di bagian "Keputusan Terbuka" atau di `docs/DECISIONS.md`, bukan hanya di chat.

### Tips
- Satu milestone = satu (atau beberapa) sesi pendek. Konteks yang bersih lebih baik daripada sesi panjang.
- Minta Claude Code selalu menyebut section PRD yang dipakai.
- Untuk M3 (AI) dan M7 (laporan), pertimbangkan meminta review terpisah (sesi baru, "review diff ini terhadap PRD §…").
- Opsional: tambahkan hook yang menjalankan `pint` dan test setelah edit, dan izinkan perintah `docker compose exec app ...` di `.claude/settings.json` agar tidak terus meminta konfirmasi.

---

## 3. Roadmap Milestone

Urutan mengikuti PRD §81, dikelompokkan agar tiap milestone menghasilkan sesuatu yang bisa dites.

### M0 — Fondasi & Tooling (M)
**PRD:** §83, §56 (secrets), §20 (bot setup)
**Tugas**
- Repo, Laravel, Docker Compose (`app`, `worker`, `scheduler`, `postgres`, `redis`, `gotenberg`, `nginx`), `.env.example`.
- Filament terpasang, Pest, Pint, Larastan/PHPStan, CI (lint + test).
- Login email/password Filament hanya aktif jika APP_ENV=local (tanpa kredensial default di kode, seeder dev tidak jalan di production). TODO: hapus di M5 saat Telegram Login Widget diimplementasikan.
- Ekstensi Postgres `pg_trgm`. Health-check endpoint.
- `CLAUDE.md`, `docs/`, skill dimasukkan ke repo. `.gitignore` untuk `tests/Eval/data/`.
- Domain + HTTPS + tunnel dev (cloudflared).
**Manual (Anda):** buat bot via BotFather, set username, token disimpan hanya di `.env`; siapkan VPS/domain; **mulai kumpulkan pesan untuk Evaluation Dataset**.
**DoD:** `docker compose up` menjalankan semua service; test contoh hijau di CI; tidak ada secret di repo.
**Prompt awal:** "Baca CLAUDE.md dan PRD §83 §56. Usulkan rencana scaffolding M0 (Plan Mode). Jangan tulis kode dulu."

### M1 — Data Model & Domain Inti (M)
**PRD:** §49, §14, §15, §29
**Tugas**
- Migrasi semua tabel MVP di §49 (tabel `incidents` boleh ditunda ke Phase 3) + index yang direkomendasikan + unique `idempotency_key`.
- Model, relasi, factory, enum (task status, activity type, dsb), global scope `user_id`.
- `TaskStatusTransition` (matriks §14) sebagai class murni tanpa dependensi framework, dengan test lengkap untuk semua kombinasi.
- Seeder demo.
**DoD:** `migrate:fresh --seed` bersih; test matriks transisi lengkap; keputusan enum `type`/`priority` tercatat.

### M2 — Ingestion Telegram & Redaction (L)
**PRD:** §7, §18, §19, §20, §23 (idempotency), §56
**Tugas**
- Webhook Telegram: verifikasi `X-Telegram-Bot-Api-Secret-Token`, path tidak mudah ditebak, whitelist `telegram_user_id`.
- Simpan `inbound_messages` (status `received`), balas "⏳ Mencatat…", dispatch job. `telegram_message_id` sebagai idempotency key; webhook dikirim ulang tidak membuat data ganda.
- `RedactionService` (API key `sk-`, `ghp_`, `AKIA`, password, private key, connection string, pola tambahan per user) + test dengan banyak kasus. Notifikasi ke user saat ada credential.
- `WorklogService` kerangka dengan `FakeAiProvider`; edit pesan konfirmasi.
- Sistem template pesan (`lang/id`, `lang/en`) dengan 2–3 variasi acak — gunakan skill `pak-carik-messages`.
- Onboarding `/start`, `/help`; daftarkan command via `setMyCommands` dari satu sumber.
**DoD:** pesan Telegram masuk, tercatat, dibalas "⏳", lalu diedit menjadi konfirmasi dummy; webhook duplikat tidak membuat entri ganda; redaction lulus test.

### M3 — AI Extraction & Evaluation Harness (XL)
**PRD:** §12, §13, §50–§54, §75, §79
**Prasyarat manual:** Evaluation Dataset minimal 50 pesan berlabel (termasuk kasus sulit §75) + snapshot state task.
**Tugas**
- `AIService` + `DeepSeekProvider` + `FakeAiProvider`; JSON mode; validasi JSON schema; retry sekali lalu tandai `failed`.
- Candidate retrieval (context injection): aturan §12, termasuk mode ringkas saat project tidak terdeteksi.
- Prompt v1 di `resources/prompts/worklog_extraction/v1.md`; satu AI call, multi-item.
- Validator backend 1–8 (§54): schema, `task_id` ∈ candidate list, ownership, project, transisi status, tanggal (tidak di masa depan; >30 hari lalu → butuh konfirmasi), kombinasi confidence + sinyal deterministik (§13).
- Logging `ai_interactions` (token, latency, `prompt_version`).
- Perintah `php artisan eval:run` → laporan akurasi (worklog extraction, project, task matching, date) dibanding target §73. Gunakan skill `prompt-eval`.
**DoD:** eval berjalan dan menghasilkan angka; threshold 0.90/0.70 disetel berdasarkan hasil; tidak ada test CI yang memanggil DeepSeek.

### M4 — Task System & Correction UX (L)
**PRD:** §10, §11, §14, §21, §47, §59, §67–§69
**Tugas**
- Terapkan hasil AI ke DB dalam satu transaction: `tasks`, `activities`, `task_events`, `task_people`.
- Multi-item message; pesan konfirmasi dengan tombol **Undo / Pindah Task / Ubah Status / Ganti Project**.
- Koreksi lewat reply pesan konfirmasi; klarifikasi task ambigu (§59, §69); `corrections` tercatat.
- Command `/undo`, `/inbox`, `/tasks`, `/task`, `/projects`, `/project`.
- Status Completed/Cancelled dari AI selalu tampil eksplisit dan bisa di-undo.
**DoD:** skenario end-to-end di §7 berjalan; undo memulihkan state persis; semua write AI punya jalur undo.

### M5 — Web Dashboard Inti & Sinkronisasi (L)
**PRD:** §22, §23, §56 (Dashboard), §74
**Tugas**
- Login Telegram Login Widget dengan verifikasi hash + whitelist + session timeout (hapus fallback login email/password dev dari M0).
- Halaman: Input Worklog (multi-item, tombol koreksi yang sama), Tasks (filter, detail timeline, edit, ubah status via matriks, pindah activity), Inbox, Settings.
- Idempotency key untuk submit dashboard; optimistic locking (`tasks.version`).
- Livewire polling (~5 detik); klarifikasi dua arah (pesan Telegram diedit jadi "✅ Sudah dijawab via dashboard").
**DoD:** kriteria §74 poin 1–2 terpenuhi (tampil ≤10 detik, 0 duplikat); konflik edit ditolak dengan pesan jelas.

### M6 — Reminder Harian (S–M)
**PRD:** §25 (Daily), §28, §29, §34
**Tugas**
- `reminder_rules` + `reminder_instances`; scheduler tiap menit; hanya hari kerja, tidak dikirim jika sudah ada activity, tidak diulang jika "Nothing Today".
- Snooze (1 jam / besok), enable/disable, batas global 3 reminder/hari, `/reminder`.
**DoD:** test dengan waktu dibekukan (Carbon::setTestNow) untuk semua aturan pengiriman.

### M7 — Report Engine & Dashboard Reports (XL)
**PRD:** §36–§46, §64, §65, §70, §84, §85
**Tugas**
- Pemilihan data per periode (cross-month, §16, §45) dan snapshot (`data_snapshot_at`, `source_activity_ids`).
- Pre-generate check entri `received`/`processing` (Tunggu / Generate tanpa) dan `generation_lock_until` (satu proses generate per report).
- Generate per section oleh AI (prompt `report_section/v1`), cek traceability (task yang disebut harus ada di data periode).
- Template generic id + en: Blade + CSS (`@page`, header/footer, nomor halaman). Markdown → HTML (CommonMark) → PDF (Gotenberg). `report_files` + checksum; signed URL.
- Dashboard Reports: generate, preview (identik dengan PDF), editor Markdown per section, **edit via instruksi** (fakta baru → activity dulu, §42/Rule 14), riwayat versi, approve (versi approved immutable).
- Worker terpisah untuk queue `reports` (concurrency 1) dan cek RAM (PRD §83, risiko VPS RAM 4 GB & lonjakan Gotenberg).
**DoD:** kriteria PDF ≥99%, preview = PDF, tiap PDF disertai `.md`; test snapshot untuk pemilihan periode.

### M8 — Review via Telegram, Reminder Bulanan, Late Entries (M)
**PRD:** §42, §43, §71, §62, §63
**Tugas**
- Review via Telegram: ringkasan draft + PDF, Approve / Regenerate / Edit via instruksi / Buka di Dashboard / Cancel.
- Monthly Report Reminder kontekstual (jumlah catatan, selesai, waiting).
- Deteksi late entries: report `outdated`, banner "Perbarui Draft", notifikasi "Buat Versi Baru".
**DoD:** skenario contoh §23 (pagi Telegram, sore dashboard) berjalan seperti tertulis.

### M9 — Hardening & Go-Live (M)
**PRD:** §56, §57, §58, §78, §79, §84
**Tugas**
- Backup harian (`pg_dump` custom + arsip storage + `.env` terenkripsi) ke lokasi di luar VPS; retensi 7 harian + 4 mingguan; notifikasi gagal ke Telegram admin; **uji restore** ke environment terpisah.
- Monitoring disk >80% dan container mati; log error terstruktur; metrik §78; dashboard biaya AI (§79).
- `reportflow:purge` untuk penghapusan data (§57).
- Rate limit webhook/login, security review, runbook (deploy, rollback, rotasi token).
- **Dogfooding satu siklus laporan bulanan penuh** sebelum Phase 3.
**DoD:** restore test berhasil; semua acceptance criteria §73, §74 terukur dan tercapai; User Correction Rate dipantau (<15%).

### Setelah MVP
Phase 3 (§72): weekly report, template per project, reminder lanjutan, incident, search, voice, DOCX. Rencanakan ulang berdasarkan data pemakaian nyata, terutama tabel `corrections`.

---

## 4. Yang perlu dikerjakan manusia (tidak bisa didelegasikan)

- Membuat dan menjaga token bot di BotFather; rotasi jika bocor (§56).
- Menyiapkan VPS, domain, DNS, dan lokasi backup di luar VPS.
- Mengumpulkan dan melabel Evaluation Dataset (mulai dari M0).
- Menyetujui kebijakan data dengan provider AI dan perjanjian kerahasiaan klien.
- Membuat avatar / aset brand (`resources/brand/`) bila belum ada.
- Review hasil tiap milestone terhadap PRD dan menjawab Keputusan Terbuka.

## 5. Risiko utama

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Akurasi task matching di bawah target | Laporan tercemar, correction rate tinggi | Eval harness sejak M3, sinyal deterministik, Undo/koreksi mudah, dataset diperkaya dari `corrections` |
| Dataset evaluasi terlambat | M3 tertahan | Mulai di M0, target 50 pesan dulu |
| Memori VPS habis saat PDF | Generate gagal | Batasi konkurensi Gotenberg, swap, limit container, monitoring |
| Scope dashboard terlalu besar untuk satu developer | Molor | Manfaatkan Filament CRUD; halaman custom hanya Input, Inbox, Reports |
| Data klien terkirim ke provider AI | Pelanggaran kerahasiaan | Redaction, tinjau kebijakan provider, `AIService` bisa diganti/di-route per project |
| Backup tidak pernah diuji | Data hilang saat dibutuhkan | Restore test bulanan, wajib di M9 |
