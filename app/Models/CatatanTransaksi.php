<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Catatan pada sebuah transaksi (booking / pemberkasan). Tiap catatan bisa diedit, ditandai selesai, dan dihapus (hanya oleh
 * pemegang hak override). Catatan proses bank, SP3K, BAST, dan revisi dokumen tetap tinggal di kartu/berkasnya masing-masing
 * dan hanya DITAMPILKAN bersama catatan ini (lihat payload()).
 */
class CatatanTransaksi extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'kavling_konsumen_id', 'jenis', 'tahap', 'isi', 'selesai', 'diselesaikan_oleh', 'diselesaikan_at', 'dibuat_oleh', 'penulis_nama',
    ];

    protected $casts = [
        'selesai'         => 'boolean',
        'diselesaikan_at' => 'datetime',
    ];

    /** Izin yang boleh menambah/mengedit/menyelesaikan catatan: siapa pun yang memegang pipeline atau dokumen konsumen. */
    private const IZIN_KELOLA = ['kelola pipeline sales', 'kelola pemberkasan bank', 'manage dokumen', 'book kavling'];

    public static function jenisLabel(): array
    {
        return ['booking' => 'Catatan booking', 'pemberkasan' => 'Catatan pemberkasan'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $event) => "Catatan transaksi telah di-{$event}");
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(KavlingKonsumen::class, 'kavling_konsumen_id');
    }

    public function penyelesai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diselesaikan_oleh');
    }

    /** Buat catatan baru atas nama pengguna yang sedang login (atau tanpa penulis bila tidak ada, mis. impor). */
    public static function tulis(KavlingKonsumen $kk, string $jenis, string $isi, ?string $tahap = null, ?User $penulis = null): self
    {
        $penulis ??= auth()->user();

        return self::create([
            'kavling_konsumen_id' => $kk->id, 'jenis' => $jenis, 'tahap' => $tahap, 'isi' => trim($isi),
            'dibuat_oleh' => $penulis?->id, 'penulis_nama' => $penulis?->name,
        ]);
    }

    /** Hak pengguna pada transaksi ini: ['tambah' => tambah/edit/selesaikan, 'hapus' => hapus]. Transaksi terkunci hanya untuk pemegang override. */
    public static function izin(KavlingKonsumen $kk, ?User $user): array
    {
        if (!$user) return ['tambah' => false, 'hapus' => false];
        $override = $user->can('override transaction lock');
        $kelola = collect(self::IZIN_KELOLA)->contains(fn ($p) => $user->can($p));

        // SPV/Leader/Superadmin (pemegang override) ikut boleh mengelola catatan karena mereka yang mengawasi dan boleh menghapusnya.
        return ['tambah' => ($kelola || $override) && (!$kk->is_locked || $override), 'hapus' => $override];
    }

    /** Semua catatan satu transaksi untuk ditampilkan: entri + catatan baca-saja dari kartu lain + jumlah yang masih terbuka. */
    public static function payload(KavlingKonsumen $kk, ?User $user): array
    {
        $kk->loadMissing('catatans.penyelesai', 'dokumens', 'bastRecord');

        $entri = $kk->catatans->map(fn (self $c) => [
            'id'                => $c->id,
            'jenis'             => $c->jenis,
            'tahap'             => $c->tahap,
            'isi'               => $c->isi,
            'selesai'           => $c->selesai,
            'penulis'           => $c->penulis_nama,
            'tanggal'           => $c->created_at?->format('d M Y H:i'),
            'diedit'            => $c->updated_at && $c->created_at && $c->updated_at->gt($c->created_at->copy()->addMinute()) && !$c->selesai,
            'diselesaikan_oleh' => $c->penyelesai?->name,
            'diselesaikan_at'   => $c->diselesaikan_at?->format('d M Y H:i'),
        ])->values();

        // Catatan revisi dokumen: terbuka selama berkas masih Perlu Revisi/Ditolak; otomatis selesai begitu berkasnya Sudah Ada.
        $revisi = $kk->dokumens->filter(fn ($d) => filled($d->catatan_revisi))->map(fn ($d) => [
            'nama' => $d->nama_dokumen, 'status_label' => $d->status_label, 'catatan' => $d->catatan_revisi, 'selesai' => $d->status === 'sudah_ada',
        ])->values();

        return [
            'entri'          => $entri,
            'revisi_dokumen' => $revisi,
            'proses_bank'    => $kk->catatan_bank,
            'sp3k'           => $kk->catatan_sp3k,
            'bast'           => $kk->bastRecord?->catatan,
            'terbuka'        => $entri->where('selesai', false)->count() + $revisi->where('selesai', false)->count(),
            'total'          => $entri->count() + $revisi->count() + collect([$kk->catatan_bank, $kk->catatan_sp3k, $kk->bastRecord?->catatan])->filter()->count(),
            'izin'           => self::izin($kk, $user),
        ];
    }
}
