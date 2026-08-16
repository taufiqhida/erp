<?php

namespace App\Http\Controllers;

use App\Models\DeveloperProfile;
use App\Models\DokumenTemplate;
use App\Models\PembiayaanProyek;
use App\Models\Project;
use App\Models\SuratTemplate;
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
                'nama_bank'          => $profile->nama_bank,
                'nomor_rekening'     => $profile->nomor_rekening,
                'atas_nama_rekening' => $profile->atas_nama_rekening,
                'logo_url'           => $profile->logo_path
                    ? Storage::disk('public')->url($profile->logo_path) : null,
                'kop_surat_url'      => $profile->kop_surat_path
                    ? Storage::disk('public')->url($profile->kop_surat_path) : null,
            ],
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
            'nama_bank'          => 'nullable|string|max:100',
            'nomor_rekening'     => 'nullable|string|max:50',
            'atas_nama_rekening' => 'nullable|string|max:100',
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
     | Pembiayaan Proyek
     --------------------------------------------------------------- */

    public function pembiayaan(): Response
    {
        $projects = Project::orderBy('nama')->get(['id', 'nama', 'kode']);
        $pembiayaans = PembiayaanProyek::with('project:id,nama')->get()
            ->keyBy('project_id');

        return Inertia::render('Pengaturan/PembiayaanProyek', [
            'projects'    => $projects,
            'pembiayaans' => $pembiayaans->map(fn($p) => [
                'project_id'          => $p->project_id,
                'kpr_subsidi_config'  => $p->kpr_subsidi_config ?? PembiayaanProyek::defaultKprSubsidi(),
                'kpr_komersil_config' => $p->kpr_komersil_config ?? [],
            ]),
            'defaultKprSubsidi' => PembiayaanProyek::defaultKprSubsidi(),
        ]);
    }

    public function updatePembiayaan(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'kpr_subsidi_config'  => 'nullable|array',
            'kpr_subsidi_config.*.key'    => 'required|string',
            'kpr_subsidi_config.*.label'  => 'required|string',
            'kpr_subsidi_config.*.jumlah' => 'required|numeric|min:0',
            'kpr_komersil_config' => 'nullable|array',
        ]);

        PembiayaanProyek::updateOrCreate(
            ['project_id' => $project->id],
            $validated
        );

        return back()->with('success', "Pengaturan pembiayaan proyek {$project->nama} berhasil disimpan.");
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
}
