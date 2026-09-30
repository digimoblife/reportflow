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
        'name_invalid' => [
            'Nama project belum bisa dipakai. Tulis 1 sampai 80 karakter.',
            'Nama project harus 1 sampai 80 karakter dan tidak boleh kosong. Coba tulis lagi.',
        ],
    ],

    'help' => [
        'guide' => [
            "Cara pakai: ceritakan saja pekerjaan Anda, misalnya \"Hari ini fix bug login 9Club\". Saya catat dan arsipkan.\n\nPerintah yang sudah aktif:\n/start - mulai dan buat project pertama\n/help - panduan ini\n\nPerintah lain (tasks, undo, report, dan seterusnya) menyusul bertahap.",
            "Panduan singkat: tulis saja apa yang Anda kerjakan hari ini, nanti saya catat.\n\nPerintah yang sudah aktif:\n/start - mulai dan buat project pertama\n/help - panduan ini\n\nPerintah lain menyusul bertahap, nggih.",
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
            'Suntingan Anda tersimpan di arsip. Catatan yang sudah dibuat sebelumnya tidak ikut berubah.',
            'Suntingan sudah saya simpan di arsip, nggih. Catatan yang sudah jadi tidak ikut berubah.',
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
        'project_new_only' => [
            'Ganti project hanya untuk task baru. Untuk task lama, pakai Pindah Task.',
            'Task lama tidak bisa pindah project; pindahkan catatannya lewat Pindah Task.',
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
