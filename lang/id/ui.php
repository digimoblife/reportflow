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
    ],
];
