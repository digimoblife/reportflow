# Keputusan Desain (Decision Log)

Catatan keputusan yang diambil saat implementasi, beserta alasannya. Sumber kebenaran produk tetap
`docs/PRD.md`; dokumen ini mencatat hal yang PRD tidak atur, atur secara ambigu, atau yang kita
putuskan berbeda. Setiap entri menyebut section PRD terkait.

---

## M1 — Data Model & Domain Inti

### Tooling

- **PHP 8.3 sebagai target.** Docker dan CI memakai PHP 8.3. `composer.json` memuat
  `config.platform.php = 8.3.0` dan `phpstan.neon` memuat `phpVersion: 80300`, sehingga tooling lokal
  dengan PHP yang lebih baru tetap menganalisis dan me-resolve paket untuk 8.3. Fitur khusus PHP 8.4
  (property hooks, asymmetric visibility, dll.) tidak boleh dipakai.
- **PHPStan level 7** tanpa baseline, menganalisis `app/` dan `database/`. Target level 8 pada M4.

### Waktu dan tanggal (PRD §49, CLAUDE.md konvensi)

- Semua kolom timestamp memakai `timestamptz` dan disimpan dalam UTC. Timestamp bawaan tabel `users`
  ikut dikonversi ke `timestamptz`. Tabel framework (`sessions`, `cache`, `jobs`) tidak diubah.
- Koneksi `pgsql` memaksa `timezone = UTC` untuk session database.
- Model menulis timestamp dengan offset (`Y-m-d H:i:sP`, trait `StoresTimestampsWithOffset`).
  Tanpa ini Eloquent membuang offset sehingga Carbon ber-zona `Asia/Jakarta` tersimpan seolah UTC.
- `activities.activity_date`, `reports.period_start`, `reports.period_end` bertipe `date`: tanggal
  kalender dalam zona waktu user, bukan titik waktu. Konversi ke zona user dilakukan sebelum menulis.

### Enum dan nilai kolom

- Nilai enum PHP di `app/Enums` mengikuti PRD. Di database, kolom bernilai tertutup memakai
  `varchar` + `CHECK` (via `$table->enum()`), dengan nilai **ditulis literal di migrasi**, bukan
  dibaca dari `Enum::cases()`, agar migrasi lama tidak berubah ketika enum berubah. Menambah nilai =
  migrasi baru yang mengganti constraint.
- `tasks.priority`: `low / normal / high`, default `normal` (Keputusan Terbuka #5 di rencana).
- `tasks.type`: string bebas, nullable, **tanpa enum**; diisi AI nanti dan tidak dibatasi.
- `tasks.status`: **tanpa default di DB**. Penulis (service M4, factory, seeder) wajib memilih
  status awal secara eksplisit. Status awal yang sah saat create diputuskan di M4.
- `InboundMessageStatus` = lima nilai PRD §48: `received, processing, processed,
  needs_clarification, failed`. Pesan yang di-undo tetap `processed`; undo tercatat di `corrections`.
- `ActivitySource` = `telegram, dashboard, report_edit, manual`. **`dashboard` ditambahkan** (tidak ada
  di PRD §49): input worklog dari dashboard juga melewati AI dan harus bisa dibedakan dari
  `manual` (edit langsung lewat form task).
- `ReportType` dan `ReportFileFormat` memuat nilai Phase 3 (`weekly`, `incident`, `docx`) sesuai §49;
  fiturnya tetap tidak dibangun di MVP. `ReminderType` hanya nilai MVP (§34): `daily_worklog`,
  `monthly_report`.
- Enum tambahan: `ReminderPriority` (§27: low/normal/high/critical) dan `Language` (`id`, `en`).
- `users.default_language` NOT NULL default `id`. `projects.default_language` nullable: `null` berarti
  mengikuti bahasa user.
- `reminder_instances.action_taken` dan `ai_interactions.purpose` sementara string (maks 64);
  enum-nya diputuskan di M6/M3.

### Constraint dan relasi

- **ON DELETE**
  - Rantai kepemilikan (users → projects → tasks → activities, reports, reminder_rules, dst.):
    `RESTRICT`. Tidak ada cascade tak sengaja yang menghapus riwayat audit.
  - Tabel join murni (`task_people`): `CASCADE`.
  - Link provenance opsional (`*.inbound_message_id`, `ai_interactions.project_id/report_id`,
    `reminder_instances.task_id`, `projects.report_template_id`, `reports.current_version_id`):
    `SET NULL`.
  - Konsekuensi untuk **`reportflow:purge` (M9, §57)**: purge harus menghapus secara berurutan dari
    daun ke akar, misalnya `report_files` → `report_versions` → `reports`; `task_events`,
    `activities`, `task_people`, `reminder_instances` → `tasks` → `projects`; lalu `corrections`,
    `ai_interactions`, `inbound_messages`, `reminder_rules`, `report_templates`, `people` → `users`.
    Karena task/activity memakai soft delete, purge harus memakai force delete.
- **Composite FK** `activities (task_id, project_id) → tasks (id, project_id)` dengan
  `ON UPDATE CASCADE`. Memindahkan task ke project lain otomatis memindahkan activity-nya, dan
  activity dengan `project_id` yang tidak cocok dengan task-nya ditolak database.
- `activities.task_id` NOT NULL: setiap activity terikat ke task.
- `tasks`: `CHECK (status = 'waiting' OR waiting_reason IS NULL)`. `waiting_reason` boleh `null` saat
  `waiting` (AI mungkin tidak tahu alasannya); service harus mengosongkannya saat keluar dari `waiting`.
- `inbound_messages`: `CHECK` bahwa `source = telegram` mewajibkan `telegram_chat_id` dan
  `telegram_message_id`.
- `reports`: `CHECK (period_end >= period_start)` dan partial unique
  `(project_id, type, period_start, period_end, language) WHERE status <> 'cancelled'`.
- `reminder_rules`: unique `(user_id, project_id, type) NULLS NOT DISTINCT` (PostgreSQL 15+) agar
  tidak ada rule global ganda.
- `report_versions` memiliki `created_at` **dan** `updated_at` (PRD hanya menyebut `created_at`),
  karena draft bisa diedit dan memakai `version` untuk optimistic locking.
- `users.telegram_user_id` unique tetapi **nullable sampai M5**: user dev (login email/password lokal)
  belum punya akun Telegram.

### Idempotency dan pesan Telegram (PRD §23, §48) — untuk M2

- Format `idempotency_key`: `telegram:{chat_id}:{message_id}` untuk Telegram (message_id hanya unik
  per chat), `dashboard:{uuid}` untuk dashboard.
- **`edited_message` memakai idempotency key yang sama** dengan pesan aslinya dan harus
  **memperbarui baris yang sudah ada** (`text`, `edited_at`), bukan membuat baris baru. Implementasi
  di M2.
- **Celah PRD §49:** kolom `inbound_messages.reply_message_id` (bigint, nullable) ditambahkan untuk
  menyimpan ID pesan balasan bot ("⏳ Mencatat…") yang kemudian diedit menjadi konfirmasi (§7) dan
  diperbarui saat sinkronisasi dua arah (§23).

### AI (PRD §49, §54, §56)

- `ai_interactions.input` **hanya boleh berisi teks setelah redaction**. Tidak ada secret yang
  disimpan, di-log, atau dikirim ke AI.
- `ai_interactions.output` bertipe `text`, bukan `jsonb`, karena respons model bisa berupa JSON tidak
  valid dan tetap harus tercatat.

### Bentuk JSON `task_events.from_value` / `to_value` (PRD §21, §47)

Kedua kolom bertipe `jsonb`. Setiap nilai adalah **snapshot lengkap** field yang disentuh event
(bukan diff), sehingga undo (M4) dapat memulihkan state persis dari `from_value`.

| event_type | from_value | to_value |
|---|---|---|
| `created` | `null` | `{"project_id": 1, "title": "…", "status": "open", "waiting_reason": null}` |
| `status_changed` | `{"status": "in_progress", "waiting_reason": null}` | `{"status": "waiting", "waiting_reason": "client"}` |
| `title_changed` | `{"title": "lama"}` | `{"title": "baru"}` |
| `moved` (task pindah project) | `{"project_id": 1, "task_id": 7}` | `{"project_id": 2, "task_id": 7}` |
| `moved` (activity pindah task) | `{"project_id": 1, "task_id": 7, "activity_ids": [10, 11]}` | `{"project_id": 1, "task_id": 9, "activity_ids": [10, 11]}` |
| `merged` (dicatat di task sumber) | `{"task_id": 7, "status": "open", "waiting_reason": null, "activity_ids": [10]}` | `{"task_id": 9, "activity_ids": [10]}` |
| `reopened` | `{"status": "completed", "waiting_reason": null}` | `{"status": "in_progress", "waiting_reason": null}` |
| `undone` | state sebelum undo (bentuk sama dengan event yang di-undo) | state sesudah undo + `"undone_event_id": 123` |

Aturan:

- Nilai enum disimpan sebagai string backing value (`"in_progress"`), bukan label.
- `waiting_reason` selalu ada (boleh `null`) di event yang memuat `status`.
- Reopen (Completed → In Progress, Cancelled → Open) dicatat sebagai `reopened`, bukan `status_changed`.
- Perubahan hanya `waiting_reason` (waiting → waiting) dicatat sebagai `status_changed` dengan status
  sama di kedua sisi. Ini bukan transisi status menurut `TaskStatusTransition`.

### Transisi status (PRD §14)

- `App\Domain\Tasks\TaskStatusTransition` adalah class PHP murni (hanya bergantung pada enum
  `TaskStatus` dan exception miliknya), dijaga oleh arch test.
- **Transisi ke status yang sama bukan transisi sah.** `canTransition()` mengembalikan `false`,
  `allowedFrom()` tidak memuat status asal, dan `assertCanTransition()` melempar
  `SameStatusTransition` (turunan `InvalidTaskStatusTransition`). Caller (M4) memperlakukannya
  sebagai **no-op tanpa `task_event`**, bukan error ke user.
- "Draft … atau dihapus" di matriks bukan status: penghapusan Draft adalah soft delete (M4).
  Draft → Cancelled tidak diizinkan.

### Optimistic locking (PRD §23)

- M1 hanya menyediakan kolom `version` (integer, default 1, `CHECK >= 1`) di `tasks` dan
  `report_versions`. **Mekanismenya (penolakan saat versi berbeda, exception konflik) ditunda ke M4.**

### Scope per user (CLAUDE.md aturan 11)

- `App\Support\UserContext` (binding `scoped`) menyimpan user yang sedang dilayani. **Tidak ada
  context global default.**
- Semua model kecuali `User` mengimplementasikan `UserScoped` dan memakai global scope `UserScope`,
  dijaga oleh arch test (model baru tanpa scope membuat test gagal).
  - `user_id` langsung (`BelongsToUser`): Project, Person, InboundMessage, Correction,
    ReportTemplate, ReminderRule, AiInteraction. Saat `creating`, `user_id` diisi dari context;
    menulis untuk user lain melempar `CrossUserWriteException`.
  - Lewat project (`ScopedThroughProject`): Task, Activity, Report.
  - Lewat parent: TaskPerson, TaskEvent → task; ReportVersion → report; ReportFile → version;
    ReminderInstance → rule.
- **Fail-closed:** query pada model ber-scope tanpa context melempar `MissingUserContextException`.
  Kode yang sah melihat semua user (scheduler, seeder, command maintenance) harus eksplisit memakai
  `UserContext::runAsSystem()`.
- **Queue/job:** job membawa `user_id` di payload dan memakai middleware
  `App\Jobs\Middleware\WithUserContext`, yang me-restore context di `finally` sehingga tidak bocor ke
  job berikutnya di worker yang sama (binding `scoped` juga di-reset Laravel antar job).
- **HTTP (M5):** middleware yang mengisi context dari user login harus terpasang di panel Filament
  **termasuk request Livewire** (`/livewire/update`), bukan hanya route halaman.
- **Test:** helper `actingAsUser()` di `tests/Pest.php` mengisi auth dan `UserContext`.
- **Risiko yang tersisa:** `DB::table()`/raw SQL tidak melewati global scope. Service tidak boleh
  memakai query builder mentah untuk data milik user tanpa filter `user_id` eksplisit. Factory
  memakai `withoutGlobalScopes()` hanya untuk membaca parent.
- Model event harus tetap aktif saat seeding (`DatabaseSeeder` tidak memakai `WithoutModelEvents`)
  karena pengisian dan penjagaan `user_id` berjalan di event `creating`.

### Index (PRD §49 + tambahan)

Dari PRD: `activities (project_id, activity_date)`, `tasks (project_id, status)`, GIN
`activities.content_structured`, `inbound_messages (user_id, status)`, unique
`inbound_messages (idempotency_key)`, dan `pg_trgm`, yang diterapkan sebagai GIN `gin_trgm_ops` pada
`tasks.title` dan `activities.summary`.

GIN pada `content_structured` memakai operator class default `jsonb_ops` (mendukung `?`, `?|`, `@>`),
bukan `jsonb_path_ops` yang lebih kecil tetapi hanya mendukung `@>`.

Tambahan beserta alasannya:

| Index | Alasan |
|---|---|
| `activities (task_id, activity_date)` | Timeline task dan "3 activity terakhir per task" di candidate retrieval (§12) |
| `activities (inbound_message_id)`, `task_events (inbound_message_id)`, `corrections (inbound_message_id)` | Undo per pesan (§21) |
| `task_events (task_id, created_at)` | Audit trail per task (§47) |
| `corrections (user_id, created_at)` | User Correction Rate (§78) |
| `ai_interactions (user_id, created_at)` | Dashboard biaya AI (§79) |
| `reminder_instances (status, next_run_at)` | Scheduler berjalan tiap menit |
| `projects (user_id, status)`, `people (user_id)`, `report_templates (user_id, project_id)`, `task_people (person_id)` | Filter scope per user dan lookup task per orang |
| `reports (project_id, period_start, period_end)` | Pemilihan report per periode (§36) |

### Seeder

- `DemoSeeder` hanya berjalan jika `APP_ENV=local` (dan melempar exception di environment lain).
  Datanya fiktif (tanpa nama klien nyata), riwayat status di-replay lewat `TaskStatusTransition`, dan
  seeder idempotent.
- Pemilik data demo adalah user dev (`DEV_USER_EMAIL`) jika ada; jika tidak, dibuat "Demo User"
  (`demo@example.test`) dengan password acak yang tidak bisa dipakai login.
- Kredensial dev dibaca lewat `config('app.dev_user.*')`, bukan `env()`, agar tetap benar saat config
  di-cache.
- **TODO(M5):** login email/password lokal dan user dev dihapus saat Telegram Login Widget
  diimplementasikan.
