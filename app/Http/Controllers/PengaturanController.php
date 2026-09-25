<?php

namespace App\Http\Controllers;

use App\Models\BankRekananPreset;
use App\Models\BiayaTambahanPreset;
use App\Models\DajamSbumPreset;
use App\Models\DeveloperProfile;
use App\Models\DeveloperProfileBank;
use App\Models\DokumenTemplate;
use App\Models\Kavling;
use App\Models\Kontraktor;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\StatusColor;
use App\Models\NotarisPreset;
use App\Models\SumberLead;
use App\Models\SuratTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PengaturanController extends Controller
{
    /* ---------------------------------------------------------------
     | Profil Developer
     --------------------------------------------------------------- */

    public function developerProfile(): Response
    {
        $profile = DeveloperProfile::getSingleton();

        return Inertia::render('Pengaturan/ProfilDeveloper', [
            'profile' => [
                'id'                 => $profile->id,
                'nama_developer'     => $profile->nama_developer,
                'alamat'             => $profile->alamat,
                'telepon'            => $profile->telepon,
                'email'              => $profile->email,
                'npwp'               => $profile->npwp,
                'nama_penandatangan'    => $profile->nama_penandatangan,
                'jabatan_penandatangan' => $profile->jabatan_penandatangan,
                'logo_url'           => $profile->logo_path
                    ? route('media.show', ['path' => $profile->logo_path]) : null,
                'kop_surat_url'      => $profile->kop_surat_path
                    ? route('media.show', ['path' => $profile->kop_surat_path]) : null,
            ],
            'banks' => $profile->banks()->orderByDesc('is_primary')->orderBy('nama_bank')->get(),
        ]);
    }

    public function updateDeveloperProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama_developer'     => 'nullable|string|max:200',
            'alamat'             => 'nullable|string',
            'telepon'            => 'nullable|string|max:30',
            'email'              => 'nullable|email|max:100',
            'npwp'               => 'nullable|string|max:30',
            'nama_penandatangan'    => 'nullable|string|max:100',
            'jabatan_penandatangan' => 'nullable|string|max:100',
            'logo'               => 'nullable|image|mimes:png,jpg,jpeg,svg|max:2048',
            'kop_surat'          => 'nullable|image|mimes:png,jpg,jpeg|max:5120',
        ]);

        $profile = DeveloperProfile::getSingleton();

        // Handle logo upload
        if ($request->hasFile('logo')) {
            if ($profile->logo_path) Storage::disk('public')->delete($profile->logo_path);
            $validated['logo_path'] = $request->file('logo')->store('developer', 'public');
        }
        unset($validated['logo']);

        // Handle kop surat upload
        if ($request->hasFile('kop_surat')) {
            if ($profile->kop_surat_path) Storage::disk('public')->delete($profile->kop_surat_path);
            $validated['kop_surat_path'] = $request->file('kop_surat')->store('developer', 'public');
        }
        unset($validated['kop_surat']);

        $profile->update($validated);

        return back()->with('success', 'Profil developer berhasil diperbarui.');
    }

    /* ---------------------------------------------------------------
     | Rekening Bank Developer (multi-bank)
     --------------------------------------------------------------- */

    public function storeDeveloperBank(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama_bank'          => 'required|string|max:100',
            'nomor_rekening'     => 'nullable|string|max:50',
            'atas_nama_rekening' => 'nullable|string|max:100',
        ]);

        $profile = DeveloperProfile::getSingleton();
        $validated['developer_profile_id'] = $profile->id;
        $validated['is_primary'] = !$profile->banks()->exists();

        DeveloperProfileBank::create($validated);

        return back()->with('success', 'Rekening bank berhasil ditambahkan.');
    }

    public function updateDeveloperBank(Request $request, DeveloperProfileBank $bank): RedirectResponse
    {
        $validated = $request->validate([
            'nama_bank'          => 'required|string|max:100',
            'nomor_rekening'     => 'nullable|string|max:50',
            'atas_nama_rekening' => 'nullable|string|max:100',
        ]);

        $bank->update($validated);

        return back()->with('success', 'Rekening bank berhasil diperbarui.');
    }

    public function destroyDeveloperBank(DeveloperProfileBank $bank): RedirectResponse
    {
        $wasPrimary = $bank->is_primary;
        $profileId = $bank->developer_profile_id;
        $bank->delete();

        // Kalau yang dihapus adalah rekening utama, jadikan rekening lain (jika ada) sebagai utama.
        if ($wasPrimary) {
            DeveloperProfileBank::where('developer_profile_id', $profileId)
                ->orderBy('id')
                ->first()
                ?->update(['is_primary' => true]);
        }

        return back()->with('success', 'Rekening bank berhasil dihapus.');
    }

    public function setPrimaryDeveloperBank(DeveloperProfileBank $bank): RedirectResponse
    {
        DeveloperProfileBank::where('developer_profile_id', $bank->developer_profile_id)
            ->update(['is_primary' => false]);
        $bank->update(['is_primary' => true]);

        return back()->with('success', "Rekening {$bank->nama_bank} dijadikan rekening utama.");
    }

    /* ---------------------------------------------------------------
     | Template Pemberkasan (Dokumen)
     --------------------------------------------------------------- */

    public function dokumenTemplates(): Response
    {
        $templates = DokumenTemplate::orderBy('cara_bayar')->orderBy('urutan')->orderBy('id')->get()
            ->groupBy('cara_bayar')
            ->map(fn($items) => $items->map(fn($t) => [
                'id'           => $t->id,
                'nama_dokumen' => $t->nama_dokumen,
                'sifat'        => $t->sifat,
                'sifat_label'  => $t->sifat_label,
                'urutan'       => $t->urutan,
            ]));

        return Inertia::render('Pengaturan/DokumenTemplates', [
            'templates'         => $templates,
            'caraBayarOptions'  => DokumenTemplate::caraBayarLabel(),
            'sifatOptions'      => DokumenTemplate::sifatLabel(),
        ]);
    }

    public function storeDokumenTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cara_bayar'   => 'required|in:cash,cash_bertahap,kpr_subsidi,kpr_komersil',
            'nama_dokumen' => 'required|string|max:100',
            'sifat'        => 'required|in:wajib,kondisional,opsional',
        ]);

        // Item baru selalu masuk paling bawah di cara bayarnya; urutan diatur lewat tombol geser.
        $validated['urutan'] = (DokumenTemplate::where('cara_bayar', $validated['cara_bayar'])->max('urutan') ?? 0) + 1;

        DokumenTemplate::create($validated);

        return back()->with('success', 'Template dokumen berhasil ditambahkan.');
    }

    public function updateDokumenTemplate(Request $request, DokumenTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required|string|max:100',
            'sifat'        => 'required|in:wajib,kondisional,opsional',
        ]);

        $template->update($validated);

        return back()->with('success', 'Template dokumen berhasil diperbarui.');
    }

    public function moveUpDokumenTemplate(DokumenTemplate $template): RedirectResponse
    {
        $this->swapDokumenTemplateOrder($template, 'up');
        return back();
    }

    public function moveDownDokumenTemplate(DokumenTemplate $template): RedirectResponse
    {
        $this->swapDokumenTemplateOrder($template, 'down');
        return back();
    }

    private function swapDokumenTemplateOrder(DokumenTemplate $template, string $direction): void
    {
        $siblings = DokumenTemplate::where('cara_bayar', $template->cara_bayar);

        $neighbor = $direction === 'up'
            ? $siblings->where('urutan', '<', $template->urutan)->orderByDesc('urutan')->first()
            : $siblings->where('urutan', '>', $template->urutan)->orderBy('urutan')->first();

        if (!$neighbor) return;

        DB::transaction(function () use ($template, $neighbor) {
            $urutan = $template->urutan;
            $template->update(['urutan' => $neighbor->urutan]);
            $neighbor->update(['urutan' => $urutan]);
        });
    }

    public function destroyDokumenTemplate(DokumenTemplate $template): RedirectResponse
    {
        $template->delete();
        return back()->with('success', 'Template dokumen berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Dana Jaminan & SBUM (library global, tanpa nominal — nominal
     | ditentukan fleksibel per konsumen/bank saat booking, bukan di sini)
     --------------------------------------------------------------- */

    public function dajamSbum(): Response
    {
        return Inertia::render('Pengaturan/DajamSbum', [
            'presets'         => DajamSbumPreset::orderBy('kategori')->orderBy('nama')->get(),
            'kategoriOptions' => DajamSbumPreset::kategoriLabel(),
        ]);
    }

    public function storeDajamSbum(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'       => 'required|string|max:150',
            'kategori'   => 'required|in:dajam,sbum',
            'keterangan' => 'nullable|string|max:255',
        ]);

        DajamSbumPreset::create($validated);

        return back()->with('success', 'Preset berhasil ditambahkan.');
    }

    public function updateDajamSbum(Request $request, DajamSbumPreset $dajamSbum): RedirectResponse
    {
        $validated = $request->validate([
            'nama'       => 'required|string|max:150',
            'kategori'   => 'required|in:dajam,sbum',
            'keterangan' => 'nullable|string|max:255',
            'is_active'  => 'boolean',
        ]);

        $dajamSbum->update($validated);

        return back()->with('success', 'Preset berhasil diperbarui.');
    }

    public function destroyDajamSbum(DajamSbumPreset $dajamSbum): RedirectResponse
    {
        $dajamSbum->delete();
        return back()->with('success', 'Preset berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Warna Status (global) — hanya warna badge/marker untuk status_jual
     | & pipeline KPR (status_penjualan) yang admin-editable. Daftar status
     | & labelnya TETAP system-driven (StatusJual enum, KavlingKonsumen::
     | getStatusPenjualanLabelAttribute()) — tidak ada tambah/hapus baris,
     | cuma ganti warna dari set yang sudah di-seed lengkap.
     --------------------------------------------------------------- */

    public function statusColors(): Response
    {
        $statusJualLabels = collect(\App\Enums\StatusJual::cases())
            ->mapWithKeys(fn($s) => [$s->value => $s->label()]);

        $statusPenjualanLabels = collect([
            'booking'      => 'Booking',
            'pemberkasan'  => 'Pemberkasan',
            'proses_bank'  => 'Proses Bank / SLIK',
            'sp3k'         => 'SP3K',
            'rencana_akad' => 'Rencana Akad',
            'akad'         => 'Akad',
            'bast'         => 'BAST',
            'batal'        => 'Batal',
        ]);

        $withLabel = fn($kategori, $labels) => StatusColor::where('kategori', $kategori)
            ->get()
            ->map(fn($c) => ['id' => $c->id, 'kode' => $c->kode, 'warna' => $c->warna, 'label' => $labels->get($c->kode, $c->kode)])
            ->sortBy(fn($c) => array_search($c['kode'], $labels->keys()->all()))
            ->values();

        return Inertia::render('Pengaturan/StatusColors', [
            'statusJual'      => $withLabel('status_jual', $statusJualLabels),
            'statusPenjualan' => $withLabel('status_penjualan', $statusPenjualanLabels),
        ]);
    }

    public function updateStatusColor(Request $request, StatusColor $statusColor): RedirectResponse
    {
        $validated = $request->validate([
            'warna' => 'required|string|max:7',
        ]);

        $statusColor->update($validated);

        return back()->with('success', 'Warna berhasil diperbarui.');
    }

    /* ---------------------------------------------------------------
     | Master Status Bangun (global) — tahap progress pembangunan kavling
     | dengan bobot custom, menggantikan enum StatusBangun lama. Progress
     | dihitung linear-kumulatif berdasarkan `urutan`, jadi total bobot
     | seluruh tahap idealnya selalu 100 (ditampilkan sebagai banner live
     | di frontend, tidak di-hard-block per aksi supaya rebalance bertahap
     | antar beberapa tahap tetap bisa dilakukan).
     --------------------------------------------------------------- */

    public function statusBangun(): Response
    {
        return Inertia::render('Pengaturan/StatusBangun', [
            'stages' => StatusBangunStage::ordered()->withCount('kavlings')->get(),
        ]);
    }

    public function storeStatusBangunStage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'  => 'required|string|max:100',
            'bobot' => 'required|numeric|min:0|max:100',
            'warna' => 'nullable|string|max:7',
        ]);

        StatusBangunStage::create([
            'nama'   => $validated['nama'],
            'bobot'  => $validated['bobot'],
            'warna'  => $validated['warna'] ?? '#64748b',
            'urutan' => (StatusBangunStage::max('urutan') ?? 0) + 1,
        ]);

        return back()->with('success', 'Tahap berhasil ditambahkan.');
    }

    public function updateStatusBangunStage(Request $request, StatusBangunStage $statusBangunStage): RedirectResponse
    {
        $validated = $request->validate([
            'nama'  => 'required|string|max:100',
            'bobot' => 'required|numeric|min:0|max:100',
            'warna' => 'nullable|string|max:7',
        ]);

        // Bobot "Belum Mulai" selalu 0 — tidak ikut menyusun progress apapun,
        // dikunci di server terlepas dari apa yang dikirim client.
        if ($statusBangunStage->is_default) {
            $validated['bobot'] = 0;
        }

        $statusBangunStage->update($validated);

        return back()->with('success', 'Tahap berhasil diperbarui.');
    }

    /**
     * Hapus tahap. "Belum Mulai" (is_default) tidak pernah bisa dihapus —
     * dijamin selalu ada, jadi tahap lain manapun yang dihapus SELALU
     * punya target revert yang valid untuk kavling yang masih memakainya.
     */
    public function destroyStatusBangunStage(StatusBangunStage $statusBangunStage): RedirectResponse
    {
        abort_if($statusBangunStage->is_default, 422, '"Belum Mulai" tidak bisa dihapus.');

        $affectedCount = Kavling::where('status_bangun_stage_id', $statusBangunStage->id)->count();
        $previous = StatusBangunStage::where('urutan', '<', $statusBangunStage->urutan)
            ->orderByDesc('urutan')
            ->first();

        DB::transaction(function () use ($statusBangunStage, $previous, $affectedCount) {
            if ($affectedCount > 0) {
                Kavling::where('status_bangun_stage_id', $statusBangunStage->id)
                    ->update(['status_bangun_stage_id' => $previous->id]);
            }
            $statusBangunStage->delete();
        });

        $msg = $affectedCount > 0
            ? "Tahap \"{$statusBangunStage->nama}\" dihapus, {$affectedCount} kavling dipindahkan ke \"{$previous->nama}\"."
            : "Tahap \"{$statusBangunStage->nama}\" berhasil dihapus.";

        return back()->with('success', $msg);
    }

    public function moveUpStatusBangunStage(StatusBangunStage $statusBangunStage): RedirectResponse
    {
        $this->swapStatusBangunStageOrder($statusBangunStage, 'up');
        return back();
    }

    public function moveDownStatusBangunStage(StatusBangunStage $statusBangunStage): RedirectResponse
    {
        $this->swapStatusBangunStageOrder($statusBangunStage, 'down');
        return back();
    }

    /**
     * "Belum Mulai" selalu di urutan pertama & tidak bisa digeser — kalau
     * tetangganya adalah "Belum Mulai", tukar posisi ditolak (bukan cuma
     * tombolnya disembunyikan di frontend, dijaga juga di server).
     */
    private function swapStatusBangunStageOrder(StatusBangunStage $stage, string $direction): void
    {
        if ($stage->is_default) return;

        $neighbor = $direction === 'up'
            ? StatusBangunStage::where('urutan', '<', $stage->urutan)->orderByDesc('urutan')->first()
            : StatusBangunStage::where('urutan', '>', $stage->urutan)->orderBy('urutan')->first();

        if (!$neighbor || $neighbor->is_default) return;

        DB::transaction(function () use ($stage, $neighbor) {
            $stageUrutan = $stage->urutan;
            $stage->update(['urutan' => $neighbor->urutan]);
            $neighbor->update(['urutan' => $stageUrutan]);
        });
    }

    /* ---------------------------------------------------------------
     | Template Surat
     --------------------------------------------------------------- */

    public function suratTemplates(): Response
    {
        $templates = SuratTemplate::orderBy('nama')->get(['id', 'nama', 'file_original_name', 'updated_at']);

        return Inertia::render('Pengaturan/SuratTemplates/Index', [
            'templates' => $templates->map(fn($t) => [
                'id'                  => $t->id,
                'nama'                => $t->nama,
                'file_original_name'  => $t->file_original_name,
                'updated_at'          => $t->updated_at->format('d M Y'),
            ]),
            'placeholders'           => SuratTemplate::availablePlaceholders(),
            'jadwalPlaceholders'     => SuratTemplate::jadwalPembayaranPlaceholders(),
        ]);
    }

    public function createSuratTemplate(): Response
    {
        return Inertia::render('Pengaturan/SuratTemplates/Form', [
            'template'           => null,
            'placeholders'       => SuratTemplate::availablePlaceholders(),
            'jadwalPlaceholders' => SuratTemplate::jadwalPembayaranPlaceholders(),
        ]);
    }

    public function storeSuratTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'file' => 'required|file|mimes:docx',
        ]);

        $file = $request->file('file');
        SuratTemplate::create([
            'nama'                => $validated['nama'],
            'file_path'           => $file->store('surat-templates'),
            'file_original_name'  => $file->getClientOriginalName(),
            'created_by'          => Auth::id(),
        ]);

        return redirect()->route('pengaturan.surat-templates')
            ->with('success', 'Template surat berhasil dibuat.');
    }

    public function editSuratTemplate(SuratTemplate $suratTemplate): Response
    {
        return Inertia::render('Pengaturan/SuratTemplates/Form', [
            'template' => [
                'id'                 => $suratTemplate->id,
                'nama'               => $suratTemplate->nama,
                'file_original_name' => $suratTemplate->file_original_name,
            ],
            'placeholders'       => SuratTemplate::availablePlaceholders(),
            'jadwalPlaceholders' => SuratTemplate::jadwalPembayaranPlaceholders(),
        ]);
    }

    public function updateSuratTemplate(Request $request, SuratTemplate $suratTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'file' => 'nullable|file|mimes:docx',
        ]);

        if ($request->hasFile('file')) {
            Storage::delete($suratTemplate->file_path);
            $file = $request->file('file');
            $validated['file_path'] = $file->store('surat-templates');
            $validated['file_original_name'] = $file->getClientOriginalName();
        }
        unset($validated['file']);

        $suratTemplate->update($validated);

        return redirect()->route('pengaturan.surat-templates')
            ->with('success', 'Template surat berhasil diperbarui.');
    }

    public function destroySuratTemplate(SuratTemplate $suratTemplate): RedirectResponse
    {
        Storage::delete($suratTemplate->file_path);
        $suratTemplate->delete();
        return back()->with('success', 'Template surat berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Preset Biaya Tambahan
     --------------------------------------------------------------- */

    public function biayaTambahan(): Response
    {
        return Inertia::render('Pengaturan/BiayaTambahan', [
            'presets' => BiayaTambahanPreset::ordered()->get(),
        ]);
    }

    public function storeBiayaTambahan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'             => 'required|string|max:150',
            'keterangan'       => 'nullable|string|max:255',
        ]);

        BiayaTambahanPreset::create($validated);

        return back()->with('success', 'Preset biaya tambahan berhasil ditambahkan.');
    }

    public function updateBiayaTambahan(Request $request, BiayaTambahanPreset $biayaTambahan): RedirectResponse
    {
        $validated = $request->validate([
            'nama'             => 'required|string|max:150',
            'keterangan'       => 'nullable|string|max:255',
            'is_active'        => 'boolean',
        ]);

        $biayaTambahan->update($validated);

        return back()->with('success', 'Preset biaya tambahan berhasil diperbarui.');
    }

    public function destroyBiayaTambahan(BiayaTambahanPreset $biayaTambahan): RedirectResponse
    {
        $biayaTambahan->delete();
        return back()->with('success', 'Preset biaya tambahan berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Program All In — bundel nominal yang mencakup Booking Fee/DP/Titipan
     | Biaya Akad. Dipilih di form booking, hasilnya baris "Titipan Biaya
     | Akad" di Kartu Piutang (nominal All In dikurangi komponen yang
     | di-include).
     --------------------------------------------------------------- */

    public function programAllIn(): Response
    {
        return Inertia::render('Pengaturan/ProgramAllIn', [
            'presets' => ProgramAllInPreset::ordered()->get(),
        ]);
    }

    public function storeProgramAllIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'                 => 'required|string|max:150',
            'nominal'              => 'required|numeric|min:0',
            'include_booking_fee'  => 'boolean',
            'include_dp'           => 'boolean',
        ]);

        ProgramAllInPreset::create($validated);

        return back()->with('success', 'Program All In berhasil ditambahkan.');
    }

    public function updateProgramAllIn(Request $request, ProgramAllInPreset $programAllIn): RedirectResponse
    {
        $validated = $request->validate([
            'nama'                 => 'required|string|max:150',
            'nominal'              => 'required|numeric|min:0',
            'include_booking_fee'  => 'boolean',
            'include_dp'           => 'boolean',
            'is_active'            => 'boolean',
        ]);

        $programAllIn->update($validated);

        return back()->with('success', 'Program All In berhasil diperbarui.');
    }

    public function destroyProgramAllIn(ProgramAllInPreset $programAllIn): RedirectResponse
    {
        $programAllIn->delete();
        return back()->with('success', 'Program All In berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Master Sumber Lead (global) — dropdown asal lead konsumen, diisi
     | sales saat booking konsumen baru.
     --------------------------------------------------------------- */

    public function sumberLead(): Response
    {
        return Inertia::render('Pengaturan/SumberLead', [
            'presets' => SumberLead::ordered()->get(),
        ]);
    }

    public function storeSumberLead(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'        => 'required|string|max:100',
            'keterangan'  => 'nullable|string|max:255',
            'is_referral' => 'boolean',
        ]);

        SumberLead::create($validated);

        return back()->with('success', 'Sumber lead berhasil ditambahkan.');
    }

    public function updateSumberLead(Request $request, SumberLead $sumberLead): RedirectResponse
    {
        $validated = $request->validate([
            'nama'        => 'required|string|max:100',
            'keterangan'  => 'nullable|string|max:255',
            'is_referral' => 'boolean',
            'is_active'   => 'boolean',
        ]);

        $sumberLead->update($validated);

        return back()->with('success', 'Sumber lead berhasil diperbarui.');
    }

    public function destroySumberLead(SumberLead $sumberLead): RedirectResponse
    {
        $sumberLead->delete();
        return back()->with('success', 'Sumber lead berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Master Notaris (global) — dropdown notaris yang menangani akad,
     | dipilih di tahap Rencana Akad.
     --------------------------------------------------------------- */

    public function notaris(): Response
    {
        return Inertia::render('Pengaturan/Notaris', [
            'presets' => NotarisPreset::ordered()->get(),
        ]);
    }

    public function storeNotaris(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:150',
        ]);

        NotarisPreset::create($validated);

        return back()->with('success', 'Notaris berhasil ditambahkan.');
    }

    public function updateNotaris(Request $request, NotarisPreset $notaris): RedirectResponse
    {
        $validated = $request->validate([
            'nama'      => 'required|string|max:150',
            'is_active' => 'boolean',
        ]);

        $notaris->update($validated);

        return back()->with('success', 'Notaris berhasil diperbarui.');
    }

    public function destroyNotaris(NotarisPreset $notaris): RedirectResponse
    {
        $notaris->delete();
        return back()->with('success', 'Notaris berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Master Bank Rekanan KPR (global) — dropdown pilihan bank di tahap
     | Pemberkasan/Proses Bank, menggantikan input teks bebas.
     --------------------------------------------------------------- */

    public function bankRekanan(): Response
    {
        return Inertia::render('Pengaturan/BankRekanan', [
            'presets' => BankRekananPreset::ordered()->get(),
        ]);
    }

    public function storeBankRekanan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'          => 'required|string|max:100',
            'nama_pt'       => 'nullable|string|max:150',
            'kantor_cabang' => 'nullable|string|max:100',
            'keterangan'    => 'nullable|string|max:255',
            'alamat'        => 'nullable|string',
        ]);

        BankRekananPreset::create($validated);

        return back()->with('success', 'Bank rekanan berhasil ditambahkan.');
    }

    public function updateBankRekanan(Request $request, BankRekananPreset $bankRekanan): RedirectResponse
    {
        $validated = $request->validate([
            'nama'          => 'required|string|max:100',
            'nama_pt'       => 'nullable|string|max:150',
            'kantor_cabang' => 'nullable|string|max:100',
            'keterangan'    => 'nullable|string|max:255',
            'alamat'        => 'nullable|string',
            'is_active'     => 'boolean',
        ]);

        $bankRekanan->update($validated);

        return back()->with('success', 'Bank rekanan berhasil diperbarui.');
    }

    public function destroyBankRekanan(BankRekananPreset $bankRekanan): RedirectResponse
    {
        $bankRekanan->delete();
        return back()->with('success', 'Bank rekanan berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Master Kontraktor — dipakai saat terbitkan SPK (Proses Bangun)
     --------------------------------------------------------------- */

    public function kontraktor(): Response
    {
        return Inertia::render('Pengaturan/Kontraktor', [
            'kontraktors' => Kontraktor::orderBy('nama')->get(),
        ]);
    }

    public function storeKontraktor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'   => 'required|string|max:150',
            'no_hp'  => 'nullable|string|max:30',
            'alamat' => 'nullable|string',
        ]);

        Kontraktor::create($validated);

        return back()->with('success', 'Kontraktor berhasil ditambahkan.');
    }

    public function updateKontraktor(Request $request, Kontraktor $kontraktor): RedirectResponse
    {
        $validated = $request->validate([
            'nama'      => 'required|string|max:150',
            'no_hp'     => 'nullable|string|max:30',
            'alamat'    => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $kontraktor->update($validated);

        return back()->with('success', 'Kontraktor berhasil diperbarui.');
    }

    public function destroyKontraktor(Kontraktor $kontraktor): RedirectResponse
    {
        abort_if($kontraktor->spks()->exists(), 422, 'Kontraktor ini sudah punya riwayat SPK, tidak bisa dihapus — nonaktifkan saja.');
        $kontraktor->delete();
        return back()->with('success', 'Kontraktor berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Preset Promo
     --------------------------------------------------------------- */

    public function promo(): Response
    {
        return Inertia::render('Pengaturan/Promo', [
            'presets' => PromoPreset::orderBy('nama')->get(),
        ]);
    }

    public function storePromo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'       => 'required|string|max:150',
            'keterangan' => 'nullable|string|max:255',
        ]);

        PromoPreset::create($validated);

        return back()->with('success', 'Preset promo berhasil ditambahkan.');
    }

    public function updatePromo(Request $request, PromoPreset $promo): RedirectResponse
    {
        $validated = $request->validate([
            'nama'       => 'required|string|max:150',
            'keterangan' => 'nullable|string|max:255',
            'is_active'  => 'boolean',
        ]);

        $promo->update($validated);

        return back()->with('success', 'Preset promo berhasil diperbarui.');
    }

    public function destroyPromo(PromoPreset $promo): RedirectResponse
    {
        $promo->delete();
        return back()->with('success', 'Preset promo berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Preset Skema DP
     --------------------------------------------------------------- */

    public function skemaDp(): Response
    {
        return Inertia::render('Pengaturan/SkemaDp', [
            'presets'          => SkemaDpPreset::orderBy('nama')->get(),
            'caraBayarOptions' => SkemaDpPreset::caraBayarLabel(),
            'basisOptions'     => SkemaDpPreset::basisLabel(),
        ]);
    }

    private function skemaDpRules(): array
    {
        return [
            'nama'                          => 'required|string|max:150',
            'cara_bayar'                    => 'nullable|in:cash,cash_bertahap,kpr_subsidi,kpr_komersil',
            'booking_fee_aktif'             => 'boolean',
            'booking_fee_tipe'              => 'required_if:booking_fee_aktif,true|nullable|in:nominal,persen',
            'booking_fee_nilai'             => 'required_if:booking_fee_aktif,true|nullable|numeric|min:0',
            'booking_fee_tenor'             => 'required|integer|min:1|max:360',
            'booking_fee_masuk_harga_jual'  => 'boolean',
            'booking_fee_basis'             => 'required|in:harga_dasar,harga_netto',
            'dp_aktif'                      => 'boolean',
            'dp_tipe'                       => 'required_if:dp_aktif,true|nullable|in:nominal,persen',
            'dp_nilai'                      => 'required_if:dp_aktif,true|nullable|numeric|min:0',
            'dp_tenor'                      => 'required|integer|min:1|max:360',
            'dp_masuk_harga_jual'           => 'boolean',
            'dp_basis'                      => 'required|in:harga_dasar,harga_netto',
            'keterangan'                    => 'nullable|string|max:255',
        ];
    }

    public function storeSkemaDp(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->skemaDpRules());

        SkemaDpPreset::create($validated);

        return back()->with('success', 'Preset skema DP berhasil ditambahkan.');
    }

    public function updateSkemaDp(Request $request, SkemaDpPreset $skemaDp): RedirectResponse
    {
        $validated = $request->validate(array_merge($this->skemaDpRules(), [
            'is_active' => 'boolean',
        ]));

        $skemaDp->update($validated);

        return back()->with('success', 'Preset skema DP berhasil diperbarui.');
    }

    public function destroySkemaDp(SkemaDpPreset $skemaDp): RedirectResponse
    {
        $skemaDp->delete();
        return back()->with('success', 'Preset skema DP berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Urutan master data — satu endpoint untuk semua master yang bisa
     | digeser naik/turun (whitelist model, bukan class dari input user).
     --------------------------------------------------------------- */

    public function moveUrutan(string $type, int $id, string $direction): RedirectResponse
    {
        $models = [
            'biaya-tambahan' => BiayaTambahanPreset::class,
            'program-all-in' => ProgramAllInPreset::class,
            'sumber-lead'    => SumberLead::class,
            'bank-rekanan'   => BankRekananPreset::class,
            'notaris'        => NotarisPreset::class,
            'sales-agent'    => SalesAgent::class,
        ];

        abort_unless(isset($models[$type]) && in_array($direction, ['up', 'down'], true), 404);

        $models[$type]::findOrFail($id)->moveUrutan($direction);

        return back();
    }

    /* ---------------------------------------------------------------
     | Master Sales / Agent
     --------------------------------------------------------------- */

    public function salesAgents(): Response
    {
        $agents = SalesAgent::ordered()
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'nama'        => $a->nama,
                'tipe'        => $a->tipe,
                'tipe_label'  => $a->tipe_label,
                'is_active'   => $a->is_active,
            ]);

        return Inertia::render('Pengaturan/SalesAgents/Index', [
            'agents' => $agents,
            'tipeOptions' => SalesAgent::tipeLabels(),
        ]);
    }

    public function createSalesAgent(): Response
    {
        return Inertia::render('Pengaturan/SalesAgents/Form', [
            'agent' => null,
            'tipeOptions' => SalesAgent::tipeLabels(),
        ]);
    }

    private function salesAgentRules(): array
    {
        return [
            'nama' => 'required|string|max:150',
            'tipe' => 'required|in:' . implode(',', array_keys(SalesAgent::tipeLabels())),
        ];
    }

    public function storeSalesAgent(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->salesAgentRules());

        SalesAgent::create($validated);

        return redirect()->route('pengaturan.sales-agents')
            ->with('success', 'Sales/Agent berhasil ditambahkan.');
    }

    public function editSalesAgent(SalesAgent $salesAgent): Response
    {
        return Inertia::render('Pengaturan/SalesAgents/Form', [
            'agent' => $salesAgent,
            'tipeOptions' => SalesAgent::tipeLabels(),
        ]);
    }

    public function updateSalesAgent(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        $validated = $request->validate(array_merge(
            $this->salesAgentRules(),
            ['is_active' => 'boolean']
        ));

        $salesAgent->update($validated);

        return redirect()->route('pengaturan.sales-agents')
            ->with('success', 'Sales/Agent berhasil diperbarui.');
    }

    public function destroySalesAgent(SalesAgent $salesAgent): RedirectResponse
    {
        $salesAgent->delete();
        return back()->with('success', 'Sales/Agent berhasil dihapus.');
    }

    public function toggleSalesAgent(SalesAgent $salesAgent): RedirectResponse
    {
        $salesAgent->update(['is_active' => !$salesAgent->is_active]);
        return back();
    }
}
