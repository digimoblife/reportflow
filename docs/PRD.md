# **Product Requirements Document (PRD)**

# **ReportFlow AI**

**Product Name:** ReportFlow AI  
**Product Type:** AI-powered Worklog & Reporting Assistant  
**Version:** 1.4  
**Status:** Revised — Ready for Development Planning  
**Primary Interface:** Telegram Bot + Web Dashboard  
**Telegram Bot:** Pak Carik (`@PakCarikk_bot`)  
**AI Model:** DeepSeek V4.1-Flash (`deepseek-flash`)  
**Backend:** Laravel  
**Database:** PostgreSQL  
**Hosting:** Single VPS (Docker Compose)  
**Output:** PDF & Markdown (DOCX opsional, Phase 3)  
**Document Engine:** Markdown → HTML (Blade) → PDF (Gotenberg Chromium)  
**Dashboard:** Laravel Filament (Livewire)  
**Primary Languages:** Indonesian & English  
**Initial Target:** Individual professional / internal use

---

# **Revision Notes — v1.4**

Perubahan utama dibandingkan v1.3:

1. **Hosting ditetapkan: seluruh sistem berjalan di satu VPS** dengan Docker Compose. Cloudflare D1 tidak digunakan (tidak kompatibel dengan Laravel dan fitur PostgreSQL yang dibutuhkan). Cloudflare R2 tidak digunakan di MVP.
2. Section baru **Storage & Backup**: file laporan disimpan di private disk VPS (Docker volume), dan backup harian wajib dikirim ke lokasi di luar VPS.
3. Rekomendasi spesifikasi VPS ditambahkan.

---

# **Revision Notes — v1.3**

Perubahan utama dibandingkan v1.2:

1. **Telegram Bot diberi nama dan persona: Pak Carik** (`@PakCarikk_bot`). Carik adalah sekretaris desa yang bertugas mencatat dan membuat laporan, sesuai fungsi bot.
2. Section baru **Bot Persona: Pak Carik** berisi karakter, gaya bahasa, contoh pesan, batasan, dan aturan implementasi.
3. Section baru **Bot Identity & BotFather Setup** berisi deskripsi, about text, daftar command, dan avatar.
4. **Security** ditambah aturan pengelolaan bot token (rotasi jika bocor) dan verifikasi webhook dengan secret token.
5. Pesan onboarding `/start` disesuaikan dengan persona.

---

# **Revision Notes — v1.2**

Perubahan utama dibandingkan v1.1:

1. **Output utama menjadi PDF dan Markdown.** Alur dokumen: isi report (JSON per section) → Markdown → HTML → PDF. File `.md` selalu tersedia karena menjadi format perantara. DOCX menjadi opsional di Phase 3.
2. **PDF dibuat dari HTML** (Blade + CSS, dirender Chromium via Gotenberg), bukan dikonversi dari DOCX. PhpWord tidak lagi digunakan di MVP.
3. **Web Dashboard masuk MVP** (sebelumnya Phase 4), dibangun dengan Laravel Filament. Login menggunakan Telegram Login Widget.
4. **Multi-Channel Consistency** ditambahkan: Telegram dan dashboard memakai satu jalur proses dan satu sumber data, dengan snapshot saat generate, pengecekan entri yang masih diproses, sinkronisasi dua arah, optimistic locking, dan idempotency key.
5. **Edit laporan dipindah ke dashboard** (editor Markdown per section). Fitur upload DOCX hasil edit dihapus.
6. Database, MVP scope, urutan pengembangan, deployment, dan acceptance criteria disesuaikan.

---

# **Revision Notes — v1.1**

Perubahan utama dibandingkan v1.0:

1. **MVP dipangkas** menjadi Phase 1 (Worklog Foundation) + Phase 2 (Monthly Reporting). Reminder lanjutan, incident report, search, dan template per project dipindah ke Phase 3.
2. **Candidate retrieval untuk task matching didefinisikan**: MVP memakai context injection (semua task aktif dikirim ke AI), bukan vector search.
3. **Confidence tidak lagi dianggap terkalibrasi.** Threshold ditentukan dari Evaluation Dataset dan dikombinasikan dengan sinyal deterministik.
4. **Correction & Undo UX** ditambahkan sebagai fitur MVP.
5. **Multi-item message** (satu pesan berisi beberapa task/project) didukung.
6. **Task lifecycle** diperjelas dengan definisi status dan matriks transisi. Status `Resolved` dihapus dari task (tetap dipakai untuk incident).
7. **Database schema direvisi**: `inbound_messages`, `task_events`, `corrections`, `people`, `report_templates`, `report_versions`, `incidents`, serta pemisahan `reminder_rules` dan `reminder_instances`.
8. **Report review dan late entries** didefinisikan (edit via instruksi, upload DOCX, status `outdated`).
9. **Document generation** diganti ke PhpWord + Gotenberg agar stack tetap satu bahasa.
10. **Security**: redaction layer sebelum data dikirim ke AI, serta pertimbangan pemrosesan data oleh AI provider pihak ketiga.
11. **Evaluation Dataset** ditambahkan sebagai prasyarat pengembangan prompt dan dasar pengukuran acceptance criteria.
12. Pipeline AI digabung menjadi **satu AI call** per pesan dengan instant acknowledgement agar respons terasa cepat.

---

# **1\. Product Overview**

## **1.1 Product Vision**

ReportFlow AI adalah asisten berbasis AI yang membantu pengguna mencatat aktivitas pekerjaan melalui Telegram, menghubungkan aktivitas tersebut dengan task yang sudah ada, menjaga riwayat pekerjaan lintas hari dan bulan, mengingatkan pengguna terhadap pekerjaan yang belum dicatat atau ditindaklanjuti, serta menghasilkan laporan profesional secara otomatis.

Konsep utama:

> **Capture your work anywhere. Track the progress. Never forget the report.**

Pengguna tidak perlu menunggu sampai berada di depan laptop untuk mencatat pekerjaan.

Cukup mengirimkan pesan melalui Telegram seperti:

> "9Club — Xing minta setup 8 domain baru."

ReportFlow AI akan memahami bahwa pesan tersebut merupakan aktivitas pekerjaan dan menyimpannya sebagai task.

Ketika beberapa hari kemudian pengguna mengirim:

> "Semua domain sudah dibeli dan dikonfigurasi."

AI akan mencari task yang relevan dan menghubungkan update tersebut ke task sebelumnya.

Pada akhir periode, system dapat mengingatkan pengguna untuk melakukan review dan menghasilkan laporan.

---

# **2\. Problem Statement**

Pengguna yang mengerjakan beberapa project secara bersamaan sering menghadapi beberapa masalah:

1. Lupa mencatat pekerjaan yang sudah dilakukan.  
2. Baru mengingat pekerjaan ketika sudah mendekati akhir bulan.  
3. Sulit mengingat detail task yang terjadi beberapa minggu sebelumnya.  
4. Satu task dapat berlangsung lintas hari atau lintas bulan.  
5. Update task sering tersebar di berbagai tempat.  
6. Sulit mengetahui task mana yang masih berjalan, waiting, atau belum memiliki final status.  
7. Membuat laporan bulanan membutuhkan waktu lama.  
8. Terkadang membutuhkan incident report secara mendadak ketika tidak berada di depan laptop.  
9. Setiap project dapat mempunyai format laporan berbeda.  
10. Laporan dapat dibutuhkan dalam bahasa Indonesia maupun bahasa Inggris.  
11. Pengguna memiliki banyak project sehingga pencatatan harus dapat membedakan masing-masing project.  
12. Reminder yang terlalu sederhana hanya mengingatkan tanggal laporan, tetapi tidak membantu pengguna mengetahui apa yang sebenarnya perlu diperiksa.

---

# **3\. Product Goals**

ReportFlow AI harus mampu:

## **G1 — Capture Work Quickly**

Memungkinkan pengguna mencatat aktivitas kerja melalui Telegram menggunakan bahasa natural.

Contoh:

> "Hari ini saya fix bug member management di 9Club."

Tidak diperlukan format khusus.

---

## **G2 — Understand Work Context**

AI memahami:

* Project  
* Task  
* Aktivitas  
* Orang yang terlibat  
* Tanggal  
* Status  
* Milestone  
* Hasil pekerjaan  
* Blocking issue  
* Dependency  
* Follow-up

---

## **G3 — Maintain Task Continuity**

Task harus menjadi entity yang persistent dan tidak terikat pada satu periode laporan.

Contoh:

17 August:

> Mia meminta setup Philippines Region.

18 August:

> Configuration selesai.

20 August:

> Configuration diberikan ke Mia.

15 September:

> Philippines Region officially launched.

Semua aktivitas tersebut merupakan satu task:

**Philippines Market Setup**

---

## **G4 — Support Cross-Month Tasks**

Task yang dimulai pada bulan sebelumnya tetap dapat dilanjutkan pada bulan berikutnya.

Contoh:

August Report:

> Philippines Market Setup — Configuration completed and handed over to Mia's team. Status: Waiting for launch.

September Report:

> Philippines Market Setup — Continued from August and officially launched publicly on 15 September. Status: Completed.

Task tetap sama.

Yang berubah hanya reporting period.

---

## **G5 — Generate Professional Reports**

ReportFlow AI harus dapat menghasilkan laporan profesional berdasarkan aktivitas yang tersimpan.

Output:

* PDF (utama)
* Markdown
* DOCX (opsional, Phase 3)

---

## **G6 — Multi Project**

Pengguna dapat mempunyai banyak project.

Contoh:

* 9Club  
* More Spin  
* VPS Infrastructure  
* BKAD  
* Other Projects

Masing-masing project dapat mempunyai:

* Task  
* Activity  
* Template  
* Reporting schedule  
* Language  
* Reminder configuration

---

## **G7 — Bilingual**

ReportFlow AI mendukung:

* Indonesian  
* English

Bahasa dapat ditentukan:

* per user  
* per project  
* per report

---

## **G8 — Prevent Forgotten Work**

System harus membantu pengguna mengingat aktivitas yang belum dicatat.

Contoh:

Jika pengguna biasanya aktif mencatat pekerjaan tetapi hari itu belum ada worklog, system dapat mengirim reminder:

> "You haven't recorded any worklog today. Did you work on anything?"

---

## **G8.1 — Work From Anywhere**

User dapat mencatat pekerjaan dan membuat laporan dari dua channel:

* **Telegram** — saat mobile atau tidak di depan laptop.
* **Web Dashboard** — saat bekerja di laptop.

Kedua channel harus selalu konsisten karena menggunakan data yang sama.

---

## **G9 — Prevent Forgotten Reports**

System harus mengingatkan pengguna ketika periode laporan sudah mendekati atau berakhir.

Reminder harus dapat menunjukkan informasi kontekstual seperti:

* jumlah activity  
* completed task  
* ongoing task  
* waiting task  
* cross-month task  
* incomplete worklog

---

# **4\. Non-Goals**

Fitur berikut tidak menjadi fokus MVP:

* Full project management seperti Jira  
* Employee management  
* Payroll  
* Accounting  
* CRM  
* Employee performance scoring  
* Automatic client billing  
* Automatic client email  
* Team collaboration kompleks  
* Slack integration  
* GitHub integration  
* Jira integration  
* Trello integration  
* Time tracking otomatis  
* Attendance system

Fitur tersebut dapat dipertimbangkan pada fase berikutnya.

---

# **5\. Target Users**

## **5.1 Primary User**

Individual professional yang menangani banyak pekerjaan/project.

Contoh:

* Software Developer  
* Sysadmin  
* Technical Support  
* IT Consultant  
* Freelancer  
* Project-based worker  
* Agency worker  
* Technical Project Manager

---

## **5.2 Secondary User**

Small team yang membutuhkan centralized worklog.

Namun MVP difokuskan terlebih dahulu pada:

> Single User

---

# **6\. Core Product Concept**

Struktur utama aplikasi:

User  
  │  
  ├── Project  
  │     │  
  │     ├── Task  
  │     │     ├── Activity  
  │     │     ├── Activity  
  │     │     ├── Milestone  
  │     │     └── Resolution  
  │     │  
  │     └── Report  
  │  
  └── Settings

Prinsip utama:

> **Task adalah entity jangka panjang. Report adalah view berdasarkan periode waktu.**

Dengan demikian task tidak dibuat ulang setiap bulan.

---

# **7\. Core Workflow**

```
User sends Telegram message
        ↓
Laravel webhook
        ↓
Simpan ke inbound_messages (status: received)
        ↓
Bot langsung membalas "⏳ Mencatat…"
        ↓
Redis Queue → Worker
        ↓
Redaction (hapus credential / secret)
        ↓
Build context: daftar project + candidate tasks
        ↓
Satu AI call: extract + match + classify + status
        ↓
Backend validation
        ↓
Save: activities, tasks, task_events
        ↓
Edit pesan "⏳" menjadi konfirmasi + tombol koreksi
```

Catatan:

* Intent detection, project detection, date extraction, task matching, activity classification, dan status detection dilakukan dalam **satu panggilan AI** untuk menekan latensi dan biaya. Pipeline dipecah menjadi beberapa panggilan hanya jika Evaluation Dataset menunjukkan peningkatan akurasi yang signifikan.
* Instant acknowledgement membuat bot terasa responsif walaupun pemrosesan AI membutuhkan beberapa detik.

Input dari Web Dashboard menggunakan jalur yang sama: disimpan ke `inbound_messages` dengan `source = dashboard`, lalu diproses oleh queue, AI, dan validasi yang sama. Perbedaannya hanya pada cara konfirmasi ditampilkan (di halaman dashboard, bukan pesan Telegram). Lihat Multi-Channel Consistency.

---

# **8\. Worklog Capture**

Pengguna dapat menggunakan bahasa natural.

Contoh:

> "9Club — Xing minta setup 8 domain premium baru."

System menginterpretasikan:

Project:

> 9Club

Task:

> Premium Domain Acquisition

Activity:

> Request received from Xing

Status:

> Open

Tanggal:

> Current date

Bot dapat memberikan konfirmasi:

> Recorded to 9Club → Premium Domain Acquisition.

---

# **9\. Natural Language Input**

Pengguna tidak harus mengikuti format tertentu.

Contoh:

> "Hari ini fix forgot password 9Club. Ternyata Mailgun suspended karena spam report. Saya buat account baru dan integrasikan ulang."

AI harus dapat menghasilkan:

Project:

> 9Club

Task:

> Forgot Password Email Issue

Activities:

1. Investigated email failure  
2. Identified Mailgun suspension  
3. Created new Mailgun account  
4. Integrated new Mailgun configuration

Root Cause:

> Mailgun account suspended after spam report.

Resolution:

> New Mailgun account integrated.

Status:

> Completed

---

# **10\. Multi-Item Messages**

Satu pesan dapat berisi lebih dari satu aktivitas, task, atau project.

Contoh:

> "Fix login 9Club, terus restart VPS More Spin karena memory penuh."

AI menghasilkan dua item:

1. Project: 9Club — Task: Login Issue — Activity: bug\_fix
2. Project: More Spin — Task: VPS Memory Issue — Activity: investigation / resolution

Aturan:

* Setiap item divalidasi secara independen oleh backend.
* Konfirmasi bot menampilkan setiap item beserta tombol koreksinya masing-masing.
* Jika satu item memiliki confidence rendah, hanya item tersebut yang meminta klarifikasi. Item lain tetap disimpan.
* Maksimum 5 item per pesan. Jika lebih, bot meminta user memecah pesan.

---

# **11\. Task Continuity**

Task matching merupakan salah satu fitur paling penting dalam ReportFlow AI.

Contoh:

## **Day 1**

User:

> "Xing minta setup domain baru untuk 9Club."

System membuat:

Task:

> 9Club Domain Setup

Status:

> Open

## **Day 3**

User:

> "Domainnya sudah dibeli."

AI mencari task aktif dan menemukan:

> 9Club Domain Setup

AI menyimpulkan bahwa pesan merupakan update terhadap task tersebut.

Status berubah menjadi:

> In Progress

## **Day 5**

User:

> "Semua domain sudah dikonfigurasi."

AI kembali menemukan task yang sama.

Status:

> Completed

---

# **12\. Task Matching Logic**

AI tidak boleh hanya mencocokkan berdasarkan keyword.

Matching dapat mempertimbangkan:

1. Project  
2. Existing task title  
3. Task description  
4. Previous activities  
5. People involved  
6. Domain/entity  
7. Technical object  
8. Date relationship  
9. Current task status  
10. Semantic similarity

Contoh:

Existing Task:

> 9Club Domain Acquisition

Previous Activity:

> Ryan requested purchase of premium domains.

New Message:

> "8 domain premium sudah dibeli."

AI harus memahami bahwa kedua aktivitas kemungkinan besar berhubungan.

## **Candidate Retrieval (MVP)**

Sebelum AI melakukan matching, backend menyiapkan **candidate list**. AI hanya boleh memilih task dari daftar ini.

Karena MVP bersifat single user, jumlah task aktif relatif kecil sehingga seluruh kandidat dapat dikirim langsung ke dalam prompt (context injection). Vector search tidak diperlukan pada MVP.

Candidate list berisi:

* Semua task berstatus Open, In Progress, Waiting, atau Blocked pada project yang terdeteksi.
* Task berstatus Completed dalam 30 hari terakhir (untuk kemungkinan reopen atau update susulan).
* Untuk setiap task: title, status, people, dan 3 activity terakhir dalam bentuk ringkas.

Jika project tidak dapat dideteksi, candidate list berisi task aktif dari seluruh project dalam format yang lebih ringkas.

Backend menolak `task_id` yang tidak berasal dari candidate list.

Vector search (pgvector) dipertimbangkan ketika candidate list sebuah project melebihi ±150 task, atau untuk kebutuhan fitur Search.

---

# **13\. Confidence-Based Matching**

AI harus menghasilkan confidence.

Recommended interpretation:

* HIGH: confidence \>= 0.90  
* MEDIUM: confidence 0.70–0.89  
* LOW: confidence \< 0.70

## **HIGH**

AI dapat langsung menghubungkan update.

## **MEDIUM**

AI meminta konfirmasi.

Contoh:

> Apakah update ini untuk task "9Club Domain Acquisition"?

Pilihan:

* Yes  
* No  
* Create New Task

## **LOW**

AI membuat task baru atau meminta klarifikasi.

AI tidak boleh secara otomatis menggabungkan task yang belum cukup jelas.

## **Kalibrasi Confidence**

Confidence yang dilaporkan AI **tidak terkalibrasi**. Angka 0.96 tidak berarti interpretasi tersebut benar 96% dari waktu.

Oleh karena itu:

* Threshold 0.90 / 0.70 di atas adalah nilai awal dan akan disetel ulang berdasarkan Evaluation Dataset.
* Keputusan akhir mengombinasikan confidence AI dengan sinyal deterministik dari backend:
  * task berada di project yang sama
  * status task masih aktif
  * kecocokan person/entity dengan activity sebelumnya
  * jarak waktu dari activity terakhir
* Jika confidence AI tinggi tetapi sinyal deterministik bertentangan (misalnya task sudah Cancelled atau berada di project lain), interpretasi diturunkan ke level MEDIUM.
* Data dari tabel `corrections` digunakan untuk mengevaluasi apakah threshold perlu diubah.

---

# **14\. Task Lifecycle**

## **Definisi Status**

| Status | Arti | Masuk report? |
| --- | --- | --- |
| Draft | Task dibuat dari interpretasi confidence rendah dan menunggu konfirmasi user | Tidak |
| Open | Request diterima, belum dikerjakan | Ya |
| In Progress | Sedang dikerjakan | Ya |
| Waiting | Menunggu pihak lain (client, vendor, API, launch, konfirmasi) | Ya |
| Blocked | Tidak dapat dilanjutkan karena kendala teknis/internal | Ya |
| Completed | Pekerjaan selesai | Ya |
| Cancelled | Pekerjaan dibatalkan | Ya (opsional per template) |

Status `Resolved` tidak digunakan untuk task. `Resolved` hanya digunakan sebagai status incident.

Waiting memiliki metadata `waiting_reason`:

* Waiting for Client
* Waiting for Vendor
* Waiting for API
* Waiting for Launch
* Waiting for Confirmation

## **Matriks Transisi**

| Dari | Boleh ke |
| --- | --- |
| Draft | Open, In Progress, Waiting, Blocked, Completed (saat dikonfirmasi) atau dihapus |
| Open | In Progress, Waiting, Blocked, Completed, Cancelled |
| In Progress | Waiting, Blocked, Completed, Cancelled |
| Waiting | In Progress, Blocked, Completed, Cancelled |
| Blocked | In Progress, Waiting, Completed, Cancelled |
| Completed | In Progress (reopen) |
| Cancelled | Open (reopen) |

Aturan:

* Backend menolak transisi di luar matriks.
* Setiap perubahan status dicatat di `task_events`.
* Perubahan status ke Completed atau Cancelled oleh AI selalu ditampilkan secara eksplisit di pesan konfirmasi dan dapat di-undo.

---

# **15\. Activity Types**

Activity dapat dikategorikan menjadi:

* request  
* investigation  
* development  
* configuration  
* bug\_fix  
* testing  
* deployment  
* communication  
* research  
* documentation  
* milestone  
* blocker  
* resolution  
* follow\_up  
* other

---

# **16\. Cross-Month Tracking**

Cross-month tracking merupakan fitur fundamental.

Contoh:

Task:

> Philippines Market Setup

17 August:

> Request received from Mia.

18 August:

> System configuration completed.

20 August:

> Configuration delivered to Mia's team.

20 August – 14 September:

> Waiting for confirmation.

15 September:

> Official public launch.

August report dapat menyatakan:

> Configuration completed and handed over to Mia's team. Status: Waiting for launch.

September report dapat menyatakan:

> Continuation from August. Philippines region officially launched publicly on 15 September. Status: Completed.

Task tetap merupakan satu entity.

Karena task bersifat persistent dan setiap activity memiliki tanggal, **cross-month reporting tidak membutuhkan logic khusus** selain query berdasarkan periode. Fitur ini termasuk dalam MVP (Phase 2).

Yang berada di Phase 3 adalah **Cross-Month Task Reminder**, bukan cross-month reporting.

---

# **17\. Project Management**

User dapat membuat project.

Contoh:

Project:

> 9Club

Language:

> English

Report Template:

> 9Club Monthly Technical Activity Report

Reporting Day:

> Last day of month

Reminder Time:

> 09:00

Project lain:

More Spin

Language:

> Indonesian

Report Template:

> More Spin Monthly Project Report

---

# **18\. Telegram Bot**

Telegram menjadi primary interface untuk MVP.

Commands:

* `/start`
* `/help`
* `/projects`
* `/project`
* `/update`
* `/tasks`
* `/task`
* `/undo` — membatalkan perubahan dari pesan terakhir
* `/inbox` — melihat pesan yang gagal diproses atau menunggu klarifikasi
* `/report`
* `/reports`
* `/review`
* `/generate`
* `/settings`
* `/reminder`

Namun command bukan satu-satunya cara interaksi.

Natural language tetap menjadi primary interface.

---

# **19\. Bot Persona: Pak Carik**

## **Identitas**

* **Nama:** Pak Carik
* **Username:** `@PakCarikk_bot`
* **Peran:** juru catat pekerjaan user. Mencatat, mengarsip, menghubungkan pekerjaan lintas hari dan bulan, mengingatkan, dan menyusun laporan.

Carik adalah sekretaris desa dalam tradisi Jawa, orang yang mengurus catatan dan laporan. Nama ini dipilih karena menggambarkan fungsi produk tanpa menonjolkan kata "AI".

## **Karakter**

* Santun dan telaten, tetapi sedikit nyleneh dan tidak kaku.
* Teliti soal arsip, selalu ingat pekerjaan sebelumnya.
* Mengingatkan dengan sopan, tidak memarahi atau menyindir.
* Ikut senang saat pekerjaan selesai.

## **Gaya Bahasa**

* Bahasa Indonesia santai dengan sedikit sisipan bahasa Jawa yang mudah dipahami: *nggih*, *nuwun sewu*, *rampung*, *monggo*.
* Sisipan bahasa Jawa maksimal satu atau dua kata per pesan agar tetap dipahami pengguna dari daerah lain.
* Jika user menulis dalam bahasa Inggris, Pak Carik membalas dalam bahasa Inggris dengan nada santai dan ramah, tanpa sisipan bahasa Jawa.
* Pesan singkat. Informasi penting (project, task, status) tetap ditulis jelas dan terstruktur.

## **Contoh Pesan**

| Situasi | Pesan |
| --- | --- |
| Onboarding | "Sugeng rawuh! Saya Pak Carik, juru catat pekerjaan Njenengan. Kita mulai dari project pertama, nggih. Namanya apa?" |
| Konfirmasi catatan | "Nggih, sudah saya catat. 9Club → Premium Domain Acquisition, status: sedang dikerjakan." |
| Task selesai | "Rampung! Task domain saya tutup, nggih." |
| Task ambigu | "Sebentar, saya cek arsip dulu. Yang domain itu yang pembelian atau konfigurasi?" |
| Reminder harian | "Nuwun sewu, hari ini belum ada catatan. Tadi ngerjain apa saja?" |
| Reminder akhir bulan | "Sudah akhir bulan. Arsip bulan ini ada 27 catatan, 8 rampung, 2 masih menunggu. Monggo, mau saya susunkan laporannya?" |
| Entri masih diproses | "Masih ada 4 catatan yang sedang saya tulis. Tunggu sebentar atau laporan dibuat tanpa catatan itu?" |
| Undo | "Siap, catatan tadi saya batalkan. Arsip kembali seperti semula." |
| Credential terdeteksi | "Nuwun sewu, pesan ini ada password/kunci rahasianya. Bagian itu tidak saya simpan, nggih." |
| Error | "Waduh, catatan ini belum bisa saya proses. Tenang, pesannya sudah saya simpan, nanti saya coba lagi." |
| Laporan siap | "Laporan September 2026 sudah jadi. Monggo dicek dulu sebelum dikirim." |

## **Batasan**

* Persona **hanya** digunakan di Telegram dan di teks antarmuka dashboard.
* **Laporan (PDF dan Markdown) selalu formal dan netral**, tanpa nama atau gaya bicara Pak Carik, karena laporan dikirim ke klien.
* Humor tidak boleh mengaburkan informasi. Status, angka, tanggal, dan pilihan tombol harus tetap jelas.
* Pesan error dan peringatan keamanan tetap harus lugas, walaupun disampaikan dengan gaya persona.

## **Implementasi**

* Pesan sistem (konfirmasi, reminder, error, onboarding) menggunakan **template tetap** di file bahasa Laravel (`lang/id`, `lang/en`) dengan variabel, bukan dibuat bebas oleh AI. Tujuannya: konsisten, dapat diuji, dan tidak menambah biaya token.
* Setiap jenis pesan dapat memiliki 2–3 variasi kalimat yang dipilih acak agar tidak monoton.
* AI hanya digunakan untuk konten yang memang membutuhkan interpretasi, misalnya ringkasan task atau jawaban pertanyaan user. Untuk konten tersebut, system prompt berisi panduan persona singkat.

---

# **20\. Bot Identity & BotFather Setup**

## **Profil Bot**

**Description** (`/setdescription`):

```
Sugeng rawuh! Saya Pak Carik, juru catat pekerjaan Anda.

Cukup ceritakan apa yang Anda kerjakan, saya catat, saya hubungkan dengan pekerjaan sebelumnya, dan saya ingatkan kalau ada yang terlewat. Tiap akhir bulan, laporan Anda saya susunkan dengan rapi.
```

**About** (`/setabouttext`):

```
Juru catat pekerjaan Anda. Cerita kerjaan, Pak Carik catat, laporan beres tiap akhir bulan.
```

**Commands** (`/setcommands`):

```
start - Mulai dan buat project pertama
help - Panduan singkat
projects - Daftar project
project - Detail atau pilih project
tasks - Daftar task
task - Detail satu task
undo - Batalkan catatan terakhir
inbox - Catatan yang perlu dicek
report - Siapkan laporan
reports - Arsip laporan
review - Tinjau catatan sebelum laporan
generate - Buat laporan sekarang
settings - Pengaturan
reminder - Atur pengingat
```

Daftar command di BotFather harus selalu sinkron dengan daftar command di section Telegram Bot. Command juga dapat didaftarkan otomatis saat deploy melalui Bot API `setMyCommands`.

## **Avatar**

Tersedia dua varian ilustrasi (SVG + PNG 512×512):

| Varian | Deskripsi | Penggunaan |
| --- | --- | --- |
| Nyleneh (utama) | Kacamata melorot, kumis melingkar, sticky note di blangkon, tumpukan kertas miring, dan segelas kopi; latar kuning bersinar | Foto profil Telegram, maskot dashboard |
| Formal | Blangkon, kacamata bulat, batik parang, buku arsip; latar hijau motif kawung | Halaman login, favicon, materi yang lebih resmi |

Aset avatar disimpan di repository pada `resources/brand/`.

## **Username**

Username saat ini adalah `@PakCarikk_bot`. Setelah bot berjalan penuh, username `@PakCarikBot` dapat diajukan melalui Telegram Bot Support.

---

# **21\. Correction & Undo UX**

AI akan melakukan kesalahan interpretasi. Tanpa mekanisme koreksi yang mudah, satu kesalahan matching dapat mencemari laporan bulanan tanpa disadari. Karena itu correction UX adalah **fitur MVP**.

## **Tombol Koreksi**

Setiap pesan konfirmasi memiliki inline button:

* ↩️ **Undo** — membatalkan seluruh perubahan dari pesan tersebut
* 🔀 **Pindah Task** — memilih task lain dari daftar atau membuat task baru
* ✏️ **Ubah Status** — memilih status sesuai matriks transisi
* 📁 **Ganti Project**

Contoh:

> Recorded.
>
> Project: 9Club
> Task: Premium Domain Acquisition
> Status: In Progress → Completed
>
> [↩️ Undo] [🔀 Pindah Task] [✏️ Ubah Status] [📁 Ganti Project]

## **Koreksi dengan Bahasa Natural**

User dapat membalas (reply) pesan konfirmasi:

> "Bukan, itu untuk task Domain Configuration."

Reply-to-message memberikan konteks pesan yang dikoreksi sehingga AI tidak perlu menebak.

## **Aturan**

* Setiap write yang berasal dari AI harus dapat di-undo.
* Undo membuat `task_events` baru, bukan menghapus riwayat.
* Setiap koreksi dicatat di tabel `corrections` dan digunakan untuk:
  * menghitung User Correction Rate
  * menambah kasus baru ke Evaluation Dataset
  * mengevaluasi threshold confidence

---

# **22\. Web Dashboard**

Web Dashboard adalah channel kedua untuk MVP, digunakan saat user bekerja di laptop.

## **Stack**

* Laravel Filament (Livewire) untuk halaman CRUD (project, task, activity).
* Halaman custom untuk input worklog, inbox, dan report.
* Tidak membutuhkan frontend developer terpisah.

## **Autentikasi**

* Login menggunakan **Telegram Login Widget**, sehingga identitas dashboard sama dengan bot (`telegram_user_id`).
* Hanya user yang ada di whitelist yang dapat login.

## **Halaman MVP**

**1. Input Worklog**

* Kotak input bahasa natural, sama seperti mengetik di Telegram.
* Dapat berisi banyak item sekaligus (multi-item).
* Hasil interpretasi AI ditampilkan per item dengan tombol koreksi yang sama seperti di Telegram.

**2. Tasks**

* Daftar task per project dengan filter status, project, dan periode.
* Detail task: timeline activity dan riwayat `task_events`.
* Edit task, ubah status (mengikuti matriks transisi), pindah activity ke task lain.

**3. Inbox**

* Pesan yang gagal diproses atau menunggu klarifikasi, dari Telegram maupun dashboard.
* Klarifikasi dapat dijawab dari sini.

**4. Reports**

* Generate report (pilih project, periode, bahasa).
* Pre-generate check (entri yang masih diproses, task waiting, detail yang belum lengkap).
* Preview HTML yang identik dengan hasil PDF.
* Editor Markdown per section.
* Riwayat versi.
* Approve dan export PDF / Markdown.

**5. Settings**

* Project, bahasa, reminder, dan hari kerja.

## **Di Luar MVP (Phase 4)**

* Analytics
* Template editor visual
* File attachments
* Advanced activity timeline

---

# **23\. Multi-Channel Consistency**

Prinsip:

> **Dua pintu masuk, satu jalur proses, satu sumber data.**

## **Satu Jalur Proses**

* Semua input, dari Telegram maupun dashboard, masuk ke `inbound_messages` dengan kolom `source` (`telegram` / `dashboard`).
* Keduanya diproses oleh service, queue, AI pipeline, dan validasi backend yang sama (`WorklogService`).
* Tidak ada logic penulisan data yang khusus untuk salah satu channel.
* Task yang dibuat dari dashboard langsung menjadi kandidat matching untuk pesan Telegram berikutnya, dan sebaliknya.

## **Snapshot Saat Generate**

Saat report di-generate, system mencatat:

* `data_snapshot_at` — waktu cutoff data
* `source_activity_ids` — daftar activity yang dipakai

Report selalu dibuat dari snapshot tersebut, bukan dari data yang terus berubah selama proses generate.

## **Pre-Generate Check: Entri yang Masih Diproses**

Sebelum generate, system memeriksa `inbound_messages` milik user dengan status `received` atau `processing`.

Jika ada, user diberi pilihan:

> 4 entri masih diproses.
>
> [Tunggu Selesai] [Generate Tanpa Entri Ini]

"Tunggu Selesai" otomatis melanjutkan generate setelah semua entri selesai diproses.

## **Entri Setelah Draft Dibuat**

Jika ada activity baru, edit, atau koreksi pada periode report setelah `data_snapshot_at`:

* Draft (belum approved) diberi banner: "4 activity baru sejak draft dibuat — [Perbarui Draft]".
* Report yang sudah approved ditandai `outdated` (lihat Late Entries).

## **Contoh Skenario**

1. Pagi hari, user mencatat 2 task melalui Telegram. Keduanya tersimpan.
2. Sore hari, user membuka dashboard dan memasukkan 4 task baru, lalu langsung menekan Generate.
3. 4 task tersebut masih diproses oleh AI. Dashboard menampilkan "4 entri masih diproses".
4. User memilih "Tunggu Selesai". Setelah keempatnya tersimpan, report dibuat dari 6 task tersebut.
5. Jika user memilih "Generate Tanpa Entri Ini", draft dibuat dari 2 task, lalu banner "Perbarui Draft" muncul setelah 4 task lainnya tersimpan.

Tidak ada data yang hilang tanpa diketahui, dan tidak ada report setengah jadi.

## **Sinkronisasi Dua Arah**

* Entri dari Telegram muncul di dashboard tanpa refresh halaman (Livewire polling, interval ±5 detik).
* Klarifikasi yang tertunda ditampilkan di Telegram dan di inbox dashboard. Jika dijawab dari salah satu channel, channel lain ikut diperbarui. Contoh: pesan Telegram diedit menjadi "✅ Sudah dijawab via dashboard".
* Undo dan koreksi dari satu channel langsung terlihat di channel lain.

## **Mencegah Konflik dan Duplikasi**

* **Optimistic locking**: tabel `tasks` dan `report_versions` memiliki kolom `version`. Jika data sudah diubah dari channel lain, penyimpanan ditolak dan user diminta memuat ulang.
* **Idempotency key**: setiap submit dari dashboard membawa key unik sehingga klik ganda atau retry jaringan tidak membuat entri dobel. Untuk Telegram, `telegram_message_id` berfungsi sebagai key.
* **Satu proses generate per report**: jika report yang sama sedang di-generate, permintaan kedua ditolak dengan pesan "Report sedang dibuat".

---

# **24\. Reminder & Notification System**

Reminder System merupakan **core feature** ReportFlow AI.

Tujuannya:

1. Mengingatkan user mencatat pekerjaan.  
2. Mengingatkan user melakukan review.  
3. Mengingatkan task yang belum ditindaklanjuti.  
4. Mengingatkan task yang masih waiting.  
5. Mengingatkan task lintas bulan.  
6. Mengingatkan pembuatan laporan.  
7. Membantu memastikan laporan tidak kehilangan informasi penting.

Reminder dikirim melalui Telegram.

---

# **25\. Reminder Types**

## **19.1 Daily Worklog Reminder**

Digunakan untuk mengingatkan user agar mencatat aktivitas pekerjaan.

Contoh:

> Daily Worklog Reminder

> You haven't recorded any worklog today.

> Did you work on anything today?

Pilihan:

* Add Worklog  
* Nothing Today  
* Remind Me Later

User dapat mengatur misalnya:

> Monday–Friday, 18:00

Aturan pengiriman:

* Hanya dikirim pada hari kerja yang dikonfigurasi.
* Tidak dikirim jika sudah ada activity yang tercatat pada hari tersebut.
* Tidak dikirim ulang pada hari yang sama jika user memilih "Nothing Today".

---

## **19.2 Weekly Review Reminder**

Digunakan untuk melakukan review aktivitas mingguan.

Contoh:

> Weekly Worklog Review

> This week you recorded:

> 12 activities  
> 5 completed tasks  
> 3 ongoing tasks  
> 2 waiting tasks

> Would you like to review them?

Pilihan:

* Review  
* Later

---

## **19.3 Monthly Report Reminder**

Reminder utama untuk laporan bulanan.

Contoh:

> Monthly Report Reminder

> September is almost over.

> You have:

> 27 activities  
> 8 completed tasks  
> 3 ongoing tasks  
> 2 waiting tasks  
> 2 cross-month tasks

> Would you like to prepare your report?

Pilihan:

* Generate Report  
* Review Activities  
* Later

---

## **19.4 Pre-Report Reminder**

Sebelum report dibuat, system melakukan pengecekan terhadap data.

Contoh:

> Before generating your September report:

> ⚠ 2 tasks are still waiting  
> ⚠ 2 tasks started in August  
> ⚠ 3 activities have incomplete information

> Would you like to review them?

Pilihan:

* Review  
* Generate Anyway

---

## **19.5 Cross-Month Task Reminder**

System dapat mendeteksi task yang dibawa dari bulan sebelumnya.

Contoh:

> Cross-Month Task Review

> The following task started in August and is still active:

> Philippines Market Setup

> Last activity: 20 August  
> Status: Waiting

> Do you have an update?

Pilihan:

* Update Task  
* Still Waiting  
* Completed  
* Dismiss

---

## **19.6 Stale Task Reminder**

System dapat mendeteksi task yang tidak mendapatkan update dalam periode tertentu.

Contoh:

> Task Follow-up Reminder

> These tasks have not been updated recently:

> 1. Domain Acquisition — Last update: 10 days ago  
> 2. API Integration — Last update: 14 days ago

> Would you like to review them?

Threshold dapat dikonfigurasi:

* 7 days  
* 14 days  
* 30 days

---

## **19.7 Waiting Task Reminder**

Task dengan status Waiting dapat menghasilkan reminder follow-up.

Contoh:

> Waiting Task Reminder

> Philippines Market Setup

> Status: Waiting for confirmation

> Last update: 20 August

> Do you have an update?

Pilihan:

* Update  
* Still Waiting  
* Completed

---

## **19.8 Incomplete Worklog Reminder**

AI dapat mendeteksi aktivitas yang informasinya belum lengkap.

Contoh user:

> "Fix bug di 9Club."

System dapat mendeteksi bahwa detail resolution belum diketahui.

Bot:

> Would you like to add more details to this worklog?

Pilihan:

* Add Details  
* Keep As Is

---

# **26\. Reminder Snooze**

User tidak harus langsung mengerjakan reminder.

Setiap reminder dapat mempunyai pilihan:

* Snooze 1 Hour  
* Snooze Tomorrow  
* Snooze Next Week  
* Dismiss

Contoh:

> I'll remind you again tomorrow at 09:00.

---

# **27\. Reminder Priority**

Reminder dapat memiliki priority:

* Low  
* Normal  
* High  
* Critical

Contoh:

Low:

> Weekly Worklog Review

Normal:

> Monthly Report Reminder

High:

> Important task has been waiting for 14 days.

Priority digunakan untuk menentukan bagaimana notification diperlakukan.

---

# **28\. Reminder Rules**

Reminder tidak boleh dikirim tanpa batas.

System harus memiliki protection terhadap notification spam.

Contoh:

Maximum reminder:

> 3 reminders/day

Maximum reminder untuk task yang sama:

> 1 reminder/day

Jika user memberikan update pada task:

> User → Update Task

Reminder terkait task tersebut dapat ditunda atau dihentikan sampai threshold berikutnya.

---

# **29\. Reminder State**

Setiap reminder memiliki state:

* Scheduled  
* Sent  
* Acknowledged  
* Snoozed  
* Completed  
* Dismissed  
* Cancelled

Contoh lifecycle:

Monthly Report Reminder  
        ↓  
     Scheduled  
        ↓  
       Sent  
        ↓  
   User selects Later  
        ↓  
     Snoozed  
        ↓  
    Sent Again  
        ↓  
   Generate Report  
        ↓  
     Completed

---

# **30\. Reminder Event Engine**

Reminder dapat dibuat berdasarkan event.

Contoh:

Task Created  
     ↓  
Task becomes Waiting  
     ↓  
No update for 7 days  
     ↓  
Reminder generated

Atau:

Month Ending  
     ↓  
Report period detected  
     ↓  
Collect activities  
     ↓  
Check incomplete tasks  
     ↓  
Send report reminder

---

# **31\. Intelligent Reminder**

AI dapat membantu menentukan apakah reminder relevan.

Contoh:

Task:

> API Integration

Status:

> Waiting

Last activity:

> 15 days ago

System dapat mengirim:

> You may want to follow up on API Integration. This task has been waiting for 15 days.

Namun AI tidak boleh bebas membuat reminder tanpa business rule.

AI hanya memberikan interpretation/proposal.

Backend tetap menentukan apakah reminder dibuat berdasarkan rules yang telah dikonfigurasi.

---

# **32\. Reminder Configuration**

User dapat mengatur reminder secara global maupun per project.

Contoh global settings:

Daily Worklog:

> 18:00

Weekly Review:

> Friday 17:00

Monthly Report:

> Last day, 09:00

Stale Task:

> After 7 days

Project-specific settings juga dapat digunakan.

Contoh:

9Club:

Daily Worklog:

> 18:30

Monthly Report:

> Last day, 09:00

---

# **33\. Reminder Commands**

Reminder dapat dikontrol melalui Telegram:

* `/reminder`  
* `/reminder on`  
* `/reminder off`  
* `/reminder status`  
* `/reminder daily`  
* `/reminder monthly`

Contoh:

`/reminder status`

Bot:

> Reminder Status

> Daily Worklog: ON — 18:00  
> Weekly Review: ON — Friday 17:00  
> Monthly Report: ON — Last day, 09:00  
> Stale Task: ON — after 7 days

---

# **34\. Recommended MVP Reminder Scope**

## **MUST HAVE (MVP — Phase 1 & 2)**

* Daily Worklog Reminder (kondisional)
* Monthly Report Reminder (dengan ringkasan kontekstual)
* Snooze (1 hour / tomorrow)
* Enable / Disable
* Global limit: maksimum 3 reminder per hari
* Telegram Notification

## **SHOULD HAVE (Phase 3)**

* Weekly Review
* Waiting Task Reminder
* Cross-Month Task Reminder
* Stale Task Reminder
* Pre-Report Review
* Reminder Priority
* Per-project reminder settings

## **LATER**

* AI-generated reminder timing
* Calendar integration
* Email notification
* Mobile push notification
* Smart notification batching

Alasan pemangkasan: nilai reminder lanjutan baru bisa dinilai setelah user memakai sistem minimal satu siklus laporan bulanan penuh. Dua reminder MVP sudah menutup dua masalah utama: lupa mencatat dan lupa membuat laporan.

---

# **35\. Reminder Design Principle**

Reminder harus:

* Helpful  
* Context-aware  
* Configurable  
* Non-intrusive  
* Actionable

Tujuan reminder bukan membanjiri user dengan notification.

Tujuannya adalah:

> **Membantu user mengingat pekerjaan yang kemungkinan akan terlupakan.**

---

# **36\. Reporting Period**

ReportFlow mendukung:

* Monthly  
* Weekly  
* Custom Period  
* Incident Report

Contoh:

September 2026:

> 1 September – 30 September

Custom:

> 15 September – 30 September

---

# **37\. Monthly Report Generation**

Workflow:

Generate  
   ↓  
Draft  
   ↓  
Review  
   ↓  
Edit  
   ↓  
Approve  
   ↓  
Export

---

# **38\. Report Structure**

Default generic report:

1. Monthly Overview  
2. Completed Tasks  
3. Detailed Activities  
4. Ongoing / Pending Tasks  
5. Cross-Month Activities  
6. Incidents / Issues  
7. Monthly Summary

Template dapat disesuaikan per project.

---

# **39\. 9Club Report Template**

Contoh:

> Monthly Technical Activity Report – 9Club

September 2026

1. Monthly Overview  
2. Completed Tasks  
3. Detailed Activities  
4. Ongoing / Pending Tasks  
5. Cross-Month Activities  
6. Incident / Bug Report  
7. Monthly Summary

---

# **40\. Report Template System**

Report template tidak boleh hard-coded.

Template mempunyai:

* Name  
* Language  
* Title  
* Sections  
* Ordering  
* Formatting Rules  
* Output Rules

Contoh:

> 9Club Monthly Technical Activity Report

Template lain:

> More Spin Monthly Project Report

> Generic Technical Report

> Technical Incident Report

## **Implementasi Template**

Template berupa **Blade (HTML) + CSS**, sehingga:

* Preview di dashboard dan hasil PDF identik.
* Desain laporan (header, tabel, warna, logo) mudah dikontrol.
* Page break, nomor halaman, dan header/footer diatur dengan CSS print (`@page`).

---

# **41\. Incident Report**

User dapat membuat incident report melalui Telegram.

Contoh:

> "More Spin jam 14:20 user tidak bisa spin. API timeout. Restart service dan normal kembali jam 14:45."

AI menghasilkan:

Project:

> More Spin

Incident:

> Spin API Timeout

Start:

> 14:20

Resolved:

> 14:45

Impact:

> Users unable to spin

Action:

> Restarted service

Status:

> Resolved

Report dapat dikembangkan menjadi:

1. Incident Overview  
2. Timeline  
3. Impact  
4. Root Cause  
5. Resolution  
6. Preventive Action

**Fase:** Phase 3.

Pada MVP, incident dicatat sebagai task biasa dengan activity type `blocker` dan `resolution`, sehingga tetap muncul di laporan bulanan pada bagian Incidents / Issues.

---

# **42\. Report Review**

Sebelum report final, system membuat:

> Draft Report

Review dapat dilakukan dari dua channel.

## **Dari Dashboard (utama)**

* Preview HTML yang identik dengan PDF.
* **Editor Markdown per section** — user mengedit teks secara langsung.
* **Edit via instruksi** — contoh: "Tambahkan detail downtime di bagian incident." AI hanya membuat ulang section terkait.
* Approve, Regenerate, Cancel.
* Riwayat dan perbandingan versi.

## **Dari Telegram**

* Bot mengirim ringkasan draft dan PDF preview.
* Pilihan: Approve, Regenerate, Edit via instruksi, Buka di Dashboard, Cancel.

Setiap edit menghasilkan versi baru di `report_versions`.

## **Aturan Fakta Baru**

* **Edit via instruksi**: jika instruksi berisi fakta baru, contohnya "Tambahkan bahwa downtime berlangsung 25 menit", fakta tersebut **terlebih dahulu disimpan sebagai activity baru** (`source = report_edit`), baru kemudian section dibuat ulang.
* **Edit manual di editor**: teks disimpan apa adanya dan versi ditandai `created_by = user_edit`. Jika perubahan tampak menambah fakta baru, dashboard menawarkan: "Simpan juga sebagai activity?" agar laporan berikutnya tidak kehilangan informasi tersebut.

---

# **43\. Report Versioning**

Report yang sudah dibuat harus mempunyai version.

Contoh:

> September 2026 Report — Version 1  
> September 2026 Report — Version 2  
> September 2026 Report — Version 3

Tujuan:

* Menyimpan history  
* Menghindari kehilangan draft  
* Membandingkan perubahan

Versi yang sudah di-approve bersifat **immutable**.

## **Late Entries**

Jika setelah report di-approve terjadi perubahan data pada periode tersebut, misalnya:

* activity baru dengan `activity_date` di dalam periode
* activity diedit atau dihapus
* status task berubah akibat koreksi

maka report ditandai **outdated** dan bot mengirim notifikasi:

> Laporan September 2026 sudah di-approve, tetapi ada 1 activity baru untuk periode tersebut. Buat versi baru?

Pilihan:

* Buat Versi Baru
* Abaikan

Pesan dengan tanggal lampau (misalnya "kemarin lusa saya…") tetap diterima. Jika tanggal lebih dari 30 hari yang lalu, bot meminta konfirmasi tanggal.

---

# **44\. Report Generation Logic**

System mengumpulkan:

* Activities  
* Tasks  
* Milestones  
* Incidents  
* Task Status  
* Cross-month Context

Kemudian AI menyusun report berdasarkan template project.

AI tidak boleh mengarang aktivitas.

Semua statement dalam report harus berasal dari data yang tersimpan atau merupakan synthesis dari data tersebut.

## **Traceability**

* Report dibuat **per section**, bukan dalam satu prompt besar, untuk mengurangi risiko halusinasi.
* Setiap `report_versions` menyimpan daftar `source_activity_ids` yang digunakan.
* Backend memeriksa bahwa setiap task yang disebut di report berasal dari data periode tersebut.

Report selalu dibuat dari snapshot data pada `data_snapshot_at`. Lihat Multi-Channel Consistency.

---

# **45\. Monthly Report Selection Logic**

Untuk periode:

> 1 September – 30 September

System mengambil activity yang tanggalnya berada dalam reporting period.

Selain itu, system juga mencari task yang:

* dimulai sebelum reporting period  
* mempunyai activity dalam reporting period

Task tersebut ditampilkan sebagai cross-month activity.

---

# **46\. Example Monthly Report Data**

Project:

> 9Club

Period:

> September 2026

Completed:

> 8 tasks

Ongoing:

> 3 tasks

Waiting:

> 2 tasks

Activities:

> 27

Cross-month:

> 2 tasks

Incidents:

> 1

AI kemudian menyusun report berdasarkan template project.

---

# **47\. Task History / Audit Trail**

Setiap perubahan penting harus dapat dilacak.

Contoh:

Task \#123

17 August:

> Created

18 August:

> Status → In Progress

20 August:

> Status → Waiting

15 September:

> Status → Completed

Tujuan:

* Debugging  
* Audit  
* User confidence  
* Report generation  
* Correction

---

# **48\. Raw Input Preservation**

Setiap input, baik pesan Telegram maupun input dari dashboard, **disimpan terlebih dahulu** ke tabel `inbound_messages` sebelum diproses oleh AI.

Status pemrosesan:

* received
* processing
* processed
* needs\_clarification
* failed

Raw message dan AI structured interpretation disimpan secara terpisah.

Contoh:

Raw:

> "domain sudah selesai dibeli"

AI:

> activity\_type \= milestone
> status \= in\_progress

Aturan:

* Pesan yang gagal diproses dapat diproses ulang melalui `/inbox`.
* Jika user mengedit pesan di Telegram, perubahan disimpan dan bot menawarkan pemrosesan ulang.
* Bagian pesan yang terdeteksi sebagai secret/credential tidak disimpan (lihat Security — Redaction Layer).

Jika AI salah interpretasi, user tetap dapat memperbaikinya.

---

# **49\. Database Design**

Catatan: kolom `id`, `created_at`, dan `updated_at` ada di semua tabel kecuali disebutkan lain.

## **users**

* telegram\_user\_id
* name
* timezone (default: Asia/Jakarta)
* default\_language
* workdays (jsonb, contoh: Mon–Fri)
* reminders\_enabled

## **projects**

* user\_id
* name
* slug
* aliases (jsonb — nama lain untuk project detection, contoh: "9C", "nineclub")
* description
* default\_language
* report\_template\_id
* status (active / archived)

Jadwal laporan dan reminder dipindahkan ke `reminder_rules`.

## **people**

* user\_id
* name
* aliases (jsonb)
* notes

## **tasks**

* project\_id
* title
* description
* type
* status
* waiting\_reason
* priority
* started\_at
* completed\_at
* last\_activity\_at
* version (optimistic locking)
* deleted\_at

## **task\_people**

* task\_id
* person\_id
* role (requester / assignee / stakeholder)

## **inbound\_messages**

* user\_id
* source (telegram / dashboard)
* idempotency\_key (unique)
* telegram\_chat\_id (nullable)
* telegram\_message\_id (nullable)
* text (setelah redaction)
* attachments (jsonb)
* received\_at
* edited\_at
* status
* error
* reprocess\_count

## **activities**

* task\_id
* project\_id
* inbound\_message\_id
* activity\_type
* summary
* content\_structured (jsonb)
* activity\_date
* date\_precision (day / week / month)
* source (telegram / report\_edit / manual)
* deleted\_at

Milestone disimpan sebagai activity dengan `activity_type = milestone`. Tabel `milestones` terpisah tidak diperlukan.

## **task\_events**

Audit trail untuk task.

* task\_id
* event\_type (created / status\_changed / title\_changed / moved / merged / reopened / undone)
* from\_value
* to\_value
* actor (ai / user / system)
* inbound\_message\_id
* created\_at

## **corrections**

* user\_id
* inbound\_message\_id
* correction\_type (undo / move\_task / change\_status / change\_project / edit\_date)
* before (jsonb)
* after (jsonb)
* created\_at

## **incidents** (Phase 3)

* project\_id
* task\_id
* title
* started\_at
* resolved\_at
* impact
* root\_cause
* resolution
* preventive\_action
* status (open / resolved)

## **report\_templates**

* user\_id
* project\_id (nullable — null berarti template global)
* name
* language
* title\_format
* sections (jsonb — urutan, judul, dan aturan tiap section)
* blade\_view (path template HTML)
* stylesheet (CSS)
* formatting\_rules (jsonb)

## **reports**

* project\_id
* type (monthly / weekly / custom / incident)
* period\_start
* period\_end
* language
* template\_id
* status (draft / generating / in\_review / approved / outdated / cancelled)
* current\_version\_id
* generation\_lock\_until
* approved\_at

## **report\_versions**

* report\_id
* version\_no
* content (jsonb — isi Markdown per section)
* data\_snapshot\_at
* source\_activity\_ids (jsonb)
* created\_by (ai\_generate / instruction\_edit / user\_edit)
* source\_channel (telegram / dashboard)
* instruction
* version (optimistic locking)
* created\_at

## **report\_files**

* report\_version\_id
* format (pdf / md / docx)
* file\_path
* checksum
* created\_at

## **reminder\_rules**

Konfigurasi reminder.

* user\_id
* project\_id (nullable)
* type
* schedule (jsonb)
* config (jsonb — contoh: threshold hari untuk stale task)
* priority
* enabled

## **reminder\_instances**

Setiap reminder yang dijadwalkan atau dikirim.

* reminder\_rule\_id
* task\_id (nullable)
* next\_run\_at
* status (scheduled / sent / acknowledged / snoozed / completed / dismissed / cancelled)
* sent\_at
* snoozed\_until
* telegram\_message\_id
* action\_taken

## **ai\_interactions**

* user\_id
* project\_id
* inbound\_message\_id
* report\_id
* purpose
* model
* prompt\_version
* input
* output
* tokens\_input
* tokens\_output
* latency\_ms
* success
* error
* created\_at

## **dashboard\_sessions**

Menggunakan session bawaan Laravel. Login melalui Telegram Login Widget dipetakan ke `users.telegram_user_id`.

## **Index yang Direkomendasikan**

* `activities (project_id, activity_date)`
* `tasks (project_id, status)`
* GIN index pada `activities.content_structured`
* `pg_trgm` untuk keyword search
* `inbound_messages (user_id, status)` untuk pre-generate check
* unique `inbound_messages (idempotency_key)`

---

# **50\. AI Architecture**

Gunakan abstraction layer:

AIService  
    │  
    └── DeepSeekProvider

Functions:

* extractWorklog()  
* matchTask()  
* classifyActivity()  
* detectStatus()  
* generateReport()  
* summarizeTask()

Tujuannya agar provider AI dapat diganti di masa depan tanpa mengubah business logic.

---

# **51\. AI Processing Pipeline**

Telegram Message  
        ↓  
  Intent Detection  
        ↓  
  Project Detection  
        ↓  
   Date Extraction  
        ↓  
  Entity Extraction  
        ↓  
Existing Task Search  
        ↓  
   Task Matching  
        ↓  
Confidence Evaluation  
        ↓  
Activity Classification  
        ↓  
   Status Detection  
        ↓  
  Structured Output  
        ↓  
Backend Validation  
        ↓  
     Database

Tahapan di atas adalah **tahapan logis**. Pada implementasi MVP, tahapan dari Intent Detection sampai Structured Output dijalankan dalam **satu panggilan AI**, sedangkan Existing Task Search dilakukan oleh backend sebelum panggilan AI (candidate retrieval). Lihat Core Workflow dan Task Matching Logic.

---

# **52\. AI Model**

Primary AI:

> DeepSeek V4.1-Flash

API model ID:

> `deepseek-flash`

Use cases:

* Worklog extraction  
* Task matching  
* Classification  
* Summarization  
* Report generation  
* Natural language query  
* Incident analysis

AI provider harus berada di belakang abstraction layer.

---

# **53\. AI Reasoning Strategy**

Tidak semua request membutuhkan reasoning yang sama.

Simple task:

> "Fix login bug."

Complex task matching:

> "Yang kemarin soal domain itu sudah selesai."

Untuk complex task matching, AI perlu mempertimbangkan:

* Current project  
* Active tasks  
* Recent activities  
* Historical activities  
* Entities  
* People  
* Time  
* Task status

---

# **54\. AI Structured Output**

AI harus menggunakan structured JSON output (JSON mode) dan divalidasi terhadap JSON schema.

Satu pesan dapat menghasilkan beberapa item.

Contoh struktur:

```json
{
  "items": [
    {
      "intent": "update_existing_task",
      "project_id": 3,
      "task_ref": { "type": "existing", "task_id": 123 },
      "confidence": 0.93,
      "matching_signals": ["same_project", "entity:domain", "person:Xing"],
      "activity": {
        "type": "milestone",
        "summary": "All premium domains configured",
        "date": "2026-09-15",
        "date_precision": "day"
      },
      "status_change": { "from": "in_progress", "to": "completed" },
      "people": ["Xing"],
      "missing_details": []
    }
  ],
  "clarification_needed": null
}
```

Untuk task baru:

```json
"task_ref": { "type": "new", "title": "Premium Domain Acquisition" }
```

Backend kemudian melakukan:

1. Validasi JSON schema (jika gagal: retry satu kali, lalu tandai `failed`)
2. Validasi `task_id` berasal dari candidate list
3. Validasi user ownership
4. Validasi project
5. Validasi status transition sesuai matriks
6. Validasi tanggal (tidak boleh di masa depan; lebih dari 30 hari lalu membutuhkan konfirmasi)
7. Kombinasi confidence dengan sinyal deterministik
8. Write to database dalam satu transaction

Setiap perubahan prompt memiliki `prompt_version` yang dicatat di `ai_interactions`.

---

# **55\. Backend Responsibility**

Backend bertanggung jawab terhadap:

* Authentication  
* Authorization  
* Data validation  
* Task ownership  
* Status transition  
* Database writes  
* Queue  
* Scheduler  
* Report generation  
* File storage  
* Audit trail  
* Reminder rules  
* Notification state

AI tidak boleh langsung mengubah database.

---

# **56\. Security**

Minimum security requirements:

## **Telegram Authentication**

Whitelist berdasarkan:

> telegram\_user\_id

Hanya user yang terdaftar yang dapat menggunakan bot.

## **Telegram Bot Token & Webhook**

* Bot token hanya disimpan di `.env` (`TELEGRAM_BOT_TOKEN`) atau secret manager, tidak pernah di source code, repository, chat, atau dokumen.
* Jika token pernah terekspos di mana pun, token **segera di-revoke** melalui @BotFather (`/revoke`) dan diganti yang baru.
* Webhook didaftarkan dengan parameter `secret_token`. Backend menolak request webhook yang tidak membawa header `X-Telegram-Bot-Api-Secret-Token` yang sesuai.
* Endpoint webhook menggunakan path yang tidak mudah ditebak dan hanya melalui HTTPS.

## **Secrets**

API keys disimpan di:

> `.env`

atau secret management system.

Jangan menyimpan API key dalam database atau source code.

## **Database**

PostgreSQL tidak boleh exposed langsung ke public internet.

## **Storage**

Report files menggunakan private storage.

## **AI Data Minimization**

Hanya data yang diperlukan yang dikirim ke AI.

Jangan mengirim:

* Password  
* API Key  
* Secret  
* Credential  
* Sensitive infrastructure data

jika tidak diperlukan.

## **Redaction Layer**

Sebelum pesan dikirim ke AI dan sebelum disimpan, backend menjalankan redaction berbasis pola:

* API key dan token (contoh pola: `sk-…`, `ghp_…`, `AKIA…`)
* Password setelah kata kunci seperti "password", "pass", "pwd"
* Private key
* Database connection string
* Pola tambahan yang dapat dikonfigurasi per user

Bagian yang terdeteksi diganti dengan `[REDACTED_SECRET]` dan tidak disimpan. Bot memberi tahu user:

> Pesan ini mengandung credential. Bagian tersebut tidak disimpan.

## **Pemrosesan Data oleh AI Provider**

Worklog berisi detail pekerjaan klien (nama klien, infrastruktur, incident). Data tersebut diproses oleh AI provider pihak ketiga.

Sebelum digunakan untuk pekerjaan klien:

* Tinjau kebijakan retensi data dan lokasi pemrosesan provider.
* Pastikan sesuai dengan perjanjian kerahasiaan dengan klien.
* Abstraction layer (`AIService`) memungkinkan provider diganti, atau project tertentu diarahkan ke provider berbeda di masa depan.

## **Dashboard**

* Login melalui Telegram Login Widget; hash dari Telegram wajib diverifikasi di backend.
* Hanya `telegram_user_id` yang ada di whitelist yang dapat login.
* HTTPS wajib.
* CSRF protection dan session timeout menggunakan fitur bawaan Laravel.
* File report hanya dapat diunduh melalui signed URL dengan masa berlaku terbatas.

---

# **57\. Data Deletion**

User harus dapat menghapus:

* Activity  
* Task  
* Project  
* Report  
* Raw Message

Untuk MVP dapat dilakukan melalui administrative operation.

Pada fase berikutnya dapat disediakan melalui Telegram/Web.

---

# **58\. Error Handling**

Jika AI gagal:

> AI processing failed.

System tidak boleh kehilangan raw message.

Raw message tetap disimpan sehingga dapat diproses ulang.

---

# **59\. AI Failure Scenario**

User:

> "Yang kemarin sudah selesai."

AI tidak yakin task mana.

Bot:

> Saya menemukan 2 task yang mungkin dimaksud:

> 1. Domain Acquisition  
> 2. Member Management Bug

> Mana yang dimaksud?

Pilihan:

* Domain Acquisition  
* Member Management  
* Create New Task

---

# **60\. Search**

User dapat bertanya menggunakan natural language.

Contoh:

> "Apa saja task 9Club bulan Agustus?"

atau:

> "Kapan terakhir perubahan Mailgun?"

atau:

> "Task apa saja yang masih waiting?"

AI mencari data dari database dan memberikan jawaban berdasarkan data yang tersimpan.

**Fase:** Phase 3.

Implementasi awal menggunakan structured filter (project, status, tanggal, person) dan keyword search (`pg_trgm`). Semantic search (pgvector) ditambahkan jika dibutuhkan.

---

# **61\. Intelligent Search**

Search harus dapat menggunakan:

* Project  
* Task  
* Activity  
* Date  
* Status  
* Person  
* Entity  
* Keyword  
* Semantic similarity

Contoh:

> "Cari semua pekerjaan terkait Mailgun."

System dapat menampilkan seluruh activity/task yang berhubungan dengan Mailgun.

---

# **62\. Smart Reporting Reminder**

Pada akhir bulan, system tidak hanya mengirim:

> "Don't forget your monthly report."

Tetapi memberikan context:

> September reporting reminder.

> You have:

> 27 activities  
> 8 completed tasks  
> 3 ongoing tasks  
> 2 waiting tasks  
> 2 cross-month tasks

> There are also 3 activities with incomplete details.

> Would you like to review them before generating your report?

Dengan demikian reminder menjadi actionable.

---

# **63\. Reminder & Reporting Relationship**

Reminder dan report generation harus terintegrasi.

Flow:

```
Reporting Period Ending
          ↓
   Reminder Engine
          ↓
 Analyze Work Memory
          ↓
┌─────────┼─────────┐
↓         ↓         ↓
Activities Tasks  Cross-month
│         │         │
└─────────┼─────────┘
          ↓
     User Review
          ↓
     Report Draft
          ↓
       Approval
          ↓
    PDF / Markdown
```

---

# **64\. Document Generation**

Output:

* **PDF** — output utama
* **Markdown** — selalu tersedia
* **DOCX** — opsional, Phase 3

## **Alur**

```
Report content (JSON, Markdown per section)
        ↓
Gabungkan menjadi file Markdown (.md)
        ↓
Render ke HTML (Blade template + CSS)
        ↓
PDF via Gotenberg (Chromium)
```

## **Alasan**

* Membuat PDF dari HTML jauh lebih mudah dikontrol dibanding membangun DOCX atau mengonversi DOCX ke PDF. Tabel, styling, font, dan layout cukup diatur dengan HTML/CSS.
* Markdown otomatis tersedia karena menjadi format perantara.
* HTML yang sama dipakai untuk preview di dashboard, sehingga tampilan di layar sama dengan PDF.
* Stack tetap PHP; Chromium berjalan terisolasi di container Gotenberg.

## **Catatan Teknis**

* Font template harus tersedia di container Gotenberg (atau di-embed melalui CSS `@font-face`).
* Header, footer, nomor halaman, dan page break diatur dengan CSS `@page`.
* Markdown dikonversi ke HTML dengan library CommonMark (sudah tersedia di ekosistem Laravel).

## **DOCX (Phase 3, opsional)**

Jika klien membutuhkan file yang dapat diedit, DOCX dapat dibuat dari file Markdown yang sama menggunakan Pandoc.

---

# **65\. Report Delivery**

Setelah report selesai:

> Your September 2026 report is ready.

> \[Download PDF\]
> \[Download Markdown\]
> \[Buka di Dashboard\]

File menggunakan private storage dan hanya dapat diakses oleh user yang berhak.

Report yang di-generate dari dashboard juga dapat dikirim ke Telegram jika user memilih "Kirim ke Telegram".

---

# **66\. MVP Telegram UX**

## **First Setup**

User:

> `/start`

Bot:

> Sugeng rawuh! Saya Pak Carik, juru catat pekerjaan Njenengan.

> Kita mulai dari project pertama, nggih.

Bot:

> Nama project-nya apa?

User:

> 9Club

Bot:

> Project "9Club" created.

> Default report language?

Pilihan:

* English  
* Indonesia

Seluruh contoh pesan bot di section UX ini mengikuti gaya bahasa pada section Bot Persona: Pak Carik. Contoh berbahasa Inggris di section lain menunjukkan isi informasi, bukan gaya bahasa final.

---

# **67\. Worklog UX**

User:

> "Hari ini fix forgot password 9Club."

Bot:

> Recorded.

> Project: 9Club  
> Task: Forgot Password Issue  
> Activity: Bug investigation / fix  
> Status: In Progress

---

# **68\. Task Update UX**

User:

> "Sudah normal setelah ganti Mailgun."

Bot:

> Updated "Forgot Password Issue".

> Status changed:

> In Progress → Completed

---

# **69\. Ambiguous Task UX**

User:

> "Yang domain itu sudah selesai."

Bot:

> Which task do you mean?

> 1. Premium Domain Acquisition  
> 2. Domain Configuration

Pilihan:

* Premium Domain Acquisition  
* Domain Configuration  
* New Task

---

# **70\. Report UX**

User:

> `/report September`

Bot:

> I found:

> 27 activities  
> 8 completed tasks  
> 3 ongoing tasks  
> 2 waiting tasks  
> 2 cross-month tasks

> Generate September report?

Pilihan:

* Generate  
* Review  
* Cancel

---

# **71\. Report Review UX**

Sebelum final:

> September 2026 Report Draft

> 8 completed tasks  
> 3 ongoing tasks  
> 2 waiting tasks  
> 2 cross-month tasks

> Please review the draft.

Pilihan:

* Approve  
* Edit via instruksi  
* Buka di Dashboard  
* Regenerate  
* Cancel

---

# **72\. MVP Scope**

**MVP = Phase 1 + Phase 2**, termasuk Web Dashboard dasar.

Target MVP: dipakai sendiri secara penuh selama minimal satu siklus laporan bulanan sebelum masuk Phase 3.

## **Phase 1 — Worklog Foundation**

Features:

* Telegram Bot + whitelist `telegram_user_id`
* Onboarding `/start` dan pembuatan project
* `inbound_messages`, queue, instant acknowledgement, dan idempotency
* Redaction layer
* AI extraction + task matching (satu AI call, multi-item)
* Candidate retrieval (context injection)
* Task creation, task update, activity storage
* Status transition matrix + `task_events`
* Correction & Undo UX
* Ambiguous task clarification
* Daily Worklog Reminder (kondisional)
* Evaluation Dataset + logging `ai_interactions`
* **Dashboard**: login Telegram, input worklog, daftar & detail task, inbox, settings
* **Sinkronisasi** Telegram ↔ dashboard (polling, klarifikasi dua arah, optimistic locking)

---

## **Phase 2 — Monthly Reporting**

Features:

* Monthly report dan custom period
* Cross-month reporting (query berbasis periode)
* Satu template generic per bahasa (Indonesian & English), Blade + CSS
* `report_versions` dengan `data_snapshot_at`
* Pre-generate check (entri yang masih diproses)
* **Dashboard Reports**: generate, preview, editor Markdown per section, edit via instruksi, riwayat versi
* Review via Telegram: approve, regenerate, edit via instruksi
* PDF (Gotenberg Chromium) dan Markdown
* Monthly Report Reminder kontekstual
* Deteksi late entries dan banner "Perbarui Draft"

---

## **Phase 3 — Intelligent Tracking**

Features:

* Weekly report
* Template per project (contoh: 9Club Monthly Technical Activity Report)
* Weekly Review, Waiting Task, Cross-Month Task, Stale Task, dan Pre-Report reminder
* Reminder priority dan per-project reminder settings
* Incident report
* Search (structured + keyword, semantic jika dibutuhkan)
* Voice note (speech-to-text)
* Screenshot sebagai input (vision)
* DOCX export (opsional, via Pandoc)

---

## **Phase 4 — Dashboard Lanjutan**

Features:

* Analytics
* Template editor visual
* File attachments
* Advanced activity timeline
* Search UI di dashboard

---

## **Phase 5 — External Integrations**

Potential integrations:

* GitHub
* Jira
* Slack
* Email
* Google Drive
* Notion
* Trello

Integrations tersebut tidak required untuk MVP.

---

# **73\. MVP Acceptance Criteria**

## **Worklog Extraction**

Engineering target:

> ≥ 90%

AI harus dapat mengidentifikasi aktivitas utama dari normal user messages.

---

## **Project Identification**

Engineering target:

> ≥ 95%

---

## **Task Matching**

Engineering target:

> ≥ 90%

---

## **Date Extraction**

Engineering target:

> ≥ 95%

---

## **Report Generation**

Engineering target:

> ≥ 95%

successful report generation.

---

## **PDF Generation**

Engineering target:

> ≥ 99%

successful PDF generation.

---

## **Response Time**

Normal worklog:

> \< 10 seconds

Tidak termasuk temporary external API issues.

Long-running report generation menggunakan asynchronous processing.

---

# **74\. Multi-Channel Acceptance Criteria**

* Entri dari Telegram tampil di dashboard dalam ≤ 10 detik setelah selesai diproses.
* 0 entri duplikat akibat klik ganda, retry jaringan, atau webhook Telegram yang dikirim ulang.
* Report tidak pernah dibuat ketika masih ada entri berstatus `processing` tanpa persetujuan user.
* Setiap report version dapat ditelusuri ke `data_snapshot_at` dan `source_activity_ids`.
* Isi preview dashboard dan PDF identik.
* Setiap PDF yang di-generate selalu disertai file Markdown.

---

# **75\. Evaluation Dataset**

Acceptance criteria di atas hanya bermakna jika ada cara mengukurnya. Karena itu Evaluation Dataset dibuat **sebelum** pengembangan prompt dimulai.

## **Isi Dataset**

* 50–100 pesan worklog nyata (dari riwayat chat, catatan, atau laporan lama).
* Wajib mencakup kasus sulit:
  * referensi samar ("yang kemarin soal domain itu")
  * multi-item dalam satu pesan
  * lintas project
  * tanggal lampau (backdated)
  * campuran bahasa Indonesia dan Inggris
  * update untuk task yang sudah Completed (reopen)
* Setiap pesan diberi label manual: project, task (existing/new), activity type, status, tanggal.
* Dataset disertai snapshot state task sebelum pesan diproses, agar matching dapat diuji secara realistis.

## **Penggunaan**

* Dijalankan otomatis setiap kali `prompt_version` berubah.
* Hasil evaluasi menjadi dasar penentuan threshold confidence.
* Kasus dari tabel `corrections` di production ditambahkan secara berkala.

---

# **76\. User Correction Rate**

Initial engineering target:

> \< 15%

dari AI-generated interpretations membutuhkan koreksi user.

Metric ini harus dimonitor dan digunakan untuk memperbaiki prompt, matching logic, dan UX.

---

# **77\. Monthly Report Preparation Time**

Target:

> \< 5 minutes

untuk bulan normal ketika aktivitas telah dicatat secara konsisten.

---

# **78\. Monitoring Metrics**

System harus memonitor:

* AI requests  
* AI failures  
* AI latency  
* Token usage  
* Task matching confidence  
* Task matching corrections  
* Report generation time  
* PDF generation failures  
* Telegram delivery failures  
* Queue failures  
* Reminder delivery  
* Reminder dismissal  
* Reminder snooze  
* Reminder conversion to action

Monitoring server dan backup dijelaskan di section Storage & Backup.

---

# **79\. AI Cost Monitoring**

Track:

* tokens\_input  
* tokens\_output  
* model  
* purpose  
* project  
* created\_at

Purpose examples:

* worklog\_extraction  
* task\_matching  
* report\_generation  
* search  
* incident\_analysis

Hal ini memungkinkan analisis biaya AI per project maupun per report.

---

# **80\. Team Requirement**

MVP dapat dikembangkan oleh:

## **1 Full-Stack / Backend Developer**

Responsibilities:

* Laravel  
* PostgreSQL  
* Telegram Bot  
* Redis  
* Queue  
* DeepSeek API  
* Task system  
* Activity system  
* Reminder system  
* Report engine  
* PDF & Markdown generation (Blade + Gotenberg)  
* Docker deployment

## **Part-time QA**

Responsibilities:

* Telegram flow testing  
* AI extraction testing  
* Task matching testing  
* Reminder testing  
* Report testing  
* PDF testing  
* Regression testing

Frontend developer terpisah tidak diperlukan. Dashboard MVP dibangun dengan Laravel Filament (Livewire) oleh developer yang sama. Tanggung jawab developer bertambah: Filament dashboard, Blade report template, dan Gotenberg.

---

# **81\. Recommended Development Priority**

1. Database schema & migrations
2. `inbound_messages` + queue + idempotency + `WorklogService` (satu jalur untuk semua channel)
3. Telegram webhook + instant acknowledgement
4. Evaluation Dataset
5. Redaction layer
6. AI extraction + matching (satu AI call, candidate retrieval)
7. Task system + status transition matrix + `task_events`
8. Confirmation & Correction / Undo UX (Telegram)
9. Dashboard: login Telegram, input worklog, tasks, inbox, settings
10. Sinkronisasi Telegram ↔ dashboard + optimistic locking
11. Daily Worklog Reminder
12. Report engine (data selection, cross-month, snapshot)
13. Pre-generate check
14. `report_versions` + dashboard report page (preview, editor, versi)
15. Markdown → HTML (Blade) → PDF (Gotenberg)
16. Review via Telegram
17. Monthly Report Reminder
18. Late-entry detection + banner "Perbarui Draft"

Setelah MVP digunakan satu siklus bulanan penuh:

19. Phase 3 — smart reminders, search, incident report, template per project, voice note, DOCX
20. Phase 4 — Dashboard lanjutan

---

# **82\. Critical Product Rules**

## **Rule 1**

Never create a new task simply because the user sent a new message.

First search for relevant existing tasks.

## **Rule 2**

Never merge tasks purely because keywords are similar.

Use context and confidence.

## **Rule 3**

Task lifetime is independent from report period.

## **Rule 4**

Raw user input must be preserved.

## **Rule 5**

AI proposes.

Backend validates.

Database owns the truth.

## **Rule 6**

AI must not invent work that does not exist in stored activities.

## **Rule 7**

Reports are generated from recorded data.

## **Rule 8**

Reminder harus mengikuti business rules dan tidak boleh mengirim notification secara tidak terkendali.

## **Rule 9**

Reminder yang sudah tidak relevan harus dihentikan.

Contoh:

Task:

> Waiting

User memberikan update:

> "API sudah aktif."

Reminder follow-up untuk task tersebut harus disesuaikan dengan status baru.

## **Rule 10**

User tetap memiliki kontrol penuh terhadap reminder.

User dapat:

* Disable  
* Snooze  
* Dismiss  
* Change schedule  
* Change frequency

## **Rule 11**

AI hanya boleh merujuk task yang ada di candidate list yang diberikan backend.

## **Rule 12**

Setiap write yang berasal dari AI harus dapat di-undo oleh user.

## **Rule 13**

Secret dan credential tidak pernah dikirim ke AI dan tidak pernah disimpan.

## **Rule 14**

Fakta baru yang muncul saat edit report harus disimpan sebagai activity terlebih dahulu. Report tidak boleh berisi fakta yang tidak ada di work memory.

## **Rule 15**

Semua channel (Telegram dan dashboard) menulis data melalui jalur proses yang sama. Tidak ada logic penulisan khusus per channel.

## **Rule 16**

Report dibuat dari snapshot data yang tercatat (`data_snapshot_at`). Entri yang masih diproses tidak boleh diabaikan tanpa sepengetahuan user.

## **Rule 17**

Setiap export PDF selalu disertai file Markdown dari isi yang sama.

---

# **83\. Recommended Deployment Architecture**

Seluruh service berjalan di **satu VPS** menggunakan Docker Compose.

## **Spesifikasi VPS (MVP)**

* 2 vCPU
* 4 GB RAM (komponen paling berat adalah Chromium di Gotenberg saat membuat PDF)
* 40–80 GB SSD
* Ubuntu LTS + Docker Engine + Docker Compose

Docker Compose services:

* reportflow-app (Laravel: webhook Telegram + Web Dashboard)
* reportflow-worker
* reportflow-scheduler
* reportflow-postgres
* reportflow-redis
* reportflow-gotenberg (Chromium)
* nginx

Architecture:

```
      Internet
    ┌────┴─────┐
    ↓          ↓
 Telegram   Browser (Dashboard)
    └────┬─────┘
         ↓
       Nginx (HTTPS)
         ↓
   reportflow-app
         │
    ┌────┼──────────────┐
    ↓    ↓              ↓
PostgreSQL  Redis    DeepSeek
             │
             ↓
    reportflow-worker
             │
             ↓
    Report Generation
             │
    Markdown → HTML
             │
     ┌───────┴────────┐
     ↓                ↓
  .md file     PDF (Gotenberg)
```

PostgreSQL, Redis, dan Gotenberg hanya dapat diakses dari network internal Docker.

---

# **84\. Storage & Backup**

## **Keputusan Hosting**

| Opsi | Keputusan | Alasan |
| --- | --- | --- |
| VPS (Docker Compose) | **Dipakai** | Laravel, queue worker, scheduler, Redis, dan Gotenberg membutuhkan proses yang berjalan terus-menerus |
| Cloudflare D1 | Tidak dipakai | Berbasis SQLite dan diakses melalui Workers; tidak mendukung `jsonb`, GIN index, `pg_trgm`, dan `pgvector` yang dibutuhkan |
| Cloudflare R2 | Tidak dipakai di MVP | Penyimpanan lokal sudah cukup untuk single user; migrasi tetap mudah karena memakai Storage abstraction Laravel |

## **File Storage**

* File laporan (PDF, Markdown) dan aset disimpan menggunakan Laravel `local` disk di `storage/app/private`.
* Folder tersebut di-mount sebagai **Docker volume** agar file tidak hilang saat container dibangun ulang atau di-update.
* File tidak pernah diakses langsung dari folder publik. Download hanya melalui **signed URL** dengan masa berlaku terbatas.
* Semua akses file melalui `Storage` facade, sehingga pindah ke object storage (R2/S3) di masa depan cukup dengan mengubah konfigurasi disk, tanpa mengubah kode.

Struktur folder:

```
storage/app/private/
└── reports/
    └── {project_id}/
        └── {report_id}/
            └── v{version_no}/
                ├── report.pdf
                └── report.md
```

## **Docker Volumes**

* `postgres_data` — data PostgreSQL
* `redis_data` — data Redis (persistence AOF)
* `app_storage` — `storage/app/private` (file laporan dan aset)

## **Backup**

Karena semua data berada di satu server, **backup wajib disimpan di luar VPS**. Backup yang hanya disimpan di VPS yang sama tidak dianggap sebagai backup.

Isi backup:

* Database: `pg_dump` (format custom, terkompresi).
* File: arsip folder `storage/app/private`.
* Konfigurasi: `.env` (dienkripsi) dan `docker-compose.yml`.

Jadwal dan retensi:

* Backup otomatis setiap hari (misalnya pukul 02:00 waktu user) melalui Laravel Scheduler atau cron.
* Simpan **7 backup harian** dan **4 backup mingguan**.

Lokasi backup (minimal satu, di luar VPS):

* Fitur snapshot/backup otomatis dari penyedia VPS.
* Server lain melalui `rsync`/SSH.
* Cloud storage (misalnya Google Drive) melalui `rclone`.

Keamanan backup:

* File backup dienkripsi sebelum dikirim keluar server.
* Kunci enkripsi disimpan terpisah dari lokasi backup.

## **Restore Test**

* Restore diuji minimal **sekali sebulan** ke environment terpisah (lokal atau staging).
* Hasil backup dan restore dicatat di log. Jika backup harian gagal, Pak Carik mengirim notifikasi ke Telegram admin.

## **Monitoring Server**

* Notifikasi jika disk usage melewati 80%.
* Notifikasi jika container `app`, `worker`, `scheduler`, atau `gotenberg` berhenti.

---

# **85\. Queue Architecture**

AI request dan document generation menggunakan queue.

Worklog:

```
User
  ↓
Telegram / Dashboard
  ↓
Laravel (simpan inbound_message + tampilkan "⏳ Mencatat…")
  ↓
Redis Queue
  ↓
Worker
  ↓
Redaction → DeepSeek
  ↓
Backend Validation
  ↓
Database
  ↓
Edit pesan konfirmasi di Telegram
```

Report:

```
Generate Report
      ↓
    Queue
      ↓
Collect Activities
      ↓
AI Summary (per section)
      ↓
Save report_version (+ data_snapshot_at)
      ↓
Build Markdown (.md)
      ↓
Render HTML (Blade)
      ↓
Convert PDF (Gotenberg Chromium)
      ↓
Store Files (private)
      ↓
Notify: Dashboard / Telegram
```

---

# **86\. Reminder Engine Architecture**

Reminder menggunakan Laravel Scheduler dan Queue.

Architecture:

Laravel Scheduler  
        ↓  
  Reminder Engine  
        ↓  
  Check Reminder Rules  
        ↓  
Check User / Project / Task State  
        ↓  
  Create Notification  
        ↓  
    Redis Queue  
        ↓  
   Telegram Worker  
        ↓  
      Telegram

Scheduler dapat berjalan setiap menit.

Reminder hanya dikirim jika `next_run_at` pada `reminder_instances` sudah tercapai, rule-nya masih enabled, dan kondisi reminder masih valid (misalnya task belum berubah status).

---

# **87\. Future Architecture**

Potential future architecture:

             Telegram  
                 │  
                 ↓  
          ┌───────────────┐  
          │    Laravel    │  
          │   API Layer   │  
          └───────┬───────┘  
                  │  
      ┌───────────┼───────────┐  
      ↓           ↓           ↓  
  PostgreSQL    Redis     AI Service  
                              │  
                          DeepSeek  
                              │  
                              ↓  
                       Report Engine  
                              │  
                       ┌──────┴──────┐  
                       ↓             ↓  
                   Markdown        PDF

Kemudian:

* Mobile App  
* GitHub  
* Jira  
* Slack  
* Email  
* Google Drive  
* Notion

dapat ditambahkan tanpa mengubah core task/activity architecture.

---

# **88\. Product Philosophy**

ReportFlow AI bukan sekadar aplikasi pembuat laporan.

Produk utamanya adalah:

> **Persistent Work Memory**

System harus mengingat:

* What happened?  
* When did it happen?  
* Which project?  
* Which task?  
* Who requested it?  
* What changed?  
* What is the current status?  
* What happened before?  
* What happened afterward?  
* What still needs follow-up?

Kemudian report menjadi output dari work memory tersebut.

Reminder menjadi mekanisme yang memastikan work memory tersebut tetap lengkap.

---

# **89\. Final Product Architecture**

```
          USER
            │
   ┌────────┴────────┐
   ↓                 ↓
TELEGRAM        DASHBOARD
   └────────┬────────┘
            │
   ┌────────┴────────┐
   ↓                 ↓
WORKLOG          REMINDER
   │                 │
   ↓                 │
WORK MEMORY ←────────┘
   │
┌──┴──────────────┐
↓                 ↓
TASKS         ACTIVITIES
│                 │
└────────┬────────┘
         ↓
   REPORT ENGINE
         │
   ┌─────┴─────┐
   ↓           ↓
  PDF      MARKDOWN
```

Dengan pendekatan ini, ReportFlow AI memiliki tiga fungsi utama:

1. **Remember** — menyimpan dan memahami pekerjaan pengguna.
2. **Remind** — mengingatkan pekerjaan atau laporan yang berpotensi terlupakan.
3. **Report** — mengubah work memory menjadi laporan profesional.

Core principle:

> **AI interprets the work. The application owns the truth.**

Dan tujuan akhirnya:

> **Capture your work anywhere. Track the progress. Never forget the report.**
