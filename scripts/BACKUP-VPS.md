# Backup & Pemulihan ERP di VPS

Backup harian: database + folder unggahan → dienkripsi (gpg AES256) → Google Drive.
Lokal disimpan 14 hari, di Drive 30 hari (ubah di `backup.env`).

Skrip: `scripts/backup-vps.sh` (ikut `git pull`). Rahasia (password DB, kata sandi enkripsi)
disimpan di `/opt/erp-backup/`, **bukan di repo**.

## A. Persiapan akun Google (di komputermu)
1. Buat akun Google baru khusus backup (mis. `erp.backup.xxx@gmail.com`). **Aktifkan 2FA.**
2. Di Google Drive akun itu, tidak perlu membuat folder — rclone membuatnya sendiri.

## B. Pemasangan di VPS (sekali saja)

### 1. Pasang rclone dan gpg
```bash
apt update && apt install -y gpg
curl https://rclone.org/install.sh | bash
rclone version
```

### 2. Hubungkan rclone ke Google Drive
VPS tidak punya browser, jadi login Google dilakukan di **komputermu**.

a. Di komputermu, pasang rclone (https://rclone.org/downloads/ — unduh versi Windows, ekstrak),
   lalu di PowerShell dari folder rclone:
```powershell
.\rclone authorize "drive"
```
   Browser terbuka → login dengan **akun Google backup** → izinkan. Di PowerShell muncul
   token JSON panjang (`{"access_token":...}`). Salin seluruhnya.

b. Di VPS:
```bash
rclone config
```
   - `n` (new remote) → name: `gdrive`
   - Storage: ketik `drive` (Google Drive)
   - client_id / client_secret: kosongkan (Enter)
   - scope: `3` (drive.file — hanya file yang dibuat rclone, paling aman)
   - Edit advanced config: `n`
   - Use web browser to automatically authenticate: **`n`** (karena VPS tanpa browser)
   - Saat diminta `config_token>`: tempel token dari langkah (a)
   - Configure as Shared Drive: `n` → `y` (simpan)

   Uji: `rclone mkdir gdrive:ERP-Backup-Staging && rclone lsd gdrive:`

### 3. Buat kata sandi enkripsi
```bash
mkdir -p /opt/erp-backup/data && chmod 700 /opt/erp-backup
head -c 32 /dev/urandom | base64 > /opt/erp-backup/gpg.pass
chmod 600 /opt/erp-backup/gpg.pass
cat /opt/erp-backup/gpg.pass
```
**SALIN isi `gpg.pass` ke password manager milikmu SEKARANG.** Tanpa kata sandi ini, backup
tidak bisa dibuka — termasuk kalau VPS hilang (file di VPS ikut hilang).

### 4. Buat user MySQL khusus backup (hak minimum, hanya baca)
Masuk MySQL (password root MySQL project ecommerce):
```bash
docker exec -it ecommerce-db mysql -uroot -p
```
```sql
CREATE USER 'erp_backup'@'%' IDENTIFIED BY 'GANTI_PASSWORD_KUAT_BEDA';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES ON erp.* TO 'erp_backup'@'%';
FLUSH PRIVILEGES;
EXIT;
```

### 5. File konfigurasi
```bash
cat > /opt/erp-backup/backup.env <<'EOF'
DB_CONTAINER=ecommerce-db
DB_NAME=erp
DB_USER=erp_backup
DB_PASS=GANTI_PASSWORD_KUAT_BEDA
APP_CONTAINER=erp-app
GPG_PASS_FILE=/opt/erp-backup/gpg.pass
RCLONE_REMOTE=gdrive:ERP-Backup-Staging
KEEP_LOCAL_DAYS=14
KEEP_REMOTE_DAYS=30
EOF
chmod 600 /opt/erp-backup/backup.env
```
(Ganti `DB_PASS` dengan password yang sama seperti di langkah 4.)

### 6. Uji manual dulu
```bash
cd /var/www/erp && git pull
bash scripts/backup-vps.sh
```
Harus berakhir dengan `=== Backup selesai ===`. Cek dua hal:
- `ls -lh /opt/erp-backup/data/` — ada dua file `.gpg`.
- `rclone ls gdrive:ERP-Backup-Staging` — dua file yang sama ada di Drive.

### 7. Jadwalkan harian
aaPanel → **Cron** → **Add task**: Type *Shell script*, name `Backup ERP`, period *Daily* jam **02:30**,
isi script:
```bash
bash /var/www/erp/scripts/backup-vps.sh
```
Klik **Execute** sekali untuk uji dari panel, lalu cek `/opt/erp-backup/backup.log`.

## C. Cara memulihkan (WAJIB diuji sekali ke database sementara)

### 1. Ambil & dekripsi file
```bash
cd /opt/erp-backup/data
# kalau file ada di Drive saja:  rclone copy gdrive:ERP-Backup-Staging/erp-db-TANGGAL.sql.gz.gpg .
gpg --batch --pinentry-mode loopback --passphrase-file /opt/erp-backup/gpg.pass \
    -d erp-db-TANGGAL.sql.gz.gpg | gunzip > /tmp/erp-restore.sql
head -c 300 /tmp/erp-restore.sql
```

### 2. UJI: impor ke database sementara (tidak menyentuh data asli)
Password root diberikan lewat variabel (JANGAN pakai `mysql -p` dengan `< file`: password dibaca dari file SQL dan login gagal).
```bash
read -rs -p "Password root MySQL: " ROOTPW; echo
docker exec -e MYSQL_PWD="$ROOTPW" ecommerce-db mysql -uroot -e "CREATE DATABASE erp_restore_test CHARACTER SET utf8mb4;"
docker exec -i -e MYSQL_PWD="$ROOTPW" ecommerce-db mysql -uroot erp_restore_test < /tmp/erp-restore.sql
docker exec -e MYSQL_PWD="$ROOTPW" ecommerce-db mysql -uroot -e "SELECT COUNT(*) FROM erp_restore_test.users;"
docker exec -e MYSQL_PWD="$ROOTPW" ecommerce-db mysql -uroot -e "DROP DATABASE erp_restore_test;"
rm /tmp/erp-restore.sql; unset ROOTPW
```
Jumlah user harus sesuai harapan. Kalau begitu, backup terbukti bisa dipulihkan.

### 3. Pemulihan sungguhan (data rusak/hilang)
**Berbahaya: menimpa data sekarang.** Backup dulu kondisi saat ini, lalu:
```bash
read -rs -p "Password root MySQL: " ROOTPW; echo
docker exec -i -e MYSQL_PWD="$ROOTPW" ecommerce-db mysql -uroot erp < /tmp/erp-restore.sql; unset ROOTPW
docker exec erp-app php artisan cache:clear
```

### 4. Pulihkan folder unggahan
```bash
gpg --batch --pinentry-mode loopback --passphrase-file /opt/erp-backup/gpg.pass \
    -d erp-uploads-TANGGAL.tar.gz.gpg | docker exec -i erp-app tar xzf - -C /var/www/html/storage/app
```

## D. Pemantauan
- Log: `/opt/erp-backup/backup.log` (cek sesekali: harus ada `Backup selesai` tiap hari).
- Cek Drive tiap minggu: ada file baru tiap hari?
- Kalau backup gagal, skrip berhenti dengan pesan `GAGAL: ...` di log.
