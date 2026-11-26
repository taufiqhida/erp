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
 * Mode Perbarui: memperbarui data konsumen/transaksi yang SUDAH ada. Aturan:
 *  - Unit dicocokkan ke transaksi yang ada; Nama file harus mirip dengan konsumen di unit itu (verifikasi).
 *  - Sel kosong, kolom yang tidak ada, atau sel yang sama dengan data sekarang = TIDAK ada perubahan. Data tidak pernah dikosongkan.
 *  - Data yang masih kosong diisi. Data yang sudah terisi hanya diganti bila opsi "Timpa" aktif; kalau tidak jadi peringatan.
 *  - NIK valid tidak pernah diganti (kosong/tidak valid boleh); nama tidak bisa diubah; transaksi Selesai/terkunci dilewati.
 *  - Tidak ada kolom keuangan, jadi angka tidak mungkin berubah.
 * Dijalankan di dalam satu transaksi database ("Cek dulu" membatalkannya), sama seperti Import Konsumen.
 */
class KonsumenPerbaruiSheetImport implements ToCollection, SkipsEmptyRows
{
    use ParsesImportCells;

    /** @var array<int,string> indeks kolom => key (dari baris judul) */
    private array $peta = [];
    private bool $bolehPipeline;
    private bool $bolehBank;

    public function __construct(private Project $project, private KonsumenImportResult $result, private bool $timpa = false)
    {
        $user = Auth::user();
        $this->bolehPipeline = (bool) $user?->can('kelola pipeline sales');
        $this->bolehBank = (bool) $user?->can('isi bank rekanan kpr');
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $cells = $row instanceof Collection ? $row->values()->all() : array_values((array) $row);
            if (!$this->peta) {                       // baris pertama = judul
                [$this->peta] = KonsumenPerbaruiSpec::bacaJudul($cells);
                continue;
            }
            if (collect($cells)->filter(fn ($c) => trim((string) $c) !== '')->isEmpty()) continue;
            $this->prosesBaris($index + 1, $cells);
        }
    }

    private function kosong(mixed $nilai): bool
    {
        return $nilai === null || trim((string) $nilai) === '';
    }

    /** Bandingkan teks file dengan data sekarang secara longgar (huruf besar/kecil, spasi; angka saja untuk HP/NPWP). */
    private function samaNilai(string $key, string $a, string $b): bool
    {
        $f = match ($key) {
            'hp', 'npwp' => fn ($x) => preg_replace('/\D/', '', $x),
            'nik' => fn ($x) => (string) Nik::bersihkan($x),
            default => fn ($x) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x))),
        };

        return $f($a) === $f($b);
    }

    private function prosesBaris(int $rowNum, array $cells): void
    {
        $sheet = KonsumenPerbaruiSpec::SHEET;
        $v = [];
        $mentah = [];
        foreach ($this->peta as $i => $key) {
            if (in_array($key, KonsumenPerbaruiSpec::REFERENSI, true)) continue;
            $raw = $cells[$i] ?? null;
            $mentah[$key] = $raw;
            $v[$key] = $this->kosong($raw) ? null : trim((string) $raw);
        }
        // Excel membuang angka 0 di depan nomor HP yang diketik sebagai angka.
        if (isset($v['hp']) && !is_string($mentah['hp'])) {
            $v['hp'] = number_format((float) $mentah['hp'], 0, '', '');
            if (str_starts_with($v['hp'], '8')) $v['hp'] = '0' . $v['hp'];
        }

        $errors = [];
        $nama = $v['nama'] ?? null;
        $kluster = $v['kluster'] ?? '';
        $blok = $v['blok'] ?? null;
        $unit = $v['unit'] ?? null;
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
                $trx = KavlingKonsumen::with(['konsumen.sumberLead', 'kavling', 'salesAgent', 'notarisPreset', 'bankRekananPreset'])
                    ->where('kavling_id', $kavling->id)->whereIn('status', ['active', 'completed'])->orderByDesc('id')->first();
                if (!$trx) $errors[] = "Unit '{$label}' belum punya transaksi aktif. Mode Perbarui hanya untuk konsumen yang sudah ada — pakai mode Tambah baru untuk konsumen baru.";
            }
        }
        if ($trx && !$errors && $trx->is_locked) $errors[] = "Transaksi di unit '{$label}' sudah Selesai/terkunci, tidak diperbarui.";

        $konsumen = $trx?->konsumen;
        if ($konsumen && $nama && !$errors) {
            similar_text(mb_strtolower($nama), mb_strtolower(trim($konsumen->nama)), $persen);
            if ($persen < ImportBantuan::AMBANG_NAMA) {
                $errors[] = "Nama di file \"{$nama}\" berbeda dengan konsumen di unit '{$label}' (\"{$konsumen->nama}\"). Barisnya dilewati supaya data tidak masuk ke orang yang salah.";
            }
        }
        if ($errors || !$trx || !$konsumen) {
            $this->result->skip($sheet, $rowNum, implode(' ', $errors ?: ['Baris tidak bisa diproses.']));

            return;
        }

        // ── Hanya sel terisi yang BERBEDA dari data sekarang yang dianggap permintaan perubahan ──
        $kini = KonsumenPerbaruiSpec::nilaiSaatIni($trx);
        $baru = [];
        foreach ($v as $key => $nilai) {
            if ($nilai === null || in_array($key, ['kluster', 'blok', 'unit', 'nama'], true)) continue;
            if (!$this->samaNilai($key, $nilai, $kini[$key] ?? '')) $baru[$key] = $nilai;
        }
        if (!$baru) {
            $this->result->tanpaPerubahan++;

            return;
        }

        $ubahKonsumen = [];
        $ubahTrx = [];
        $rincian = [];
        $peringatan = [];

        // Isi bila kosong; ganti hanya bila Timpa; selain itu peringatan.
        $boleh = function (bool $lamaKosong, string $judul, string $lama, string $nilaiBaru) use (&$rincian, &$peringatan): bool {
            if ($lamaKosong || $this->timpa) {
                $rincian[] = "{$judul}: " . ($lama === '' ? '(kosong)' : $lama) . " → {$nilaiBaru}";

                return true;
            }
            $peringatan[] = "{$judul} sudah terisi ('{$lama}'), berbeda dengan file ('{$nilaiBaru}') — tidak ditimpa.";

            return false;
        };

        // ── NIK ──
        if (isset($baru['nik'])) {
            if (!is_string($mentah['nik'])) {
                $errors[] = 'NIK terbaca sebagai angka oleh Excel (digitnya bisa terpotong). Ubah format kolom NIK jadi Teks lalu ketik ulang.';
            } else {
                $nik = Nik::bersihkan($baru['nik']);
                if ($pesan = Nik::periksa($nik)) {
                    $errors[] = $pesan;
                } elseif (Nik::valid($konsumen->nik)) {
                    $peringatan[] = "NIK di file ({$nik}) berbeda dengan NIK tersimpan ({$konsumen->nik}) — tidak diganti. Koreksi lewat form konsumen.";
                } elseif ($dipakai = Konsumen::withTrashed()->where('nik', $nik)->where('id', '!=', $konsumen->id)->first()) {
                    $errors[] = "NIK {$nik} sudah dipakai konsumen lain (\"{$dipakai->nama}\", ID {$dipakai->id}).";
                } else {
                    $ubahKonsumen['nik'] = $nik;
                    $rincian[] = 'NIK: ' . ($this->kosong($konsumen->nik) ? '(kosong)' : "'{$konsumen->nik}' (tidak valid)") . " → {$nik}";
                    if ($p = Nik::peringatan($nik)) $peringatan[] = $p;
                }
            }
        }

        // ── Teks sederhana & pilihan ──
        $kolomKonsumen = ['hp' => ['no_hp', 'No. HP'], 'email' => ['email', 'Email'], 'npwp' => ['npwp', 'NPWP'], 'alamat' => ['alamat', 'Alamat KTP']];
        if (isset($baru['email']) && !filter_var($baru['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Email '{$baru['email']}' tidak valid.";
        if (isset($baru['npwp']) && !in_array(strlen(preg_replace('/\D/', '', $baru['npwp'])), [15, 16], true)) $errors[] = "NPWP '{$baru['npwp']}' harus 15 atau 16 digit.";
        foreach ($kolomKonsumen as $key => [$kolom, $judul]) {
            if (isset($baru[$key]) && $boleh($this->kosong($konsumen->{$kolom}), $judul, (string) $konsumen->{$kolom}, $baru[$key])) $ubahKonsumen[$kolom] = $baru[$key];
        }

        foreach (['kerja' => ['pekerjaan', 'Pekerjaan', KonsumenImportSpec::pekerjaanMap()], 'nikah' => ['status_pernikahan', 'Status Pernikahan', KonsumenImportSpec::statusPernikahanMap()]] as $key => [$kolom, $judul, $peta]) {
            if (!isset($baru[$key])) continue;
            $nilai = $peta[$baru[$key]] ?? null;
            if (!$nilai) { $errors[] = "{$judul} '{$baru[$key]}' tidak dikenal."; continue; }
            if ($boleh($this->kosong($konsumen->{$kolom}), $judul, $kini[$key], $baru[$key])) $ubahKonsumen[$kolom] = $nilai;
        }

        // ── Wilayah KTP & Domisili ──
        $wilayah = function (string $awalanKolom, string $awalanFile, string $judul) use ($v, $baru, $konsumen, &$ubahKonsumen, &$errors, $boleh) {
            $kunci = array_map(fn ($k) => $awalanFile . $k, ['prov', 'kab', 'kec', 'kel']);
            if (!array_intersect($kunci, array_keys($baru))) return;

            [$kode, $salah] = Wilayah::cocokkan(...array_map(fn ($k) => $v[$k] ?? null, $kunci));
            if ($salah) { array_push($errors, ...$salah); return; }
            if (!$kode) return;

            $lama = Wilayah::kodeTerdalam($konsumen, $awalanKolom);
            // Sama atau lebih dangkal dari yang tersimpan: tidak ada yang berubah (sel kosong tidak menghapus).
            if ($lama !== null && ($lama === $kode || str_starts_with($lama, $kode . '.'))) return;

            $atribut = Wilayah::atribut($kode, $awalanKolom);
            $teksBaru = implode(', ', array_filter([$atribut[$awalanKolom . 'kelurahan_nama'], $atribut[$awalanKolom . 'kecamatan_nama'], $atribut[$awalanKolom . 'kabupaten_nama'], $atribut[$awalanKolom . 'provinsi_nama']]));
            // Melengkapi tingkat yang lebih dalam di bawah wilayah tersimpan diperlakukan seperti mengisi yang kosong.
            $melengkapi = $lama === null || str_starts_with($kode, $lama . '.');
            if ($boleh($melengkapi, $judul, (string) Wilayah::ringkas($konsumen, $awalanKolom), $teksBaru)) $ubahKonsumen += $atribut;
        };
        $wilayah('', '', 'Wilayah KTP');

        $adaDomisili = array_intersect(['dom_sama', 'dom_alamat', 'dom_prov', 'dom_kab', 'dom_kec', 'dom_kel'], array_keys($baru));
        if ($adaDomisili) {
            $isianSama = mb_strtolower($v['dom_sama'] ?? '');
            $isianLain = array_intersect(['dom_alamat', 'dom_prov', 'dom_kab', 'dom_kec', 'dom_kel'], array_keys($baru));
            $statusLama = $konsumen->domisili_sama_ktp;

            if ($isianSama !== '' && !in_array($isianSama, ['ya', 'tidak'], true)) {
                $errors[] = "Domisili Sama KTP harus \"Ya\" atau \"Tidak\" (terisi '{$v['dom_sama']}').";
            } elseif ($isianSama === 'ya' && $isianLain) {
                $errors[] = 'Domisili Sama KTP = Ya, tetapi kolom domisili lain diisi. Ubah menjadi "Tidak" atau kosongkan kolom domisili lain.';
            } elseif ($isianSama === 'ya') {
                if ($boleh($statusLama === null, 'Domisili', $statusLama === null ? '' : 'berbeda dari KTP', 'sama dengan KTP')) $ubahKonsumen = array_merge($ubahKonsumen, Wilayah::domisili('sama'));
            } else {
                // Domisili berbeda dari KTP.
                $lanjut = true;
                if ($statusLama !== false) {
                    $lanjut = $boleh($statusLama === null, 'Domisili', $statusLama === null ? '' : 'sama dengan KTP', 'berbeda dari KTP');
                    if ($lanjut) $ubahKonsumen['domisili_sama_ktp'] = false;
                }
                if ($lanjut) {
                    if (isset($baru['dom_alamat']) && $boleh($this->kosong($konsumen->domisili_alamat), 'Alamat Domisili', (string) $konsumen->domisili_alamat, $baru['dom_alamat'])) {
                        $ubahKonsumen['domisili_alamat'] = $baru['dom_alamat'];
                    }
                    $wilayah('domisili_', 'dom_', 'Wilayah Domisili');
                }
            }
        }

        // ── Sumber lead ──
        $cariNama = function (string $key, string $kelas, string $judul) use ($baru, &$errors) {
            if (!isset($baru[$key])) return null;
            $m = $kelas::whereRaw('LOWER(nama) = ?', [mb_strtolower($baru[$key])])->first();
            if (!$m) $errors[] = "{$judul} '{$baru[$key]}' tidak dikenal.";

            return $m;
        };
        $lead = $cariNama('lead', SumberLead::class, 'Sumber Lead');
        $referral = $v['referral'] ?? null;
        if ($lead && $boleh($this->kosong($konsumen->sumber_lead_id), 'Sumber Lead', $kini['lead'], $lead->nama)) {
            if ($lead->is_referral && !$referral) {
                $errors[] = "Keterangan Referral wajib diisi karena Sumber Lead '{$lead->nama}' bertanda referral.";
            } else {
                $ubahKonsumen['sumber_lead_id'] = $lead->id;
                $ubahKonsumen['referral_keterangan'] = $lead->is_referral ? $referral : null;
                if ($lead->is_referral) $rincian[] = "Keterangan Referral → {$referral}";
            }
        } elseif (!isset($baru['lead']) && isset($baru['referral']) && $konsumen->sumberLead?->is_referral) {
            if ($boleh($this->kosong($konsumen->referral_keterangan), 'Keterangan Referral', (string) $konsumen->referral_keterangan, $baru['referral'])) {
                $ubahKonsumen['referral_keterangan'] = $baru['referral'];
            }
        }

        // ── Data transaksi (perlu izin masing-masing) ──
        $isiTrx = function (string $key, string $kolom, string $judul, string $kelas, bool $izin, string $namaIzin) use ($baru, $kini, $trx, $cariNama, &$ubahTrx, &$peringatan, $boleh) {
            if (!isset($baru[$key])) return;
            if (!$izin) { $peringatan[] = "{$judul} diabaikan: Anda tidak punya izin \"{$namaIzin}\"."; return; }
            $m = $cariNama($key, $kelas, $judul);
            if (!$m || (int) $trx->{$kolom} === (int) $m->id) return;
            if ($boleh($this->kosong($trx->{$kolom}), $judul, $kini[$key], $m->nama)) $ubahTrx[$kolom] = $m->id;
        };
        $isiTrx('sales', 'sales_agent_id', 'Sales/Agent', SalesAgent::class, $this->bolehPipeline, 'kelola pipeline sales');
        $isiTrx('notaris', 'notaris_preset_id', 'Notaris', NotarisPreset::class, $this->bolehPipeline, 'kelola pipeline sales');
        if (isset($baru['bank']) && !in_array($trx->cara_bayar, ['kpr_subsidi', 'kpr_komersil'], true)) {
            $peringatan[] = 'Bank Rekanan KPR diabaikan: transaksi ini bukan KPR.';
        } else {
            $isiTrx('bank', 'bank_rekanan_preset_id', 'Bank Rekanan KPR', BankRekananPreset::class, $this->bolehBank, 'isi bank rekanan kpr');
        }

        // Satu kesalahan melewatkan seluruh baris (tidak ada perubahan sebagian).
        if ($errors) {
            $this->result->skip($sheet, $rowNum, "{$label} ({$konsumen->nama}): " . implode(' ', array_unique($errors)));

            return;
        }
        foreach ($peringatan as $p) $this->result->warn($sheet, $rowNum, "{$label} ({$konsumen->nama}): {$p}");

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
