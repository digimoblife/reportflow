<?php

/*
| Label antarmuka bot (bukan pesan persona): satu nilai per kunci, tanpa variasi acak.
*/

return [
    'labels' => [
        'project' => 'Project',
        'task' => 'Task',
        'activity' => 'Aktivitas',
        'status' => 'Status',
        'new' => 'baru',
        'reopened' => 'dibuka lagi',
        'explicit' => 'sesuai pesan Anda',
    ],

    'activity_types' => [
        'request' => 'Permintaan', 'investigation' => 'Investigasi', 'development' => 'Pengembangan', 'configuration' => 'Konfigurasi',
        'bug_fix' => 'Perbaikan bug', 'testing' => 'Pengujian', 'deployment' => 'Deploy', 'communication' => 'Komunikasi',
        'research' => 'Riset', 'documentation' => 'Dokumentasi', 'milestone' => 'Milestone', 'blocker' => 'Hambatan',
        'resolution' => 'Penyelesaian masalah', 'follow_up' => 'Tindak lanjut', 'other' => 'Lainnya',
    ],

    'statuses' => [
        'draft' => 'Draft', 'open' => 'Baru', 'in_progress' => 'Sedang dikerjakan', 'waiting' => 'Menunggu',
        'blocked' => 'Terhambat', 'completed' => 'SELESAI ✅', 'cancelled' => 'DIBATALKAN ✖️',
    ],

    'buttons' => [
        'undo' => '↩️ Undo',
        'undo_all' => '↩️ Undo semua',
        'move' => '🔀 Pindah Task',
        'status' => '✏️ Ubah Status',
        'project' => '📁 Ganti Project',
        'yes' => '✅ Ya',
        'new_task' => '🆕 Task baru',
        'back' => '⬅️ Kembali',
        'cancel' => '✖️ Batal',
        'keep_date' => '✅ Pakai :date',
        'today' => '📅 Hari ini',
        'redo' => '🔄 Proses ulang',
        'keep' => 'Biarkan',
        'prev' => '⬅️ Sebelumnya',
        'next' => 'Berikutnya ➡️',
    ],

    'dashboard' => [
        'worklog' => [
            'nav' => 'Catat Pekerjaan',
            'title' => 'Catat Pekerjaan',
            'intro' => 'Tulis apa yang Anda kerjakan, boleh beberapa sekaligus. Saya cocokkan dengan task yang ada.',
            'placeholder' => 'Contoh: Harbor Portal: bug invoice sudah diperbaiki. Lalu rapat dengan Rina soal laporan.',
            'submit' => 'Kirim',
            'recent' => 'Catatan terbaru',
            'empty' => 'Belum ada catatan.',
            'channel' => ['telegram' => 'Telegram', 'dashboard' => 'Dashboard'],
            'states' => [
                'received' => 'Menunggu diproses',
                'processing' => 'Sedang diproses',
                'processed' => 'Selesai',
                'needs_clarification' => 'Butuh jawaban Anda',
                'failed' => 'Gagal diproses',
            ],
            'item_states' => [
                'pending' => 'Menunggu jawaban Anda di Inbox',
                'skipped' => 'Dilewati',
                'rejected' => 'Ditolak: tidak cocok dengan arsip',
                'undone' => 'Dibatalkan',
            ],
            'actions' => [
                'undo' => 'Undo',
                'undo_all' => 'Undo semua',
                'move' => 'Pindah Task',
                'status' => 'Ubah Status',
                'project' => 'Ganti Project',
                'apply' => 'Terapkan',
                'cancel' => 'Batal',
            ],
            'pick' => [
                'move' => 'Pindahkan catatan ini ke task:',
                'status' => 'Ubah status ":task" menjadi:',
                'project' => 'Pindahkan task baru ini ke project:',
                'new_task' => 'Task baru',
                'choose' => 'Pilih…',
            ],
            'notices' => [
                'queued' => 'Catatan diterima, sedang diproses.',
                'duplicate' => 'Catatan ini sudah diterima sebelumnya.',
                'empty' => 'Tulis catatannya dulu.',
                'invalid' => 'Catatan terlalu panjang. Pecah menjadi beberapa bagian.',
                'redaction_failed' => 'Catatan tidak bisa diperiksa dengan aman, jadi tidak disimpan. Coba kirim ulang, bisa dipecah.',
                'secrets' => ':count bagian berisi kunci atau password tidak disimpan.',
                'undone' => 'Catatan dibatalkan.',
                'undone_partial' => 'Catatan dibatalkan, tetapi task sudah berubah sejak itu sehingga statusnya tidak dikembalikan.',
                'done' => 'Sudah diperbarui.',
                'stale' => 'Task baru saja berubah dari tempat lain. Muat ulang lalu coba lagi.',
                'not_possible' => 'Perubahan itu tidak bisa dilakukan.',
            ],
        ],
        'login' => [
            'title' => 'Masuk',
            'heading' => 'Masuk ke ReportFlow',
            'intro' => 'Masuk dengan akun Telegram Anda. Hanya akun yang sudah terdaftar yang bisa masuk.',
            'not_configured' => 'Login Telegram belum dikonfigurasi. Hubungi pemilik sistem.',
        ],
    ],

    'lists' => [
        'active_tasks' => 'task aktif',
        'completed_tasks' => 'selesai',
        'project' => 'Project',
        'status' => 'Status',
        'waiting_for' => 'Menunggu',
        'last_activity' => 'Aktivitas terakhir',
        'timeline' => 'Riwayat',
        'recent' => 'Catatan terakhir',
        'none' => 'belum ada',
        'inbox_failed' => 'gagal diproses',
        'inbox_pending' => 'menunggu jawaban',
        'more' => '…dan :count lagi',
    ],

    'events' => [
        'created' => 'dibuat', 'status_changed' => 'status berubah', 'title_changed' => 'judul diubah',
        'moved' => 'dipindahkan', 'merged' => 'digabung', 'reopened' => 'dibuka lagi', 'undone' => 'dibatalkan',
    ],

    'waiting_reasons' => [
        'client' => 'klien', 'vendor' => 'vendor', 'api' => 'API', 'launch' => 'peluncuran', 'confirmation' => 'konfirmasi',
    ],
];
