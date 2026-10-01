# Runbook: uji langsung laporan (M7)

Prasyarat: stack berjalan (`docker compose ps`: `worker-reports`, `gotenberg`, `app` sehat), user terdaftar dengan beberapa activity di bulan yang dilaporkan, login dashboard (`reportflow:login-link`, lihat `dashboard-live-test.md`).
Untuk narasi AI sungguhan isi `DEEPSEEK_API_KEY` dan `AI_PROVIDER=deepseek` (lewat `scripts/dev-env.py`, nilai tidak dicetak); dengan `AI_PROVIDER=fake` semua narasi jatuh ke kalimat tetap (`fallback`), fakta dan PDF tetap benar.

## Skenario
1. **Buat laporan:** menu *Laporan* → *Buat laporan* → pilih project, periode (satu bulan penuh = bulanan), bahasa. Baris muncul "Sedang dibuat", lalu "Dalam tinjauan" dalam beberapa detik.
2. **Pratinjau = PDF:** buka laporan, bandingkan pratinjau dengan PDF hasil unduhan (isi sama; PDF punya nomor halaman). Unduh juga `.md`: keduanya selalu ada bersama.
3. **Entri masih diproses:** kirim catatan dari Telegram lalu segera buat laporan: muncul pilihan *Tunggu selesai* / *Buat tanpa entri ini*. Pilih tunggu: laporan baru jadi setelah catatan selesai diproses dan memuatnya.
4. **Sunting manual:** ubah satu section → *Simpan sebagai versi baru* (v2). Tulis angka baru di teks: muncul tawaran *Simpan sebagai activity*.
5. **Edit via instruksi:** tulis instruksi di bawah section (mis. di Insiden: "tambahkan: API Shipment Tracking mati 25 menit pada 14 September"). Hasil: activity baru (`report_edit`, terlihat di halaman Task) dan versi baru yang memuat fakta itu. Coba fakta tanpa task yang jelas: ditolak dengan permintaan menyebut task.
6. **Versi:** bandingkan v1 dan v2 (diff per section). Setujui v2; ubah lagi: jadi v3 dalam tinjauan, v2 tetap *Disetujui* dan bisa diunduh.
7. **Konflik:** buka laporan di dua tab, simpan di tab 1, simpan di tab 2: tab 2 ditolak dengan pesan jelas dan teksnya tidak hilang.
8. **Keamanan:** salin URL unduhan, tunggu >10 menit atau ubah satu karakter: 403. Tautan unduh tidak bisa dibuka tanpa tanda tangan.

## RAM (VPS 4 GB, PRD §83)
```bash
docker stats --no-stream --format '{{.Name}} {{.MemUsage}}'
```
Terukur di mesin dev setelah satu laporan: `worker-reports` ≈ 60 MiB (batas 384M), `gotenberg` ≈ 500 MiB (batas 1G, satu Chromium), `worker` ≈ 50 MiB. Pantau lagi saat beberapa laporan dibuat berurutan; render PDF bersifat serial (satu job `reports` sekaligus).

## Jika PDF gagal
`docker compose logs --tail=50 worker-reports` (hanya kode `pdf_engine_*`, tanpa isi dokumen); `docker compose ps gotenberg`. Job dicoba ulang 3 kali; versi tanpa file menampilkan "File sedang disiapkan", `.md` tidak pernah tersedia tanpa PDF.
