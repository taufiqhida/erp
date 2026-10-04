# Backup database MySQL project ini ke file .sql bertimestamp.
# Baca kredensial dari .env (tidak di-hardcode), simpan ke folder backups/
# di luar tracking git (lihat .gitignore).
#
# Pemakaian:
#   powershell -ExecutionPolicy Bypass -File scripts\backup-db.ps1
#
# Cari mysqldump otomatis dari instalasi Laragon kalau tidak ada di PATH.

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root ".env"

if (-not (Test-Path $envFile)) {
    Write-Error "File .env tidak ditemukan di $envFile"
    exit 1
}

function Get-EnvValue($name, $default = "") {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if (-not $line) { return $default }
    $value = ($line -split "=", 2)[1]
    return $value.Trim('"').Trim()
}

$dbHost = Get-EnvValue "DB_HOST" "127.0.0.1"
$dbPort = Get-EnvValue "DB_PORT" "3306"
$dbName = Get-EnvValue "DB_DATABASE"
$dbUser = Get-EnvValue "DB_USERNAME" "root"
$dbPass = Get-EnvValue "DB_PASSWORD" ""

if (-not $dbName) {
    Write-Error "DB_DATABASE kosong di .env"
    exit 1
}

# Cari mysqldump: coba PATH dulu, lalu lokasi umum Laragon.
$mysqldump = Get-Command mysqldump -ErrorAction SilentlyContinue
if ($mysqldump) {
    $mysqldumpPath = $mysqldump.Source
} else {
    $candidates = Get-ChildItem "C:\laragon\bin\mysql\*\bin\mysqldump.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending
    if ($candidates) {
        $mysqldumpPath = $candidates[0].FullName
    } else {
        Write-Error "mysqldump.exe tidak ditemukan di PATH maupun C:\laragon\bin\mysql\*\bin\. Sesuaikan path di script ini kalau instalasi MySQL-mu beda lokasi."
        exit 1
    }
}

$backupDir = Join-Path $root "backups"
if (-not (Test-Path $backupDir)) {
    New-Item -ItemType Directory -Path $backupDir | Out-Null
}

$timestamp = Get-Date -Format "yyyy-MM-dd_HHmmss"
$outFile = Join-Path $backupDir "backup_${dbName}_${timestamp}.sql"

Write-Host "Backup database '$dbName' ke $outFile ..."

# Tabel infrastruktur Laravel (bukan data bisnis) sengaja dikecualikan —
# tidak perlu ikut backup/restore, dan bisa bikin konflik session/cache
# kalau di-restore ke environment lain.
$ignoreTables = @("sessions", "cache", "cache_locks", "jobs", "job_batches", "failed_jobs")
$ignoreArgs = $ignoreTables | ForEach-Object { "--ignore-table=${dbName}.$_" }

$args = @(
    "--host=$dbHost",
    "--port=$dbPort",
    "--user=$dbUser",
    "--single-transaction",
    "--routines",
    "--triggers"
)
if ($dbPass) { $args += "--password=$dbPass" }
$args += $ignoreArgs
$args += $dbName

$output = & $mysqldumpPath @args

if ($LASTEXITCODE -ne 0) {
    Write-Error "mysqldump gagal (exit code $LASTEXITCODE)."
    exit 1
}

# Tulis tanpa BOM (Out-File -Encoding utf8 di Windows PowerShell 5.1 selalu
# nambah BOM, yang bisa bikin baris pertama file .sql gagal dibaca ulang
# oleh client `mysql` saat restore).
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllLines($outFile, $output, $utf8NoBom)

$sizeKb = [math]::Round((Get-Item $outFile).Length / 1KB, 1)
Write-Host "Selesai. Ukuran file: $sizeKb KB"
Write-Host "Simpan file ini di tempat aman (bukan cuma di folder project) kalau ini backup penting."
