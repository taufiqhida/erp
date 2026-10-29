<?php

namespace App\Support;

use App\Models\BiayaTambahanPreset;
use App\Models\CatatanTransaksi;
use App\Models\KavlingKonsumen;
use App\Models\ProgramAllInPreset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Perubahan Rincian Pemesanan setelah booking: biaya kelebihan tanah, biaya tambahan, promo/diskon, program All In, harga dasar
 * mengikuti unit, dan tanggal booking. Satu jalur untuk dua cara pakai:
 *  - langsung oleh SPV/Leader/Superadmin (pemegang override), atau
 *  - sebagai USULAN Admin Sales yang baru diterapkan setelah disetujui SPV/Leader (lihat CancellationRequestController).
 * Pratinjau dihitung dengan MENERAPKAN lalu membatalkan transaksi database, jadi pasti sama dengan hasil penerapan sesungguhnya.
 *
 * Konvensi lama dipertahankan: Booking Fee/DP/jadwal cicilan yang sudah dibuat tidak ikut dihitung ulang (kecuali pergeseran tanggal
 * akibat koreksi tanggal booking), dan komponen yang sudah ada pembayarannya terkunci.
 */
class PerubahanRincian
{
    /** Kolom rincian yang boleh diubah (nama kolom sama dengan form). */
    public const KOLOM = [
        'biaya_kelebihan_tanah_aktif', 'biaya_kelebihan_tanah_luas', 'biaya_kelebihan_tanah_mode', 'biaya_kelebihan_tanah_harga_per_m2',
        'biaya_kelebihan_tanah_nominal_input', 'biaya_tambahan', 'promo_preset_id', 'diskon_mode', 'diskon_nilai', 'program_all_in_preset_id',
        'potongan_tanah',
    ];

    /** Ambil hanya kolom rincian dari data tervalidasi. */
    public static function ambil(array $validated): array
    {
        return array_intersect_key($validated, array_flip(self::KOLOM));
    }

    /**
     * Terapkan perubahan rincian ke transaksi. Pemanggil yang mengurus DB::transaction. Mengabort 422 bila menyentuh komponen
     * yang sudah ada pembayarannya.
     */
    public static function terapkanRincian(KavlingKonsumen $kk, array $u, bool $hargaDasarKeUnit = false): void
    {
        $kk->loadMissing('biayaTambahans.pembayarans', 'pembayarans', 'kavling');

        // Harga dasar mengikuti harga unit saat ini (mis. unit sudah ditukar tetapi harga lama dipertahankan).
        if ($hargaDasarKeUnit) {
            abort_if($kk->kavling->harga === null, 422, 'Unit ini belum punya harga, harga dasar tidak bisa mengikutinya.');
            $kk->harga_dasar = (float) $kk->kavling->harga;
        }

        // Lawan dari promo yang dipilih: hanya biaya ini yang boleh mendapat potongan promo.
        $lawan = PotonganPromo::lawan(!empty($u['promo_preset_id']) ? (int) $u['promo_preset_id'] : null);

        // ── Biaya Kelebihan Tanah: terkunci bila sudah ada pembayaran jenis biaya_tanah (nilai dan potongan lama dipertahankan).
        $biayaKelebihanLocked = $kk->pembayarans->where('jenis', 'biaya_tanah')->isNotEmpty();
        if ($biayaKelebihanLocked) {
            $biayaKelebihanNominal = (float) $kk->biaya_kelebihan_tanah_nominal;
            $potonganTanah = (float) $kk->biaya_kelebihan_tanah_potongan;
        } else {
            $biayaKelebihanNominal = 0;
            if ($u['biaya_kelebihan_tanah_aktif'] ?? false) {
                $biayaKelebihanNominal = ($u['biaya_kelebihan_tanah_mode'] ?? null) === 'per_m2'
                    ? round($u['biaya_kelebihan_tanah_luas'] * $u['biaya_kelebihan_tanah_harga_per_m2'], 2)
                    : (float) $u['biaya_kelebihan_tanah_nominal_input'];
            }
            $kk->biaya_kelebihan_tanah_aktif        = $u['biaya_kelebihan_tanah_aktif'] ?? false;
            $kk->biaya_kelebihan_tanah_luas         = $u['biaya_kelebihan_tanah_luas'] ?? null;
            $kk->biaya_kelebihan_tanah_mode         = $u['biaya_kelebihan_tanah_mode'] ?? null;
            $kk->biaya_kelebihan_tanah_harga_per_m2 = $u['biaya_kelebihan_tanah_harga_per_m2'] ?? null;
            $kk->biaya_kelebihan_tanah_nominal      = $biayaKelebihanNominal;

            $pt = PotonganPromo::untukBaris((float) $biayaKelebihanNominal, $u['potongan_tanah'] ?? null, $lawan['tanah'] && ($u['biaya_kelebihan_tanah_aktif'] ?? false));
            $potonganTanah = $pt['potongan'];
            $kk->biaya_kelebihan_tanah_potongan        = $pt['potongan'];
            $kk->biaya_kelebihan_tanah_potongan_mode   = $pt['mode'];
            $kk->biaya_kelebihan_tanah_potongan_nilai  = $pt['nilai'];
        }

        // ── Biaya Tambahan: item yang sudah punya cicilan tercatat tidak boleh diubah/dihapus; yang belum boleh diubah/dihapus/ditambah.
        $submitted = collect($u['biaya_tambahan'] ?? []);
        $existingLocked = $kk->biayaTambahans->filter(fn ($bt) => $bt->pembayarans->isNotEmpty());
        $existingUnpaid = $kk->biayaTambahans->filter(fn ($bt) => $bt->pembayarans->isEmpty());

        // Form mengirim SEMUA baris, termasuk yang terkunci. Baris terkunci yang dikirim apa adanya (item dan nominal sama) diabaikan,
        // sehingga item lain tetap bisa ditambah/diubah; hanya upaya MENGUBAH item yang sudah ada pembayarannya yang ditolak.
        $lockedById = $existingLocked->keyBy('id');
        foreach ($submitted as $item) {
            $row = !empty($item['id']) ? $lockedById->get((int) $item['id']) : null;
            if ($row) {
                $berubah = (int) $item['preset_id'] !== (int) $row->biaya_tambahan_preset_id || abs((float) $item['nominal'] - (float) $row->nominal) > 0.004;
                abort_if($berubah, 422, 'Ada item Biaya Tambahan yang sudah ada pembayaran — tidak bisa diubah.');
            }
        }
        $submitted = $submitted->reject(fn ($item) => !empty($item['id']) && $lockedById->has((int) $item['id']))->values();
        $submittedIds = $submitted->pluck('id')->filter()->all();

        $existingUnpaid->whereNotIn('id', $submittedIds)->each->delete();

        foreach ($submitted as $item) {
            $preset = BiayaTambahanPreset::find($item['preset_id']);
            $bruto = (float) $item['nominal'];
            $pot = PotonganPromo::untukBaris($bruto, $item['potongan'] ?? null, in_array((int) $item['preset_id'], $lawan['biaya_tambahan'], true));
            $data = [
                'biaya_tambahan_preset_id' => $item['preset_id'],
                'nominal'                  => $bruto,
                'potongan'                 => $pot['potongan'],
                'potongan_mode'            => $pot['mode'],
                'potongan_nilai'           => $pot['nilai'],
            ];
            if (!empty($item['id'])) {
                $row = $existingUnpaid->firstWhere('id', $item['id']);
                abort_if(!$row, 422, 'Item Biaya Tambahan tidak valid.');
                $row->update($data + ['nama' => $preset?->nama ?? $row->nama]);
            } else {
                $kk->biayaTambahans()->create($data + ['nama' => $preset?->nama ?? 'Biaya Tambahan']);
            }
        }

        // total_biaya_tambahan = nilai BERSIH (setelah potongan promo).
        $biayaTambahanTotal = (float) $kk->biayaTambahans()->get()->sum(fn ($b) => $b->ditagih);
        $totalBiayaTambahan = ($biayaKelebihanNominal - $potonganTanah) + $biayaTambahanTotal;

        // ── Diskon / Promo.
        $subtotal = (float) $kk->harga_dasar + $totalBiayaTambahan;
        // Diskon = potongan promo pada lawan "Harga Jual": hanya berlaku bila promo yang dipilih memang punya lawan itu.
        $diskonAktif = !empty($u['diskon_mode']) && $lawan['harga_jual'];
        $diskonNominal = 0;
        if ($diskonAktif) {
            $diskonNominal = $u['diskon_mode'] === 'persen'
                ? round($subtotal * ((float) $u['diskon_nilai'] / 100), 2)
                : (float) $u['diskon_nilai'];
        }

        $kk->promo_preset_id      = $u['promo_preset_id'] ?? null;
        $kk->diskon_mode          = $diskonAktif ? $u['diskon_mode'] : null;
        $kk->diskon_nilai         = $diskonAktif ? ($u['diskon_nilai'] ?? null) : null;
        $kk->diskon_nominal       = $diskonNominal;
        $kk->total_biaya_tambahan = $totalBiayaTambahan;
        $kk->harga_deal           = $subtotal - $diskonNominal;

        // ── Program All In: terkunci bila Titipan Biaya Akad sudah ada pembayaran.
        $titipanLocked = $kk->pembayarans->where('jenis', 'titipan_biaya_akad')->isNotEmpty();
        if (!$titipanLocked) {
            $allInPreset = !empty($u['program_all_in_preset_id']) ? ProgramAllInPreset::find($u['program_all_in_preset_id']) : null;
            $titipan = 0;
            if ($allInPreset) {
                $titipan = (float) $allInPreset->nominal;
                if ($allInPreset->include_booking_fee) $titipan -= (float) $kk->booking_fee;
                if ($allInPreset->include_dp) $titipan -= (float) $kk->jadwalTagihans()->where('jenis', 'dp')->sum('jumlah');
                $titipan = max(0, $titipan);
            }
            $kk->program_all_in_preset_id   = $allInPreset?->id;
            $kk->titipan_biaya_akad_nominal = $titipan;
        }

        $kk->save();
    }

    /**
     * Terapkan SELURUH usulan: rincian, harga dasar ke unit, dan tanggal booking, dalam satu transaksi database, lalu catat satu
     * catatan transaksi yang merangkum perbedaan sebelum/sesudah. $usulan = ['rincian' => [...]|null, 'harga_dasar_ke_unit' => bool,
     * 'tanggal_booking' => 'Y-m-d'|null].
     */
    public static function terapkanSemua(KavlingKonsumen $kk, array $usulan, string $alasan, User $penerap, ?User $pengaju = null, bool $catat = true): array
    {
        return DB::transaction(function () use ($kk, $usulan, $alasan, $penerap, $pengaju, $catat) {
            $sebelum = self::potret($kk);

            if (!empty($usulan['rincian']) || !empty($usulan['harga_dasar_ke_unit'])) {
                self::terapkanRincian($kk, $usulan['rincian'] ?? [], (bool) ($usulan['harga_dasar_ke_unit'] ?? false));
            }
            $tanggal = $usulan['tanggal_booking'] ?? null;
            if ($tanggal && !Carbon::parse($tanggal)->isSameDay($kk->fresh()->tanggal_booking)) {
                KoreksiTanggalBooking::terapkan($kk->fresh(), Carbon::parse($tanggal), $alasan, $penerap, catat: false);
            }

            $baris = self::bandingkan($sebelum, self::potret($kk));
            if ($catat && $baris) {
                $ringkas = collect($baris)->map(fn ($b) => "{$b['label']}: {$b['lama']} → {$b['baru']}")->implode('; ');
                $oleh = $pengaju ? "diusulkan {$pengaju->name}, disetujui {$penerap->name}" : "diubah langsung oleh {$penerap->name}";
                CatatanTransaksi::tulis($kk, 'booking', "Rincian pemesanan diubah ({$oleh}). {$ringkas}. Alasan: {$alasan}.", 'Ubah rincian', $penerap);
                activity('transaksi')->causedBy($penerap)->performedOn($kk)->withProperties(['perubahan' => $baris, 'alasan' => $alasan, 'pengaju' => $pengaju?->id])
                    ->log('Rincian pemesanan diubah' . ($pengaju ? ' (usulan disetujui)' : ' langsung'));
            }

            return $baris;
        });
    }

    /**
     * Pratinjau: terapkan lalu batalkan. Mengembalikan ['baris' => [[label, lama, baru]], 'konflik' => string|null].
     * Konflik (mis. komponen sudah terkunci pembayaran) tidak dilempar, tetapi dikembalikan sebagai pesan.
     */
    public static function pratinjau(KavlingKonsumen $kk, array $usulan, string $alasan = 'pratinjau'): array
    {
        $hasil = ['baris' => [], 'konflik' => null];
        try {
            DB::transaction(function () use ($kk, $usulan, $alasan, &$hasil) {
                $hasil['baris'] = self::terapkanSemua($kk, $usulan, $alasan, auth()->user() ?? new User(), null, catat: false);
                throw new HttpResponseException(response('pratinjau', 200));       // memaksa rollback
            });
        } catch (HttpResponseException) {
            // rollback disengaja
        } catch (HttpException $e) {
            $hasil['konflik'] = $e->getMessage() ?: 'Perubahan tidak bisa diterapkan.';
        }
        $kk->refresh();

        return $hasil;
    }

    /** Potret nilai-nilai yang ditampilkan saat membandingkan sebelum/sesudah. */
    private static function potret(KavlingKonsumen $kk): array
    {
        $kk->unsetRelations();
        $kk->refresh()->load('biayaTambahans', 'promoPreset', 'programAllInPreset');
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        return [
            'Tanggal booking' => $kk->tanggal_booking?->format('d M Y') ?? '-',
            'Harga dasar'     => $rp($kk->harga_dasar),
            'Biaya kelebihan tanah' => $kk->biaya_kelebihan_tanah_aktif
                ? $rp($kk->biaya_kelebihan_tanah_nominal) . ((float) $kk->biaya_kelebihan_tanah_potongan > 0 ? ' (potongan promo ' . $rp($kk->biaya_kelebihan_tanah_potongan) . ')' : '')
                : 'Tidak ada',
            'Biaya tambahan'  => $kk->biayaTambahans->isEmpty() ? 'Tidak ada' : $kk->biayaTambahans->map(fn ($b) => "{$b->nama} {$rp($b->nominal)}" . ((float) $b->potongan > 0 ? " (potongan promo {$rp($b->potongan)})" : ''))->implode(', '),
            'Promo'           => $kk->promoPreset?->nama ?? 'Tidak ada',
            'Diskon'          => $kk->diskon_mode ? ($kk->diskon_mode === 'persen' ? rtrim(rtrim(number_format((float) $kk->diskon_nilai, 2, ',', ''), '0'), ',') . '% (' . $rp($kk->diskon_nominal) . ')' : $rp($kk->diskon_nominal)) : 'Tidak ada',
            'Program All In'  => $kk->programAllInPreset?->nama ?? 'Tidak ada',
            'Harga deal'      => $rp($kk->harga_deal),
        ];
    }

    private static function bandingkan(array $sebelum, array $sesudah): array
    {
        $baris = [];
        foreach ($sebelum as $label => $lama) {
            if ($lama !== $sesudah[$label]) $baris[] = ['label' => $label, 'lama' => $lama, 'baru' => $sesudah[$label]];
        }

        return $baris;
    }
}
