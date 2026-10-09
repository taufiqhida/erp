# Panduan Deploy & Operasional ERP

Panduan ini merangkum cara menjalankan, memperbarui, memulihkan, dan memindahkan ERP di VPS.
Untuk backup & pemulihan database secara rinci, lihat [`scripts/BACKUP-VPS.md`](../scripts/BACKUP-VPS.md).

> Semua perintah `docker compose ...` dijalankan **di VPS (lewat SSH)**, di folder `/var/www/erp`.
> Jangan menempel password/kunci ke chat atau repository.

## 1. Gambaran susunan

```
Browser ──HTTPS──▶ nginx host (aaPanel)  ──▶  127.0.0.1:8083  ──▶  kontainer `erp-app`
                   (SSL, reverse proxy)                              (nginx + PHP-FPM + scheduler)
                                                                         │
                                                                         ▼
                                                            MySQL (kontainer terpisah)
```

- **Satu kontainer** `erp-app` (nginx + PHP-FPM + scheduler lewat supervisord). Image: `php:8.4-fpm-alpine`.
- Port hanya terbuka di `127.0.0.1:8083`. Akses publik **hanya** lewat nginx host + HTTPS.
- Upload dan log disimpan di volume Docker (`storage_app`, `storage_logs`) supaya bertahan saat rebuild.
- Konfigurasi ada di `.env` (tidak masuk Git). Contoh: `.env.docker.example`.
- Staging memakai MySQL kontainer milik proyek lain (`ecommerce-db`, jaringan `shared-db`) dengan
  database dan user ERP sendiri. **Production sebaiknya memakai database sendiri**
  (`docker-compose.standalone.yml`).

## 2. Deploy pembaruan (rutin)

Dikerjakan di VPS. Lakukan di luar jam kerja dan kabari tim bila ada perubahan besar.

```bash
cd /var/www/erp

# 1. Catat versi yang sedang berjalan (untuk rollback)
git rev-parse --short HEAD

# 2. Backup dulu (wajib bila ada migrasi baru)
sudo bash /var/www/erp/scripts/backup-vps.sh

# 3. Ambil kode terbaru dan bangun ulang
git pull
docker compose up -d --build

# 4. Cek migrasi (jalan otomatis bila RUN_MIGRATIONS=true di .env)
docker compose exec app php artisan migrate:status | tail -10
# bila masih Pending:
docker compose exec app php artisan migrate --force

# 5. Bersihkan cache
docker compose exec app php artisan optimize:clear

# 6. Cek singkat
docker compose logs --tail=30 app
```

Lalu buka alamat ERP dengan **Ctrl+F5**, login, dan coba satu-dua halaman utama.

### Aturan migrasi database
- Perubahan yang hanya **menambah** (kolom, tabel, index) aman.
- Perubahan yang **menghapus/mengubah tipe** harus diuji dulu di lokal dengan salinan data asli.
- Selalu backup sebelum deploy yang membawa migrasi.

## 3. Rollback

Bila setelah deploy ada masalah serius:

```bash
cd /var/www/erp
git checkout <commit-lama>          # commit yang dicatat di langkah 1
docker compose up -d --build
```

Bila migrasi ikut bermasalah, pulihkan database dari backup yang diambil sebelum deploy
(lihat bagian C–D di `scripts/BACKUP-VPS.md`). Setelah stabil, kembali ke cabang utama dengan
`git checkout main` dan perbaiki masalahnya di lokal sebelum deploy ulang.

## 4. Pengaturan nginx host (aaPanel)

Situs ERP berupa **reverse proxy** ke `http://127.0.0.1:8083`. Pada konfigurasi proxy pastikan ada:

```
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto https;   # WAJIB — tanpa ini aset dimuat lewat http:// dan halaman blank
client_max_body_size 50M;                   # batas unggahan
```

Di `.env`: `APP_URL=https://<domain>`, `TRUSTED_PROXIES=*`, `SESSION_SECURE_COOKIE=true`.

> Situs di aaPanel tidak perlu PHP, database, atau FTP — semuanya ada di dalam kontainer.

> **JANGAN aktifkan Cache pada reverse proxy aaPanel.** Cache nginx memakai alamat halaman sebagai kunci (bukan siapa pengguna),
> jadi halaman milik satu pengguna (termasuk pengalihan ke /login) bisa tersaji ke orang lain. Gejalanya: jendela incognito baru
> langsung masuk tanpa login, pilihan tema tidak bertahan, halaman kosong atau pengalihan ke /login yang acak.
> Cek: `curl -s -o /dev/null -D - https://<domain>/beranda | grep -iE "^(HTTP|x-cache|age)"` — tidak boleh ada baris `x-cache`/`age`.
> Perbaikan: aaPanel → Website → situs → Reverse proxy → Edit → matikan Cache (atau ganti `proxy_cache cache_one;` jadi
> `proxy_cache off;` di berkas proxy-nya), reload nginx, lalu keluarkan semua sesi:
> `docker compose exec app php artisan tinker --execute="DB::table('sessions')->truncate();"`

## 5. Ganti domain / alamat

1. DNS: record A ke IP VPS (awalnya **DNS only**, bukan proxied).
2. aaPanel: situs baru + reverse proxy ke `127.0.0.1:8083` + SSL Let's Encrypt.
3. `.env`: ubah `APP_URL`, lalu
   ```bash
   docker compose up -d --force-recreate && docker compose exec app php artisan config:clear
   ```
4. Domain lama: alihkan (redirect 301) dengan baris `return 301 https://<domain-baru>$request_uri;`
   pada blok `server` HTTPS domain lama, lalu `nginx -t && nginx -s reload`.
   Setelah DNS lama dihapus, hapus file konfigurasi dan sertifikatnya (`certbot delete --cert-name ...`).
5. Semua pengguna perlu login ulang sekali (sesi terikat domain).

Tidak perlu rebuild image untuk ganti domain.

## 6. Memindahkan ke server baru

Pada dasarnya = deploy di server baru + memulihkan backup.

1. Server baru: amankan (SSH key, firewall, fail2ban), pasang Docker, `git clone`, siapkan `.env`.
   **Pakai `APP_KEY` yang sama** dengan server lama (simpan di password manager, bukan hanya di server).
2. Turunkan TTL DNS beberapa jam sebelumnya. Minta tim berhenti mengisi data saat pindah.
3. Di server lama: dump database + salin volume `storage_app` (lihat bagian C `BACKUP-VPS.md`).
4. Di server baru: pulihkan database dan folder unggahan, jalankan `docker compose up -d --build`.
5. Uji, lalu arahkan record DNS ke IP server baru. Biarkan server lama beberapa hari sebelum dimatikan.

## 6b. Menyiapkan production baru (urutan awal)

Dikerjakan SEKALI di server production yang baru. Perintah `docker compose ...` dijalankan di folder `/var/www/erp`.

1. Deploy kode dan pastikan semua migrasi `Ran` (`migrate:status`).
2. Isi role dan izin (aman diulang; jangan jalankan seeder lain):
   ```bash
   docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force
   ```
3. Buat akun superadmin pertama. Password sementara tampil SEKALI dan wajib diganti saat login pertama:
   ```bash
   docker compose exec app php artisan users:buat-superadmin email@perusahaan.com --nama="Nama Lengkap"
   ```
3b. (Opsional) Isi master baku (Sumber Lead dan Template Pemberkasan). Aman diulang; yang sudah ada tidak ditimpa:
   ```bash
   docker compose exec app php artisan db:seed --class=MasterAwalSeeder --force
   ```
   Datanya di `database/data/master-awal.json` (format yang sama dengan Pindah Master Data, jadi bisa juga diimpor lewat halamannya dengan pratinjau).
4. Login, lalu **Pengaturan → Pindah Master Data**: impor file master dari staging (Periksa dulu → Terapkan).
5. Unggah ulang file yang tidak ikut impor: logo dan kop surat (Profil Developer), template Word (Template Surat).
6. Buat akun staf lewat **Manajemen Role** (password sementara, wajib ganti). Nama dan email akun hanya bisa diubah superadmin (tombol **Ubah Data**); pengguna tidak bisa mengubahnya sendiri di Profil.
7. Baru **Import Kavling**, lalu **Import Konsumen**.

> JANGAN menjalankan `db:seed` tanpa `--class` (berisi data demo) dan jangan `RealUsersSeeder` (12 akun berpassword bawaan).
> Seeder role hanya perlu diulang bila ada izin baru di kode. Migrasi berjalan sekali dan tidak menimpa ubahan di layar.

### Memindahkan master data (staging → production)
- Di staging: Pengaturan → Pindah Master Data → pilih master → **Unduh file master** (`.json`).
- Di production: bagian Impor → pilih file → **Periksa dulu** (belum mengubah apa pun) → cek ringkasan → **Terapkan impor**.
- Pencocokan memakai nama (huruf besar/kecil diabaikan). Yang sudah ada dilewati kecuali "Perbarui yang sudah ada" dinyalakan.
- Akun, proyek, kavling, konsumen, transaksi tidak ikut. File berisi nomor rekening perusahaan bila Profil Developer dicentang: simpan hati-hati, hapus setelah dipakai.

## 7. Perintah rutin yang berguna

```bash
# Paksa semua pengguna membuat password baru saat login berikutnya
docker compose exec app php artisan users:wajib-ganti-password --all

# Status migrasi
docker compose exec app php artisan migrate:status

# Bersihkan cache aplikasi / konfigurasi
docker compose exec app php artisan optimize:clear

# Log terbaru
docker compose logs --tail=50 app

# Cek DNS
nslookup <domain>
```

## 8. Masalah yang pernah terjadi

| Gejala | Penyebab | Perbaikan |
|---|---|---|
| Halaman login blank putih, Console: *Mixed Content* | Proxy tidak mengirim `X-Forwarded-Proto` | Tambah header di bagian 4, lalu `config:clear` |
| Gambar unggahan 404 (nginx) | Aturan ekstensi gambar menangkap `/media/...` | Sudah diperbaiki di `docker/nginx/default.conf` (blok `location ^~ /media/`) — `git pull` + build ulang |
| Tabel `pengumuman` tidak ada | Migrasi belum dijalankan | `docker compose exec app php artisan migrate --force` |
| SSL gagal terbit | DNS belum menyebar / record masih *proxied* | Pastikan DNS only, tunggu, ulangi |
| Incognito langsung masuk tanpa login / tema tidak bertahan / halaman blank acak | Cache reverse proxy aaPanel menyajikan halaman pengguna lain | Matikan cache proxy (bagian 4), kosongkan sesi |
| Judul tab "Laravel" | `VITE_APP_NAME` tidak diisi saat build | Sudah diperbaiki di kode (judul diambil dari Branding) |

## 9. Daftar periksa keamanan (sebelum dipakai data asli)

- [ ] Semua password bawaan diganti (`users:wajib-ganti-password --all`).
- [ ] 2FA aktif di GitHub, aaPanel, penyedia domain/DNS, penyedia VPS, akun Google backup.
- [ ] Backup harian berjalan **dan ke luar server**, dan pemulihan sudah diuji satu kali.
- [ ] SSH memakai key, login password dimatikan; firewall (`ufw`) dan fail2ban aktif.
- [ ] Hanya port 22/80/443 terbuka dari luar; port aplikasi dan database tidak terbuka.
- [ ] `APP_DEBUG=false`, `.env` tidak ada di repository.
- [ ] Pemantauan ketersediaan (mis. UptimeRobot) terpasang.
