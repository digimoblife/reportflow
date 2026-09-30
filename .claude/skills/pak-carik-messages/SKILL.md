---
name: pak-carik-messages
description: Menulis atau mengubah template pesan bot Telegram dan teks UI dashboard dengan persona Pak Carik (lang/id dan lang/en). Gunakan skill ini kapan pun menambah pesan konfirmasi, reminder, error, onboarding, peringatan keamanan, atau teks tombol, dan saat user menyebut persona, gaya bahasa bot, Pak Carik, lang file, atau copywriting bot, walaupun tidak menyebut skill ini.
---

# Pesan Pak Carik

Rujukan: PRD §19 (persona), §20 (BotFather), §21 (tombol koreksi).

## Prinsip
- Pesan sistem = **template tetap** di `lang/id/*.php` dan `lang/en/*.php` dengan variabel. **Bukan dibuat AI saat runtime.**
- Setiap jenis pesan punya 2–3 variasi; dipilih acak lewat helper yang sama (jangan buat mekanisme baru per pesan).
- Persona hanya di Telegram dan teks antarmuka dashboard. **Jangan dipakai di laporan (PDF/MD).**

## Gaya (bahasa Indonesia)
- Santun, telaten, sedikit nyleneh, tidak kaku. Tidak memarahi atau menyindir.
- Bahasa santai, **maksimal 1–2 kata Jawa per pesan** (contoh: *nggih, nuwun sewu, rampung, monggo*).
- Singkat. Info penting (project, task, status, angka, tanggal, pilihan tombol) ditulis jelas dan terstruktur.
- Humor tidak boleh mengaburkan informasi.
- **Error dan peringatan keamanan tetap lugas**, walau bernada persona.
- Ikut senang saat pekerjaan selesai; pengingat harus sopan.

## Gaya (English)
- Santai dan ramah, **tanpa sisipan bahasa Jawa**. Bot membalas dalam bahasa yang dipakai user.

## Cara menambah pesan
1. Cari kunci yang sudah ada di `lang/id/` dan `lang/en/`; jangan menduplikasi.
2. Buat kunci baru dengan 2–3 variasi di **kedua** bahasa. Nama kunci: `<area>.<situasi>` (contoh: `worklog.recorded`, `reminder.daily`).
3. Gunakan placeholder Laravel (`:project`, `:task`, `:status`, `:count`), bukan string digabung di kode.
4. Tambahkan test: semua kunci `id` ada padanannya di `en`, placeholder identik di semua variasi, tidak ada
   variasi kosong, dan tidak ada lebih dari 2 kata Jawa dari daftar yang diizinkan per variasi.
5. Pesan dengan tombol: teks tombol jelas dan literal (Undo, Pindah Task, Ubah Status, Ganti Project, Tunggu Selesai,
   Generate Tanpa Entri Ini, dst). Jangan diberi gaya nyleneh.

## Contoh acuan (dari PRD §19)
| Situasi | Contoh |
|---|---|
| Konfirmasi catatan | "Nggih, sudah saya catat. 9Club → Premium Domain Acquisition, status: sedang dikerjakan." |
| Task selesai | "Rampung! Task domain saya tutup, nggih." |
| Ambigu | "Sebentar, saya cek arsip dulu. Yang domain itu yang pembelian atau konfigurasi?" |
| Undo | "Siap, catatan tadi saya batalkan. Arsip kembali seperti semula." |
| Credential | "Nuwun sewu, pesan ini ada password/kunci rahasianya. Bagian itu tidak saya simpan, nggih." |
| Error | "Waduh, catatan ini belum bisa saya proses. Tenang, pesannya sudah saya simpan, nanti saya coba lagi." |

## Yang dihindari
- Menyindir user karena lupa mencatat.
- Emoji berlebihan (⏳ untuk "sedang mencatat" dan tombol sudah cukup).
- Menyebut "AI" atau "model" ke user tanpa perlu (PRD memilih nama Carik justru agar tidak menonjolkan AI).
