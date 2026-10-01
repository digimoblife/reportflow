# Runbook: uji langsung laporan lewat Telegram (M8)

Prasyarat: `telegram-live-test.md` selesai (webhook aktif, user terdaftar, `worker` dan `worker-reports` sehat), beberapa catatan di bulan yang dilaporkan, `DEEPSEEK_API_KEY` terisi bila ingin narasi AI sungguhan (tanpa itu, narasi memakai kalimat tetap). Tombol "Buka di Dashboard" hanya muncul bila `APP_URL` memakai https dan domain publik.

## Skenario
1. **Mulai:** kirim `/report` (atau `/report 2026-09`). Bot membalas "sedang dibuat", lalu mengirim ringkasan draft dengan tombol **Approve / Regenerate / Edit via instruksi / Cancel** dan menyusul file PDF. Beberapa project: pilih project dulu.
2. **Entri tertunda:** kirim catatan lalu segera `/report`: bot bertanya **Tunggu selesai / Tanpa entri ini**. Pilih tunggu: draft muncul setelah catatan selesai dan memuatnya.
3. **Edit via instruksi:** *Edit via instruksi* → pilih section → tulis, mis. "tambahkan: API Tracking mati 25 menit tanggal 14". Bot menyimpan fakta sebagai activity (terlihat di `/task` task itu) dan mengirim versi baru. Coba fakta tanpa task yang jelas: ditolak dengan permintaan menyebut task. Coba kata kunci rahasia palsu di instruksi: tidak tersimpan.
4. **Approve:** tekan *Approve*: bubble menjadi "disetujui" dan PDF + Markdown terkirim sebagai dokumen. Tombol lama di review sebelumnya setelah itu menjawab "sudah berubah".
5. **Late entry:** setelah approve, catat pekerjaan bertanggal dalam periode (mis. "kemarin ..."). Dalam ≤10 menit (atau jalankan `docker compose exec app php artisan reports:check-drift`) bot mengirim "Laporan ... sudah disetujui, tetapi ada N perubahan. Buat versi baru?" sekali saja. *Abaikan* menutupnya; *Buat Versi Baru* membuat versi 2 dalam tinjauan (versi 1 tetap utuh). Dashboard menampilkan status Kedaluwarsa dan banner yang sama.
6. **Pengingat bulanan:** `/reminder monthly 14:30` (atur beberapa menit ke depan, **hanya bekerja pada hari terakhir bulan**; untuk uji di hari lain pakai tes otomatis atau ubah jam sistem di lingkungan lokal). Pesan memuat ringkasan per project dan tombol *Buat Laporan / Tinjau Aktivitas / Nanti*. `/reminder` menampilkan status harian dan bulanan.
7. **Dashboard ↔ Telegram:** di halaman laporan dashboard tekan *Kirim ke Telegram*; ubah/approve dari dashboard lalu tekan tombol di review Telegram lama: dijawab "sudah berubah" dan diganti review terbaru.
8. **Daftar:** `/reports` menampilkan laporan terbaru; `/review` mengirim ulang draft yang menunggu.
