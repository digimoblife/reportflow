<?php

/*
| Teks tetap laporan (PDF/Markdown): formal dan netral, bukan persona Pak Carik (CLAUDE.md aturan 8).
*/

return [
    'title' => 'Laporan Bulanan :project — :period',
    'title_custom' => 'Laporan Aktivitas :project — :period',
    'generic_template' => 'Laporan Bulanan Umum',

    'sections' => [
        'overview' => 'Ringkasan Bulanan',
        'completed' => 'Pekerjaan Selesai',
        'detailed' => 'Rincian Aktivitas',
        'ongoing' => 'Pekerjaan Berjalan / Tertunda',
        'cross_month' => 'Aktivitas Lintas Bulan',
        'incidents' => 'Insiden / Kendala',
        'summary' => 'Kesimpulan',
    ],

    'labels' => [
        'project' => 'Project',
        'period' => 'Periode',
        'activities' => 'Aktivitas',
        'completed_tasks' => 'Task selesai',
        'ongoing_tasks' => 'Task berjalan',
        'waiting_tasks' => 'Task menunggu',
        'cross_month_tasks' => 'Task lintas bulan',
        'incidents' => 'Insiden / kendala',
        'task' => 'Task',
        'status' => 'Status',
        'completed_on' => 'Selesai pada',
        'started' => 'Mulai',
        'last_activity' => 'Aktivitas terakhir',
        'date' => 'Tanggal',
        'type' => 'Jenis',
        'description' => 'Uraian',
        'waiting_for' => 'Menunggu',
        'generated' => 'Dibuat',
        'none' => 'Tidak ada.',
    ],

    'statuses' => [
        'draft' => 'Draf', 'open' => 'Baru', 'in_progress' => 'Sedang dikerjakan', 'waiting' => 'Menunggu',
        'blocked' => 'Terhambat', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan',
    ],

    'activity_types' => [
        'request' => 'Permintaan', 'investigation' => 'Investigasi', 'development' => 'Pengembangan', 'configuration' => 'Konfigurasi',
        'bug_fix' => 'Perbaikan bug', 'testing' => 'Pengujian', 'deployment' => 'Deployment', 'communication' => 'Komunikasi',
        'research' => 'Riset', 'documentation' => 'Dokumentasi', 'milestone' => 'Milestone', 'blocker' => 'Hambatan',
        'resolution' => 'Penyelesaian masalah', 'follow_up' => 'Tindak lanjut', 'other' => 'Lainnya',
    ],

    'waiting_reasons' => [
        'client' => 'klien', 'vendor' => 'vendor', 'api' => 'API', 'launch' => 'peluncuran', 'confirmation' => 'konfirmasi',
    ],

    // Kalimat pengganti bila narasi AI ditolak backend (traceability): hanya angka dari data.
    'fallback' => [
        'overview' => 'Pada periode :period tercatat :activities aktivitas pada project :project: :completed task selesai, :ongoing task berjalan, :waiting task menunggu, dan :cross task lintas bulan.',
        'detailed' => 'Rincian aktivitas pada periode ini disajikan per task berikut.',
        'ongoing' => 'Task berikut masih berjalan atau menunggu pada akhir periode.',
        'summary' => 'Pada periode :period tercatat :activities aktivitas; :completed task diselesaikan dan :open task masih berjalan atau menunggu.',
    ],
];
