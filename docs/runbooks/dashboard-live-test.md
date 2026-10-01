# Runbook: uji langsung dashboard (M5)

Tujuan: mencoba dashboard dan sinkronisasi dua arah dengan Telegram sungguhan. Aturan keamanan runbook
`telegram-live-test.md` berlaku (jangan mencetak token/secret, tunnel hanya ke `http://127.0.0.1:8081`).

## Persiapan
1. Ikuti `telegram-live-test.md` sampai webhook aktif dan user terdaftar (`reportflow:user:create <ID> --name=… --language=id`; tambahkan `/start` + nama project pertama).
2. Login dev (Telegram Login Widget butuh domain HTTPS tetap yang didaftarkan lewat `/setdomain` di BotFather, jadi untuk dev pakai link sekali pakai):
   ```bash
   docker compose exec app php artisan reportflow:login-link <ID_TELEGRAM>
   ```
   Buka URL yang dicetak (berlaku 5 menit, sekali pakai) di browser. Dashboard ada di `/admin`. Link tidak tersedia di production.
3. Setelah punya domain: isi `TELEGRAM_BOT_USERNAME` di `.env`, `/setdomain` di BotFather ke domain itu, lalu halaman `/admin/login` menampilkan widget.

## Skenario
1. **Catat dari dashboard**: "Catat Pekerjaan" → tulis catatan, Kirim. Dalam ≤ 5 detik hasil per item muncul (project → task, aktivitas, status). Klik Kirim dua kali cepat: hanya satu entri.
2. **Catat dari Telegram**: kirim catatan ke bot. Tanpa reload, entri tampil di dashboard (label Telegram) ≤ 5 detik setelah bot mengonfirmasi.
3. **Undo dari dashboard** untuk catatan Telegram: bubble konfirmasi di Telegram berubah menjadi "dibatalkan" tanpa tombol.
4. **Koreksi** (Pindah Task / Ubah Status / Ganti Project) dari dashboard: Telegram ikut tergambar ulang; `/task <id>` menunjukkan riwayatnya.
5. **Klarifikasi dua arah**: kirim catatan samar dari Telegram sampai bot bertanya. Jawab dari **Inbox** dashboard → bubble pertanyaan di Telegram menjadi "✅ Sudah dijawab lewat dashboard". Ulangi, jawab dari Telegram → Inbox dashboard menghilangkan item itu ≤ 5 detik. Jawab dari kedua sisi hampir bersamaan: hanya satu yang menulis.
6. **Task**: filter status/project/periode, buka detail (aktivitas + riwayat), ubah judul, ubah status (hanya opsi sah), pindahkan aktivitas. Buka halaman detail yang sama di dua tab, ubah di tab 1, lalu ubah di tab 2: tab 2 ditolak dengan pesan "baru saja diubah dari tempat lain" dan "Muat ulang" memperbaikinya.
7. **Inbox → Proses ulang** untuk catatan yang gagal (matikan AI sementara untuk membuatnya gagal).
8. **Pengaturan**: ganti bahasa/zona waktu, tambah/ubah nama/alias/arsipkan project; project yang diarsipkan tidak lagi ditawarkan ke asisten.
9. **Keamanan**: link login yang sama kedua kalinya → 403; akses `/admin` tanpa login → halaman login; keluar (menu pengguna) lalu tombol Back tidak menampilkan data.
