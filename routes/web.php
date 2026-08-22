<?php

use App\Http\Controllers\BastController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CancellationRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DokumenKonsumenController;
use App\Http\Controllers\KavlingController;
use App\Http\Controllers\KeuanganController;
use App\Http\Controllers\KonsumenController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Redirect root ke Halaman Utama (pilih proyek) jika login, ke halaman
// login jika belum — Dashboard sekarang menu terpisah, bukan landing.
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('beranda');
    }
    return redirect()->route('login');
});

// Sajikan file dari disk 'public' lewat PHP (fallback kalau symlink
// public/storage tidak di-follow dengan benar oleh web server, kasus umum
// di sebagian environment Windows/Laragon).
Route::get('media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.show');

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profile (user sendiri)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ── Halaman Utama Pilih Proyek ──────────────────────────────────────
    // Landing page setelah login — bukan bagian dari resource "projects"
    // (bukan sekadar "daftar proyek"), tapi tetap reuse ProjectController::
    // index() apa adanya karena isinya (kartu proyek, tambah/edit/hapus,
    // "Semua Proyek") persis yang dibutuhkan di sini.
    Route::get('beranda', [ProjectController::class, 'index'])->name('beranda');

    // ── Projects (CRUD) ───────────────────────────────────────────────
    // Rute spesifik ini HARUS didaftarkan sebelum Route::resource, supaya
    // "clear-active" tidak ditangkap sebagai {project} route-model-binding
    // oleh rute GET projects/{project} (projects.show).
    Route::get('projects/clear-active', [ProjectController::class, 'clearActiveProject'])
        ->name('projects.clear-active');
    Route::resource('projects', ProjectController::class)->except(['index']);

    // Siteplan & Import
    Route::post('projects/{project}/siteplan', [ProjectController::class, 'uploadSiteplan'])
        ->name('projects.siteplan.upload');
    Route::patch('projects/{project}/kavling-koordinat', [ProjectController::class, 'updateKavlingKoordinat'])
        ->name('projects.kavling-koordinat');
    Route::patch('projects/{project}/siteplan-marker-size', [ProjectController::class, 'updateSiteplanMarkerSize'])
        ->name('projects.siteplan-marker-size');
    Route::get('projects/{project}/kavling-template', [ProjectController::class, 'downloadKavlingTemplate'])
        ->name('projects.kavling-template');
    Route::post('projects/{project}/import-kavling', [ProjectController::class, 'importKavling'])
        ->name('projects.import-kavling');
    Route::post('projects/{project}/import-kavling-mapped', [ProjectController::class, 'importKavlingMapped'])
        ->name('projects.import-kavling-mapped');

    // Kavlings (nested under project)
    Route::resource('projects.kavlings', KavlingController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->shallow();

    // Kavling – toggle ketersediaan (Tersedia / Tidak Tersedia)
    Route::patch('kavlings/{kavling}/status-jual', [KavlingController::class, 'updateStatusJual'])
        ->name('kavlings.status-jual');

    // Kavling – update status bangun
    Route::patch('kavlings/{kavling}/status-bangun', [KavlingController::class, 'updateStatusBangun'])
        ->name('kavlings.status-bangun');

    // Kavling – upload foto rumah / denah rumah
    Route::post('kavlings/{kavling}/upload-gambar', [KavlingController::class, 'uploadGambar'])
        ->name('kavlings.upload-gambar');

    // ── Penjualan (menu proyek untuk sales) ───────────────────────────
    Route::get('penjualan', [BookingController::class, 'projectList'])->name('penjualan.index');
    Route::get('penjualan/{project}', [BookingController::class, 'projectDetail'])->name('penjualan.project');
    Route::get('proyek/{project}/kavlings-available', [BookingController::class, 'availableKavlings'])->name('bookings.available-kavlings');

    // Booking
    Route::post('kavlings/{kavling}/booking', [BookingController::class, 'store'])->name('bookings.store');
    Route::patch('kavling-konsumen/{kk}/status', [BookingController::class, 'updateStatus'])
        ->name('bookings.update-status');
    Route::patch('kavling-konsumen/{kk}/bank-decision', [BookingController::class, 'updateBankDecision'])
        ->name('bookings.bank-decision');
    Route::patch('kavling-konsumen/{kk}/sp3k-decision', [BookingController::class, 'updateSp3kDecision'])
        ->name('bookings.sp3k-decision');
    Route::patch('kavling-konsumen/{kk}/revert-previous', [BookingController::class, 'revertToPreviousStage'])
        ->name('bookings.revert-previous');
    Route::patch('kavling-konsumen/{kk}/rencana-akad', [BookingController::class, 'updateRencanaAkad'])
        ->name('bookings.rencana-akad');
    Route::patch('kavling-konsumen/{kk}/bank-rekanan', [BookingController::class, 'updateBankRekanan'])
        ->name('bookings.bank-rekanan');

    // ── BAST ──────────────────────────────────────────────────────────
    Route::patch('kavling-konsumen/{kk}/bast', [BastController::class, 'update'])->name('bast.update');
    Route::patch('kavling-konsumen/{kk}/bast/confirm-selesai', [BastController::class, 'confirmSelesai'])->name('bast.confirm-selesai');

    // ── Dokumen Konsumen ───────────────────────────────────────────────
    Route::get('kavling-konsumen/{kk}/dokumen', [DokumenKonsumenController::class, 'index'])
        ->name('dokumen.index');
    Route::patch('dokumen/{dok}/status', [DokumenKonsumenController::class, 'updateStatus'])
        ->name('dokumen.update-status');
    Route::post('dokumen/{dok}/upload', [DokumenKonsumenController::class, 'uploadFile'])
        ->name('dokumen.upload');

    // ── Konsumens (CRUD) ───────────────────────────────────────────────
    Route::resource('konsumens', KonsumenController::class);

    // ── Rincian Biaya Akad (Dajam/SBUM/Biaya Akad per transaksi) ────────
    Route::post('kavling-konsumen/{transaksi}/biaya-akad', [KonsumenController::class, 'storeRincianBiayaAkad'])
        ->name('konsumens.biaya-akad.store');
    Route::patch('biaya-akad/{rincian}', [KonsumenController::class, 'updateRincianBiayaAkad'])
        ->name('konsumens.biaya-akad.update');
    Route::delete('biaya-akad/{rincian}', [KonsumenController::class, 'destroyRincianBiayaAkad'])
        ->name('konsumens.biaya-akad.destroy');

    // ── Keuangan ──────────────────────────────────────────────────────
    Route::get('keuangan', [KeuanganController::class, 'index'])->name('keuangan.index');
    Route::get('keuangan/transaksi/{kk}', [KeuanganController::class, 'detail'])->name('keuangan.detail');
    Route::post('keuangan/transaksi/{kk}/selesai', [KeuanganController::class, 'markComplete'])->name('keuangan.mark-complete');
    Route::post('kavling-konsumen/{kk}/pembayaran', [KeuanganController::class, 'storePembayaran'])
        ->name('pembayaran.store');
    Route::delete('pembayaran/{pembayaran}', [KeuanganController::class, 'destroyPembayaran'])
        ->name('pembayaran.destroy');
    Route::post('jadwal-tagihan/{jadwal}/bayar', [KeuanganController::class, 'payJadwalTagihan'])
        ->name('jadwal-tagihan.bayar');
    Route::delete('jadwal-tagihan/{jadwal}/bayar', [KeuanganController::class, 'destroyJadwalTagihanPembayaran'])
        ->name('jadwal-tagihan.bayar.destroy');
    Route::patch('jadwal-tagihan/{jadwal}/tanggal', [KeuanganController::class, 'updateJadwalTagihanTanggal'])
        ->name('jadwal-tagihan.update-tanggal');
    Route::post('kavling-konsumen/{kk}/pelunasan/cicilan', [KeuanganController::class, 'storePelunasanCicilan'])
        ->name('pelunasan.cicilan.store');
    Route::delete('jadwal-tagihan/{jadwal}', [KeuanganController::class, 'destroyJadwalTagihan'])
        ->name('jadwal-tagihan.destroy');
    Route::post('kavling-konsumen/{kk}/biaya-tanah/bayar', [KeuanganController::class, 'payBiayaTanah'])
        ->name('biaya-tanah.bayar');
    Route::delete('kavling-konsumen/{kk}/biaya-tanah/bayar', [KeuanganController::class, 'destroyBiayaTanahPembayaran'])
        ->name('biaya-tanah.bayar.destroy');
    Route::post('kavling-konsumen/{kk}/tambahan-um/bayar', [KeuanganController::class, 'payTambahanUm'])
        ->name('tambahan-um.bayar');
    Route::delete('kavling-konsumen/{kk}/tambahan-um/bayar', [KeuanganController::class, 'destroyTambahanUmPembayaran'])
        ->name('tambahan-um.bayar.destroy');
    Route::post('kavling-konsumen/{kk}/pencairan-kpr/tahap', [KeuanganController::class, 'storePencairanKprTahap'])
        ->name('pencairan-kpr.tahap.store');
    Route::patch('pencairan-kpr/tahap/{tahap}', [KeuanganController::class, 'updatePencairanKprTahap'])
        ->name('pencairan-kpr.tahap.update');
    Route::delete('pencairan-kpr/tahap/{tahap}', [KeuanganController::class, 'destroyPencairanKprTahap'])
        ->name('pencairan-kpr.tahap.destroy');
    Route::post('biaya-tambahan/{item}/bayar', [KeuanganController::class, 'payBiayaTambahan'])
        ->name('biaya-tambahan.bayar');
    Route::delete('biaya-tambahan/{item}/bayar', [KeuanganController::class, 'destroyBiayaTambahanPembayaran'])
        ->name('biaya-tambahan.bayar.destroy');
    Route::post('rincian-biaya-akad/{item}/bayar', [KeuanganController::class, 'payDajamSbum'])
        ->name('rincian-biaya-akad.bayar');
    Route::delete('rincian-biaya-akad/{item}/bayar', [KeuanganController::class, 'destroyDajamSbumPembayaran'])
        ->name('rincian-biaya-akad.bayar.destroy');
    Route::patch('kavling-konsumen/{kk}/kpr', [KeuanganController::class, 'updateKpr'])
        ->name('keuangan.update-kpr');
    Route::patch('kavling-konsumen/{kk}/sbum', [KeuanganController::class, 'updateSbum'])
        ->name('keuangan.update-sbum');
    Route::get('pembayaran/{pembayaran}/kuitansi', [KeuanganController::class, 'kuitansi'])
        ->name('pembayaran.kuitansi');

    // ── Cancellation Requests ─────────────────────────────────────────
    Route::get('cancellation-requests', [CancellationRequestController::class, 'index'])
        ->name('cancellation-requests.index');
    Route::post('cancellation-requests', [CancellationRequestController::class, 'store'])
        ->name('cancellation-requests.store');
    Route::patch('cancellation-requests/{cancellationRequest}/approve', [CancellationRequestController::class, 'approve'])
        ->name('cancellation-requests.approve');
    Route::patch('cancellation-requests/{cancellationRequest}/reject', [CancellationRequestController::class, 'reject'])
        ->name('cancellation-requests.reject');

    // ── Pengaturan (superadmin & manajer) ─────────────────────────────
    Route::middleware('role:superadmin|manajer')->prefix('pengaturan')->name('pengaturan.')->group(function () {
        // Profil Developer
        Route::get('profil-developer', [PengaturanController::class, 'developerProfile'])
            ->name('profil-developer');
        Route::patch('profil-developer', [PengaturanController::class, 'updateDeveloperProfile'])
            ->name('profil-developer.update');

        // Rekening Bank Developer (multi-bank)
        Route::post('profil-developer/banks', [PengaturanController::class, 'storeDeveloperBank'])
            ->name('profil-developer.banks.store');
        Route::patch('profil-developer/banks/{bank}', [PengaturanController::class, 'updateDeveloperBank'])
            ->name('profil-developer.banks.update');
        Route::delete('profil-developer/banks/{bank}', [PengaturanController::class, 'destroyDeveloperBank'])
            ->name('profil-developer.banks.destroy');
        Route::patch('profil-developer/banks/{bank}/primary', [PengaturanController::class, 'setPrimaryDeveloperBank'])
            ->name('profil-developer.banks.primary');

        // Template Pemberkasan / Dokumen
        Route::get('dokumen-templates', [PengaturanController::class, 'dokumenTemplates'])
            ->name('dokumen-templates');
        Route::post('dokumen-templates', [PengaturanController::class, 'storeDokumenTemplate'])
            ->name('dokumen-templates.store');
        Route::patch('dokumen-templates/{template}', [PengaturanController::class, 'updateDokumenTemplate'])
            ->name('dokumen-templates.update');
        Route::delete('dokumen-templates/{template}', [PengaturanController::class, 'destroyDokumenTemplate'])
            ->name('dokumen-templates.destroy');

        // Dana Jaminan & SBUM (global, tanpa nominal)
        Route::get('dajam-sbum', [PengaturanController::class, 'dajamSbum'])
            ->name('dajam-sbum');
        Route::post('dajam-sbum', [PengaturanController::class, 'storeDajamSbum'])
            ->name('dajam-sbum.store');
        Route::patch('dajam-sbum/{dajamSbum}', [PengaturanController::class, 'updateDajamSbum'])
            ->name('dajam-sbum.update');
        Route::delete('dajam-sbum/{dajamSbum}', [PengaturanController::class, 'destroyDajamSbum'])
            ->name('dajam-sbum.destroy');

        // Template Surat
        Route::get('surat-templates', [PengaturanController::class, 'suratTemplates'])
            ->name('surat-templates');
        Route::get('surat-templates/create', [PengaturanController::class, 'createSuratTemplate'])
            ->name('surat-templates.create');
        Route::post('surat-templates', [PengaturanController::class, 'storeSuratTemplate'])
            ->name('surat-templates.store');
        Route::get('surat-templates/{suratTemplate}/edit', [PengaturanController::class, 'editSuratTemplate'])
            ->name('surat-templates.edit');
        Route::patch('surat-templates/{suratTemplate}', [PengaturanController::class, 'updateSuratTemplate'])
            ->name('surat-templates.update');
        Route::delete('surat-templates/{suratTemplate}', [PengaturanController::class, 'destroySuratTemplate'])
            ->name('surat-templates.destroy');

        // Preset Biaya Tambahan
        Route::get('biaya-tambahan', [PengaturanController::class, 'biayaTambahan'])
            ->name('biaya-tambahan');
        Route::post('biaya-tambahan', [PengaturanController::class, 'storeBiayaTambahan'])
            ->name('biaya-tambahan.store');
        Route::patch('biaya-tambahan/{biayaTambahan}', [PengaturanController::class, 'updateBiayaTambahan'])
            ->name('biaya-tambahan.update');
        Route::delete('biaya-tambahan/{biayaTambahan}', [PengaturanController::class, 'destroyBiayaTambahan'])
            ->name('biaya-tambahan.destroy');

        // Preset Promo
        Route::get('promo', [PengaturanController::class, 'promo'])
            ->name('promo');
        Route::post('promo', [PengaturanController::class, 'storePromo'])
            ->name('promo.store');
        Route::patch('promo/{promo}', [PengaturanController::class, 'updatePromo'])
            ->name('promo.update');
        Route::delete('promo/{promo}', [PengaturanController::class, 'destroyPromo'])
            ->name('promo.destroy');

        // Preset Skema DP
        Route::get('skema-dp', [PengaturanController::class, 'skemaDp'])
            ->name('skema-dp');
        Route::post('skema-dp', [PengaturanController::class, 'storeSkemaDp'])
            ->name('skema-dp.store');
        Route::patch('skema-dp/{skemaDp}', [PengaturanController::class, 'updateSkemaDp'])
            ->name('skema-dp.update');
        Route::delete('skema-dp/{skemaDp}', [PengaturanController::class, 'destroySkemaDp'])
            ->name('skema-dp.destroy');

        // Master Sales / Agent
        Route::get('sales-agents', [PengaturanController::class, 'salesAgents'])
            ->name('sales-agents');
        Route::get('sales-agents/create', [PengaturanController::class, 'createSalesAgent'])
            ->name('sales-agents.create');
        Route::post('sales-agents', [PengaturanController::class, 'storeSalesAgent'])
            ->name('sales-agents.store');
        Route::get('sales-agents/{salesAgent}/edit', [PengaturanController::class, 'editSalesAgent'])
            ->name('sales-agents.edit');
        Route::patch('sales-agents/{salesAgent}', [PengaturanController::class, 'updateSalesAgent'])
            ->name('sales-agents.update');
        Route::delete('sales-agents/{salesAgent}', [PengaturanController::class, 'destroySalesAgent'])
            ->name('sales-agents.destroy');
        Route::patch('sales-agents/{salesAgent}/toggle', [PengaturanController::class, 'toggleSalesAgent'])
            ->name('sales-agents.toggle');
    });

    // ── Role Management + User Management (superadmin only) ───────────
    Route::middleware('role:superadmin')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::patch('roles/{user}/assign', [RoleController::class, 'assign'])->name('roles.assign');
        Route::post('users', [RoleController::class, 'storeUser'])->name('users.store');
        Route::post('projects/{project}/assign-users', [RoleController::class, 'assignProject'])
            ->name('projects.assign-users');
    });
});

require __DIR__ . '/auth.php';
