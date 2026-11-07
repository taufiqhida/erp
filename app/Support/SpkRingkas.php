<?php

namespace App\Support;

use App\Models\Project;
use App\Models\StatusBangunStage;
use Illuminate\Support\Collection;

/**
 * Daftar SPK sebuah proyek beserta status turunannya, dipakai halaman SPK Unit dan ringkasan di Proses Bangun.
 *
 *  - berjalan        : masih ada unit yang belum selesai dibangun
 *  - selesai         : semua unit selesai dibangun (tahap terakhir dan 100%)
 *  - lewat deadline  : berjalan DAN tanggal deadline sudah lewat (SPK yang sudah selesai tidak pernah ditandai terlambat)
 */
class SpkRingkas
{
    public const STATUS_BERJALAN = 'berjalan';
    public const STATUS_SELESAI = 'selesai';

    /** @return Collection<int, array<string, mixed>> */
    public static function daftar(Project $project): Collection
    {
        $hariIni = now()->startOfDay();

        return $project->spks()
            ->with(['kontraktor:id,nama', 'kavlings.statusBangunStage'])
            ->get()
            ->map(function ($spk) use ($hariIni) {
                $unit = $spk->kavlings
                    ->map(fn ($k) => [
                        'id'       => $k->id,
                        'nomor'    => $k->nomor_lengkap,
                        'progress' => $k->progress_bangun,
                        'selesai'  => $k->bangun_selesai,
                    ])
                    ->sort(fn ($a, $b) => strnatcasecmp($a['nomor'], $b['nomor']))
                    ->values();
                $selesai = $unit->count() > 0 && $unit->every(fn ($u) => $u['selesai']);
                $sisaHari = (int) $hariIni->diffInDays($spk->tanggal_deadline->copy()->startOfDay(), false);   // negatif = sudah lewat

                return [
                    'id'               => $spk->id,
                    'nomor_spk'        => $spk->nomor_spk,
                    'kontraktor_id'    => $spk->kontraktor_id,
                    'kontraktor_nama'  => $spk->kontraktor->nama,
                    'tanggal_terbit'   => $spk->tanggal_terbit->format('d M Y'),
                    'terbit_raw'       => $spk->tanggal_terbit->format('Y-m-d'),
                    'tanggal_deadline' => $spk->tanggal_deadline->format('d M Y'),
                    'deadline_raw'     => $spk->tanggal_deadline->format('Y-m-d'),
                    'sisa_hari'        => $sisaHari,
                    'nilai_spk'        => $spk->nilai_spk !== null ? (float) $spk->nilai_spk : null,
                    'catatan'          => $spk->catatan,
                    'unit'             => $unit->all(),
                    'jumlah_unit'      => $unit->count(),
                    'jumlah_selesai'   => $unit->where('selesai', true)->count(),
                    'status'           => $selesai ? self::STATUS_SELESAI : self::STATUS_BERJALAN,
                    'lewat_deadline'   => !$selesai && $sisaHari < 0,
                    // Badge deadline: SPK selesai netral; yang berjalan memakai ambang warna model Spk.
                    'deadline_status'  => $selesai ? 'selesai' : $spk->deadline_status,
                ];
            })
            ->values();
    }

    /** @param  Collection<int, array<string, mixed>>  $daftar */
    public static function kartu(Collection $daftar): array
    {
        $berjalan = $daftar->where('status', self::STATUS_BERJALAN);

        return [
            'total'          => $daftar->count(),
            'berjalan'       => $berjalan->count(),
            'lewat_deadline' => $berjalan->where('lewat_deadline', true)->count(),
            'selesai'        => $daftar->where('status', self::STATUS_SELESAI)->count(),
        ];
    }
}
