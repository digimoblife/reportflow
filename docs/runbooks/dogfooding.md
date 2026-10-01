# Runbook: dogfooding satu siklus laporan bulanan penuh

Syarat DoD M9 dan gerbang menuju Phase 3. Tujuannya membuktikan angka PRD §73–§77 dengan pemakaian nyata, bukan dengan data uji.
Yang menjalankan adalah Anda; alatnya sudah ada (halaman **Kesehatan**, tabel `corrections`, `eval:run`).

## Aturan main

- Satu bulan kalender penuh. Catat **semua** pekerjaan lewat Pak Carik (Telegram atau dashboard), tanpa jalan pintas ke database.
- Jangan membetulkan data lewat SQL atau tinker. Kalau salah, pakai Undo/koreksi; itu yang diukur.
- Setiap kali bot salah, jangan hanya memperbaiki: catat di tabel di bawah (kategori + contoh anonim). Itu bahan prompt/eval berikutnya.
- Data klien nyata hanya bila poin A di `go-live-checklist.md` sudah terjawab. Contoh yang dibagikan harus anonim.

## Selama bulan berjalan (mingguan, 10 menit)

1. Buka **Kesehatan** (rentang 7 hari). Perhatikan: User Correction Rate, p95 waktu proses, AI gagal, antrean, pengingat (terkirim vs ditindaklanjuti).
2. Tinjau koreksi minggu itu: `Inbox` dan riwayat task. Kelompokkan: project salah, task salah, tanggal salah, status salah, duplikat, lainnya.
3. Jangan mengubah prompt di tengah bulan. Ubah = versi baru + `eval:run`, dan itu merusak perbandingan. Kumpulkan dulu.
4. Setiap alert yang datang: tangani dengan `incident.md` dan catat penyebabnya.

## Akhir bulan: siklus laporan (ukur §77)

1. Mulai stopwatch. Minta laporan bulanan dari Telegram (`/report`) atau dashboard.
2. Tunggu atau pilih "Generate Tanpa Entri Ini" bila ada entri tertunda; baca tiap section, edit bila perlu, setujui.
3. Berhenti saat PDF + MD terunduh dan disetujui. **Target: < 5 menit** untuk bulan normal (PRD §77). Catat waktunya dan apa yang menyita waktu.
4. Uji akhir: ubah satu catatan di bulan itu sesudah disetujui → laporan harus menjadi `outdated` dan menawarkan versi baru (§43).

## Lembar penilaian

Isi dari halaman Kesehatan dengan rentang 30 hari pada hari terakhir.

| Kriteria | Target | Sumber | Hasil | Lolos |
|---|---|---|---|---|
| User Correction Rate | < 15% (§76) | Kesehatan → koreksi | | |
| Waktu catatan normal p95 | < 10 dtk (§73) | Kesehatan → catatan | | |
| Sukses pembuatan laporan | ≥ 95% (§73) | Kesehatan → laporan | | |
| Sukses PDF | ≥ 99% (§73) | Kesehatan → laporan | | |
| Waktu menyusun laporan bulanan | < 5 menit (§77) | stopwatch | | |
| Entri duplikat | 0 (§74) | pantauan manual | | |
| Laporan dibuat saat ada entri `processing` tanpa persetujuan | 0 (§74) | pantauan manual | | |
| Preview dashboard = PDF; PDF selalu disertai MD (§74) | selalu | laporan bulan ini | | |
| Alert palsu / alert terlewat | 0 / 0 | catatan insiden | | |
| Restore test | lolos | `backup-restore.md` | | |

Akurasi ekstraksi/project/task/tanggal (§73: ≥ 90 / 95 / 90 / 95%) diukur offline: ekspor kasus koreksi bulan ini ke dataset anonim
(`docs/runbooks/evaluation-dataset.md`) dan jalankan `eval:run` (provider fake untuk struktur; `--provider=deepseek --send-to-deepseek`
hanya oleh Anda, atas data yang boleh dikirim).

## Keputusan

- Semua baris lolos → catat tanggal dan hasil di `docs/DECISIONS.md`; Phase 3 boleh direncanakan ulang berdasarkan tabel `corrections`.
- Ada yang gagal → tulis penyebab dan perbaikan (prompt/eval, UX, infrastruktur), perbaiki, dan ulangi untuk metrik yang gagal
  (tidak harus sebulan penuh bila yang gagal adalah satu metrik teknis yang bisa diukur ulang dalam sepekan).
