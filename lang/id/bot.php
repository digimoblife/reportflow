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
        'recorded_dummy' => [
            'Nggih, catatan sudah saya simpan di arsip. Penafsiran otomatis menyusul di versi berikutnya.',
            'Sudah saya simpan, nggih. Untuk sementara belum saya kaitkan ke task.',
            'Rampung, catatan tersimpan di arsip. Pemetaan ke task menyusul nanti.',
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

    'errors' => [
        'generic' => [
            'Waduh, ada yang tidak beres di pihak saya. Coba kirim lagi sebentar lagi.',
            'Terjadi kendala di pihak saya. Silakan coba lagi sebentar lagi.',
        ],
    ],
];
