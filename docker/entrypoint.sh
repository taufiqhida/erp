#!/bin/sh
set -e

# ── Tunggu MySQL siap ────────────────────────────────────────────────────
# docker-compose `depends_on` cuma nunggu container-nya START, bukan MySQL-nya
# SIAP nerima koneksi — jadi migrate bisa gagal kalau langsung dijalankan.
echo "Menunggu database ${DB_HOST:-db}:${DB_PORT:-3306}..."
tries=0
until php -r "new PDO('mysql:host=${DB_HOST:-db};port=${DB_PORT:-3306}', '${DB_USERNAME:-root}', '${DB_PASSWORD}');" 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "Database tidak kunjung siap setelah 30x percobaan, menyerah."
        exit 1
    fi
    sleep 2
done
echo "Database siap."

# ── Setup sekali jalan tiap container start ─────────────────────────────
# Aman dijalankan berulang (idempotent) — migrate cuma jalankan migrasi baru,
# storage:link skip kalau symlink sudah ada, cache selalu di-generate ulang
# (bukan masalah kalau ke-overwrite dengan isi yang sama).
php artisan storage:link --force || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# artisan di atas jalan sebagai root, jadi file yang dibuatnya (cache, log) milik root,
# padahal PHP-FPM & scheduler jalan sebagai www-data -> tidak bisa menulis log/session/cache
# (hasilnya HTTP 500 tanpa jejak di log). Samakan kepemilikan tiap container start,
# termasuk isi volume lama.
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# Migrasi database TIDAK dijalankan otomatis di sini secara default — sengaja,
# supaya container kedua (kalau nanti di-scale >1 replika) tidak balapan
# migrate bersamaan. Jalankan manual sekali per deploy:
#   docker compose exec app php artisan migrate --force
# Set RUN_MIGRATIONS=true di .env kalau memang mau otomatis (aman untuk
# single-container staging, TIDAK disarankan kalau nanti replika >1).
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "RUN_MIGRATIONS=true — menjalankan migrate --force..."
    php artisan migrate --force
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
