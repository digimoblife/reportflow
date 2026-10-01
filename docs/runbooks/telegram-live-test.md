# Runbook: uji langsung Telegram (M2)

Tujuan: mencoba bot dengan Telegram sungguhan dari mesin dev lewat quick tunnel, tanpa mengekspos apa pun selain
satu path webhook. Bukan prosedur production (production: TLS + `TRUSTED_PROXIES`, lihat `docs/DECISIONS.md`).

## Aturan

- **Jangan mencetak atau menempelkan nilai** `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_SECRET_TOKEN`,
  `TELEGRAM_WEBHOOK_PATH`, `DEEPSEEK_API_KEY`, `DEV_USER_PASSWORD`. Ubah `.env` dengan `scripts/dev-env.py`
  (membuat nilai acak di dalam proses dan hanya melaporkan "terisi (N karakter)"), atau lewat editor untuk token.
  Nilai di argumen perintah terlihat di `ps` dan riwayat shell.
- **Tunnel hanya boleh menunjuk `http://127.0.0.1:8081`.** Jangan pernah port 80 atau layanan lain.
- **`docker compose logs nginx` dianggap sensitif**: baris error nginx memuat request line, yaitu path rahasia. Jangan
  ditempel ke chat/issue. `error_log` tidak dinaikkan levelnya; server block tunnel memakai `access_log off`.
- Log aplikasi (`storage/logs/laravel.log`) tidak memuat teks pesan, tetapi memuat ID Telegram pengirim tak terdaftar.

## Cara container mendapat `.env` (dan cara memuat ulang)

Tidak ada `env_file`. Sumbernya dua:

| Mekanisme | Siapa | Kapan berlaku | Cara memuat ulang |
|---|---|---|---|
| Laravel membaca `.env` dari bind mount `.:/var/www/html` | `app` (php-fpm), `worker`, `scheduler` | app: setiap request; worker/scheduler: **sekali saat proses mulai** | app: otomatis. worker/scheduler: `docker compose restart worker scheduler` |
| Interpolasi compose `${VAR}` dari `.env` | `nginx` (`TELEGRAM_WEBHOOK_PATH`), `postgres`, `redis`, `APP_ENV` untuk `app` | **saat container dibuat** | `docker compose up -d --force-recreate <service>`; `restart` **tidak** membaca ulang |

Perangkap lain: file yang di-bind-mount **satu per satu** (`docker/php/php.ini`, `docker/nginx/*.conf`, `tunnel.conf.template`)
menunjuk inode lama bila `git checkout`/`git pull` menggantinya; di container file itu hilang (mis. `memory_limit` kembali 128M dan
suite test kehabisan memori). Setelah pindah branch: `docker compose up -d --force-recreate app worker scheduler nginx`.

Akibat: setelah mengubah token/secret/`APP_URL`, jalankan `docker compose restart worker scheduler`. Setelah mengubah
`TELEGRAM_WEBHOOK_PATH`, jalankan juga `docker compose up -d --force-recreate nginx`. `APP_ENV` dari compose mengalahkan `.env`
hanya untuk `app`.

## Jaringan: worker harus punya egress

`reportflow-internal` bersifat `internal: true` (tanpa internet). Worker yang mengirim konfirmasi ke Telegram (dan nanti ke AI)
juga tergabung di `reportflow-public`; postgres dan redis hanya di jaringan internal. Tanpa itu, pesan "⏳" tidak pernah
diedit (DNS `api.telegram.org` gagal di worker, job pengiriman berulang tiap ~12 dtk sampai `retryUntil`). Ditemukan di uji
langsung pertama; dijaga `tests/Unit/Infra/NginxTunnelTest.php`.

## Persiapan (sekali)

```bash
scripts/dev-env.py gen TELEGRAM_BOT_SECRET_TOKEN 32        # hanya jika kosong
scripts/dev-env.py gen TELEGRAM_WEBHOOK_PATH 24            # hanya jika kosong
scripts/dev-env.py default TELEGRAM_CLIENT http
scripts/dev-env.py gen DEV_USER_PASSWORD 24 --force        # lalu: docker compose exec app php artisan db:seed
# token bot: tempel sendiri ke .env lewat editor (TELEGRAM_BOT_TOKEN=...)
docker compose up -d --force-recreate nginx && docker compose restart worker scheduler
bash scripts/verify-tunnel-port.sh                         # semua harus 404, "ALL OK"
```

`db:seed` (lokal) memperbarui hash password user dev; tanpa itu password lama di database tetap berlaku.

## Menjalankan uji

1. `bash scripts/verify-tunnel-port.sh` harus `ALL OK`. Kalau tidak, **berhenti**.
2. `cloudflared tunnel --no-autoupdate --url http://127.0.0.1:8081` (satu instance). Catat URL `https://….trycloudflare.com`.
3. `scripts/dev-env.py put APP_URL https://….trycloudflare.com`, lalu `docker compose restart worker scheduler`.
4. `docker compose exec app php artisan telegram:set-webhook --dry-run` → url ter-mask, secret `[hidden]`,
   `allowed_updates` = `message`, `edited_message`, `callback_query` (tombol). Lalu tanpa `--dry-run`.
5. Verifikasi tanpa mencetak token/path (token tidak masuk argumen proses):
   ```bash
   docker compose exec -T app php artisan tinker --execute='
   $r = Illuminate\Support\Facades\Http::acceptJson()->get("https://api.telegram.org/bot".config("telegram.token")."/getWebhookInfo")->json("result");
   $u = parse_url($r["url"] ?? ""); echo json_encode(["host"=>$u["host"]??null,"panjang_path"=>strlen($u["path"]??"")-1,"pending"=>$r["pending_update_count"]??null,"allowed"=>$r["allowed_updates"]??null,"error"=>$r["last_error_message"]??null]),PHP_EOL;'
   ```
6. `php artisan telegram:sync-commands --dry-run`, lalu tanpa `--dry-run`.
7. Kirim satu pesan ke bot; ID Telegram ada di `storage/logs/laravel.log` (`unregistered_sender`).
   `php artisan reportflow:user:create <ID> --name="…" --language=id` (tanpa `--email` agar akun kosong dan onboarding bisa diuji).

## Skenario tombol dan koreksi (M4)

Setelah webhook aktif dan user terdaftar (`reportflow:user:create`, lalu `/start` dan nama project pertama; tambah project/task lewat pesan atau seeder).
Kirim tiap catatan sebagai pesan baru, amati bubble konfirmasi:

1. **Catatan jelas**: "Harbor: bug invoice sudah diperbaiki". Konfirmasi memuat project → task, aktivitas, status; tombol Undo / Pindah Task / Ubah Status / Ganti Project.
2. **Undo**: tekan ↩️ Undo → bubble menjadi "dibatalkan", tanpa tombol; `/tasks` menunjukkan status kembali.
3. **Pindah Task / Ubah Status / Ganti Project** (task baru saja): pilih dari daftar, tekan ⬅️ Kembali untuk batal. Ubah Status hanya menawarkan status yang sah.
4. **Pertanyaan klarifikasi**: catatan samar ("yang kemarin itu sudah beres") → satu pesan pertanyaan per item; jawab Ya / Task baru / Batal.
5. **Lebih dari 5 item** dalam satu pesan → tidak ada yang tersimpan, bot minta dipecah.
6. **Edit pesan** yang sudah diproses → bot menawarkan Proses ulang / Biarkan.
7. **Reply koreksi**: balas bubble konfirmasi dengan "bukan, itu untuk task X" → hasil lama dibatalkan, hasil baru dikonfirmasi, bubble lama menjadi "dibatalkan".
8. **Command**: `/projects`, `/project <nama>`, `/tasks` (tombol halaman), `/task <id|kata>`, `/inbox` (tombol Proses ulang), `/undo`.
9. Tekan tombol dua kali cepat, dan tombol milik pesan lama setelah Undo: tidak boleh ada efek ganda.

Callback tidak masuk bila webhook lama masih memakai `allowed_updates` lama: jalankan ulang `telegram:set-webhook`.

## Pembacaan database (hanya baca)

```bash
TG=<ID_TELEGRAM>
dbq() { docker compose exec -T postgres sh -c 'PGOPTIONS="-c default_transaction_read_only=on" psql -U "$POSTGRES_USER" -d reportflow -P pager=off' <<< "$1"; }
ME="(select id from users where telegram_user_id=$TG)"
LAST="select id,status,error,reply_message_id is not null as ada_balasan,edited_at is not null as diedit,length(text) as panjang,attachments from inbound_messages where user_id=$ME order by id desc limit 5;"
```

## Cleanup (setelah selesai atau bila ada anomali)

1. `deleteWebhook` (Bot API) dan pastikan `getWebhookInfo` menunjukkan `url` kosong.
2. Hentikan cloudflared; pastikan tidak ada proses tunnel tersisa.
3. `scripts/dev-env.py put APP_URL http://localhost`.
4. Rotasi: `scripts/dev-env.py gen TELEGRAM_BOT_SECRET_TOKEN 32 --force` dan `gen TELEGRAM_WEBHOOK_PATH 24 --force`.
5. `docker compose up -d --force-recreate nginx && docker compose restart app worker scheduler`; semua harus `healthy`.
6. Hapus dari chat Telegram pesan yang berisi credential palsu. Bila ada anomali keamanan: `/revoke` di @BotFather
   dan isi token baru.

Curiga secret bocor: rotasi secret (langkah 4 lalu `telegram:set-webhook` ulang). Curiga path bocor: rotasi path + recreate nginx.
Curiga token bocor: `/revoke` di @BotFather.
