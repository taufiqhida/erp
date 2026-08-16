<?php

use App\Http\Controllers\BastController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CancellationRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DokumenKonsumenController;
use App\Http\Controllers\KavlingController;
use App\Http\Controllers\KeuanganController;
use App\Http\Controllers\KonsumenController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Redirect root ke dashboard jika login, ke halaman login jika belum
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profile (user sendiri)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ── Projects (CRUD) ───────────────────────────────────────────────
    Route::resource('projects', ProjectController::class);

    // Siteplan & Import
    Route::post('projects/{project}/siteplan', [ProjectController::class, 'uploadSiteplan'])
        ->name('projects.siteplan.upload');
    Route::patch('projects/{project}/kavling-koordinat', [ProjectController::class, 'updateKavlingKoordinat'])
        ->name('projects.kavling-koordinat');
    Route::post('projects/{project}/import-kavling', [ProjectController::class, 'importKavling'])
        ->name('projects.import-kavling');
    Route::post('projects/{project}/import-kavling-mapped', [ProjectController::class, 'importKavlingMapped'])
        ->name('projects.import-kavling-mapped');

    // Kavlings (nested under project)
    Route::resource('projects.kavlings', KavlingController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->shallow();

    // Kavling – update status bangun
    Route::patch('kavlings/{kavling}/status-bangun', [KavlingController::class, 'updateStatusBangun'])
        ->name('kavlings.status-bangun');

    // Kavling – upload foto rumah / denah rumah
    Route::post('kavlings/{kavling}/upload-gambar', [KavlingController::class, 'uploadGambar'])
        ->name('kavlings.upload-gambar');

    // ── Penjualan (menu proyek untuk sales) ───────────────────────────
    Route::get('penjualan', [BookingController::class, 'projectList'])->name('penjualan.index');
    Route::get('penjualan/{project}', [BookingController::class, 'projectDetail'])->name('penjualan.project');

    // Booking
    Route::post('kavlings/{kavling}/booking', [BookingController::class, 'store'])->name('bookings.store');
    Route::patch('kavling-konsumen/{kk}/status', [BookingController::class, 'updateStatus'])
        ->name('bookings.update-status');
    Route::patch('kavling-konsumen/{kk}/bank-decision', [BookingController::class, 'updateBankDecision'])
        ->name('bookings.bank-decision');
    Route::patch('kavling-konsumen/{kk}/sp3k-decision', [BookingController::class, 'updateSp3kDecision'])
        ->name('bookings.sp3k-decision');
    Route::patch('kavling-konsumen/{kk}/revert-pemberkasan', [BookingController::class, 'revertToPemberkasan'])
        ->name('bookings.revert-pemberkasan');
    Route::patch('kavling-konsumen/{kk}/swap-unit', [BookingController::class, 'swapUnit'])
        ->name('bookings.swap-unit');

    // ── BAST ──────────────────────────────────────────────────────────
    Route::patch('kavling-konsumen/{kk}/bast', [BastController::class, 'update'])->name('bast.update');
    Route::post('kavling-konsumen/{kk}/bast/upload', [BastController::class, 'uploadDokumen'])->name('bast.upload');

    // ── Dokumen Konsumen ───────────────────────────────────────────────
    Route::get('kavling-konsumen/{kk}/dokumen', [DokumenKonsumenController::class, 'index'])
        ->name('dokumen.index');
    Route::patch('dokumen/{dok}/status', [DokumenKonsumenController::class, 'updateStatus'])
        ->name('dokumen.update-status');
    Route::post('dokumen/{dok}/upload', [DokumenKonsumenController::class, 'uploadFile'])
        ->name('dokumen.upload');

    // ── Konsumens (CRUD) ───────────────────────────────────────────────
    Route::resource('konsumens', KonsumenController::class);

    // ── Keuangan ──────────────────────────────────────────────────────
    Route::get('keuangan', [KeuanganController::class, 'index'])->name('keuangan.index');
    Route::post('kavling-konsumen/{kk}/pembayaran', [KeuanganController::class, 'storePembayaran'])
        ->name('pembayaran.store');
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

        // Template Pemberkasan / Dokumen
        Route::get('dokumen-templates', [PengaturanController::class, 'dokumenTemplates'])
            ->name('dokumen-templates');
        Route::post('dokumen-templates', [PengaturanController::class, 'storeDokumenTemplate'])
            ->name('dokumen-templates.store');
        Route::patch('dokumen-templates/{template}', [PengaturanController::class, 'updateDokumenTemplate'])
            ->name('dokumen-templates.update');
        Route::delete('dokumen-templates/{template}', [PengaturanController::class, 'destroyDokumenTemplate'])
            ->name('dokumen-templates.destroy');

        // Pembiayaan Proyek
        Route::get('pembiayaan', [PengaturanController::class, 'pembiayaan'])
            ->name('pembiayaan');
        Route::patch('pembiayaan/{project}', [PengaturanController::class, 'updatePembiayaan'])
            ->name('pembiayaan.update');

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
