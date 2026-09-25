<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Urutan tampil yang bisa diatur admin (tombol geser naik/turun) untuk master
 * data. Item baru otomatis masuk paling bawah. Model bisa mengelompokkan
 * urutan (mis. per kategori) lewat urutanGroup().
 */
trait HasUrutan
{
    protected static function bootHasUrutan(): void
    {
        static::creating(function ($model) {
            if (empty($model->urutan)) {
                $model->urutan = (int) $model->urutanSiblings()->max('urutan') + 1;
            }
        });
    }

    /** Kolom => nilai yang membatasi "tetangga" urutan; kosong = satu daftar tunggal. */
    public function urutanGroup(): array
    {
        return [];
    }

    private function urutanSiblings(): Builder
    {
        $query = static::query();
        foreach ($this->urutanGroup() as $column => $value) {
            $query->where($column, $value);
        }

        return $query;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('urutan')->orderBy('id');
    }

    /** Tukar posisi dengan tetangga terdekat; tidak melakukan apa-apa di ujung daftar. */
    public function moveUrutan(string $direction): void
    {
        $siblings = $this->urutanSiblings();

        $neighbor = $direction === 'up'
            ? $siblings->where('urutan', '<', $this->urutan)->orderByDesc('urutan')->first()
            : $siblings->where('urutan', '>', $this->urutan)->orderBy('urutan')->first();

        if (!$neighbor) return;

        DB::transaction(function () use ($neighbor) {
            $urutan = $this->urutan;
            $this->forceFill(['urutan' => $neighbor->urutan])->save();
            $neighbor->forceFill(['urutan' => $urutan])->save();
        });
    }
}
