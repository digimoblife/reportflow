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
- **PHPStan level 8** (naik dari 7 pada M4g) tanpa baseline, menganalisis `app/` dan `database/`.

### Waktu dan tanggal (PRD §49, CLAUDE.md konvensi)

- Semua kolom timestamp memakai `timestamptz` dan disimpan dalam UTC. Timestamp bawaan tabel `users`
  ikut dikonversi ke `timestamptz`. Tabel framework (`sessions`, `cache`, `jobs`) tidak diubah.
- Koneksi `pgsql` memaksa `timezone = UTC` untuk session database.
- Model menulis timestamp dengan offset (`Y-m-d H:i:sP`, trait `StoresTimestampsWithOffset`).
  Tanpa ini Eloquent membuang offset sehingga Carbon ber-zona `Asia/Jakarta` tersimpan seolah UTC.
- **Binding datetime dengan offset (ketergantungan pada internal Laravel).** `Connection::prepareBindings()`
  memformat nilai `DateTimeInterface` dengan `getDateFormat()` milik **query grammar**, default
  `Y-m-d H:i:s` (tanpa offset). Akibatnya Carbon ber-zona `Asia/Jakarta` dibandingkan dengan kolom
  `timestamptz` seolah-olah UTC dan hasilnya bergeser 7 jam. `App\Database\PostgresConnection`
  (didaftarkan lewat `Connection::resolverFor('pgsql')` di `AppServiceProvider`) memakai
  `App\Database\Query\Grammars\PostgresGrammar` yang memformat `Y-m-d H:i:sP`.
  - Kita bergantung pada dua hal internal: `prepareBindings()` memakai `Grammar::getDateFormat()`, dan
    `PostgresConnection::getDefaultQueryGrammar()` dapat di-override. `DatetimeBindingTest` ("binds datetimes
    with their UTC offset") gagal jelas jika salah satunya berubah saat upgrade Laravel; test itu memeriksa
    hasil `prepareBindings()` dan string yang benar-benar diterima PostgreSQL (`select ?::text`).
  - Kolom `date` tidak terpengaruh: PostgreSQL membuang jam dan offset saat membaca literal ke `date`, jadi yang
    dipakai adalah tanggal kalender pada zona milik Carbon itu. Pemanggil tetap harus mengubah ke zona user
    sebelum membandingkan atau menulis `date` (00:30 Jakarta adalah 17:30 UTC hari sebelumnya).
  - `whereDate()` pada kolom `timestamptz` membandingkan tanggal kalender **UTC** (session timezone = UTC),
    bukan tanggal Jakarta. Untuk batas hari zona user pakai `whereBetween` dengan awal/akhir hari ber-zona.
  - `Model::getDateFormat()` (trait `StoresTimestampsWithOffset`) mengatur penulisan atribut model; grammar di
    atas mengatur nilai di klausa query. Keduanya diperlukan.
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
- **KEPUTUSAN TERBUKA untuk rencana M4 (belum dipilih):** bagaimana mencatat perubahan yang hanya mengubah
  `waiting_reason` (waiting → waiting). Ini bukan transisi status menurut `TaskStatusTransition`. Seeder dan
  test M1 belum bergantung pada salah satu opsi.
  - **(a) Tipe event terpisah `waiting_reason_changed`.**
    - Kelebihan: `status_changed` selalu berarti status berubah, jadi laporan, filter audit, dan hitungan
      "berapa kali status berubah" tidak perlu mengecualikan kasus from == to. Undo dan tampilan timeline
      memperlakukannya sebagai jenis perubahan sendiri.
    - Kekurangan: nilai enum baru `TaskEventType` dan migrasi yang mengganti CHECK `task_events_event_type_check`
      (aturan "menambah nilai = migrasi baru"), plus satu cabang lagi di undo dan di tampilan timeline.
  - **(b) Tetap `status_changed` dengan status sama di kedua sisi.**
    - Kelebihan: tanpa perubahan skema atau enum, dan bentuk `from_value`/`to_value` sudah ditentukan
      (`{"status", "waiting_reason"}`).
    - Kekurangan: konsumen wajib membedakan "status berubah" dari "hanya alasan berubah" dengan membandingkan
      `from_value.status` dan `to_value.status`. Undo harus memulihkan `waiting_reason` tanpa memanggil
      `TaskStatusTransition` (tidak ada transisi), dan laporan/statistik yang menghitung `status_changed` harus
      mengecualikan kasus ini agar tidak menghitung perubahan status yang tidak terjadi.

### Transisi status (PRD §14)

- `App\Domain\Tasks\TaskStatusTransition` adalah class PHP murni (hanya bergantung pada enum
  `TaskStatus` dan exception miliknya), dijaga oleh arch test.
- **Transisi ke status yang sama bukan transisi sah.** `canTransition()` mengembalikan `false`,
  `allowedFrom()` tidak memuat status asal, dan `assertCanTransition()` melempar
  `SameStatusTransition` (turunan `InvalidTaskStatusTransition`). Caller (M4) memperlakukannya
  sebagai **no-op tanpa `task_event`**, bukan error ke user.
- "Draft … atau dihapus" di matriks bukan status: penghapusan Draft adalah soft delete (M4).
  Draft → Cancelled tidak diizinkan.

### Lingkungan test

- `phpunit.xml` memakai `tests/bootstrap.php`, yang menulis `APP_ENV=testing`, `DB_*` (guard `reportflow_test`),
  dan `DEV_USER_*` kosong ke `$_ENV`, `$_SERVER`, dan `putenv()` sebelum Laravel boot. Sebabnya: `<env force="true">`
  PHPUnit hanya menulis `$_ENV` dan `putenv()`, sedangkan repository dotenv Laravel membaca `$_SERVER` lebih dulu,
  sehingga `APP_ENV=local` dari docker-compose mengalahkan phpunit.xml.
- Test yang butuh environment lain memakai `withAppEnvironment()` (`tests/Pest.php`), yang me-restore nilai
  sebelumnya dan boot ulang aplikasi. Jangan mengandalkan transaksi `RefreshDatabase` di dalam callback-nya.
- Jangan memanggil `db:seed` tanpa `--force` di test ber-environment production: prompt konfirmasi (Laravel Prompts)
  dijawab "tidak" tanpa terminal sehingga command dibatalkan dan asersi lulus tanpa menguji guard seeder.

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

---

## M2 — Ingestion Telegram & Redaction

PRD §7, §18–§20, §23, §48, §56, §58, §66–§69.

### Akses dan whitelist (PRD §56)

- **Satu sumber whitelist: `users.telegram_user_id`.** `TELEGRAM_ALLOWED_USER_IDS` dihapus dari `.env.example`
  (dua sumber akan menyimpang). User dibuat lewat `php artisan reportflow:user:create {telegram_id}`
  (`--name --email --language --timezone`); `--email` yang menunjuk user yang belum punya Telegram ID menautkan ID
  itu (untuk user dev lokal). Password acak, tidak pernah dicetak, tidak bisa dipakai login.
- `users.email` dan `users.password` masih NOT NULL: user Telegram mendapat email sintetis
  `tg<id>@telegram.invalid` dan password acak. **Sementara sampai M5** (Telegram Login Widget).
- **Pengirim tak terdaftar:** tidak diproses, tidak disimpan, **tanpa balasan** (balasan membuktikan bot hidup dan
  memungkinkan enumerasi). ID numeriknya dicatat di log (level info, tanpa teks) paling sering sekali per ID per jam
  (`Cache::add`), supaya pemilik tahu ID yang perlu didaftarkan.
- Hanya chat privat antara manusia dan bot yang dilayani (`chat.type = private`, `chat.id = from.id`, `from.is_bot = false`);
  grup, kanal, dan pesan dari bot diabaikan diam-diam. Update selain `message` dan `edited_message` juga diabaikan.

### Webhook

- Route dari `TELEGRAM_WEBHOOK_PATH` (`routes/telegram.php`, tanpa grup `web`: tanpa sesi/CSRF). Route **tidak didaftarkan**
  bila path kosong, < 32 karakter, atau berisi karakter di luar `A-Za-z0-9_-`.
- Urutan middleware: `RequireSecureInProduction` → `VerifyTelegramSecret` → `throttle:telegram-webhook` (120/menit per IP).
  Secret diperiksa **sebelum** rate limit agar lalu lintas tak terautentikasi tidak menghabiskan jatah Telegram.
  Secret dibandingkan dengan `hash_equals`; secret tidak dikonfigurasi = tolak semua (fail closed). Semua penolakan
  berupa 404 polos. Pembatasan lalu lintas tak terautentikasi (flood) adalah tugas nginx/firewall.
- **HTTPS-only di production:** request non-secure → 404. TLS dihentikan di luar container, jadi `isSecure()` mengandalkan
  `X-Forwarded-Proto` **hanya dari proxy di `TRUSTED_PROXIES`** (daftar IP/CIDR dipisah koma, default kosong = tidak ada
  yang dipercaya; wildcard `*`, `0.0.0.0/0`, `::/0` membuat aplikasi gagal boot). IP untuk rate limit juga hanya
  memercayai proxy itu. **Konfigurasi deploy:** isi `TRUSTED_PROXIES` dengan alamat proxy TLS (M9).
- `TELEGRAM_CLIENT=fake` di production membuat aplikasi gagal boot (jika tidak, semua balasan akan hilang diam-diam).
  `AI_PROVIDER` hanya mengenal `fake` sampai M3 (TODO(M3): provider DeepSeek).
- **Perlakuan error (Telegram mengirim ulang sampai menerima 2xx):**
  - Tidak bisa diperbaiki dengan retry (tipe update tak didukung, payload cacat, pengirim tak terdaftar, chat non-privat): **200**.
  - Gagal sebelum pesan tersimpan (mis. database mati): **500** agar Telegram mengulang, tetapi paling banyak 3 kali per
    `update_id` (`Cache::increment`), setelah itu **200** supaya update beracun tidak berulang tanpa akhir. Log hanya
    berisi `update_id` dan nama kelas exception.
  - Setelah pesan tersimpan, kegagalan ack/notifikasi/dispatch tidak menghasilkan 5xx.

### Alur pesan

- Urutan: identifikasi user → redaction → `INSERT ... ON CONFLICT DO NOTHING` (`insertOrIgnore`, tanpa jalur exception
  unique violation sehingga aman di dalam transaksi) → ack "⏳" (`reply_message_id`) → dispatch job. `text` yang
  tersimpan selalu pasca-redaction; teks asli tidak pernah disimpan. Laravel `TrimStrings` memangkas spasi di tepi
  dan `ConvertEmptyStringsToNull` menjadikan teks kosong `null` (diabaikan).
- **Duplikat:** baris sudah ada → 200 tanpa ack baru. Bila baris masih `received` (percobaan pertama mati sebelum
  dispatch), job **dikirim ulang**; job mengklaim baris secara atomik sehingga tidak ada pemrosesan ganda.
- **Ack gagal:** pesan tetap tersimpan dan diproses; `reply_message_id` null. Konfirmasi lalu dikirim sebagai pesan baru
  dan id-nya disimpan.
- **Kunci "sudah dikerjakan"** untuk hal yang bukan baris `inbound_messages` (command, balasan non-teks, suntingan) ditulis
  di cache **setelah** aksi berhasil, bukan sebelumnya, sehingga kegagalan tetap bisa diulang oleh Telegram.
- **`edited_message`:** kunci idempotency sama; baris diperbarui (`text` pasca-redaction, `edited_at`), tidak diproses ulang.
  - Baris `received`: diam-diam. Baris `processing`: diam-diam; **M3 harus menangani balapan suntingan vs pemrosesan**
    (job bisa sudah membaca teks lama).
  - Baris `processed`/`failed`/`needs_clarification`: teks diperbarui dan satu pesan `worklog.edit_saved_notice` dikirim
    ("suntingan tersimpan di arsip, catatan yang sudah dibuat tidak ikut berubah"). Pemrosesan ulang menunggu tombol
    callback (M4). Suntingan yang sama dikirim ulang tidak menggandakan pesan (kunci per `edit_date`).
  - Baris tidak ada (pesan asli tak pernah kita simpan): diperlakukan sebagai pesan baru dengan `edited_at` terisi.
  - Secret baru di hasil suntingan → peringatan credential.
- **Non-teks:** pesan hanya-media dijawab template `unsupported.{voice,image,other}` dan tidak disimpan (voice/screenshot
  adalah Phase 3). **Caption diproses sebagai teks**; `attachments` hanya menyimpan tipe (`[{"type":"photo"}]`), tanpa
  `file_id`; pengguna diberi `worklog.attachment_ignored`.
- **Bahasa balasan:** deterministik. Hitung kata fungsi Indonesia vs Inggris pada teks tersimpan; menang bila skor ≥ 2 dan
  unggul; selain itu `users.default_language`. Command selalu memakai `default_language`. Karena hanya bergantung pada teks
  tersimpan, ack dan konfirmasi (di job) konsisten tanpa kolom tambahan.
- **`/start`:** state "menunggu nama project" disimpan di cache (`tg:state:{user_id}`, 24 jam), bukan tabel. Hilang = user
  cukup `/start` lagi. Pesan berikutnya menjadi nama project (redaction dulu; berisi secret = ditolak; 1–80 karakter; slug
  yang sudah ada dipakai ulang) lewat `ProjectService` dan **tidak** disimpan sebagai `inbound_messages` (bukan worklog).
  Project baru `default_language = null` (ikut user). Pilihan bahasa laporan lewat tombol (PRD §66) ditunda: butuh
  callback_query (M4+).
- **Command:** 14 command PRD §20 persis, satu sumber di `BotCommandRegistry`, deskripsi di `lang/*/bot_commands.php`.
  `/update` dihapus. Hanya `/start` dan `/help` berfungsi; sisanya dijawab `commands.unavailable`, command tak dikenal
  `commands.unknown`. `telegram:sync-commands` mengirim daftar default (id) dan `language_code=en`.
- **Batas 4096 karakter:** semua pesan keluar dipotong ke 4096 (`TelegramText::fit`), tanpa `parse_mode` (teks polos).

### Pemrosesan (PRD §7, §48, §58)

- `ProcessInboundMessage`: klaim atomik `received → processing` (retry boleh mengklaim ulang `processing`), `WorklogService`,
  `processed`. **Bukan** `tries` tetap: `retryUntil` 10 menit + `maxExceptions = 3`, backoff 5/30/120 detik.
  `WithoutOverlapping("inbound-user:{id}")->releaseAfter(5)->expireAfter(120)` menyerialkan pesan satu user; job yang
  bertemu kunci di-*release* (tidak dihitung gagal). Urutan FIFO ketat antar-job tidak dijamin bila > 1 worker; M4 perlu
  "klaim pesan tertua dulu per user" karena di sana urutan berpengaruh.
- Gagal permanen (`failed()`): status `failed`, `error = worklog_failed:<NamaKelas>` (tanpa isi pesan/exception message),
  ⏳ diedit menjadi `worklog.failed`, teks asli tetap tersimpan.
- **Pengiriman konfirmasi adalah job terpisah** (`DeliverInboundConfirmation`): kegagalan Telegram tidak pernah mengubah
  `processed` menjadi `failed` dan tidak mengulang pemrosesan. Klasifikasi: 400/403 tidak di-retry (edit gagal → coba kirim
  pesan baru sekali; lalu log tanpa isi); 429 menunggu `retry_after`; 5xx/timeout retry sampai `retryUntil`;
  "message is not modified" dianggap sukses. Penanda cache setelah sukses mencegah konfirmasi ganda.
- Panggilan `AiProvider` di `WorklogService` M2 (fake) belum dicatat ke `ai_interactions`; pencatatan lewat `AIService` di M3.
- **Kewajiban M4/M7:** pembersih pesan yang macet di `processing` (worker mati di tengah) dan `received` yang tak pernah
  di-dispatch; M2 hanya menutup kasus `received` lewat pengiriman ulang webhook.

### Redaction (PRD §56; CLAUDE.md aturan 6 dan 7)

- Pengganti `[REDACTED_SECRET]`; hasil = teks bersih + hitungan per kategori (`api_key`, `password`, `private_key`,
  `connection_string`, `custom`, `redaction_error`); nilai tidak pernah disimpan. Idempotent.
- Pola: token (`sk-`, `sk_live_`, `ghp_`…, `github_pat_`, `glpat-`, `AKIA/ASIA`, `AIza`, `xox*`, token bot Telegram, JWT,
  `Bearer`), blok PEM (termasuk tanpa penutup), URI database/broker berkredensial dan password di URL, env-style
  `*_SECRET/TOKEN/PASSWORD/API_KEY=…`, kata kunci password (`password`, `passwd`, `passphrase`, `kata sandi`, `sandi`,
  varian `passwordnya`) dengan `:`/`=`/`->`, bentuk kata ("password is X", "passwordnya X") hanya untuk nilai yang
  tampak seperti secret, dan `pass/pwd/pw` hanya dengan `:`/`=` dan nilai yang tampak seperti secret (≥ 6 karakter,
  huruf + angka/simbol). Kata biasa ("password reset", "pass" sebagai kata, hash git, UUID, "task-…") tidak terkena.
- **Tanpa deteksi entropi umum** (false positive tinggi pada hash/UUID). Konsekuensi: secret berbentuk tak dikenal yang
  ditulis tanpa kata kunci lolos. Batasan yang diketahui: `password: <kalimat biasa>` menghapus kata pertama setelahnya.
- **Fail closed:** PCRE error, UTF-8 tidak valid, atau input > 50.000 karakter → seluruh teks diganti placeholder,
  kategori `redaction_error`. Untuk kasus ini pesan **tidak disimpan dan tidak diproses**, dan pengguna menerima
  `security.redaction_error` (bukan "credential terdeteksi": ini kegagalan pemeriksaan, bukan temuan). Batas: semua
  quantifier berbatas atas, `pcre.backtrack_limit` = 200.000 selama redaction (dipulihkan sesudahnya); input adversarial
  50.000 karakter selesai ≈ 1 ms (JIT), test mensyaratkan < 1,5 dtk.
- Pola tambahan: `config/redaction.php` (`extra_patterns` global, `user_patterns[user_id]`), divalidasi saat dimuat
  (pola rusak = exception). Kolom per user menunggu UI pengaturan (M5).
- **Batasan:** redaction tidak menghapus pesan asli di riwayat chat Telegram; pesan berisi credential tetap ada di sana.
  Bot memberi tahu bagian itu tidak disimpan; menghapus pesan di chat adalah tindakan user (bot tidak bisa menghapus
  pesan user di chat privat setelah 48 jam, dan tidak kita coba). Kredensial yang terlanjur dikirim sebaiknya dianggap
  bocor dan diganti.
- **Jalur log:** teks mentah hanya di `TelegramUpdate` → `RedactionService::redact`; parameter penerimanya
  `#[SensitiveParameter]`; `zend.exception_ignore_args=On` di `docker/php/php.ini`; tidak ada `Log::` yang menyertakan teks;
  `RedactingLogProcessor` (tap semua channel) menyaring message, context, extra, dan meratakan Throwable menjadi string
  ter-redaksi; objek dicatat hanya nama kelasnya. Kolom `error` hanya kode + nama kelas. Payload job hanya id.
- **Token bot:** `HttpTelegramClient` menangkap semua exception HTTP/koneksi (Laravel `HttpClientException`, Guzzle) dan
  melempar `TelegramApiException` tanpa `previous` dan tanpa URL (URL memuat token). Diuji dengan token palsu yang
  dirakit saat runtime di log, exception, render handler, dan pesan keluar.

### Penyimpangan dari contoh PRD

- **Kata Jawa** dihitung per kata (tanpa pengecualian frasa), maksimal 2 per variasi, sesuai skill `pak-carik-messages`.
  Contoh onboarding PRD §19 (4 kata Jawa) dipendekkan. Daftar yang diizinkan: nggih, nuwun, sewu, rampung, monggo,
  sugeng, rawuh, njenengan, matur, waduh.
- `/update` dihapus dari daftar command (PRD §18 vs §20; rencana implementasi, temuan #1).

### Isolasi test dari layanan dev (penutupan M2)

`tests/bootstrap.php` menulis nilai berikut ke `$_ENV`, `$_SERVER`, dan `putenv()` sebelum Laravel boot; `TestIsolationTest`
menguncinya. Host `*.invalid` tidak pernah resolve, sehingga akses tak sengaja gagal keras.

| Setting | Nilai di test | Risiko bila tidak dikunci |
|---|---|---|
| `DB_CONNECTION/DATABASE/USERNAME`, `DB_URL` | pgsql / `reportflow_test` / `reportflow_tester` / kosong | migrate:fresh menghapus data dev (dijaga juga guard `TestCase`) |
| `CACHE_STORE`, `SESSION_DRIVER` | `array` | `Cache::flush()` dan kunci test menimpa cache/sesi Redis dev |
| `QUEUE_CONNECTION` | `sync` (test antrean memakai koneksi `database` di DB test) | job test masuk antrean Redis dev dan diproses worker dev |
| `REDIS_HOST/PORT/PASSWORD/URL` | `redis.invalid` / 1 / kosong | semua yang di atas lewat jalur Redis |
| `MAIL_MAILER`, `BROADCAST_CONNECTION` | `array` / `log` | email/siaran sungguhan |
| `LOG_CHANNEL`, `LOG_STACK` | `sink` (bukan `null`: `env()` mengubah string "null" menjadi PHP null lalu logger darurat menulis ke file) | log test menumpuk di `storage/logs/laravel.log` dev |
| `FILESYSTEM_DISK` | `local` (test file wajib `Storage::fake`) | file test di storage bersama |
| `GOTENBERG_URL` | `gotenberg.invalid` | PDF test lewat Chromium dev |
| `TELEGRAM_CLIENT/BOT_TOKEN/BOT_SECRET_TOKEN/WEBHOOK_PATH` | `fake` / kosong / nilai test / nilai test | pesan sungguhan ke Telegram |
| `AI_PROVIDER`, `DEEPSEEK_API_KEY`, `DEEPSEEK_BASE_URL` | `fake` / kosong / `deepseek.invalid` | biaya dan kebocoran data ke provider |
| `AWS_*`, `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_*`, `LOG_SLACK_WEBHOOK_URL` | kosong | kredensial layanan pihak ketiga terbaca |
| `APP_KEY` | kunci test tetap | test memakai kunci enkripsi dev |
| `TRUSTED_PROXIES`, `DEV_USER_*`, `APP_ENV` | kosong / kosong / `testing` | perilaku bergantung mesin |

### Uji langsung M2 dengan Telegram sungguhan (temuan)

Uji manual 30 September 2026 lewat quick tunnel (skenario 1–8 dan 10 lulus; 9 dilewati karena tidak ada akun kedua).
Prosedur: `docs/runbooks/telegram-live-test.md`.

- **Worker harus punya egress.** `reportflow-internal` bersifat `internal: true`; worker yang hanya di sana tidak bisa resolve
  `api.telegram.org`, sehingga konfirmasi tidak pernah mengedit "⏳" (job pengiriman mencoba ulang tiap ~12 dtk sampai
  `retryUntil`, tanpa error di log aplikasi dan tanpa `failed_jobs`). Worker kini juga di `reportflow-public`; postgres dan
  redis tetap internal saja. Dijaga `tests/Unit/Infra/NginxTunnelTest.php`. Suite tidak bisa menangkapnya karena memakai fake
  Telegram; pemeriksaan jaringan hanya bisa statis atau manual. M3 (DeepSeek) memakai jalur egress yang sama.
- **Bind mount file tunggal** (`docker/php/php.ini`, `docker/nginx/*.conf`) menunjuk inode lama setelah `git checkout`/`pull`;
  container kehilangan file itu (mis. `memory_limit` kembali 128M). Setelah pindah branch, recreate container.
- **`.env` dibaca berbeda per container:** Laravel membaca file yang di-mount saat proses mulai (worker/scheduler perlu
  `restart`); nginx menerima `TELEGRAM_WEBHOOK_PATH` lewat interpolasi compose saat container dibuat (perlu
  `up -d --force-recreate`).
- **Quick tunnel tidak tahan Mac tidur.** Tunnel dan Docker berhenti bersamaan; Telegram menumpuk update dengan
  `Wrong response from the webhook: 530` dan edit pesan tidak sampai. Pakai `caffeinate -w <pid cloudflared>`; setelah webhook
  dihapus dengan `drop_pending_updates=true`, update yang tertahan tidak diputar ulang.
- **Kunci palsu pendek tidak di-redaksi** (`sk-` + 17 karakter, batas 20): sesuai desain. Uji credential harus memakai contoh
  yang cukup panjang.
- **Perilaku saat worker mati:** pesan tetap `received` dengan "⏳" terkirim, suntingan tercatat, dan selesai otomatis setelah
  worker hidup. Pengguna tidak melihat petunjuk apa pun selama menunggu; relevan untuk pembersih pesan macet (M4/M7).
- **Tindak lanjut kecil:** `telegram:set-webhook --dry-run` menampilkan 4 karakter pertama path webhook; sebaiknya hanya
  panjangnya.

---

## M3 — AI Extraction & Evaluation Harness (kerangka)

PRD §12, §13, §50–§54, §73, §75, §79. Dibangun dan diuji dengan `FakeAiProvider`; tidak ada panggilan DeepSeek di test/CI.

### Kontrak AI dan DeepSeek
- `AiProvider::complete(AiRequest): AiResponse` (system + user, JSON mode, token dan latensi) menggantikan seam sementara M2. `AIService::extractWorklog`
  satu-satunya jalan dari logika bisnis ke model; setiap percobaan dicatat ke `ai_interactions` (purpose `worklog_extraction`, `prompt_version`
  `worklog_extraction@vN`, `input` = teks ter-redaksi + kandidat tanpa system prompt, `output` mentah, token, latensi, `success`, `error` = kode).
- **Retry:** JSON tidak valid / schema gagal → satu percobaan ulang dengan daftar field yang gagal (`previous_reply_rejected`), lalu `AiExtractionFailed`
  (pesan hanya berisi kode; job M2 menandai `failed`). Error provider (timeout, HTTP) **tidak** diulang di sini; itu tugas retry queue.
- **DeepSeek (diverifikasi ke api-docs.deepseek.com, 30 Sep 2026):** model `deepseek-flash` (dan `deepseek-v4-pro`) ada, endpoint `/chat/completions`, JSON mode
  `response_format: {"type":"json_object"}` **mengharuskan kata "json" dan contoh format di prompt** (prompt v1 memenuhinya) dan dapat sesekali mengembalikan
  konten kosong (diperlakukan sebagai jawaban tidak valid → retry). Usage: `prompt_tokens`, `completion_tokens`. Parameter `thinking`/`reasoning_effort` tidak dipakai.
  Model diambil dari `AI_MODEL`. **Kebijakan retensi data DeepSeek dan perjanjian klien tetap keputusan Anda (Keputusan Terbuka #7).**
- `DeepSeekProvider` menangkap semua exception HTTP dan melempar `AiProviderException` tanpa `previous`/URL/header (header memuat kunci API), seperti klien Telegram.
- **Guard boot:** `AI_PROVIDER` hanya `deepseek` atau `fake`; di production wajib `deepseek`. Default config = `deepseek` (bukan `fake`): `composer install`/`package:discover` berjalan tanpa `.env` sebagai production dan tidak boleh gagal; dev menulis `AI_PROVIDER=fake` di `.env`. Kunci kosong ditolak saat dipakai. `TestIsolationTest` mengunci endpoint/kunci di test.

### Prompt dan schema
- `resources/prompts/worklog_extraction/v1.md`, instruksi Inggris, satu panggilan, multi-item, hanya `task_id` dari daftar kandidat. **v1 tidak boleh diubah:**
  test mengunci checksum; perubahan = `v2.md` + `eval:run`.
- `resources/schemas/worklog_extraction.v1.json` divalidasi oleh `JsonSchemaValidator` tulisan sendiri (tanpa dependency; keyword: type, enum, required, properties,
  additionalProperties, items, min/maxItems, minimum/maximum, min/maxLength, pattern, oneOf). Error berisi path + keyword, tidak pernah nilai.

### Candidate retrieval (§12)
`CandidateBuilder`: project disebut lewat nama atau alias (kata utuh, tanpa memperhatikan huruf, ≥ 2 karakter) → semua task Open/In Progress/Waiting/Blocked di project itu
+ Completed dengan `completed_at` ≤ 30 hari, masing-masing dengan 3 activity terakhir (ringkasan dipotong 200 karakter) dan nama orang. Tanpa project terdeteksi → mode
**compact**: task aktif semua project tanpa riwayat activity. Draft, Cancelled, soft-deleted, project arsip, dan data user lain tidak pernah masuk. `CandidateSet` dipakai dua kali:
di prompt dan sebagai daftar putih validator.

### Validator backend (§54 langkah 2–7)
`ExtractionValidator` → `ValidatedProposal` (per item `accepted` / `needs_confirmation` / `rejected` + kode alasan). Ditolak: `task_ref_not_in_candidates`, `task_not_owned`,
`intent_ref_mismatch`, `project_task_mismatch`, `project_unknown`, `date_in_future`, `date_invalid`. Butuh konfirmasi (walau confidence tinggi): `date_older_than_30_days`, `project_missing`,
`status_from_mismatch`, `status_transition_invalid`, `status_initial_invalid`. Status memakai status **sebenarnya** task (bukan klaim model), lewat `TaskStatusTransition`;
status yang sama = no-op (`status_unchanged`); Completed/Cancelled ditandai `explicitTerminal`. Task baru boleh mulai di open/in_progress/waiting/blocked/completed (pilihan final di M4).
Tanggal dinilai di zona waktu user. `ConfidencePolicy`: ≥ 0.90 high, 0.70–0.89 medium, < 0.70 low (`config/ai.php`); high diturunkan ke medium bila ada sinyal yang bertentangan:
`task_cancelled`, `stale_task` (aktif dan tanpa activity > 90 hari), `person_mismatch`. Low tetap `needs_confirmation` (M4 memutuskan: task baru atau klarifikasi).
Ini satu class validator + satu `ConfidencePolicy` (bukan satu class per aturan seperti di rencana), agar urutan aturan terbaca di satu tempat; tiap aturan punya test tabel sendiri.

### Integrasi
`WorklogService::process` = candidates → AI → validasi → `WorklogResult(proposal)`. **Belum menulis ke tasks/activities** (M4); konfirmasi ke user tetap dummy. Proposal dapat dibangun ulang
secara deterministik dari `ai_interactions.output` + snapshot kandidat, jadi M4 tidak perlu kolom baru untuk menyimpannya.

### Evaluation harness
- `eval:run` (`--dataset=sample|local`, `--provider=fake|deepseek`, `--prompt=name@vN`, `--json`, `--out`, `--baseline`). Menjalankan pipeline produksi yang sama (redaction → kandidat → AIService → validator)
  di dalam transaksi database yang **selalu di-rollback**, untuk user sekali pakai; hanya boleh di luar production. Laporan berisi angka dan id kasus, tidak pernah teks pesan.
- `--provider=fake` = oracle yang menjawab dari label: 100% hanya membuktikan harness dan validator sepakat dengan label. `--provider=deepseek` wajib `--send-to-deepseek` (konfirmasi eksplisit).
- Metrik: extraction, project, matching (target §73: 90/95/90/95), date, status, dan backend rules; per kategori; akurasi per rentang confidence (bahan menyetel 0.90/0.70).
  Catatan ambiguitas: kasus `ambiguous` benar bila tidak ada task yang diterima otomatis.
- **Dataset sampel** (`tests/Eval/data-sample`, 66 kasus sintetis, 20 label bertanda `review`) ditulis dari aturan PRD, bukan dari pemakaian nyata; angkanya tidak dapat dipakai untuk menyetel threshold.
  Dataset nyata Anda di `tests/Eval/data/` (gitignored). Format dan cara pakai: `docs/runbooks/evaluation-dataset.md`.

### Temuan dari harness
- Redaction melewatkan nama kunci huruf kecil/majemuk seperti `client_secret=…` (aturan env-style hanya huruf besar). Ditambah aturan untuk nama majemuk (`client_secret`, `api-key`, `access_token`, …)
  dan `token=`/`secret=`/`apikey=` polos; kata biasa ("token expired", "secret santa") tidak terkena. Batasan: nilai tanpa tanda `:`/`=` tetap tidak terdeteksi.

### Belum dan diserahkan ke milestone berikut
Menerapkan proposal ke DB dan pesan konfirmasi sungguhan (M4); UX konfirmasi untuk `needs_confirmation`; deteksi task duplikat; penyetelan threshold dengan dataset nyata; level PHPStan 8 (M4).

### Kebijakan data ke DeepSeek (keputusan user, 30 Sep 2026)
Teks worklog (pasca-redaction) **boleh dikirim ke DeepSeek**. `DEEPSEEK_API_KEY` sudah diisi di `.env` lokal. Redaction tetap berjalan sebelum simpan dan sebelum kirim ke AI
(CLAUDE.md aturan 6), dan `eval:run --provider=deepseek` tetap memerlukan `--send-to-deepseek`. Kebijakan retensi DeepSeek dan perjanjian kerahasiaan per klien tetap tanggung jawab user
bila nanti ada klien yang melarang; `AIService` dapat diarahkan ke provider lain per project.

### Dataset `realistic` (30 Sep 2026)
`tests/Eval/data-realistic/`: 107 kasus sintetis bergaya catatan Telegram nyata (Indonesia kasual, singkatan, typo, campur Inggris, rujukan samar), lima klien fiktif, 20 task. Label ditulis dari aturan PRD;
37 kasus ditandai `review`. Bukan pengganti dataset nyata user untuk menyetel threshold.

### Hasil `eval:run` pertama dengan DeepSeek (30 Sep 2026)
Prompt `worklog_extraction@v1`, model `deepseek-flash`, dataset `realistic` (107 kasus sintetis), 108 panggilan, ≈ 3,2 dtk/panggilan (rata-rata), 193k token masuk / 59k keluar.

| Metrik | Hasil | Target §73 |
|---|---|---|
| Worklog extraction (jumlah item ditemukan) | 97/101 = 96,0% | ≥ 90% ✓ |
| Project identification | 97/101 = 96,0% | ≥ 95% ✓ |
| Task matching | 100/107 = 93,5% | ≥ 90% ✓ |
| Date extraction | 91/92 = 98,9% | ≥ 95% ✓ |
| Status detection (tanpa target) | 85/92 = 92,4% | - |
| Klasifikasi tipe activity (tanpa target) | 76/101 = 75,2% | - |

- Metrik "extraction" dipecah dari "classification" (tipe activity): tipe sebagian besar subjektif (`development` vs `documentation`, `investigation` vs `bug_fix`, `follow_up` vs `communication`,
  `deployment` vs `milestone`), jadi kesalahan tipe tidak lagi dihitung sebagai gagal menemukan pekerjaan. Kebanyakan selisih tipe adalah label yang bisa diperdebatkan.
- Akurasi task matching per confidence: high (≥ 0,90) 53/54 = 98,1%, medium 31/36 = 86,1%, low 9/10 = 90,0%. Threshold 0,90/0,70 sementara dipertahankan; medium memang perlu konfirmasi.
  Angka ini berasal dari dataset sintetis dan **tidak** cukup untuk menyetel threshold.
- Kelemahan nyata model di v1 (kandidat isi v2): (1) rencana/niat masa depan ("besok mau deploy…", "nanti sore ada meeting…", "rencana minggu depan…") dicatat sebagai pekerjaan (R085–R087, 0/3);
  (2) mengisi perubahan status untuk bagian kecil ("retry api kurir selesai" → task completed; "etiket obat selesai dicetak" → completed) padahal hanya sebagian yang selesai (R063–R065);
  (3) pesan permintaan mencatat sandi ("pass db … tolong catat ya") tetap dibuat item (R105). Sisanya label yang meragukan (`review`).
- Sesuai skill `prompt-eval`, v1 tidak diubah; perbaikan = `v2.md` lalu bandingkan dengan `--baseline`.

### Prompt v2 vs v1 (DeepSeek, dataset `realistic`, 30 Sep 2026) — TIDAK VALID, lihat koreksi di bawahnya
**Angka pada tabel di bagian ini salah:** `eval:run --prompt=` hanya dipakai pada tahap prefetch; tahap penilaian memakai prompt default (v1), sehingga run "v2" sebenarnya v1 (plus panggilan prefetch terbuang). Kesimpulan "v2 hanya menaikkan tipe" tidak berlaku.
v2 = definisi tipe activity, "hanya yang sudah terjadi" (rencana bukan pekerjaan), status konservatif (bagian tugas selesai ≠ task selesai), tanggal Senin/tanggal 1 untuk minggu/bulan.
Dua run per versi (concurrency 8, satu run v1 sebelumnya berurutan memberi hasil yang sama):

| Metrik | v1 (run 1 / 2) | v2 (run 1 / 2) |
|---|---|---|
| Extraction | 96,0% / 96,0% | 96,0% / 96,0% |
| Project | 96,0% / 96,0% | 96,0% / 96,0% |
| Task matching | 93,5% / 95,3% | 94,3% / 92,5% |
| Date | 98,9% / 98,9% | 98,9% / 98,9% |
| Status | 92,4% / 91,3% | 91,2% / 93,5% |
| Tipe activity | 75,2% / 75,2% | 79,0% / 78,2% |

- **Kebisingan run-ke-run besar:** dua run v1 yang sama berbeda pada 11 kasus (6 membaik, 5 memburuk) walau `temperature: 0`. Selisih beberapa kasus antar versi **bukan bukti**; bandingkan lewat beberapa run
  atau dataset lebih besar/nyata sebelum menyimpulkan.
- v2 konsisten lebih baik hanya pada **klasifikasi tipe** (+3 sampai +4 poin, dua run): definisi tipe membantu. Metrik bertarget PRD tidak berubah berarti.
- Target v2 yang **tidak** tercapai lewat instruksi teks: rencana masa depan tetap dicatat sebagai pekerjaan (R085–R087), dan status "task selesai" tetap diisi untuk bagian tugas (R063–R065). Instruksi negatif
  saja tidak cukup untuk model ini; perbaikan andal perlu struktur di output (mis. field `happened` per item yang dibuang backend bila false) atau konfirmasi di M4 untuk status terminal (sudah rencana: Completed/Cancelled dari AI selalu eksplisit dan bisa di-undo).
- **Keputusan user (30 Sep 2026): v2 menjadi default** (`ai.extraction.prompt = worklog_extraction@v2`). Masalah rencana masa depan dan status untuk bagian tugas diselesaikan di M4 lewat konfirmasi dan Undo, bukan lewat prompt.
- `eval:run` kini punya `--concurrency` (prefetch paralel, hasil identik dengan berurutan), `--only`, `--ids`, `--limit`; 107 kasus ≈ 1 menit dengan concurrency 8 (sebelumnya ≈ 6 menit).

### Koreksi: v1 vs v2 dengan harness yang sudah diperbaiki (30 Sep 2026)
Bug: `EvalRunner` mengabaikan `--prompt` pada tahap penilaian. Diperbaiki + test (`runs the prompt version it was asked for`). Dua run per versi, `deepseek-flash`, dataset `realistic`, concurrency 8 (≈ 1,5 menit/run):

| Metrik | v1 (run 1 / 2) | v2 (run 1 / 2) |
|---|---|---|
| Extraction (item ditemukan) | 96,0% / 97,0% | 98,0% / 97,0% |
| Project | 96,0% / 97,0% | 98,0% / 97,0% |
| Task matching | 94,4% / 95,3% | 95,3% / 94,4% |
| Date | 98,9% / 98,9% | 98,9% / 97,8% |
| Tipe activity | 76,2% / 76,2% | **83,0% / 82,2%** |
| Status | 91,3% / 92,4% | **78,0% / 80,4%** |
| Rencana masa depan (R085–R087) | 0/3 / 0/3 | **3/3 / 3/3** |

- v2 **memperbaiki** rencana masa depan (tidak lagi dicatat sebagai pekerjaan) dan klasifikasi tipe (+6 poin, konsisten). Metrik bertarget PRD sama atau sedikit lebih baik; input token ≈ +28% (prompt lebih panjang).
- v2 **menurunkan** skor status: model kini memindahkan task `open` ke `in_progress` setiap kali ada pekerjaan (mis. "schema voucher sudah dibuat", "bahas bug invoice"). Label dataset saya berasumsi
  "status tidak berubah kecuali dinyatakan"; PRD §11 (contoh Day 3: "Domainnya sudah dibeli" → In Progress) justru mendukung perpindahan Open → In Progress saat ada kemajuan.
  **Ini keputusan produk, bukan bug model** — masuk daftar keputusan terbuka M4: apakah pekerjaan pertama pada task Open otomatis memindahkannya ke In Progress? Bila ya, label dataset ikut diubah dan aturan dinyatakan eksplisit.
- Klaim "bagian tugas selesai ≠ task selesai" (R063–R065) sebagian masih salah; ditangani konfirmasi status terminal di M4.
- Default `ai.extraction.prompt` = `worklog_extraction@v2` (keputusan user).

## M4e — Command daftar dan proses ulang (PRD §10, §20, §58)

- `/projects`, `/project [nama]`, `/tasks`, `/task <id|kata>`, `/inbox` bersifat baca-saja dan ber-scope user; teksnya label + data, persona hanya di satu baris judul (`bot.list.*`).
- Tombol navigasi yang tidak terkait pesan masuk memakai payload `v:{view}:{page}[:{ref}]` (`ViewData`), bukan `a:`; hanya view baca-saja.
- Argumen command adalah teks mentah: hanya dipakai sebagai kata pencarian (LIKE dengan wildcard di-escape), tidak disimpan atau dicatat.
- Proses ulang (`redo`) = `ReprocessService`: undo hasil run lama (`corrections(undo)`), status → `received`, `outcome` → null, `reprocess_count++`, job baru. Hanya dari `processed|failed|needs_clarification`; tekan ganda → "sedang diproses".
  Bubble pertanyaan lama diedit menjadi "diganti" tanpa tombol; bubble konfirmasi lama menjadi "⏳" lalu diedit oleh job pengiriman. Penanda cache pengiriman kini memuat `reprocess_count`.
- Suntingan pesan yang sudah selesai: pesan menawarkan [Proses ulang] / [Biarkan]; tanpa jawaban tidak ada yang berubah (menutup TODO M2).

## M4f — Koreksi lewat reply (PRD §21)

- Balasan (reply) ke bubble konfirmasi yang punya item `applied` disimpan sebagai `inbound_messages` sendiri dengan `correction_of_id` (input mentah tetap diawetkan, jalur proses tetap satu). Reply ke hal lain, atau ke konfirmasi tanpa item terapan, adalah catatan biasa.
- `ReplyCorrectionService`: AI (`worklog_correction@v1`, purpose `worklog_correction`, dicatat di `ai_interactions`) mengusulkan hasil terkoreksi untuk catatan ASLI; usulan divalidasi seperti ekstraksi (candidate list, skema, confidence). Task yang dibuat catatan asli dikeluarkan dari kandidat (akan hilang saat undo).
- Dalam satu transaksi: undo hasil lama (`corrections(undo)`), lalu apply usulan ke pesan balasan. Undo hanya terjadi bila ada item yang tidak ditolak validator. Balasan yang bukan koreksi (`items: []`), usulan ditolak, atau AI gagal → hasil lama tidak berubah; bot bilang tidak ada yang berubah.
- Bubble konfirmasi lama diedit menjadi "dibatalkan" tanpa tombol.
- Belum ada: kasus koreksi di dataset eval (harness `eval:run` hanya mengevaluasi ekstraksi). Dicatat untuk M4g/Phase berikutnya; kualitas prompt koreksi di model sungguhan belum diukur.

## M4g — Penutup M4 (30 Sep – 1 Okt 2026)

- **Aturan produk Open → In Progress** (keputusan user): pekerjaan nyata (`ProposalApplier::WORK_TYPES`) pada task `open` memindahkannya ke `in_progress`; dijalankan backend, tercatat di `task_events`. Karena itu `eval:run`
  tidak lagi menghitung "in_progress" pada task Open + pekerjaan nyata sebagai keputusan model (sisi label maupun prediksi dinormalkan menjadi "tanpa perubahan status"). Label dataset TIDAK diubah:
  label tetap berarti "status yang dinyatakan eksplisit di pesan". (Mencoba melabel ulang membuat metrik menghukum model atas sesuatu yang dikerjakan backend; percobaan itu dibatalkan.)
- **Eval v2, dataset `realistic`, DeepSeek `deepseek-flash`, 2 run, skor status dengan aturan di atas:**

| Metrik | Run 1 | Run 2 |
|---|---|---|
| Extraction | 97,0% | 98,0% |
| Project | 97,0% | 98,0% |
| Task matching | 94,3% | 94,3% |
| Date | 97,8% | 98,9% |
| Tipe activity | 82,2% | 83,0% |
| Status | 84,8% | 83,5% |
| Aturan backend (`decision`) | 100% | 100% |

  Target PRD (extraction 90, project 95, matching 90, date 95) tercapai di kedua run. Status (84%) masih di bawah v1 (91–92%): sisa kesalahan adalah model mengisi `in_progress`/`waiting`/`completed` pada
  bagian tugas (R027, R030, R035, R064, R074, R081, …) atau melewatkan `completed` eksplisit (R052). Ditangani konfirmasi Completed/Cancelled dan Undo (M4c/M4d), bukan prompt. Angka ini dari data sintetis; ambang
  dan keputusan prompt berikutnya tetap menunggu dataset nyata user (`tests/Eval/data/`).
- PHPStan naik ke level 8 tanpa baseline. `WorklogResult` kini selalu membawa proposal dan outcome (bidang `placeholder` dihapus).
- Prompt koreksi (`worklog_correction@v1`) belum punya kasus eval (lihat M4f).

## M5a — Login dashboard (PRD §22, §56)

- Panel `admin` tidak punya login email/password lagi (di semua environment). Halaman login (`TelegramLogin`) hanya memuat Telegram Login Widget bila `TELEGRAM_BOT_USERNAME` terisi.
- `TelegramLoginVerifier`: HMAC-SHA256 dengan SHA256(token bot) sebagai kunci, `hash_equals`, `auth_date` ≤ 5 menit (toleransi 60 dtk ke depan), nilai harus string, payload yang sama hanya berlaku sekali (cache). Semua kegagalan = 403 polos.
  Field tambahan dari Telegram ikut dalam string yang ditandatangani (seperti referensi Telegram), bukan ditolak.
- Whitelist = `users.telegram_user_id`; `User::canAccessPanel` mensyaratkannya. Panel di production HTTPS-only (`RequireSecureInProduction` pada middleware panel).
- Jalur dev/darurat: `php artisan reportflow:login-link <telegram_id>` mencetak link sekali pakai (5 menit, hanya hash token di cache, ditolak di production). Bukan command bot: daftar PRD §20 tidak berubah. Widget baru bisa dipakai setelah ada domain HTTPS tetap yang didaftarkan lewat `/setdomain` di BotFather.
- `BindUserContext` mengikat `UserContext` dari user yang login pada page load (authMiddleware) dan pada setiap update Livewire (persistent middleware); tanpa user, query ber-scope gagal-tertutup.

## M5b — Catat Pekerjaan di dashboard (PRD §22, §23)

- Halaman beranda panel = `WorklogInput`: kotak teks → `DashboardSubmission` (redaction fail-closed, `inbound_messages.source=dashboard`, `idempotency_key = dashboard:{user}:{uuid form}`, `insertOrIgnore`, lalu `ProcessInboundMessage`). Kunci diganti setelah sukses; klik ganda/retry membawa kunci sama → satu baris.
  Pesan dashboard tidak memicu pesan Telegram (`DeliverInboundConfirmation` hanya untuk sumber Telegram).
- Daftar catatan terbaru (kedua channel, `wire:poll.5s`) memakai `OutcomeItemPresenter` (data terstruktur; Telegram memformat teks darinya, keluaran Telegram tidak berubah). Tombol Undo / Pindah Task / Ubah Status / Ganti Project memanggil `UndoService` / `CorrectionService` yang sama dengan Telegram.
- Bahasa UI per request dari `users.default_language` (di `BindUserContext`, termasuk update Livewire).

## M5c — Halaman Task (PRD §22, §23)

- `TaskResource`: daftar (filter status/project/periode `last_activity_at` dalam hari kalender user, pencarian judul, polling 10 dtk) dan halaman detail (detail, aktivitas, riwayat `task_events`). Tidak ada buat/hapus di UI: task lahir dari catatan, dan setiap perubahan lewat `TaskLifecycle`.
- Edit di halaman detail: ubah judul (`TaskLifecycle::rename`, event `title_changed`), ubah status (opsi dari matriks, `changeStatus`), pindahkan aktivitas (`ActivityMover`: event `moved` di task asal dan tujuan dengan `activity_ids`, `project_id` aktivitas mengikuti task tujuan, `last_activity_at` dihitung ulang dari `activity_date` terbaru, baris `corrections(move_task)` tanpa pesan).
- Optimistic locking: halaman menyimpan `loadedVersion` (terkunci) saat dibuka dan memakainya sebagai `expectedVersion`. Bila task berubah dari channel lain, edit ditolak dengan notifikasi "Task ini baru saja diubah…" dan aksi "Muat ulang" memperbarui versi.

## M5d — Inbox dan sinkronisasi dua arah (PRD §22, §23, §74)

- `PendingAnswerService` (Worklog) menjawab pertanyaan klarifikasi untuk semua channel; `PendingAnswerHandler` (Telegram) hanya pembungkus. Jawaban kedua dari channel mana pun menemukan item tidak lagi `pending` dan tidak menulis apa-apa ("sudah dijawab").
  `ProposalApplier::applyPending` kini mempertahankan `question_message_id` pada item hasil, agar channel lain tetap bisa menemukan bubble pertanyaannya.
- Halaman `Inbox`: pesan `failed` / `needs_clarification` dari kedua channel, polling 5 dtk. Teks dan tombol pertanyaan memakai `ConfirmationComposer::question()` yang sama dengan Telegram (callback data di-parse menjadi aksi Livewire), jadi tidak ada logika pertanyaan ganda.
- `SyncTelegramBubbles` (job idempoten, hanya id): Dashboard → Telegram. `answered`: bubble pertanyaan menjadi "✅ Sudah dijawab lewat dashboard" (+ hasil dan tombol koreksi bila diterapkan) lalu konfirmasi digambar ulang; `refresh`: setelah undo/koreksi dari dashboard; `reprocessed`: bubble lama ditutup, konfirmasi kembali "⏳". Pesan asal dashboard tidak punya bubble sehingga tidak ada job. Telegram → dashboard otomatis lewat polling.
- Keputusan sederhana M5: pertanyaan untuk catatan yang berasal dari dashboard hanya muncul di dashboard.

## M5e — Pengaturan dan penutup M5 (PRD §22, §74)

- Halaman `Settings`: bahasa, zona waktu (daftar IANA), hari kerja, saklar reminder (hanya disimpan; logika reminder = M6) dan project (`ProjectService::rename/setAliases/setActive`: nama unik per user lewat slug, alias unik maks 10, arsip tidak menghapus task/riwayat tetapi project tidak lagi ditawarkan ke asisten).
- Kriteria §74 poin 1–2 diuji di `tests/Feature/Dashboard/AcceptanceTest.php`: halaman memoll tiap 5 dtk (≤ 10 dtk) dan tidak ada duplikat dari klik ganda, retry, atau webhook terkirim ulang. Poin 3–6 (report) milik M7.
- Di luar M5: halaman Reports (M7), reminder (M6), command `/login` di bot, pertanyaan klarifikasi untuk catatan dashboard di Telegram.

## M6 — Reminder harian (PRD §25, §26, §28, §29, §33, §34)

- **Cakupan:** hanya Daily Worklog Reminder (MVP). Monthly Report dan late entries = M8 (butuh laporan M7); `/reminder monthly` menjawab "belum tersedia". Weekly/Waiting/Stale/Cross-month = Phase 3. Tidak ada AI (§31: backend menentukan).
- **Satu rule global per user** (`reminder_rules`, type `daily_worklog`, `schedule.time` "HH:MM" zona user, default 18:00), dibuat otomatis oleh `ReminderSettings::daily()`. Hari kerja dan saklar global dari `users.workdays` / `users.reminders_enabled` (default true: user yang sudah ada mulai diingatkan pada pass scheduler pertama).
- **Scheduler:** `reminders:dispatch` tiap menit (`routes/console.php`, container `scheduler`). Satu instance per rule per hari lokal (`reminder_instances.reminder_date`, unik, `insertOrIgnore`) → idempoten. `SendReminder` mengklaim secara atomik (`scheduled|snoozed → sent`, `send_count+1`); retry setelah kegagalan Telegram hanya mengirim.
- **Aturan kirim (`ReminderPolicy`, dicek di dispatcher dan lagi di job):** reminders/rule aktif; hari kerja; "activity hari ini" = ada `activities.activity_date` = tanggal lokal hari ini (catatan untuk kemarin tidak menggugurkan; catatan bertanggal hari ini yang ditulis kapan pun menggugurkan); batas 3 pesan per hari lokal (semua kiriman, termasuk kiriman ulang setelah snooze); instance gugur (`cancelled`, alasan di `action_taken`: `disabled`, `already_logged`, `expired`, `daily_limit`, `undeliverable`).
- **Jendela kedaluwarsa 2 jam:** reminder tidak dibuat/dikirim lebih dari 2 jam setelah jadwal (scheduler mati tidak boleh membangunkan user pukul 23:00).
- **Tombol** (`r:{instance}:{aksi}`, `ReminderCallback`): Tambah catatan (`acknowledged`), Tidak ada hari ini (`dismissed`), Ingatkan 1 jam lagi (`snoozed`, maks 3 kali; snooze yang jatuh melewati tengah malam = ditutup `snooze_past_day`). "Snooze besok" pada reminder harian sama dengan "Tidak ada hari ini", karena reminder hari kerja berikutnya sudah terjadwal. Hanya bubble pengiriman terbaru dari instance yang masih `sent` yang berlaku; tekan ganda/bubble lama → "sudah ditanggapi".
- **`/reminder`:** status, `on`, `off` (mematikan membatalkan instance yang menunggu), `daily HH:MM` (juga `9.05`, `9:30`), `monthly`. Halaman Pengaturan memakai `ReminderSettings` yang sama.

## M7a–M7b — Data periode dan generate per section (PRD §36–§38, §44, §45)

- **Hibrida (keputusan user):** angka, daftar dan tabel tiap section dibuat deterministik dari snapshot (`ReportFactsBuilder`, Markdown formal id/en dari `lang/*/report.php`, teks buatan orang di-escape). AI (`report_section@v1`) hanya menulis narasi untuk section `overview`, `detailed`, `ongoing`, `summary`; narasi ditaruh di depan fakta.
- **Snapshot:** `ReportDataSelector` memilih activity (tanggal kalender user, inklusif, tidak terhapus) per project; versi berikutnya memakai `source_activity_ids` yang dibekukan bila diminta. Grup: completed (`completed_at` dalam periode), ongoing (open/in_progress/blocked + activity di periode), waiting, cross-month (mulai sebelum periode, `started_at` atau activity pertama), incidents (blocker/resolution). Cancelled/Draft tidak dilaporkan. Status task dibaca live ("keadaan di akhir periode seperti diketahui sekarang").
- **Traceability narasi (`SectionNarrativeValidator`):** model hanya boleh menyebut task lewat `{{task:ID}}` (diganti judul asli, di-escape); ID harus ada di data section dan dideklarasikan di `used_task_ids`; angka harus muncul di data section (id tidak dihitung); tanpa heading/tabel/HTML/link; skema ketat; maks 1500 karakter. Ditolak → satu retry dengan kode kesalahan (tanpa teks balasan) → kalau tetap gagal atau provider error: kalimat tetap berisi angka dari data, `fallback: true`. Periode tanpa activity tidak memanggil AI.
- **Lock:** `generation_lock_until` diambil atomik; lock kedaluwarsa (worker mati) boleh diambil alih; crash mengembalikan status sebelumnya. `GenerateReport` (queue `reports`) mendukung "Tunggu Selesai" (release 15 dtk sampai tidak ada entri `received|processing` atau batas `waitUntil`) dan "Tanpa entri ini".
- Belum ada eval kualitas narasi di model sungguhan (seperti prompt koreksi).

## M7c — Markdown, HTML, PDF, file dan unduh (PRD §40, §56, §64, §65)

- **Alur:** versi → `ReportMarkdown` (.md) dan `ReportHtml` (CommonMark + ekstensi tabel; `html_input=strip`, `allow_unsafe_links=false`) di dalam Blade `reports.templates.generic` (CSS `@page` A4, heading, tabel dengan header berulang) → `PdfRenderer`. **Pratinjau dashboard dan masukan Gotenberg adalah string HTML yang sama** (diuji); footer/nomor halaman lewat file `footer.html` Gotenberg, bukan bagian pratinjau. Font: Liberation Sans/Arial (ada di Gotenberg). Hasil render sungguhan diperiksa lewat container Gotenberg lokal.
- **`PdfRenderer`** (interface): `GotenbergPdfRenderer` (`POST /forms/chromium/convert/html`, multipart `index.html` + `footer.html`, A4; error hanya berupa kode `pdf_engine_*`, dokumen tidak pernah masuk pesan/log) dan `FakePdfRenderer`. `PDF_RENDERER=fake` dipaksa di tes (`tests/bootstrap.php`, `TestIsolationTest`); production menolak `fake` saat boot. Renderer ikut daftar klien keluar yang boleh memakai fasad `Http` (tes arsitektur).
- **File:** disk privat `reports` (`REPORTS_DISK_ROOT`, di tes `Storage::fake('reports')` global). `ReportFiles::ensure`: PDF dirender dulu; hanya bila sukses `.md` dan `.pdf` ditulis dan kedua baris `report_files` dicatat (checksum SHA-256), kegagalan di langkah mana pun menghapus yang sudah tertulis, sehingga PDF tidak pernah ada tanpa `.md`. Idempoten. Dibuat oleh job `RenderReportFiles` (queue `reports`, 3 percobaan) setelah versi tersimpan.
- **Unduh:** `GET /reports/files/{file}` (`signed`, kedaluwarsa 10 menit, `u` = pemilik, HTTPS-only di production, throttle); `SignedDownload::url` hanya membuat URL untuk file milik user (query ber-scope); respons `attachment`, `nosniff`, `no-store`.

## M7d — Halaman Laporan di dashboard (PRD §22, §23, §42, §43)

- **Daftar** (`ReportResource`, user-scoped lewat project, polling 5 dtk, filter status/project) dan **halaman laporan** (`ViewReport`): pratinjau = `ReportHtml::render` di iframe `sandbox` (string sama dengan masukan Gotenberg, diuji), unduhan PDF/MD lewat signed URL baru tiap render (hanya bila kedua file ada), editor Markdown per section, riwayat versi, perbandingan dua versi (diff baris per section, `ReportDiff`), Setujui, Batalkan.
- **Generate** (`ReportRequests`): periode satu bulan penuh = `monthly`, selain itu `custom`; laporan untuk project+jenis+periode+bahasa yang sama dipakai ulang (versi baru), `cancelled` boleh dibuat lagi; ditolak "sedang dibuat" bila lock aktif; pre-generate check menampilkan pilihan Tunggu Selesai / Tanpa entri ini hanya bila ada entri `received|processing`.
- **Versi append-only.** `ReportWorkflow`: edit = versi baru `user_edit` (snapshot dan `source_activity_ids` ikut versi sebelumnya), approve menandai `report_versions.approved_at` (kolom baru) dan model menolak mengubah/menghapus versi yang sudah di-approve (`ImmutableReportVersionException`). Laporan "approved" selama versi saat ini approved; edit berikutnya = versi baru, status kembali `in_review`, versi approved lama utuh. Kegagalan generate mengembalikan status (`Approved` bila versi saat ini approved).
- **Optimistic locking:** halaman membawa `loadedVersionId` (terkunci); edit/approve dengan versi yang sudah usang ditolak (`StaleReportException`) dengan pesan jelas dan teks yang sedang diketik tidak hilang. Polling 3 dtk memuat versi baru otomatis hanya bila tidak ada teks yang belum disimpan; selain itu muncul tombol "Muat versi terbaru".

## M7e — Edit via instruksi dan fakta baru (PRD §42, CLAUDE.md aturan 9)

- **Urutan (`ReportEditor::instruct`):** instruksi di-redact (gagal = ditolak, tidak ada panggilan AI) → AI `report_instruction@v1` menemukan FAKTA BARU (hanya hal yang terjadi dan belum ada di data; tiap fakta harus untuk satu task di laporan, tanggal dalam periode dan tidak di masa depan, divalidasi, retry sekali) → fakta yang tidak cocok dengan task mana pun menghentikan proses ("sebutkan task-nya", tidak ada yang ditulis) → section ditulis ulang (`report_section`) dari data **seolah-olah fakta sudah tersimpan** → baru dalam SATU transaksi: activity `source=report_edit` (+ `touchActivity` task) dan versi `instruction_edit` (`instruction` tersimpan, `source_activity_ids` bertambah). Semua langkah AI terjadi sebelum transaksi; kegagalan di mana pun = tidak ada activity dan tidak ada versi. Konflik versi saat model bekerja = rollback seluruhnya.
- Angka dalam narasi hanya boleh berasal dari data: **instruksi tidak ikut memperluas daftar angka**, jadi angka yang diketik editor baru boleh muncul setelah menjadi activity. Rewrite yang jatuh ke kalimat tetap ditolak (`rewrite_failed`) daripada disimpan setengah hati. Section yang hanya berisi data (completed, cross_month, incidents) berubah hanya bila ada fakta baru.
- **Edit manual:** setelah simpan, section yang memuat angka baru (dibanding versi sebelumnya) memunculkan tawaran "Simpan sebagai activity?" (form task laporan, tanggal dalam periode, uraian; di-redact); hanya saran, tidak menulis apa pun sendiri.
- Belum ada eval kualitas `report_instruction`/`report_section` di model sungguhan.

## M7f — Worker laporan, RAM dan penutup (PRD §83, §84)

- **`worker-reports`** (queue `reports`, satu proses, `--max-time=3600 --memory=320`, batas 384M, jaringan internal + egress untuk AI); worker utama kini `--queue=default,ai`. Gotenberg tetap 1 Chromium. Dijaga tes infra.
- **Terukur lokal** (satu laporan nyata via Redis + Gotenberg): `worker-reports` ≈ 58 MiB, `gotenberg` ≈ 504 MiB, `worker` ≈ 48 MiB; PDF ≈ 31 KB, `.md` ≈ 1,2 KB. Render PDF sungguhan diperiksa visual.
- Tidak ada pengantar untuk daftar kosong (section Ongoing/Detailed tanpa data hanya berisi "None."/"Tidak ada.").
- **Sisa/di luar M7:** kualitas narasi `report_section` dan `report_instruction` di model sungguhan belum diukur (tidak ada eval laporan); pengiriman/review lewat Telegram, reminder bulanan dan late entries = M8; template per project dan DOCX = Phase 3.

## M8a — Review laporan via Telegram (PRD §42, §65)

- **Dokumen, bukan tautan:** `TelegramClient::sendDocument` (multipart `document`, timeout upload 30 dtk) mengirim PDF (saat review) dan PDF + `.md` (setelah approve) langsung ke chat; tidak butuh domain publik. Keyboard boleh berisi tombol URL (`array<string,string>`). Tombol "Buka di Dashboard" hanya bila URL laporan berskema https dan host publik (Telegram menolak URL lokal).
- **`SendReportReview`** (queue `reports`, id + token): menunggu file versi itu sampai 180 dtk (release 10 dtk), lalu mengirim apa yang ada dengan catatan "file menyusul" daripada tidak mengirim apa pun; tiap langkah (pesan, PDF, MD) bertanda cache per dispatch sehingga retry tidak mengulang. Dipicu dari tombol Telegram, `/review`, dan tombol dashboard "Kirim ke Telegram".
- **Tombol** `p:{report}:{versi}:{aksi}[:{arg}]` (`ReportCallback`): Approve, Regenerate, Edit via instruksi (menu section → "tulis instruksinya" → pesan berikutnya), Cancel, Kembali. Penjaga basi: nomor versi di payload ≠ versi saat ini → toast "sudah berubah" dan bubble diganti review terbaru, tanpa mengubah apa pun. Semua aksi lewat `ReportWorkflow`/`ReportEditor`/`ReportRequests` yang sama dengan dashboard.
- **Instruksi dari Telegram:** state "menunggu instruksi" (`AwaitingReportInstruction`, cache 10 menit, hanya id + kunci section). Pesan teks berikutnya (setelah redaction) diproses `ReportEditor::instruct(channel=telegram)` dan **tidak** disimpan sebagai worklog; versi baru dikirim lagi sebagai review. Bila laporan berubah setelah section dipilih → "sudah berubah".
- `/review` (kirim ulang draft `in_review` terbaru) dan `/reports` (6 laporan terbaru + status) tersedia; `/report` dan `/generate` menyusul di M8b.

## M8b — Mulai laporan dari Telegram (PRD §20, §23, §42)

- `/report [YYYY-MM]`: tanpa argumen, bulan yang dilaporkan = bulan berjalan mulai tanggal 25 (zona user), selain itu bulan lalu; argumen salah → petunjuk. Hanya project aktif yang punya activity di bulan itu yang ditawarkan; satu → langsung, beberapa → tombol `g:{project}:{YYYYMM}` (`ReportStartCallback`). Bahasa laporan = `projects.default_language`, bukan bahasa bot.
- Sebelum generate: entri `received|processing` → pertanyaan [Tunggu selesai] / [Tanpa entri ini] (`wt`/`sk`, tidak terikat versi); laporan yang sudah `approved` → [Buat versi baru] / [Biarkan] (`nv`/`ig`); lock aktif → "sedang dibuat". `GenerateReport(channel=telegram)` mengirim review lewat `SendReportReview` begitu versi jadi.

## M8c — Pengingat bulanan (PRD §25, §32, §33, §62, §63)

- **Rule** `monthly_report` global per user (`schedule`: hari terakhir bulan, `time` default 09:00), dibuat otomatis oleh `ReminderSettings::monthly()`; saklar global `users.reminders_enabled` berlaku untuk keduanya. Dikirim pada **hari terakhir bulan di zona user, tanpa memandang hari kerja**, dalam jendela 2 jam dari jam jadwal; instance unik per rule+tanggal.
- **Gugur** (`ReminderPolicy`): `disabled`, `expired` (lewat jendela, atau snooze melewati 3 hari setelah akhir bulan), `no_activity` (bulan itu tanpa activity), `report_exists` (setiap project aktif ber-activity sudah punya laporan `approved` untuk bulan itu; draft/in_review tidak dihitung), `daily_limit` (batas 3 pesan/hari kini dihitung per hari lokal saat pengiriman dan dibagi dengan pengingat harian).
- **Isi kontekstual** (`MonthlyReminderComposer` + `MonthlySummary`, angka dari `ReportDataSelector` yang sama dengan laporan): per project aktif ber-activity: aktivitas, selesai, berjalan, menunggu, lintas bulan. "Activity dengan detail belum lengkap" (PRD §62) tidak dimuat karena datanya tidak disimpan (sisa).
- **Tombol**: Buat Laporan (`gen` → `ReportCommands::start` untuk bulan itu: satu project langsung, beberapa memilih), Tinjau Aktivitas (`rev` → daftar task berjalan), Nanti (`later` → snooze 1 hari, maks 3). `/reminder monthly [HH:MM|on|off]` dan Pengaturan dashboard mengatur jam dan saklarnya.

## M8d — Late entries (PRD §43)

- **`ReportDrift`** (read-only) menghitung perubahan data periode sejak `data_snapshot_at` versi saat ini (atau sejak "Abaikan" bila lebih baru): `new` (activity bertanggal dalam periode, ditulis setelah baseline, bukan sumber versi), `changed` (activity periode yang diedit), `removed` (activity sumber yang dihapus), `tasks` (task dalam laporan yang punya `task_events` baru: status/judul/pindah). Activity yang ditambahkan edit-via-instruksi ke versi itu sendiri bukan drift.
- **`reports:check-drift`** (tiap 10 menit, idempoten): laporan `approved` dengan drift → `outdated` (transisi atomik) + SATU notifikasi Telegram "…sudah di-approve, tetapi ada N perubahan. Buat versi baru?" [Buat Versi Baru] [Abaikan]; `outdated` yang perubahannya hilang (mis. undo) kembali `approved` tanpa pesan. Draft/`in_review` tidak diubah statusnya: dashboard menampilkan banner "N perubahan sejak draft dibuat" + [Perbarui Draft].
- **Abaikan** = `approved` lagi + `reports.drift_dismissed_at`; perubahan setelah itu memicu lagi. **Buat Versi Baru / Perbarui Draft** = generate dari snapshot baru (versi approved lama tetap utuh dan bisa diunduh). Laporan `outdated` tidak bisa dibatalkan (punya versi approved).
