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
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\ProsesBangunController;
use App\Http\Controllers\RencanaAkadController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SuratGenerateController;
use App\Http\Controllers\TipeUnitPresetController;
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

    // ── Alamat lama → alamat baru (301) ───────────────────────────────────
    // Tautan/bookmark/riwayat yang sudah tersimpan tetap hidup. Hanya GET; query string
    // (mis. ?transaksi=1) ikut dibawa. Boleh dihapus kalau sudah lama tidak dipakai.
    $alihkan = function (string $lama, string $baru) {
        Route::get($lama, function (\Illuminate\Http\Request $request) use ($baru) {
            $url = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $request->route($m[1]), $baru);
            $query = $request->getQueryString();

            return redirect('/' . $url . ($query ? '?' . $query : ''), 301);
        });
    };
    $alihkan('projects/clear-active', 'proyek/clear-active');
    $alihkan('projects/create', 'proyek/create');
    $alihkan('projects/{project}', 'proyek/{project}');
    $alihkan('projects/{project}/edit', 'proyek/{project}/edit');
    $alihkan('projects/{project}/tipe-unit', 'proyek/{project}/tipe-unit');
    $alihkan('projects/{project}/export-kavling', 'proyek/{project}/export-kavling');
    $alihkan('projects/{project}/kavling-template', 'proyek/{project}/kavling-template');
    $alihkan('cancellation-requests', 'proyek/pembatalan');
    $alihkan('penjualan', 'pemasaran/penjualan');
    $alihkan('penjualan/{project}', 'pemasaran/penjualan/{project}');
    $alihkan('rencana-akad', 'pemasaran/rencana-akad');
    $alihkan('konsumens', 'pemasaran/konsumen');
    $alihkan('konsumens/export', 'pemasaran/konsumen/export');
    $alihkan('konsumens/{konsumen}', 'pemasaran/konsumen/{konsumen}');
    $alihkan('konsumens/{konsumen}/edit', 'pemasaran/konsumen/{konsumen}/edit');
    $alihkan('konsumens/{project}/import-template', 'pemasaran/konsumen/{project}/import-template');
    $alihkan('kavling-konsumen/{kk}/dokumen', 'pemasaran/transaksi/{kk}/dokumen');
    $alihkan('pembayaran/{pembayaran}/kuitansi', 'keuangan/kuitansi/{pembayaran}');

    // Profile (user sendiri)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::patch('/preferensi', [\App\Http\Controllers\PreferensiController::class, 'update'])->name('preferensi.update');

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
    Route::get('proyek/clear-active', [ProjectController::class, 'clearActiveProject'])
        ->name('projects.clear-active');
    // URI-nya "proyek" (seragam dengan Proses Bangun/SPK); NAMA route tetap "projects.*"
    // supaya semua route('projects.xxx') di kode & Vue tidak berubah.
    Route::resource('proyek', ProjectController::class)
        ->parameters(['proyek' => 'project'])
        ->names('projects')
        ->where(['project' => '[0-9]+'])
        ->except(['index']);

    // Siteplan & Import
    Route::post('proyek/{project}/siteplan', [ProjectController::class, 'uploadSiteplan'])
        ->name('projects.siteplan.upload')->middleware('throttle:heavy');
    Route::patch('proyek/{project}/kavling-koordinat', [ProjectController::class, 'updateKavlingKoordinat'])
        ->name('projects.kavling-koordinat');
    Route::patch('proyek/{project}/siteplan-marker-size', [ProjectController::class, 'updateSiteplanMarkerSize'])
        ->name('projects.siteplan-marker-size');
    Route::get('proyek/{project}/kavling-template', [ProjectController::class, 'downloadKavlingTemplate'])
        ->name('projects.kavling-template')->middleware('throttle:heavy');
    Route::get('proyek/{project}/export-kavling', [ProjectController::class, 'exportKavlingExcel'])
        ->name('projects.export-kavling')->middleware('throttle:heavy');
    Route::post('proyek/{project}/import-kavling', [ProjectController::class, 'importKavling'])
        ->name('projects.import-kavling')->middleware('throttle:heavy');

    // Kavlings (nested under project) — index dihapus, sudah digabung ke
    // tabel di Projects/Show.vue (server-side paginated) supaya tidak ada
    // 2 halaman terpisah untuk hal yang sama.
    Route::resource('projects.kavlings', KavlingController::class)
        ->only(['store', 'update', 'destroy'])
        ->shallow();

    // Tipe Unit (master data preset, scoped per proyek)
    Route::get('proyek/{project}/tipe-unit', [TipeUnitPresetController::class, 'index'])
        ->name('projects.tipe-unit.index');
    Route::resource('projects.tipe-unit', TipeUnitPresetController::class)
        ->only(['store', 'update', 'destroy'])
        ->shallow();
    Route::post('tipe-unit/{tipeUnitPreset}/upload-gambar', [TipeUnitPresetController::class, 'uploadGambar'])
        ->name('tipe-unit.upload-gambar')->middleware('throttle:heavy');

    // Kavling – toggle ketersediaan (Tersedia / Tidak Tersedia)
    Route::patch('kavlings/{kavling}/status-jual', [KavlingController::class, 'updateStatusJual'])
        ->name('kavlings.status-jual');

    // Kavling – update status bangun
    Route::patch('kavlings/{kavling}/status-bangun', [KavlingController::class, 'updateStatusBangun'])
        ->name('kavlings.status-bangun');
    Route::patch('kavlings/{kavling}/id-rumah', [KavlingController::class, 'updateIdRumah'])
        ->name('kavlings.id-rumah');
    Route::patch('kavlings/{kavling}/hgb-no', [KavlingController::class, 'updateHgbNo'])
        ->name('kavlings.hgb-no');
    Route::patch('kavlings/{kavling}/catatan', [KavlingController::class, 'updateCatatan'])
        ->name('kavlings.catatan');

    // ── Proses Bangun (progress bangun + SPK) — terpisah dari Stok Kavling ──
    Route::get('proyek/{project}/proses-bangun', [ProsesBangunController::class, 'index'])
        ->name('proses-bangun.index');
    Route::get('proyek/{project}/spk/buat', [ProsesBangunController::class, 'createSpk'])
        ->name('spk.create');
    Route::post('proyek/{project}/spk', [ProsesBangunController::class, 'storeSpk'])
        ->name('spk.store');

    // ── Penjualan (menu proyek untuk sales) ───────────────────────────
    Route::get('pemasaran/rencana-akad', [RencanaAkadController::class, 'index'])->name('rencana-akad.index');
    Route::get('pemasaran/penjualan', [BookingController::class, 'projectList'])->name('penjualan.index');
    Route::get('pemasaran/penjualan/{project}', [BookingController::class, 'projectDetail'])->name('penjualan.project');
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
    Route::patch('kavling-konsumen/{kk}/rincian-pesanan', [BookingController::class, 'updateRincianPesanan'])
        ->name('bookings.rincian-pesanan');

    // ── BAST ──────────────────────────────────────────────────────────
    Route::patch('kavling-konsumen/{kk}/bast', [BastController::class, 'update'])->name('bast.update');
    Route::patch('kavling-konsumen/{kk}/bast/confirm-selesai', [BastController::class, 'confirmSelesai'])->name('bast.confirm-selesai');

    // ── Dokumen Konsumen ───────────────────────────────────────────────
    Route::get('pemasaran/transaksi/{kk}/dokumen', [DokumenKonsumenController::class, 'index'])
        ->name('dokumen.index');
    Route::patch('dokumen/{dok}/status', [DokumenKonsumenController::class, 'updateStatus'])
        ->name('dokumen.update-status');

    // ── Konsumens (CRUD) — tanpa create/store: konsumen baru cuma dibuat
    // lewat form booking, biar tidak ada row konsumen yatim tanpa transaksi.
    // export DIDAFTAR SEBELUM resource() — kalau setelah, "konsumens/{konsumen}"
    // dari resource() bakal duluan menangkap "konsumens/export" (1 segmen sama).
    Route::get('pemasaran/konsumen/export', [KonsumenController::class, 'exportKonsumenExcel'])
        ->name('konsumens.export')->middleware('throttle:heavy');
    Route::resource('pemasaran/konsumen', KonsumenController::class)
        ->parameters(['konsumen' => 'konsumen'])
        ->names('konsumens')
        ->except(['create', 'store']);
    Route::get('pemasaran/konsumen/{project}/import-template', [KonsumenController::class, 'downloadImportTemplate'])
        ->name('konsumens.import-template')->middleware('throttle:heavy');
    Route::post('pemasaran/konsumen/{project}/import', [KonsumenController::class, 'importKonsumen'])
        ->name('konsumens.import')->middleware('throttle:heavy');

    // ── Rincian Biaya Akad (Dajam/SBUM/Biaya Akad per transaksi) ────────
    Route::post('kavling-konsumen/{transaksi}/biaya-akad', [KonsumenController::class, 'storeRincianBiayaAkad'])
        ->name('konsumens.biaya-akad.store');
    Route::patch('biaya-akad/{rincian}', [KonsumenController::class, 'updateRincianBiayaAkad'])
        ->name('konsumens.biaya-akad.update');
    Route::delete('biaya-akad/{rincian}', [KonsumenController::class, 'destroyRincianBiayaAkad'])
        ->name('konsumens.biaya-akad.destroy');

    // ── Cetak Dokumen (generate dari Template Surat) — GET karena murni
    // export/read (tidak ubah data tersimpan), jadi bisa lewat form biasa
    // tanpa perlu CSRF token di frontend, cukup navigasi/submit native.
    Route::get('kavling-konsumen/{kk}/surat/{suratTemplate}/generate', [SuratGenerateController::class, 'generate'])
        ->name('surat.generate');

    // ── Keuangan ──────────────────────────────────────────────────────
    Route::get('keuangan', [KeuanganController::class, 'index'])->name('keuangan.index');
    Route::get('keuangan/export', [KeuanganController::class, 'exportPiutangExcel'])->name('keuangan.export')->middleware('throttle:heavy');
    Route::get('keuangan/pencairan-kpr', [KeuanganController::class, 'pencairan'])->name('keuangan.pencairan');
    Route::get('keuangan/pencairan-kpr/export', [KeuanganController::class, 'exportPencairanExcel'])->name('keuangan.pencairan.export')->middleware('throttle:heavy');
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
    Route::post('kavling-konsumen/{kk}/biaya-tanah/cicilan', [KeuanganController::class, 'storeBiayaTanahCicilan'])
        ->name('biaya-tanah.cicilan.store');
    Route::post('kavling-konsumen/{kk}/tambahan-um/cicilan', [KeuanganController::class, 'storeTambahanUmCicilan'])
        ->name('tambahan-um.cicilan.store');
    Route::post('kavling-konsumen/{kk}/titipan-biaya-akad/cicilan', [KeuanganController::class, 'storeTitipanBiayaAkadCicilan'])
        ->name('titipan-biaya-akad.cicilan.store');
    Route::patch('cicilan-pembayaran/{pembayaran}', [KeuanganController::class, 'updateCicilanKonsumen'])
        ->name('cicilan-pembayaran.update');
    Route::delete('cicilan-pembayaran/{pembayaran}', [KeuanganController::class, 'destroyCicilanKonsumen'])
        ->name('cicilan-pembayaran.destroy');
    Route::post('kavling-konsumen/{kk}/pencairan-kpr/tahap', [KeuanganController::class, 'storePencairanKprTahap'])
        ->name('pencairan-kpr.tahap.store');
    Route::patch('pencairan-kpr/tahap/{tahap}', [KeuanganController::class, 'updatePencairanKprTahap'])
        ->name('pencairan-kpr.tahap.update');
    Route::delete('pencairan-kpr/tahap/{tahap}', [KeuanganController::class, 'destroyPencairanKprTahap'])
        ->name('pencairan-kpr.tahap.destroy');
    Route::post('biaya-tambahan/{item}/cicilan', [KeuanganController::class, 'storeBiayaTambahanCicilan'])
        ->name('biaya-tambahan.cicilan.store');
    Route::post('rincian-biaya-akad/{item}/bayar', [KeuanganController::class, 'payDajamSbum'])
        ->name('rincian-biaya-akad.bayar');
    Route::delete('rincian-biaya-akad/{item}/bayar', [KeuanganController::class, 'destroyDajamSbumPembayaran'])
        ->name('rincian-biaya-akad.bayar.destroy');
    Route::get('keuangan/kuitansi/{pembayaran}', [KeuanganController::class, 'kuitansi'])
        ->name('pembayaran.kuitansi');

    // ── Cancellation Requests ─────────────────────────────────────────
    Route::get('proyek/pembatalan', [CancellationRequestController::class, 'index'])
        ->name('cancellation-requests.index');
    Route::post('cancellation-requests', [CancellationRequestController::class, 'store'])
        ->name('cancellation-requests.store');
    Route::patch('cancellation-requests/{cancellationRequest}/approve', [CancellationRequestController::class, 'approve'])
        ->name('cancellation-requests.approve');
    Route::patch('cancellation-requests/{cancellationRequest}/reject', [CancellationRequestController::class, 'reject'])
        ->name('cancellation-requests.reject');

    // ── Pengaturan — permission granular per domain (lihat RolesAndPermissionsSeeder),
    // menggantikan middleware blanket role:superadmin|manajer lama.
    Route::prefix('pengaturan')->name('pengaturan.')->group(function () {
        // Superadmin saja: Profil Developer, Dokumen Template, Surat Template,
        // Biaya Tambahan, Sumber Lead, Promo, Skema DP, Warna Status.
        Route::middleware('permission:manage system settings')->group(function () {
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
            Route::patch('dokumen-templates/{template}/move-up', [PengaturanController::class, 'moveUpDokumenTemplate'])
                ->name('dokumen-templates.move-up');
            Route::patch('dokumen-templates/{template}/move-down', [PengaturanController::class, 'moveDownDokumenTemplate'])
                ->name('dokumen-templates.move-down');
            Route::delete('dokumen-templates/{template}', [PengaturanController::class, 'destroyDokumenTemplate'])
                ->name('dokumen-templates.destroy');

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

            // Master Sumber Lead
            Route::get('sumber-lead', [PengaturanController::class, 'sumberLead'])
                ->name('sumber-lead');
            Route::post('sumber-lead', [PengaturanController::class, 'storeSumberLead'])
                ->name('sumber-lead.store');
            Route::patch('sumber-lead/{sumberLead}', [PengaturanController::class, 'updateSumberLead'])
                ->name('sumber-lead.update');
            Route::delete('sumber-lead/{sumberLead}', [PengaturanController::class, 'destroySumberLead'])
                ->name('sumber-lead.destroy');

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

            // Warna Status (global, hanya warna — status_jual & pipeline KPR)
            Route::get('status-colors', [PengaturanController::class, 'statusColors'])
                ->name('status-colors');
            Route::patch('status-colors/{statusColor}', [PengaturanController::class, 'updateStatusColor'])
                ->name('status-colors.update');
        });

        // Admin Keuangan: Dana Jaminan & SBUM (preset), Notaris, Bank Rekanan.
        Route::middleware('permission:manage dajam sbum preset')->group(function () {
            Route::get('dajam-sbum', [PengaturanController::class, 'dajamSbum'])
                ->name('dajam-sbum');
            Route::post('dajam-sbum', [PengaturanController::class, 'storeDajamSbum'])
                ->name('dajam-sbum.store');
            Route::patch('dajam-sbum/{dajamSbum}', [PengaturanController::class, 'updateDajamSbum'])
                ->name('dajam-sbum.update');
            Route::delete('dajam-sbum/{dajamSbum}', [PengaturanController::class, 'destroyDajamSbum'])
                ->name('dajam-sbum.destroy');
        });

        Route::middleware('permission:manage system settings')->group(function () {
            Route::get('pengumuman', [\App\Http\Controllers\PengumumanController::class, 'index'])->name('pengumuman');
            Route::post('pengumuman', [\App\Http\Controllers\PengumumanController::class, 'store'])->name('pengumuman.store');
            Route::patch('pengumuman/{pengumuman}', [\App\Http\Controllers\PengumumanController::class, 'update'])->name('pengumuman.update');
            Route::delete('pengumuman/{pengumuman}', [\App\Http\Controllers\PengumumanController::class, 'destroy'])->name('pengumuman.destroy');
        });

        Route::middleware('permission:manage notaris')->group(function () {
            Route::get('notaris', [PengaturanController::class, 'notaris'])
                ->name('notaris');
            Route::post('notaris', [PengaturanController::class, 'storeNotaris'])
                ->name('notaris.store');
            Route::patch('notaris/{notaris}', [PengaturanController::class, 'updateNotaris'])
                ->name('notaris.update');
            Route::delete('notaris/{notaris}', [PengaturanController::class, 'destroyNotaris'])
                ->name('notaris.destroy');
        });

        Route::middleware('permission:manage bank rekanan')->group(function () {
            Route::get('bank-rekanan', [PengaturanController::class, 'bankRekanan'])
                ->name('bank-rekanan');
            Route::post('bank-rekanan', [PengaturanController::class, 'storeBankRekanan'])
                ->name('bank-rekanan.store');
            Route::patch('bank-rekanan/{bankRekanan}', [PengaturanController::class, 'updateBankRekanan'])
                ->name('bank-rekanan.update');
            Route::delete('bank-rekanan/{bankRekanan}', [PengaturanController::class, 'destroyBankRekanan'])
                ->name('bank-rekanan.destroy');
        });

        // Admin Proyek: Status Bangun (master), Kontraktor.
        Route::middleware('permission:manage status bangun master')->group(function () {
            Route::get('status-bangun', [PengaturanController::class, 'statusBangun'])
                ->name('status-bangun');
            Route::post('status-bangun', [PengaturanController::class, 'storeStatusBangunStage'])
                ->name('status-bangun.store');
            Route::patch('status-bangun/{statusBangunStage}', [PengaturanController::class, 'updateStatusBangunStage'])
                ->name('status-bangun.update');
            Route::delete('status-bangun/{statusBangunStage}', [PengaturanController::class, 'destroyStatusBangunStage'])
                ->name('status-bangun.destroy');
            Route::patch('status-bangun/{statusBangunStage}/move-up', [PengaturanController::class, 'moveUpStatusBangunStage'])
                ->name('status-bangun.move-up');
            Route::patch('status-bangun/{statusBangunStage}/move-down', [PengaturanController::class, 'moveDownStatusBangunStage'])
                ->name('status-bangun.move-down');
        });

        Route::middleware('permission:manage kontraktor')->group(function () {
            Route::get('kontraktor', [PengaturanController::class, 'kontraktor'])
                ->name('kontraktor');
            Route::post('kontraktor', [PengaturanController::class, 'storeKontraktor'])
                ->name('kontraktor.store');
            Route::patch('kontraktor/{kontraktor}', [PengaturanController::class, 'updateKontraktor'])
                ->name('kontraktor.update');
            Route::delete('kontraktor/{kontraktor}', [PengaturanController::class, 'destroyKontraktor'])
                ->name('kontraktor.destroy');
        });

        // Leader: Program All In, Sales / Agent.
        Route::middleware('permission:manage program all in')->group(function () {
            Route::get('program-all-in', [PengaturanController::class, 'programAllIn'])
                ->name('program-all-in');
            Route::post('program-all-in', [PengaturanController::class, 'storeProgramAllIn'])
                ->name('program-all-in.store');
            Route::patch('program-all-in/{programAllIn}', [PengaturanController::class, 'updateProgramAllIn'])
                ->name('program-all-in.update');
            Route::delete('program-all-in/{programAllIn}', [PengaturanController::class, 'destroyProgramAllIn'])
                ->name('program-all-in.destroy');
        });

        Route::middleware('permission:manage sales agent')->group(function () {
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

        // Urutan (geser naik/turun) — dipakai 6 jenis master sekaligus (lihat
        // moveUrutan()), jadi permission-nya dicek DI DALAM controller per
        // $type, bukan lewat middleware route (satu endpoint, banyak domain).
        Route::patch('urutan/{type}/{id}/{direction}', [PengaturanController::class, 'moveUrutan'])
            ->whereNumber('id')->whereIn('direction', ['up', 'down'])
            ->name('urutan.move');
    });

    // ── Wajib ganti password (akun baru / hasil reset) ────────────────────
    Route::get('password/wajib-ganti', [\App\Http\Controllers\Auth\ForcePasswordChangeController::class, 'show'])->name('password.wajib');
    Route::put('password/wajib-ganti', [\App\Http\Controllers\Auth\ForcePasswordChangeController::class, 'update'])->name('password.wajib.update');

    // ── Role Management + User Management (superadmin only) ───────────
    Route::middleware('role:superadmin')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::patch('roles/{user}/assign', [RoleController::class, 'assign'])->name('roles.assign');
        Route::post('users', [RoleController::class, 'storeUser'])->name('users.store');
        Route::post('users/{user}/reset-password', [RoleController::class, 'resetPassword'])->name('users.reset-password');
        Route::patch('users/{user}/aktif', [RoleController::class, 'toggleAktif'])->name('users.toggle-aktif');
        Route::delete('users/{user}', [RoleController::class, 'destroyUser'])->name('users.destroy');
        Route::post('projects/{project}/assign-users', [RoleController::class, 'assignProject'])
            ->name('projects.assign-users');
    });

    // ── Audit Trail (permission 'view audit trail', bukan hardcode superadmin
    // — supaya konsisten dengan pola RBAC lainnya, walau hari ini cuma
    // superadmin yang pegang permission itu) ──────────────────────────
    Route::middleware('permission:view audit trail')->group(function () {
        Route::get('audit-trail', [AuditTrailController::class, 'index'])->name('audit-trail.index');
    });
});

require __DIR__ . '/auth.php';
