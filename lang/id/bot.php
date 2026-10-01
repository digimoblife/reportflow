<?php

/*
| Pesan bot Pak Carik (persona) — bahasa Indonesia. PRD §19, skill pak-carik-messages.
| Setiap kunci: 2–3 variasi, placeholder identik di semua variasi, maksimal 2 kata Jawa per variasi.
| Pesan error dan keamanan tetap lugas.
*/

return [
    'onboarding' => [
        'welcome' => [
            'Sugeng rawuh! Saya Pak Carik, juru catat pekerjaan Anda. Kita mulai dari project pertama. Nama project-nya apa?',
            'Halo, saya Pak Carik, juru catat pekerjaan Anda. Kita mulai dari project pertama, nggih. Nama project-nya apa?',
            'Salam kenal, saya Pak Carik. Saya yang mencatat pekerjaan Anda. Project pertamanya namanya apa, nggih?',
        ],
        'already_set_up' => [
            'Anda sudah punya :count project. Cukup ceritakan pekerjaan Anda, saya yang mencatat.',
            'Semua sudah siap (:count project). Ketik saja apa yang Anda kerjakan, nggih.',
        ],
        'project_created' => [
            'Project ":project" sudah saya buat. Sekarang ceritakan apa yang Anda kerjakan, nggih.',
            'Beres, project ":project" sudah masuk arsip. Monggo, ceritakan pekerjaan Anda.',
        ],
        'project_exists' => [
            'Project ":project" sudah ada di arsip. Silakan langsung ceritakan pekerjaan Anda.',
            'Nama ":project" sudah terpakai, jadi saya pakai project yang sudah ada. Silakan lanjut bercerita.',
        ],
        'new_project_usage' => [
            'Tulis nama project barunya, misalnya /project baru Harbor Portal.',
            'Nama project barunya apa? Contoh: /project baru Kedai App.',
        ],
        'name_invalid' => [
            'Nama project belum bisa dipakai. Tulis 1 sampai 80 karakter.',
            'Nama project harus 1 sampai 80 karakter dan tidak boleh kosong. Coba tulis lagi.',
        ],
    ],

    'help' => [
        'guide' => [
            "Cara kerjanya: ceritakan saja pekerjaan Anda, misalnya \"Hari ini fix bug login 9Club\". Saya catat dan arsipkan.\n\nPerintah yang tersedia:\n/start - mulai dan buat project pertama\n/projects - daftar project\n/project - detail satu project\n/project baru <nama> - buat project baru\n/tasks - task yang sedang berjalan\n/task - detail satu task\n/undo - batalkan catatan terakhir\n/inbox - catatan yang perlu dicek\n/reminder - atur pengingat harian dan bulanan\n/report - buat laporan bulanan\n/review - tinjau draft laporan\n/reports - daftar laporan\n/help - panduan ini",
            "Panduan singkat: tulis apa yang Anda kerjakan hari ini, nanti saya catat.\n\nPerintah yang tersedia:\n/start - mulai dan buat project pertama\n/projects - daftar project\n/project - detail satu project\n/project baru <nama> - buat project baru\n/tasks - task yang sedang berjalan\n/task - detail satu task\n/undo - batalkan catatan terakhir\n/inbox - catatan yang perlu dicek\n/reminder - atur pengingat harian dan bulanan\n/report - buat laporan bulanan\n/review - tinjau draft laporan\n/reports - daftar laporan\n/help - panduan ini",
        ],
    ],

    'worklog' => [
        'ack' => [
            '⏳ Mencatat…',
            '⏳ Sebentar, saya catat…',
            '⏳ Saya tulis dulu…',
        ],
        'recorded' => [
            'Nggih, sudah saya catat.',
            'Beres, sudah masuk arsip.',
            'Sudah saya catat, ini rinciannya.',
        ],
        'nothing_recorded' => [
            'Dari pesan ini belum ada pekerjaan yang perlu saya catat.',
            'Pesan ini saya baca, tetapi tidak ada pekerjaan yang saya catat.',
        ],
        'pending_notice' => [
            'Masih ada :count catatan yang menunggu jawaban Anda di bawah.',
            'Ada :count catatan yang perlu Anda pastikan dulu, nggih.',
        ],
        'rejected_notice' => [
            ':count catatan tidak bisa saya simpan karena tidak cocok dengan arsip.',
            'Ada :count catatan yang saya tolak karena datanya tidak sesuai arsip.',
        ],
        'split_required' => [
            'Pesan ini berisi lebih dari 5 catatan, jadi belum ada yang saya simpan. Tolong dipecah jadi beberapa pesan, nggih.',
            'Terlalu banyak catatan dalam satu pesan (maksimal 5). Belum ada yang tersimpan; mohon kirim ulang dalam beberapa pesan.',
        ],
        'failed' => [
            'Waduh, catatan ini belum bisa saya proses. Tenang, pesannya sudah saya simpan dan bisa dicoba lagi nanti.',
            'Catatan ini gagal saya proses. Pesan aslinya aman tersimpan, nanti bisa diproses ulang.',
        ],
        'edit_saved_notice' => [
            'Suntingan Anda tersimpan. Catatan yang sudah dibuat dari versi lama belum berubah. Proses ulang dengan teks baru?',
            'Suntingan sudah saya simpan, nggih. Catatan lama belum ikut berubah. Mau diproses ulang dengan teks baru?',
        ],
        'attachment_ignored' => [
            'Lampirannya belum bisa saya baca, jadi hanya tulisannya yang saya catat.',
            'Hanya teksnya yang saya catat, nggih. Lampiran belum bisa saya baca.',
        ],
    ],

    'security' => [
        'credential_detected' => [
            'Nuwun sewu, pesan ini mengandung password atau kunci rahasia (:count bagian). Bagian itu tidak saya simpan.',
            'Pesan ini mengandung credential (:count bagian). Bagian itu sudah dihapus dan tidak disimpan, nggih.',
        ],
        'redaction_error' => [
            'Pesan ini gagal saya periksa dengan aman, jadi tidak saya simpan dan tidak saya proses. Mohon kirim ulang, kalau perlu dalam potongan yang lebih pendek.',
            'Saya tidak bisa memeriksa pesan ini dengan aman. Pesannya tidak disimpan dan tidak diproses. Silakan kirim ulang.',
        ],
    ],

    'commands' => [
        'unavailable' => [
            'Perintah :command belum tersedia. Untuk sekarang, ceritakan saja pekerjaan Anda dan saya catat.',
            'Maaf, :command belum bisa dipakai. Ketik /help untuk perintah yang sudah aktif.',
        ],
        'unknown' => [
            'Perintah :command tidak saya kenal. Ketik /help untuk daftar perintah.',
            'Saya tidak mengenal :command. Coba /help untuk melihat yang tersedia.',
        ],
    ],

    'unsupported' => [
        'voice' => [
            'Pesan suara belum bisa saya baca. Tolong tuliskan saja, nggih.',
            'Voice note belum didukung. Kirim dalam bentuk teks, nggih.',
        ],
        'image' => [
            'Gambar atau screenshot belum bisa saya baca. Tolong ceritakan dengan tulisan, nggih.',
            'Gambar belum didukung. Kirim dalam bentuk teks, ya.',
        ],
        'other' => [
            'Jenis pesan ini belum bisa saya catat. Kirim dalam bentuk teks, nggih.',
            'Saya baru bisa mencatat pesan teks. Tolong kirim dalam bentuk tulisan.',
        ],
    ],

    'question' => [
        'match' => [
            'Apakah catatan ini untuk task ":task"?',
            'Sebentar, catatan ini untuk task ":task", betul?',
        ],
        'project' => [
            'Catatan ini untuk project yang mana?',
            'Saya belum yakin projectnya. Yang mana?',
        ],
        'date' => [
            'Tanggalnya :date, lebih dari 30 hari lalu. Tetap dicatat pada tanggal itu?',
            'Catatan ini bertanggal :date (sudah lebih dari 30 hari). Pakai tanggal itu?',
        ],
        'cancelled' => [
            'Baik, catatan itu tidak jadi saya simpan.',
            'Siap, catatan itu saya lewati.',
        ],
    ],

    'undo' => [
        'done' => [
            'Siap, catatan tadi saya batalkan. Arsip kembali seperti semula.',
            'Beres, catatan itu saya batalkan.',
        ],
        'some' => [
            ':count catatan sudah saya batalkan.',
            'Sudah saya batalkan :count catatan, nggih.',
        ],
        'partial' => [
            'Catatannya saya hapus, tetapi task sudah berubah sejak itu, jadi statusnya tidak saya kembalikan.',
            'Catatan dibatalkan. Status task tidak saya ubah karena task sudah berubah setelahnya.',
        ],
        'nothing' => [
            'Belum ada catatan yang bisa dibatalkan.',
            'Tidak ada catatan terakhir yang bisa saya batalkan.',
        ],
    ],

    'correction' => [
        'pick_task' => [
            'Pindahkan catatan ini ke task yang mana?',
            'Catatan ini mau dipindah ke task mana?',
        ],
        'pick_status' => [
            'Ubah status task ":task" menjadi apa?',
            'Status task ":task" mau diubah jadi apa?',
        ],
        'pick_project' => [
            'Pindahkan task baru ini ke project yang mana?',
            'Task baru ini mau dipindah ke project mana?',
        ],
        'done' => [
            'Sudah saya ubah.',
            'Beres, sudah diperbarui.',
        ],
        'stale' => [
            'Task ini baru saja berubah. Silakan coba lagi.',
            'Ada perubahan lain pada task ini. Coba sekali lagi.',
        ],
        'not_possible' => [
            'Perubahan itu tidak bisa dilakukan.',
            'Tidak bisa, perubahan itu tidak diizinkan.',
        ],
        'reply_applied' => [
            'Nggih, saya perbaiki. Begini catatan yang baru.',
            'Siap, sudah saya betulkan. Ini hasilnya.',
        ],
        'reply_unchanged' => [
            'Saya baca balasan Anda, tapi belum menemukan yang perlu diubah. Catatan tetap seperti semula.',
            'Belum ada yang berubah, nggih. Coba tulis perbaikannya lebih jelas, misalnya nama task atau statusnya.',
        ],
        'project_new_only' => [
            'Ganti project hanya untuk task baru. Untuk task lama, pakai Pindah Task.',
            'Task lama tidak bisa pindah project; pindahkan catatannya lewat Pindah Task.',
        ],
    ],

    'list' => [
        'projects_title' => [
            'Project Anda (:count):',
            'Ini daftar project Anda (:count):',
        ],
        'projects_empty' => [
            'Belum ada project. Ketik /start untuk membuat yang pertama.',
            'Project masih kosong. Ketik /start dulu, nggih.',
        ],
        'project_not_found' => [
            'Tidak ada project dengan nama itu. Coba /projects untuk melihat daftarnya.',
            'Project itu tidak ketemu. Lihat daftar lengkap di /projects.',
        ],
        'project_pick' => [
            'Ada beberapa project yang cocok. Pilih salah satu:',
            'Yang cocok lebih dari satu. Silakan pilih:',
        ],
        'tasks_title' => [
            'Task aktif (:count), halaman :page/:pages:',
            'Ada :count task aktif, halaman :page/:pages:',
        ],
        'tasks_empty' => [
            'Belum ada task aktif.',
            'Tidak ada task yang sedang berjalan.',
        ],
        'task_usage' => [
            'Tulis nomor atau kata dari judul task, misalnya /task 12 atau /task invoice.',
            'Sebutkan nomor atau kata judul task, contoh: /task 12 atau /task invoice.',
        ],
        'task_not_found' => [
            'Tidak ada task yang cocok. Coba /tasks untuk melihat daftarnya.',
            'Task itu tidak ketemu. Lihat daftar di /tasks.',
        ],
        'task_pick' => [
            'Ada beberapa task yang cocok. Pilih salah satu:',
            'Yang cocok lebih dari satu. Silakan pilih:',
        ],
        'inbox_title' => [
            'Catatan yang perlu dicek (:count):',
            'Ada :count catatan yang masih perlu dicek:',
        ],
        'inbox_empty' => [
            'Beres, tidak ada catatan yang tertahan.',
            'Kotak masuk bersih, tidak ada yang perlu dicek.',
        ],
    ],

    'reprocess' => [
        'started' => [
            'Siap, catatan itu saya proses ulang.',
            'Nggih, saya proses ulang catatan itu.',
        ],
        'busy' => [
            'Catatan itu sedang diproses.',
            'Catatan itu sedang berjalan, tunggu sebentar.',
        ],
        'superseded' => [
            'Pertanyaan ini diganti karena catatannya diproses ulang.',
            'Catatan ini sedang diproses ulang, pertanyaan lama tidak berlaku.',
        ],
        'kept' => [
            'Baik, catatan lama saya biarkan seperti semula.',
            'Oke, tidak ada yang diubah.',
        ],
    ],

    'reminder' => [
        'daily' => [
            'Selamat sore! Belum ada catatan pekerjaan hari ini. Ada yang sudah dikerjakan?',
            'Nuwun sewu, hari ini belum ada catatan pekerjaan di arsip. Ada yang mau dicatat?',
        ],
        'add_prompt' => [
            'Monggo, tulis saja pekerjaannya di sini, nanti saya catat.',
            'Siap, ketik saja apa yang sudah dikerjakan, saya yang mencatat.',
        ],
        'none_done' => [
            'Baik, hari ini tidak saya ingatkan lagi.',
            'Siap, pengingat hari ini saya tutup.',
        ],
        'snoozed' => [
            'Baik, saya ingatkan lagi satu jam lagi.',
            'Siap, satu jam lagi saya ingatkan.',
        ],
        'snooze_limit' => [
            'Sudah tiga kali ditunda, jadi pengingat hari ini saya tutup.',
            'Penundaan sudah tiga kali, pengingat hari ini saya tutup ya.',
        ],
        'status' => [
            "Status pengingat:\n\nHarian: :state — :time (:days)\nBulanan: :mstate — :mtime (:mwhen)",
            "Pengingat saat ini:\n\nHarian: :state — :time (:days)\nBulanan: :mstate — :mtime (:mwhen)",
        ],
        'on_done' => [
            'Siap, pengingat saya nyalakan lagi.',
            'Baik, pengingat aktif kembali.',
        ],
        'off_done' => [
            'Baik, pengingat saya matikan. Nyalakan lagi dengan /reminder on.',
            'Siap, tidak ada pengingat lagi sampai Anda menyalakannya (/reminder on).',
        ],
        'daily_set' => [
            'Siap, pengingat harian sekarang pukul :time.',
            'Baik, saya ingatkan tiap hari kerja pukul :time.',
        ],
        'daily_invalid' => [
            'Jamnya belum jelas. Tulis seperti /reminder daily 18:00.',
            'Format jam salah. Contoh yang benar: /reminder daily 17:30.',
        ],
        'usage' => [
            "Perintah pengingat:\n/reminder - lihat status\n/reminder on - nyalakan\n/reminder off - matikan\n/reminder daily 18:00 - atur jam harian\n/reminder monthly 09:00 - atur jam pengingat bulanan\n/reminder monthly days 3 - atur berapa hari sebelum akhir bulan",
            "Cara memakai pengingat:\n/reminder - status\n/reminder on atau off\n/reminder daily 18:00 - jam pengingat harian",
        ],
        'monthly_intro' => [
            'Bulan :month hampir berakhir. Ini ringkasan catatan Anda:',
            'Penutup bulan :month. Berikut isi catatan Anda bulan ini:',
        ],
        'monthly_ask' => [
            'Mau menyiapkan laporannya sekarang?',
            'Laporannya mau disiapkan sekarang?',
        ],
        'monthly_started' => [
            'Siap, laporan saya siapkan.',
            'Baik, saya mulai menyiapkan laporannya.',
        ],
        'monthly_review' => [
            'Baik, ini task yang sedang berjalan untuk Anda tinjau dulu.',
            'Silakan tinjau dulu task yang berjalan di bawah ini.',
        ],
        'monthly_set' => [
            'Siap, pengingat laporan bulanan sekarang pukul :time (:when).',
            'Baik, saya ingatkan soal laporan bulanan pukul :time (:when).',
        ],
        'monthly_days_set' => [
            'Siap, pengingat laporan bulanan saya kirim :when.',
            'Baik, mulai bulan ini saya ingatkan soal laporan bulanan :when.',
        ],
        'monthly_days_invalid' => [
            'Angkanya belum pas. Pilih 0 sampai :max, misalnya /reminder monthly days 3.',
            'Hitungannya harus 0 sampai :max hari. Contoh: /reminder monthly days 3.',
        ],
        'monthly_on' => [
            'Pengingat laporan bulanan dinyalakan.',
            'Siap, pengingat laporan bulanan aktif.',
        ],
        'monthly_off' => [
            'Pengingat laporan bulanan dimatikan.',
            'Baik, pengingat laporan bulanan saya matikan.',
        ],
        'answered' => [
            'Pengingat ini sudah ditanggapi.',
            'Pengingat ini sudah dijawab sebelumnya.',
        ],
    ],

    'report' => [
        'start_usage' => [
            'Tulis bulannya seperti /report 2026-09, atau /report saja untuk bulan yang sedang dilaporkan.',
            'Format bulan: /report 2026-09. Tanpa tambahan, saya pilih bulan yang pas.',
        ],
        'no_activity' => [
            'Belum ada catatan di bulan :month, jadi belum ada yang bisa dilaporkan.',
            'Bulan :month masih kosong, belum ada aktivitas untuk dilaporkan.',
        ],
        'pick_project' => [
            'Laporan :month untuk project yang mana?',
            'Pilih project untuk laporan :month:',
        ],
        'pending_entries' => [
            ':count catatan masih diproses, jadi belum semuanya masuk ke laporan. Tunggu dulu atau buat tanpa catatan itu?',
            'Masih ada :count catatan yang diproses. Mau menunggu sampai selesai, atau buat laporan tanpa catatan itu?',
        ],
        'already_approved' => [
            'Laporan :period sudah disetujui. Buat versi baru?',
            'Laporan :period sudah final. Mau dibuatkan versi baru?',
        ],
        'outdated' => [
            'Laporan :period (:project) sudah disetujui, tetapi ada :count perubahan pada periode itu sejak datanya diambil. Buat versi baru?',
            'Ada :count perubahan di periode laporan :period (:project) setelah laporannya disetujui. Mau dibuatkan versi baru?',
        ],
        'dismissed' => [
            'Baik, tidak ada yang diubah.',
            'Siap, saya biarkan seperti semula.',
        ],
        'review_intro' => [
            'Draft laporan sudah siap. Silakan ditinjau dulu, nggih.',
            'Ini draft laporannya. Cek ringkasannya, lalu putuskan langkah berikutnya.',
        ],
        'review_approved' => [
            'Laporan ini sudah disetujui.',
            'Laporan ini sudah final dan disetujui.',
        ],
        'approved' => [
            'Laporan disetujui. Filenya saya kirim sebentar lagi, PDF dan Markdown.',
            'Siap, laporan disetujui. PDF dan Markdown menyusul di bawah.',
        ],
        'generating' => [
            'Laporan sedang dibuat. Saya kabari begitu draft-nya siap.',
            'Draft laporan sedang disusun, tunggu sebentar ya.',
        ],
        'busy' => [
            'Laporan ini sedang dibuat. Tunggu sampai selesai.',
            'Masih ada proses pembuatan laporan yang berjalan untuk periode ini.',
        ],
        'stale' => [
            'Laporan sudah berubah sejak pesan ini dikirim. Berikut versi terbarunya.',
            'Pesan ini sudah usang karena laporannya diperbarui. Pakai yang terbaru.',
        ],
        'cancelled' => [
            'Laporan dibatalkan.',
            'Baik, laporan ini saya batalkan.',
        ],
        'not_possible' => [
            'Langkah itu tidak bisa dilakukan sekarang.',
            'Maaf, langkah itu belum bisa dijalankan saat ini.',
        ],
        'edit_pick' => [
            'Bagian mana yang ingin diubah?',
            'Pilih bagian laporan yang mau disunting.',
        ],
        'edit_prompt' => [
            'Tulis instruksinya untuk bagian ":section" dalam satu pesan, misalnya "persingkat" atau "tambahkan bahwa downtime 25 menit di task Tracking".',
            'Silakan kirim instruksi untuk bagian ":section" dalam satu pesan. Fakta baru akan saya simpan sebagai activity dulu.',
        ],
        'instructed' => [
            'Sudah saya terapkan sebagai versi baru. :count fakta baru disimpan sebagai activity. Draft terbarunya menyusul.',
            'Instruksi diterapkan jadi versi baru (:count fakta baru tersimpan). Saya kirim draft terbarunya.',
        ],
        'instruction' => [
            'unmatched' => [
                'Fakta ini belum cocok dengan task mana pun di laporan: :facts. Sebutkan nama task-nya, lalu kirim instruksinya lagi.',
                'Saya tidak menemukan task untuk: :facts. Sebut task-nya, nggih, lalu coba lagi.',
            ],
            'redaction_failed' => [
                'Instruksi itu tidak bisa diperiksa dengan aman, jadi tidak saya proses.',
                'Instruksinya tidak bisa diperiksa dengan aman. Coba kirim lagi dalam bentuk yang lebih pendek.',
            ],
            'ai_failed' => [
                'Instruksi belum bisa diproses sekarang, atau faktanya tidak lolos pemeriksaan. Tidak ada yang diubah.',
                'Belum bisa memproses instruksi itu. Tidak ada yang diubah, coba lagi sebentar lagi.',
            ],
            'nothing_to_change' => [
                'Bagian ini hanya berisi data, jadi instruksi tanpa fakta baru tidak mengubah apa pun.',
                'Bagian itu isinya data murni; tanpa fakta baru tidak ada yang bisa diubah.',
            ],
            'rewrite_failed' => [
                'Bagian itu belum bisa ditulis ulang dengan aman. Tidak ada yang diubah, coba lagi.',
                'Penulisan ulang belum lolos pemeriksaan, jadi tidak ada yang diubah. Silakan coba lagi.',
            ],
            'invalid' => [
                'Instruksinya terlalu pendek, terlalu panjang, atau bagiannya tidak dikenal. Coba lagi.',
                'Instruksi tidak valid. Tulis 3 sampai 1000 karakter.',
            ],
        ],
        'files_pending' => [
            'File PDF masih disiapkan dan akan menyusul.',
            'PDF-nya belum selesai dibuat, menyusul sebentar lagi.',
        ],
        'nothing_to_review' => [
            'Tidak ada laporan yang menunggu tinjauan.',
            'Belum ada draft laporan yang perlu ditinjau.',
        ],
        'none' => [
            'Belum ada laporan.',
            'Laporan masih kosong. Mulai dengan /report.',
        ],
        'list_title' => [
            'Laporan terbaru:',
            'Ini laporan Anda belakangan ini:',
        ],
        'list_hint' => [
            'Ketik /review untuk meninjau draft terbaru.',
            'Gunakan /review untuk membuka draft yang menunggu.',
        ],
    ],

    'ops' => [
        'alert' => [
            'database' => [
                'Waduh, database tidak bisa dihubungi. Cek container postgres sekarang.',
                'Waduh, database tidak bisa dihubungi. Cek container postgres sekarang.',
            ],
            'redis' => [
                'Redis tidak bisa dihubungi, jadi antrean dan cache terganggu.',
                'Redis tidak bisa dihubungi, jadi antrean dan cache terganggu.',
            ],
            'gotenberg' => [
                'Gotenberg (pembuat PDF) tidak terjangkau. Laporan belum bisa dibuat PDF-nya.',
                'Gotenberg (pembuat PDF) tidak terjangkau. Laporan belum bisa dibuat PDF-nya.',
            ],
            'disk' => [
                'Disk terisi :value% (batas :limit%). Bersihkan atau perbesar sebelum penuh.',
                'Disk terisi :value% (batas :limit%). Bersihkan atau perbesar sebelum penuh.',
            ],
            'queue_backlog' => [
                'Antrean menumpuk: :value pekerjaan menunggu (batas :limit). Cek worker.',
                'Antrean menumpuk: :value pekerjaan menunggu (batas :limit). Cek worker.',
            ],
            'failed_jobs' => [
                ':value pekerjaan antrean gagal dalam 24 jam terakhir. Lihat failed_jobs.',
                ':value pekerjaan antrean gagal dalam 24 jam terakhir. Lihat failed_jobs.',
            ],
            'worker_default' => [
                'Worker antrean utama tidak terlihat hidup (usia detak :value menit, batas :limit).',
                'Worker antrean utama tidak terlihat hidup (usia detak :value menit, batas :limit).',
            ],
            'worker_reports' => [
                'Worker laporan tidak terlihat hidup (usia detak :value menit, batas :limit).',
                'Worker laporan tidak terlihat hidup (usia detak :value menit, batas :limit).',
            ],
            'backup_failed' => [
                'Backup terakhir GAGAL. Periksa log layanan backup.',
                'Backup terakhir GAGAL. Periksa log layanan backup.',
            ],
            'backup_stale' => [
                'Backup terakhir sudah :value jam lalu (batas :limit jam), atau belum pernah berhasil.',
                'Backup terakhir sudah :value jam lalu (batas :limit jam), atau belum pernah berhasil.',
            ],
            'restore_overdue' => [
                'Uji restore terakhir sudah :value hari lalu (batas :limit). Jalankan restore-test.',
                'Uji restore terakhir sudah :value hari lalu (batas :limit). Jalankan restore-test.',
            ],
        ],
        'recovered' => [
            'database' => [
                'Database sudah bisa dihubungi lagi.',
                'Database sudah bisa dihubungi lagi.',
            ],
            'redis' => [
                'Redis sudah pulih.',
                'Redis sudah pulih.',
            ],
            'gotenberg' => [
                'Gotenberg sudah terjangkau lagi.',
                'Gotenberg sudah terjangkau lagi.',
            ],
            'disk' => [
                'Pemakaian disk sudah turun ke :value%.',
                'Pemakaian disk sudah turun ke :value%.',
            ],
            'queue_backlog' => [
                'Antrean sudah normal lagi.',
                'Antrean sudah normal lagi.',
            ],
            'failed_jobs' => [
                'Tidak ada lagi pekerjaan gagal dalam 24 jam terakhir.',
                'Tidak ada lagi pekerjaan gagal dalam 24 jam terakhir.',
            ],
            'worker_default' => [
                'Worker antrean utama hidup lagi.',
                'Worker antrean utama hidup lagi.',
            ],
            'worker_reports' => [
                'Worker laporan hidup lagi.',
                'Worker laporan hidup lagi.',
            ],
            'backup_failed' => [
                'Backup sudah berhasil lagi.',
                'Backup sudah berhasil lagi.',
            ],
            'backup_stale' => [
                'Backup sudah segar lagi.',
                'Backup sudah segar lagi.',
            ],
            'restore_overdue' => [
                'Uji restore sudah dilakukan, terima kasih.',
                'Uji restore sudah dilakukan, terima kasih.',
            ],
        ],
    ],

    'sync' => [
        'answered_via_dashboard' => [
            '✅ Sudah dijawab lewat dashboard.',
            '✅ Pertanyaan ini sudah dijawab di dashboard.',
        ],
    ],

    'callback' => [
        'answered' => [
            'Pertanyaan ini sudah dijawab.',
            'Sudah dijawab tadi, nggih.',
        ],
        'expired' => [
            'Tombol ini sudah tidak berlaku.',
            'Tombol ini sudah kedaluwarsa, nggih.',
        ],
    ],

    'errors' => [
        'generic' => [
            'Waduh, ada yang tidak beres di pihak saya. Coba kirim lagi sebentar lagi.',
            'Terjadi kendala di pihak saya. Silakan coba lagi sebentar lagi.',
        ],
    ],
];
