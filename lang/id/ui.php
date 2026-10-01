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
