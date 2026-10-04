# Deploy dengan Docker (staging/produksi)

Setup ini menjalankan aplikasi lewat 3 container: `app` (Nginx + PHP-FPM jadi
satu), `scheduler` (cron internal buat `finance:recompute` harian), dan `db`
(MySQL 8 — **wajib** versi 8, lihat catatan di `docker/php/Dockerfile`).

## Yang dibutuhkan di VPS

- Docker Engine + Docker Compose plugin (`docker compose version` harus jalan).
- Minimal ~1GB RAM kosong (MySQL + PHP + Nginx sekaligus).
- Domain yang sudah diarahkan (A record) ke IP VPS — kalau mau pakai HTTPS.

## Langkah pertama kali

```bash
git clone <url-repo-ini> erp
cd erp
cp .env.docker.example .env
```

Edit `.env`, minimal isi:
- `APP_URL` — domain/IP staging sungguhan.
- `DB_PASSWORD` — password database aplikasi (bebas, hindari karakter aneh
  seperti `$ " ' \` biar tidak bermasalah waktu di-parse shell).
- `MYSQL_ROOT_PASSWORD` — **beda** dari `DB_PASSWORD` di atas.

Generate `APP_KEY` (WAJIB, aplikasi tidak akan jalan tanpa ini):

```bash
docker compose run --rm app php artisan key:generate --show
```

Salin hasilnya (`base64:...`) ke baris `APP_KEY=` di `.env`.

Jalankan:

```bash
docker compose up -d --build
```

Tunggu ~1-2 menit (build image pertama kali agak lama karena `npm run build`
+ `composer install`). Cek statusnya:

```bash
docker compose ps
docker compose logs -f app
```

Kalau `RUN_MIGRATIONS=true` di `.env` (default), migrasi database jalan
otomatis saat container `app` start pertama kali. Cek berhasil dengan:

```bash
docker compose exec app php artisan migrate:status
```

Buat user pertama (superadmin) lewat tinker, atau jalankan seeder RBAC kalau
mau pola role yang sama seperti development:

```bash
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder
docker compose exec app php artisan db:seed --class=RealUsersSeeder
```

**PENTING**: `RealUsersSeeder` membuat 12 user dengan password default
`password` dan email `@erp.local` (dummy) — ganti passwordnya lewat halaman
Kelola Role begitu bisa login, atau edit dulu isi `database/seeders/RealUsersSeeder.php`
sebelum dijalankan kalau mau langsung pakai email/password asli.

Buka `http://<domain-atau-IP-VPS>:8080` (atau port lain kalau `APP_PORT`
diubah di `.env`).

## Kalau perlu HTTPS / domain asli di depan

Container `app` cuma dengar di port internal (default 8080 di host). Untuk
HTTPS, pasang reverse proxy DI LUAR compose ini — dua pilihan paling umum:

- **Nginx/Caddy langsung di VPS** (di luar Docker) yang proxy ke
  `127.0.0.1:8080`, sertifikat lewat Certbot/Caddy otomatis.
- **Traefik/Nginx Proxy Manager** sebagai container terpisah kalau mau semua
  di dalam Docker juga.

Tidak disertakan di compose ini supaya setupnya tetap sederhana dan tidak
mengasumsikan domain/DNS sudah siap — tambahkan belakangan setelah aplikasi
jalan.

## Update / redeploy (setelah ada perubahan kode)

```bash
git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force
```

## Backup database

```bash
docker compose exec db mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE" > backup.sql
```

(Idealnya dijadwalkan cron DI LUAR container, atau pindahkan ke object
storage — jangan cuma disimpan di disk VPS yang sama.)

## Kalau ada kendala, ini yang perlu dicek/ditanyakan

1. **Versi Docker & OS VPS** — `docker --version`, `docker compose version`,
   dan `cat /etc/os-release`. Kalau Docker belum terpasang, itu langkah
   pertama (`curl -fsSL https://get.docker.com | sh` untuk Ubuntu/Debian).
2. **Port 8080 (atau `APP_PORT`) sudah dipakai layanan lain?** Cek dengan
   `sudo ss -tlnp | grep 8080` sebelum `docker compose up`.
3. **RAM/disk VPS** — `free -h` dan `df -h`. Build pertama butuh ruang
   sementara ekstra buat `node_modules`/layer build (dibuang otomatis
   setelah build selesai, tapi butuh cukup disk saat prosesnya).
4. Kalau container `db` tidak pernah "healthy" — cek `docker compose logs db`,
   biasanya karena `MYSQL_ROOT_PASSWORD` kosong/tidak diisi di `.env`.
5. Kalau halaman blank/500 — cek `docker compose logs app`, dan pastikan
   `APP_KEY` sudah diisi (penyebab paling umum).

Kalau ada error spesifik, salin pesan errornya (dari `docker compose logs`)
— itu lebih membantu saya bantu debug daripada deskripsi umum.
