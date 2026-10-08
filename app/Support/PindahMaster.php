<?php

namespace App\Support;

use App\Models\BankRekananPreset;
use App\Models\BiayaTambahanPreset;
use App\Models\DajamSbumPreset;
use App\Models\DeveloperProfile;
use App\Models\DokumenTemplate;
use App\Models\Kontraktor;
use App\Models\NotarisPreset;
use App\Models\ProgramAllInPreset;
use App\Models\PromoPreset;
use App\Models\SalesAgent;
use App\Models\SkemaDpPreset;
use App\Models\StatusBangunStage;
use App\Models\StatusColor;
use App\Models\SumberLead;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Pindah master data (pengaturan global/preset) antar server — mis. dari staging ke production — lewat file JSON.
 *
 * Aturan inti:
 *  - Hanya master yang tertulis di definisi(); akun, proyek, kavling, konsumen, transaksi TIDAK pernah ikut.
 *  - Pencocokan memakai NAMA (bukan id, karena id beda antar server), tanpa membedakan huruf besar/kecil.
 *  - Yang belum ada dibuat; yang sudah ada dilewati, kecuali pengguna meminta "perbarui" (hanya nilai yang beda).
 *  - proses() dijalankan di dalam transaksi database: pratinjau = proses yang SAMA lalu dibatalkan (rollback),
 *    penerapan = di-commit hanya kalau tidak ada masalah sama sekali. Tidak pernah ada data setengah masuk.
 *  - File (logo, kop surat, template Word) tidak ikut; diunggah ulang lewat halamannya masing-masing.
 */
class PindahMaster
{
    public const FORMAT = 'ssid-master';
    public const VERSI = 1;
    public const PROFIL = 'profil_developer';

    private const WARNA = 'regex:/^#[0-9a-fA-F]{6}$/';

    /** @return array<string, array<string, mixed>> */
    public static function definisi(): array
    {
        $bool = 'nullable|boolean';
        $cara = 'in:cash,cash_bertahap,kpr_subsidi,kpr_komersil';
        $basis = 'nullable|in:harga_dasar,harga_netto';

        return [
            'sumber_lead' => [
                'label' => 'Sumber Lead', 'model' => SumberLead::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'keterangan', 'is_referral', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'keterangan' => 'nullable|string|max:2000', 'is_referral' => $bool, 'is_active' => $bool],
            ],
            'bank_rekanan' => [
                'label' => 'Bank Rekanan KPR', 'model' => BankRekananPreset::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'nama_pt', 'kantor_cabang', 'keterangan', 'alamat', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'nama_pt' => 'nullable|string|max:255', 'kantor_cabang' => 'nullable|string|max:255',
                    'keterangan' => 'nullable|string|max:2000', 'alamat' => 'nullable|string|max:2000', 'is_active' => $bool],
            ],
            'notaris' => [
                'label' => 'Notaris', 'model' => NotarisPreset::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'is_active' => $bool],
            ],
            'sales_agent' => [
                'label' => 'Sales / Agent', 'model' => SalesAgent::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'tipe', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'tipe' => 'required|in:inhouse,freelance,agen,allowance', 'is_active' => $bool],
            ],
            'kontraktor' => [
                'label' => 'Kontraktor', 'model' => Kontraktor::class, 'cocok' => ['nama'],
                'kolom' => ['nama', 'no_hp', 'alamat', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'no_hp' => 'nullable|string|max:50', 'alamat' => 'nullable|string|max:2000', 'is_active' => $bool],
            ],
            'skema_dp' => [
                'label' => 'Skema DP', 'model' => SkemaDpPreset::class, 'cocok' => ['nama', 'cara_bayar'],
                'kolom' => ['nama', 'cara_bayar', 'booking_fee_aktif', 'booking_fee_tipe', 'booking_fee_nilai', 'booking_fee_tenor',
                    'booking_fee_masuk_harga_jual', 'booking_fee_basis', 'dp_aktif', 'dp_tipe', 'dp_nilai', 'dp_tenor',
                    'dp_masuk_harga_jual', 'dp_basis', 'keterangan', 'is_active'],
                'aturan' => [
                    'nama' => 'required|string|max:255', 'cara_bayar' => 'required|' . $cara,
                    'booking_fee_aktif' => $bool, 'booking_fee_tipe' => 'nullable|string|max:30', 'booking_fee_nilai' => 'nullable|numeric|min:0',
                    'booking_fee_tenor' => 'nullable|integer|min:0|max:600', 'booking_fee_masuk_harga_jual' => $bool, 'booking_fee_basis' => $basis,
                    'dp_aktif' => $bool, 'dp_tipe' => 'nullable|string|max:30', 'dp_nilai' => 'nullable|numeric|min:0',
                    'dp_tenor' => 'nullable|integer|min:0|max:600', 'dp_masuk_harga_jual' => $bool, 'dp_basis' => $basis,
                    'keterangan' => 'nullable|string|max:2000', 'is_active' => $bool,
                ],
            ],
            'promo' => [
                'label' => 'Promo', 'model' => PromoPreset::class, 'cocok' => ['nama'],
                'kolom' => ['nama', 'keterangan', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'keterangan' => 'nullable|string|max:2000', 'is_active' => $bool],
            ],
            'program_all_in' => [
                'label' => 'Program All In', 'model' => ProgramAllInPreset::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'nominal', 'include_booking_fee', 'include_dp', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'nominal' => 'nullable|numeric|min:0', 'include_booking_fee' => $bool, 'include_dp' => $bool, 'is_active' => $bool],
            ],
            'biaya_tambahan' => [
                'label' => 'Biaya Tambahan', 'model' => BiayaTambahanPreset::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'keterangan', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'keterangan' => 'nullable|string|max:2000', 'is_active' => $bool],
            ],
            'dajam_sbum' => [
                'label' => 'Dana Jaminan & SBUM', 'model' => DajamSbumPreset::class, 'cocok' => ['kategori', 'nama'],
                'kolom' => ['nama', 'kategori', 'keterangan', 'is_active'],
                'aturan' => ['nama' => 'required|string|max:255', 'kategori' => 'required|in:dajam,sbum', 'keterangan' => 'nullable|string|max:2000', 'is_active' => $bool],
            ],
            'dokumen_template' => [
                'label' => 'Template Pemberkasan', 'model' => DokumenTemplate::class, 'cocok' => ['cara_bayar', 'nama_dokumen'], 'urut' => ['cara_bayar', 'urutan'],
                'kolom' => ['cara_bayar', 'nama_dokumen', 'sifat'],
                'aturan' => ['cara_bayar' => 'required|' . $cara, 'nama_dokumen' => 'required|string|max:255', 'sifat' => 'required|in:wajib,kondisional,opsional'],
                'baru' => fn (array $row) => $row + ['urutan' => (int) DokumenTemplate::where('cara_bayar', $row['cara_bayar'])->max('urutan') + 1],
            ],
            'status_bangun' => [
                'label' => 'Status Bangun (tahap, bobot, warna)', 'model' => StatusBangunStage::class, 'cocok' => ['nama'], 'urut' => 'ordered',
                'kolom' => ['nama', 'bobot', 'warna'],
                'aturan' => ['nama' => 'required|string|max:255', 'bobot' => 'required|numeric|min:0|max:100', 'warna' => 'required|' . self::WARNA],
                'baru' => fn (array $row) => $row + ['urutan' => (int) StatusBangunStage::max('urutan') + 1, 'is_default' => false],
            ],
            'warna_status' => [
                'label' => 'Warna Status', 'model' => StatusColor::class, 'cocok' => ['kategori', 'kode'],
                'kolom' => ['kategori', 'kode', 'warna'],
                'aturan' => ['kategori' => 'required|in:status_jual,status_penjualan', 'kode' => 'required|string|max:60', 'warna' => 'required|' . self::WARNA],
            ],
            self::PROFIL => [
                'label' => 'Profil Developer (teks & rekening bank, tanpa file)', 'khusus' => true,
            ],
        ];
    }

    /** @return array<int, array{key: string, label: string, jumlah: int}> */
    public static function daftar(): array
    {
        $hasil = [];
        foreach (self::definisi() as $key => $d) {
            $jumlah = isset($d['khusus'])
                ? 1 + DeveloperProfile::getSingleton()->banks()->count()
                : $d['model']::query()->count();
            $hasil[] = ['key' => $key, 'label' => $d['label'], 'jumlah' => $jumlah];
        }

        return $hasil;
    }

    /** Hanya key yang dikenal, urutan mengikuti definisi. */
    public static function kunciValid(array $keys): array
    {
        return array_values(array_intersect(array_keys(self::definisi()), $keys));
    }

    public static function ekspor(array $keys): array
    {
        $data = [];
        foreach (self::kunciValid($keys) as $key) {
            $d = self::definisi()[$key];
            if (isset($d['khusus'])) {
                $data[$key] = self::eksporProfil();
                continue;
            }
            $q = $d['model']::query();
            $urut = $d['urut'] ?? null;
            if ($urut === 'ordered') {
                $q->ordered();
            } elseif (is_array($urut)) {
                foreach ($urut as $kolom) $q->orderBy($kolom);
                $q->orderBy('id');
            } else {
                $q->orderBy('id');
            }
            $data[$key] = $q->get()->map(fn ($m) => $m->only($d['kolom']))->all();
        }

        return [
            'format' => self::FORMAT,
            'versi' => self::VERSI,
            'dibuat' => now()->toIso8601String(),
            'sumber' => config('app.url'),
            'data' => $data,
        ];
    }

    /** Pesan galat kalau isi file bukan ekspor master yang sah; null kalau sah. */
    public static function periksaPayload(mixed $payload): ?string
    {
        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT) {
            return 'File ini bukan hasil ekspor master data sistem ini.';
        }
        if ((int) ($payload['versi'] ?? 0) !== self::VERSI) {
            return 'Versi file tidak cocok dengan sistem ini (file versi ' . ($payload['versi'] ?? '?') . ', sistem versi ' . self::VERSI . ').';
        }
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return 'File tidak berisi data master.';
        }

        return null;
    }

    /**
     * Jalankan (atau simulasikan) impor. Mengembalikan ['hasil' => [key => hitung], 'masalah' => [string], 'diterapkan' => bool].
     * Simulasi = proses yang sama lalu rollback, jadi angkanya persis sama dengan penerapan sungguhan.
     */
    public static function proses(array $payload, array $keys, bool $perbarui, bool $terapkan): array
    {
        $hasil = [];
        $masalah = [];

        DB::beginTransaction();
        try {
            foreach (self::kunciValid($keys) as $key) {
                if (!array_key_exists($key, $payload['data'])) {
                    $masalah[] = '[' . self::definisi()[$key]['label'] . '] Tidak ada di file ini.';
                    continue;
                }
                $d = self::definisi()[$key];
                $r = isset($d['khusus'])
                    ? self::prosesProfil((array) $payload['data'][$key], $perbarui)
                    : self::prosesTabel($d, (array) $payload['data'][$key], $perbarui);
                $hasil[$key] = $r['hitung'];
                $masalah = array_merge($masalah, $r['masalah']);
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            return ['hasil' => $hasil, 'masalah' => [...$masalah, 'Terjadi galat saat memproses: ' . $e->getMessage()], 'diterapkan' => false];
        }

        if ($terapkan && !$masalah) {
            DB::commit();

            return ['hasil' => $hasil, 'masalah' => [], 'diterapkan' => true];
        }

        DB::rollBack();

        return ['hasil' => $hasil, 'masalah' => $masalah, 'diterapkan' => false];
    }

    // ── Master tabel biasa ────────────────────────────────────────────────

    private static function prosesTabel(array $d, array $rows, bool $perbarui): array
    {
        $hitung = self::hitungKosong(count($rows));
        $masalah = [];
        $model = $d['model'];
        $lunakHapus = in_array(SoftDeletes::class, class_uses_recursive($model), true);

        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                $masalah[] = "[{$d['label']}] Baris " . ($i + 1) . ': bukan data yang valid.';
                continue;
            }
            $row = Arr::only($row, $d['kolom']);
            $v = Validator::make($row, $d['aturan']);
            if ($v->fails()) {
                $masalah[] = "[{$d['label']}] Baris " . ($i + 1) . ': ' . implode(' ', $v->errors()->all());
                continue;
            }

            $q = $lunakHapus ? $model::withTrashed() : $model::query();
            foreach ($d['cocok'] as $kolom) {
                $q->whereRaw("LOWER({$kolom}) = ?", [mb_strtolower((string) $row[$kolom])]);
            }
            $ada = $q->first();

            if (!$ada) {
                $hitung['baru']++;
                $model::create(isset($d['baru']) ? $d['baru']($row) : $row);
                continue;
            }

            $beda = [];
            foreach (array_diff($d['kolom'], $d['cocok']) as $kolom) {
                if (array_key_exists($kolom, $row) && !self::sama($ada->{$kolom}, $row[$kolom])) $beda[] = $kolom;
            }
            if (!$beda) {
                $hitung['sama']++;
            } elseif ($perbarui) {
                $ada->update(Arr::only($row, $beda));
                $hitung['beda']++;
                $hitung['diperbarui']++;
            } else {
                $hitung['beda']++;
                $hitung['dilewati']++;
            }
        }

        return ['hitung' => $hitung, 'masalah' => $masalah];
    }

    // ── Profil Developer (1 baris + rekening bank) ────────────────────────

    private const PROFIL_TEKS = ['nama_developer', 'alamat', 'telepon', 'email', 'npwp', 'nama_penandatangan', 'jabatan_penandatangan'];

    private static function eksporProfil(): array
    {
        $p = DeveloperProfile::getSingleton();

        return [
            'teks' => $p->only(self::PROFIL_TEKS),
            'bank' => $p->banks()->orderBy('id')->get()->map(fn ($b) => $b->only(['nama_bank', 'nomor_rekening', 'atas_nama_rekening', 'is_primary']))->all(),
        ];
    }

    private static function prosesProfil(array $data, bool $perbarui): array
    {
        $masalah = [];
        $teks = Arr::only((array) ($data['teks'] ?? []), self::PROFIL_TEKS);
        $banks = array_values((array) ($data['bank'] ?? []));
        $hitung = self::hitungKosong(1 + count($banks));

        $v = Validator::make($teks, [
            'nama_developer' => 'nullable|string|max:255', 'alamat' => 'nullable|string|max:2000', 'telepon' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255', 'npwp' => 'nullable|string|max:50',
            'nama_penandatangan' => 'nullable|string|max:255', 'jabatan_penandatangan' => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return ['hitung' => $hitung, 'masalah' => ['[Profil Developer] ' . implode(' ', $v->errors()->all())]];
        }

        $p = DeveloperProfile::getSingleton();
        $belumDiisi = in_array(trim((string) $p->nama_developer), ['', 'Nama Developer'], true);
        $beda = [];
        foreach ($teks as $kolom => $nilai) {
            if (!self::sama($p->{$kolom}, $nilai)) $beda[] = $kolom;
        }

        if ($belumDiisi) {
            $hitung['baru']++;
            $p->update($teks);
        } elseif (!$beda) {
            $hitung['sama']++;
        } elseif ($perbarui) {
            $hitung['beda']++;
            $hitung['diperbarui']++;
            $p->update(Arr::only($teks, $beda));
        } else {
            $hitung['beda']++;
            $hitung['dilewati']++;
        }

        foreach ($banks as $i => $row) {
            $row = is_array($row) ? Arr::only($row, ['nama_bank', 'nomor_rekening', 'atas_nama_rekening', 'is_primary']) : [];
            $vb = Validator::make($row, [
                'nama_bank' => 'required|string|max:255', 'nomor_rekening' => 'required|string|max:100',
                'atas_nama_rekening' => 'nullable|string|max:255', 'is_primary' => 'nullable|boolean',
            ]);
            if ($vb->fails()) {
                $masalah[] = '[Profil Developer] Rekening bank ' . ($i + 1) . ': ' . implode(' ', $vb->errors()->all());
                continue;
            }

            $ada = $p->banks()->where('nomor_rekening', trim($row['nomor_rekening']))
                ->whereRaw('LOWER(nama_bank) = ?', [mb_strtolower($row['nama_bank'])])->first();
            if (!$ada) {
                $hitung['baru']++;
                $row['is_primary'] = !empty($row['is_primary']) && !$p->banks()->where('is_primary', true)->exists();
                $p->banks()->create($row);
            } elseif (!self::sama($ada->atas_nama_rekening, $row['atas_nama_rekening'] ?? null)) {
                $hitung['beda']++;
                if ($perbarui) {
                    $ada->update(['atas_nama_rekening' => $row['atas_nama_rekening'] ?? null]);
                    $hitung['diperbarui']++;
                } else {
                    $hitung['dilewati']++;
                }
            } else {
                $hitung['sama']++;
            }
        }

        return ['hitung' => $hitung, 'masalah' => $masalah];
    }

    // ── Pembantu ───────────────────────────────────────────────────────────

    private static function hitungKosong(int $total): array
    {
        return ['total' => $total, 'baru' => 0, 'sama' => 0, 'beda' => 0, 'diperbarui' => 0, 'dilewati' => 0];
    }

    /** Perbandingan longgar: boolean, angka (selisih < 0,005), dan teks (spasi pinggir diabaikan, null = kosong). */
    private static function sama(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) return (bool) $a === (bool) $b;
        if (is_numeric($a) && is_numeric($b)) return abs((float) $a - (float) $b) < 0.005;

        return trim((string) $a) === trim((string) $b);
    }
}
