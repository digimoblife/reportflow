---
name: prompt-eval
description: Jalankan dan tafsirkan Evaluation Dataset ReportFlow setiap kali prompt AI, JSON schema output, candidate retrieval, aturan confidence, atau AIService diubah. Gunakan skill ini kapan pun user menyebut prompt, prompt_version, akurasi task matching, threshold confidence, eval, evaluation dataset, atau kesalahan interpretasi AI, walaupun tidak menyebut kata "eval" secara eksplisit.
---

# Prompt Eval (ReportFlow AI)

Mengukur apakah perubahan pada bagian AI membuat hasil lebih baik atau lebih buruk. Rujukan: PRD §13, §54, §73, §75, §76.

## Kapan dipakai
- Mengubah file di `resources/prompts/**`
- Mengubah JSON schema output, candidate retrieval, atau logika confidence + sinyal deterministik
- Mengganti model/provider di `AIService`
- Menambah kasus baru dari tabel `corrections`

## Aturan
1. **Jangan mengubah prompt di tempat.** Salin ke versi baru: `resources/prompts/<nama>/v<N+1>.md`, naikkan `prompt_version`.
2. Dataset ada di `tests/Eval/data/` (di-gitignore, bisa berisi data klien asli). **Jangan mencetak isi pesan
   dataset ke output panjang, jangan commit, jangan salin ke dokumen lain.** Laporkan angka dan ID kasus saja.
3. Eval memanggil provider sungguhan hanya bila user menjalankannya secara eksplisit. Untuk CI gunakan
   `FakeAiProvider` dan dataset anonim kecil (`tests/Eval/data-sample/`).

## Langkah
1. Jalankan baseline pada versi prompt saat ini: `php artisan eval:run --prompt=<nama>@<versi lama>`.
2. Buat versi baru, jalankan lagi: `php artisan eval:run --prompt=<nama>@<versi baru>`.
3. Bandingkan per metrik dengan target PRD §73:
   | Metrik | Target |
   |---|---|
   | Worklog extraction | ≥ 90% |
   | Project identification | ≥ 95% |
   | Task matching | ≥ 90% |
   | Date extraction | ≥ 95% |
4. Bandingkan juga per kategori kasus sulit (referensi samar, multi-item, lintas project, backdated, campuran
   bahasa, reopen task Completed). Regresi di satu kategori tetap harus dilaporkan walau rata-rata naik.
5. Untuk threshold confidence (0.90 / 0.70): hitung ketepatan per rentang confidence dan cari titik potong yang
   menjaga User Correction Rate < 15%. Ingat confidence AI **tidak terkalibrasi** (§13).

## Format laporan ke user
- Versi yang dibandingkan, jumlah kasus, tanggal jalan.
- Tabel metrik: baseline vs baru vs target (+ selisih).
- Daftar ID kasus yang berubah (membaik / memburuk), tanpa isi pesan.
- Rekomendasi: pakai versi baru / tahan / iterasi lagi, disertai alasan singkat.
- Jika ada kasus yang kelihatannya salah label di dataset, tandai untuk direview manusia; jangan diubah sendiri.

## Jika `eval:run` belum ada
Berarti M3 belum selesai. Beri tahu user dan tawarkan membuat harness sesuai PRD §75 terlebih dahulu.
