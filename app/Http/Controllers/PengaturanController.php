<?php

namespace App\Http\Controllers;

use App\Models\BiayaTambahanPreset;
use App\Models\DajamSbumPreset;
use App\Models\DeveloperProfile;
use App\Models\DeveloperProfileBank;
use App\Models\DokumenTemplate;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\SuratTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $templates = DokumenTemplate::orderBy('cara_bayar')->orderBy('urutan')->get()
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
            'urutan'       => 'integer|min:0',
        ]);

        DokumenTemplate::create($validated);

        return back()->with('success', 'Template dokumen berhasil ditambahkan.');
    }

    public function updateDokumenTemplate(Request $request, DokumenTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required|string|max:100',
            'sifat'        => 'required|in:wajib,kondisional,opsional',
            'urutan'       => 'integer|min:0',
        ]);

        $template->update($validated);

        return back()->with('success', 'Template dokumen berhasil diperbarui.');
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
            'kategori'   => 'required|in:dajam,sbum,biaya_akad',
            'keterangan' => 'nullable|string|max:255',
        ]);

        DajamSbumPreset::create($validated);

        return back()->with('success', 'Preset berhasil ditambahkan.');
    }

    public function updateDajamSbum(Request $request, DajamSbumPreset $dajamSbum): RedirectResponse
    {
        $validated = $request->validate([
            'nama'       => 'required|string|max:150',
            'kategori'   => 'required|in:dajam,sbum,biaya_akad',
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
     | Template Surat
     --------------------------------------------------------------- */

    public function suratTemplates(): Response
    {
        $templates = SuratTemplate::orderBy('nama')->get(['id', 'nama', 'subjek', 'created_at']);

        return Inertia::render('Pengaturan/SuratTemplates/Index', [
            'templates'    => $templates,
            'placeholders' => SuratTemplate::availablePlaceholders(),
        ]);
    }

    public function createSuratTemplate(): Response
    {
        return Inertia::render('Pengaturan/SuratTemplates/Form', [
            'template'     => null,
            'placeholders' => SuratTemplate::availablePlaceholders(),
        ]);
    }

    public function storeSuratTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama'   => 'required|string|max:100',
            'subjek' => 'nullable|string|max:200',
            'isi'    => 'required|string',
        ]);

        $validated['created_by'] = Auth::id();
        SuratTemplate::create($validated);

        return redirect()->route('pengaturan.surat-templates')
            ->with('success', 'Template surat berhasil dibuat.');
    }

    public function editSuratTemplate(SuratTemplate $suratTemplate): Response
    {
        return Inertia::render('Pengaturan/SuratTemplates/Form', [
            'template' => [
                'id'     => $suratTemplate->id,
                'nama'   => $suratTemplate->nama,
                'subjek' => $suratTemplate->subjek,
                'isi'    => $suratTemplate->isi,
            ],
            'placeholders' => SuratTemplate::availablePlaceholders(),
        ]);
    }

    public function updateSuratTemplate(Request $request, SuratTemplate $suratTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'nama'   => 'required|string|max:100',
            'subjek' => 'nullable|string|max:200',
            'isi'    => 'required|string',
        ]);

        $suratTemplate->update($validated);

        return redirect()->route('pengaturan.surat-templates')
            ->with('success', 'Template surat berhasil diperbarui.');
    }

    public function destroySuratTemplate(SuratTemplate $suratTemplate): RedirectResponse
    {
        $suratTemplate->delete();
        return back()->with('success', 'Template surat berhasil dihapus.');
    }

    /* ---------------------------------------------------------------
     | Preset Biaya Tambahan
     --------------------------------------------------------------- */

    public function biayaTambahan(): Response
    {
        return Inertia::render('Pengaturan/BiayaTambahan', [
            'presets' => BiayaTambahanPreset::orderBy('nama')->get(),
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
     | Master Sales / Agent
     --------------------------------------------------------------- */

    public function salesAgents(): Response
    {
        $agents = SalesAgent::with('user:id,name')
            ->orderBy('nama')
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'nama'        => $a->nama,
                'tipe'        => $a->tipe,
                'tipe_label'  => $a->tipe_label,
                'user_nama'   => $a->user?->name,
                'no_hp'       => $a->no_hp,
                'email'       => $a->email,
                'agency_nama' => $a->agency_nama,
                'komisi_label' => $a->komisi_label,
                'is_active'   => $a->is_active,
            ]);

        return Inertia::render('Pengaturan/SalesAgents/Index', [
            'agents' => $agents,
        ]);
    }

    public function createSalesAgent(): Response
    {
        return Inertia::render('Pengaturan/SalesAgents/Form', [
            'agent' => null,
            'users' => User::role('sales')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function salesAgentRules(?SalesAgent $agent = null): array
    {
        $userUniqueId = $agent?->id;

        return [
            'nama'                => 'required|string|max:150',
            'tipe'                => 'required|in:inhouse,freelance',
            'user_id'             => "nullable|exists:users,id|unique:sales_agents,user_id,{$userUniqueId}",
            'nik'                 => 'nullable|string|max:20',
            'npwp'                => 'nullable|string|max:30',
            'no_hp'               => 'nullable|string|max:20',
            'email'               => 'nullable|email|max:100',
            'nama_bank'           => 'nullable|string|max:100',
            'nomor_rekening'      => 'nullable|string|max:50',
            'atas_nama_rekening'  => 'nullable|string|max:100',
            'agency_nama'         => 'nullable|string|max:150',
            'komisi_tipe'         => 'required|in:nominal,persen',
            'komisi_nilai'        => 'nullable|numeric|min:0',
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
            'users' => User::role('sales')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateSalesAgent(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        $validated = $request->validate(array_merge(
            $this->salesAgentRules($salesAgent),
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
