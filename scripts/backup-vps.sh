#!/usr/bin/env bash
#
# Backup harian ERP di VPS: database + folder unggahan -> terenkripsi -> Google Drive.
# Panduan pemasangan & pemulihan: scripts/BACKUP-VPS.md
#
# Konfigurasi dibaca dari /opt/erp-backup/backup.env (BUKAN dari repo — berisi rahasia).
# Jalankan manual untuk uji:  bash /var/www/erp/scripts/backup-vps.sh
#
set -euo pipefail

CONFIG="${BACKUP_CONFIG:-/opt/erp-backup/backup.env}"
[ -f "$CONFIG" ] || { echo "Config tidak ada: $CONFIG" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONFIG"

: "${DB_CONTAINER:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASS:?}"
: "${APP_CONTAINER:?}" "${GPG_PASS_FILE:?}" "${RCLONE_REMOTE:?}"
BACKUP_DIR="${BACKUP_DIR:-/opt/erp-backup/data}"
KEEP_LOCAL_DAYS="${KEEP_LOCAL_DAYS:-14}"
KEEP_REMOTE_DAYS="${KEEP_REMOTE_DAYS:-30}"
MIN_DB_BYTES="${MIN_DB_BYTES:-5000}"        # dump lebih kecil dari ini dianggap gagal
MIN_UPLOADS_BYTES="${MIN_UPLOADS_BYTES:-100}"
LOG="${LOG:-/opt/erp-backup/backup.log}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%F_%H%M)"
DB_FILE="$BACKUP_DIR/erp-db-$STAMP.sql.gz.gpg"
UP_FILE="$BACKUP_DIR/erp-uploads-$STAMP.tar.gz.gpg"

log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG"; }
fail() { log "GAGAL: $*"; rm -f "$DB_FILE.tmp" "$UP_FILE.tmp"; exit 1; }
trap 'fail "error di baris $LINENO"' ERR

gpg_enc() {
    gpg --batch --yes --pinentry-mode loopback --passphrase-file "$GPG_PASS_FILE" \
        --symmetric --cipher-algo AES256
}

log "=== Backup dimulai ==="

# 1. Database — --single-transaction: konsisten tanpa mengunci tabel saat ERP dipakai.
docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
    mysqldump -u"$DB_USER" --single-transaction --triggers --no-tablespaces "$DB_NAME" \
    | gzip -9 | gpg_enc > "$DB_FILE.tmp"
[ "$(stat -c%s "$DB_FILE.tmp")" -ge "$MIN_DB_BYTES" ] || fail "dump database terlalu kecil ($(stat -c%s "$DB_FILE.tmp") byte) — dianggap gagal"
mv "$DB_FILE.tmp" "$DB_FILE"
log "Database OK: $(basename "$DB_FILE") ($(du -h "$DB_FILE" | cut -f1))"

# 2. Folder unggahan (siteplan, dokumen konsumen) — langsung dari dalam container ERP.
docker exec "$APP_CONTAINER" tar czf - -C /var/www/html/storage/app . | gpg_enc > "$UP_FILE.tmp"
[ "$(stat -c%s "$UP_FILE.tmp")" -ge "$MIN_UPLOADS_BYTES" ] || fail "arsip unggahan terlalu kecil — dianggap gagal"
mv "$UP_FILE.tmp" "$UP_FILE"
log "Unggahan OK: $(basename "$UP_FILE") ($(du -h "$UP_FILE" | cut -f1))"

# 3. Kirim ke penyimpanan luar. Kalau upload gagal (mis. akun/token bermasalah), backup LOKAL
#    tetap sah dan pembersihan lokal tetap jalan — tapi hasil akhir dicatat sebagai PERINGATAN
#    (exit code 2) supaya terlihat bahwa salinan luar server belum ada.
UPLOAD_OK=1
if rclone copy "$DB_FILE" "$RCLONE_REMOTE" --quiet && rclone copy "$UP_FILE" "$RCLONE_REMOTE" --quiet; then
    log "Upload ke $RCLONE_REMOTE OK"
    rclone delete "$RCLONE_REMOTE" --min-age "${KEEP_REMOTE_DAYS}d" --quiet || log "peringatan: pembersihan penyimpanan luar gagal (tidak fatal)"
else
    UPLOAD_OK=0
    log "PERINGATAN: upload ke $RCLONE_REMOTE GAGAL — backup hanya ada di server ini"
fi

# 4. Bersih-bersih lokal (selalu jalan, supaya disk tidak penuh).
find "$BACKUP_DIR" -name 'erp-*.gpg' -mtime +"$KEEP_LOCAL_DAYS" -delete

if [ "$UPLOAD_OK" -eq 1 ]; then
    log "=== Backup selesai ==="
else
    log "=== Backup selesai (LOKAL SAJA, upload gagal) ==="
    exit 2
fi
