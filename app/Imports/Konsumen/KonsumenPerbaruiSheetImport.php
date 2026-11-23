<?php

namespace App\Imports\Konsumen;

use App\Models\BankRekananPreset;
use App\Models\Kavling;
use App\Models\KavlingKonsumen;
use App\Models\Konsumen;
use App\Models\NotarisPreset;
use App\Models\Project;
use App\Models\SalesAgent;
use App\Models\SumberLead;
use App\Support\KonsumenImportSpec;
use App\Support\KonsumenPerbaruiSpec;
use App\Support\Nik;
use App\Support\Wilayah;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Mode Perbarui: menyusulkan data yang BELUM terisi pada konsumen/transaksi yang sudah ada. Aturan:
 *  - Unit dicocokkan ke transaksi yang ada (aktif/selesai); Nama file harus mirip dengan konsumen di unit itu (verifikasi).
 *  - Hanya MENGISI yang masih kosong. Data yang sudah ada dan berbeda tidak ditimpa — jadi peringatan.
 *  - NIK yang kosong/tidak valid boleh diganti NIK valid; NIK valid tidak pernah diganti.
 *  - Transaksi Selesai/terkunci dilewati. Tidak ada kolom keuangan, jadi angka tidak mungkin berubah.
 * Dijalankan di dalam satu transaksi database ("Cek dulu" membatalkannya), sama seperti Import Konsumen.
 */
class KonsumenPerbaruiSheetImport implements ToCollection, SkipsEmptyRows
{
    use ParsesImportCells;

    private array $columns;
    private bool $bolehPipeline;
    private bool $bolehBank;

    public function __construct(private Project $project, private KonsumenImportResult $result)
    {
        $this->columns = KonsumenPerbaruiSpec::columns();
        $user = Auth::user();
        $this->bolehPipeline = (bool) $user?->can('kelola pipeline sales');
        $this->bolehBank = (bool) $user?->can('isi bank rekanan kpr');
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            if ($index === 0) continue;
            $cells = $row instanceof Collection ? $row->values()->all() : array_values((array) $row);
            if (collect($cells)->filter(fn ($c) => trim((string) $c) !== '')->isEmpty()) continue;
            $this->prosesBaris($index + 1, $cells);
        }
    }

    private function kosong(mixed $nilai): bool
    {
        return $nilai === null || trim((string) $nilai) === '';
    }

    private function sama(mixed $a, mixed $b, bool $angkaSaja = false): bool
    {
        $f = fn ($x) => $angkaSaja ? preg_replace('/\D/', '', (string) $x) : mb_strtolower(trim((string) $x));

        return $f($a) === $f($b);
    }

    private function prosesBaris(int $rowNum, array $cells): void
    {
        $sheet = KonsumenPerbaruiSpec::SHEET;
        $mentah = [];
        $v = [];
        foreach ($this->columns as $i => [$key]) {
            $raw = $cells[$i] ?? null;
            $mentah[$key] = $raw;
            $v[$key] = is_string($raw) ? trim($raw) : $raw;
        }
        $str = fn (string $k) => $v[$k] !== null && $v[$k] !== '' ? (string) $v[$k] : null;

        $errors = [];
        $nama = $str('nama');
        $kluster = $str('kluster') ?? '';
        $blok = $str('blok');
        $unit = $str('unit');
        if (!$nama) $errors[] = 'Nama Konsumen kosong.';
        if (!$blok) $errors[] = 'Blok kosong.';
        if (!$unit) $errors[] = 'Nomor Kavling kosong.';

        // ── Cari transaksi di unit itu ──
        $trx = null;
        $label = ($kluster !== '' ? "{$kluster} · " : '') . "{$blok}-{$unit}";
        if ($blok && $unit) {
            $kavling = Kavling::where('project_id', $this->project->id)
                ->where('kluster_key', $kluster)->where('blok_key', $blok)->where('nomor_kavling', $unit)->first();
            if (!$kavling) {
                $errors[] = "Unit '{$label}' tidak ditemukan di Stok Kavling proyek ini.";
            } else {
                $trx = KavlingKonsumen::with(['konsumen', 'kavling'])->where('kavling_id', $kavling->id)
                    ->whereIn('status', ['active', 'completed'])->orderByDesc('id')->first();
                if (!$trx) $errors[] = "Unit '{$label}' belum punya transaksi aktif. Mode Perbarui hanya untuk konsumen yang sudah ada — pakai mode Tambah baru untuk konsumen baru.";
            }
        }
        if ($trx && !$errors) {
            if ($trx->status === 'completed' || $trx->is_locked) $errors[] = "Transaksi di unit '{$label}' sudah Selesai/terkunci, tidak diperbarui.";
        }

        $konsumen = $trx?->konsumen;
        if ($konsumen && $nama && !$errors) {
            similar_text(mb_strtolower($nama), mb_strtolower(trim($konsumen->nama)), $persen);
            if ($persen < ImportBantuan::AMBANG_NAMA) {
                $errors[] = "Nama di file \"{$nama}\" berbeda dengan konsumen di unit '{$label}' (\"{$konsumen->nama}\"). Barisnya dilewati supaya data tidak masuk ke orang yang salah.";
            }
        }

        // ── Baca & validasi nilai file ──
        $nikFile = null;
        if (!$this->kosong($mentah['nik'])) {
            if (!is_string($mentah['nik'])) {
                $errors[] = 'NIK terbaca sebagai angka oleh Excel (digitnya bisa terpotong). Ubah format kolom NIK jadi Teks lalu ketik ulang.';
            } else {
                $nikFile = Nik::bersihkan($mentah['nik']);
                if ($pesan = Nik::periksa($nikFile)) $errors[] = $pesan;
            }
        }

        $hp = $this->kosong($mentah['hp']) ? null : (is_string($mentah['hp']) ? trim($mentah['hp']) : number_format((float) $mentah['hp'], 0, '', ''));
        if ($hp !== null && !is_string($mentah['hp']) && str_starts_with($hp, '8')) $hp = '0' . $hp;   // Excel membuang angka 0 di depan
        $email = $str('email');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email '{$email}' tidak valid.";
        $npwp = $str('npwp');
        if ($npwp !== null && !in_array(strlen(preg_replace('/\D/', '', $npwp)), [15, 16], true)) $errors[] = "NPWP '{$npwp}' harus 15 atau 16 digit.";
        $alamat = $str('alamat');

        $lookup = function (string $key, string $modelClass, string $nama, string $kolom = 'nama') use ($str, &$errors) {
            $label = $str($key);
            if (!$label) return null;
            $m = $modelClass::whereRaw("LOWER({$kolom}) = ?", [mb_strtolower($label)])->first();
            if (!$m) $errors[] = "{$nama} '{$label}' tidak dikenal.";

            return $m;
        };

        $kerjaKey = null;
        if ($l = $str('kerja')) {
            $kerjaKey = KonsumenImportSpec::pekerjaanMap()[$l] ?? null;
            if (!$kerjaKey) $errors[] = "Pekerjaan '{$l}' tidak dikenal.";
        }
        $nikahKey = null;
        if ($l = $str('nikah')) {
            $nikahKey = KonsumenImportSpec::statusPernikahanMap()[$l] ?? null;
            if (!$nikahKey) $errors[] = "Status Pernikahan '{$l}' tidak dikenal.";
        }
        $lead = $lookup('lead', SumberLead::class, 'Sumber Lead');
        $sales = $lookup('sales', SalesAgent::class, 'Sales/Agent');
        $notaris = $lookup('notaris', NotarisPreset::class, 'Notaris');
        $bank = $lookup('bank', BankRekananPreset::class, 'Bank Rekanan KPR');
        $referral = $str('referral');

        [$kodeWilayah, $errWilayah] = Wilayah::cocokkan($str('prov'), $str('kab'), $str('kec'), $str('kel'));
        array_push($errors, ...$errWilayah);

        if ($errors || !$trx || !$konsumen) {
            $this->result->skip($sheet, $rowNum, implode(' ', $errors ?: ['Baris tidak bisa diproses.']));

            return;
        }

        // ── Susun perubahan: hanya mengisi yang kosong ──
        $rincian = [];
        $ubahKonsumen = [];
        $ubahTrx = [];
        $warn = fn (string $pesan) => $this->result->warn($sheet, $rowNum, "{$label} ({$konsumen->nama}): {$pesan}");

        // NIK: kosong/tidak valid boleh diganti; yang valid tidak pernah diganti.
        if ($nikFile !== null) {
            if (Nik::valid($konsumen->nik)) {
                if ($konsumen->nik !== $nikFile) $warn("NIK di file ({$nikFile}) berbeda dengan NIK tersimpan ({$konsumen->nik}) — tidak diganti.");
            } else {
                $dipakai = Konsumen::withTrashed()->where('nik', $nikFile)->where('id', '!=', $konsumen->id)->first();
                if ($dipakai) {
                    $this->result->skip($sheet, $rowNum, "{$label}: NIK {$nikFile} sudah dipakai konsumen lain (\"{$dipakai->nama}\", ID {$dipakai->id}). Barisnya dilewati.");

                    return;
                }
                $ubahKonsumen['nik'] = $nikFile;
                $rincian[] = 'NIK ' . ($this->kosong($konsumen->nik) ? '(kosong)' : "'{$konsumen->nik}' (tidak valid)") . " ← {$nikFile}";
                if ($p = Nik::peringatan($nikFile)) $warn($p);
            }
        }

        $isiKosong = function (string $kolom, string $labelKolom, mixed $baru, mixed $tampil = null, bool $angkaSaja = false) use ($konsumen, &$ubahKonsumen, &$rincian, $warn) {
            if ($this->kosong($baru)) return;
            $lama = $konsumen->{$kolom};
            if ($this->kosong($lama)) {
                $ubahKonsumen[$kolom] = $baru;
                $rincian[] = "{$labelKolom} ← " . ($tampil ?? $baru);
            } elseif (!$this->sama($lama, $baru, $angkaSaja)) {
                $warn("{$labelKolom} sudah terisi ('{$lama}'), berbeda dengan file ('" . ($tampil ?? $baru) . "') — tidak ditimpa.");
            }
        };
        $isiKosong('no_hp', 'No. HP', $hp, null, true);
        $isiKosong('email', 'Email', $email);
        $isiKosong('npwp', 'NPWP', $npwp, null, true);
        $isiKosong('alamat', 'Alamat', $alamat);

        // Wilayah: mengisi yang kosong, atau MELENGKAPI tingkat lebih dalam di bawah wilayah yang sudah tersimpan. Yang bertentangan tidak ditimpa.
        if ($kodeWilayah) {
            $lamaKode = Wilayah::kodeTerdalam($konsumen);
            if ($lamaKode === null || (str_starts_with($kodeWilayah, $lamaKode . '.') && $kodeWilayah !== $lamaKode)) {
                $atribut = Wilayah::atribut($kodeWilayah);
                $ubahKonsumen += $atribut;
                $rincian[] = 'Wilayah ← ' . implode(', ', array_filter([$atribut['kelurahan_nama'], $atribut['kecamatan_nama'], $atribut['kabupaten_nama'], $atribut['provinsi_nama']]));
            } elseif ($kodeWilayah !== $lamaKode && !str_starts_with($lamaKode, $kodeWilayah . '.')) {
                $warn('Wilayah sudah terisi (' . Wilayah::ringkas($konsumen) . '), berbeda dengan file — tidak ditimpa.');
            }
        }
        $isiKosong('pekerjaan', 'Pekerjaan', $kerjaKey, $str('kerja'));
        $isiKosong('status_pernikahan', 'Status Pernikahan', $nikahKey, $str('nikah'));

        if ($lead) {
            if ($this->kosong($konsumen->sumber_lead_id)) {
                if ($lead->is_referral && !$referral) {
                    $this->result->skip($sheet, $rowNum, "{$label}: Keterangan Referral wajib diisi karena Sumber Lead '{$lead->nama}' bertanda referral.");

                    return;
                }
                $ubahKonsumen['sumber_lead_id'] = $lead->id;
                $rincian[] = "Sumber Lead ← {$lead->nama}";
                if ($lead->is_referral) {
                    $ubahKonsumen['referral_keterangan'] = $referral;
                    $rincian[] = "Keterangan Referral ← {$referral}";
                }
            } elseif ((int) $konsumen->sumber_lead_id !== (int) $lead->id) {
                $warn("Sumber Lead sudah terisi, berbeda dengan file ('{$lead->nama}') — tidak ditimpa.");
            }
        }
        if ($referral && !$lead && $konsumen->sumber_lead_id && $this->kosong($konsumen->referral_keterangan) && $konsumen->sumberLead?->is_referral) {
            $ubahKonsumen['referral_keterangan'] = $referral;
            $rincian[] = "Keterangan Referral ← {$referral}";
        }

        $isiTrx = function (string $kolom, string $labelKolom, ?object $baru, bool $izin, string $namaIzin) use ($trx, &$ubahTrx, &$rincian, $warn) {
            if (!$baru) return;
            if (!$izin) { $warn("{$labelKolom} diabaikan: Anda tidak punya izin \"{$namaIzin}\"."); return; }
            $lama = $trx->{$kolom};
            if ($this->kosong($lama)) {
                $ubahTrx[$kolom] = $baru->id;
                $rincian[] = "{$labelKolom} ← {$baru->nama}";
            } elseif ((int) $lama !== (int) $baru->id) {
                $warn("{$labelKolom} sudah terisi, berbeda dengan file ('{$baru->nama}') — tidak ditimpa.");
            }
        };
        $isiTrx('sales_agent_id', 'Sales/Agent', $sales, $this->bolehPipeline, 'kelola pipeline sales');
        $isiTrx('notaris_preset_id', 'Notaris', $notaris, $this->bolehPipeline, 'kelola pipeline sales');
        if ($bank) {
            if (!in_array($trx->cara_bayar, ['kpr_subsidi', 'kpr_komersil'], true)) $warn('Bank Rekanan KPR diabaikan: transaksi ini bukan KPR.');
            else $isiTrx('bank_rekanan_preset_id', 'Bank Rekanan KPR', $bank, $this->bolehBank, 'isi bank rekanan kpr');
        }

        if (!$ubahKonsumen && !$ubahTrx) {
            $this->result->tanpaPerubahan++;

            return;
        }

        DB::transaction(function () use ($konsumen, $trx, $ubahKonsumen, $ubahTrx) {
            if ($ubahKonsumen) $konsumen->update($ubahKonsumen);
            if ($ubahTrx) KavlingKonsumen::withoutFinanceRefresh(fn () => $trx->update($ubahTrx));
        });

        $this->result->imported++;
        $this->result->perubahan[] = "[{$sheet}] Baris {$rowNum} — {$label} ({$konsumen->nama}): " . implode('; ', $rincian);
    }
}
