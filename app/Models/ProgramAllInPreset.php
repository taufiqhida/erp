<?php

namespace App\Models;

use App\Models\Concerns\HasUrutan;
use Illuminate\Database\Eloquent\Model;

class ProgramAllInPreset extends Model
{
    use HasUrutan;

    protected $fillable = [
        'nama',
        'nominal',
        'include_booking_fee',
        'include_dp',
        'dp_nominal_tetap',
        'is_active',
    ];

    protected $casts = [
        'nominal'              => 'decimal:2',
        'include_booking_fee'  => 'boolean',
        'include_dp'           => 'boolean',
        'dp_nominal_tetap'     => 'decimal:2',
        'is_active'            => 'boolean',
    ];

    /**
     * DP yang dikurangkan dari nominal All In: 0 bila DP tidak termasuk; nominal tetap preset bila diisi (mis. 1,66 jt di dalam paket
     * 10,5 jt, apa pun DP skemanya); selain itu DP skema yang dipilih (perilaku lama).
     */
    public function potonganDp(float $dpSkema): float
    {
        if (!$this->include_dp) return 0.0;

        return $this->dp_nominal_tetap !== null ? (float) $this->dp_nominal_tetap : $dpSkema;
    }

    /** Titipan Biaya Akad bruto = nominal All In dikurangi Booking Fee (bila termasuk) dan DP (lihat potonganDp). Satu-satunya rumus. */
    public function titipanBruto(float $bookingFee, float $dpSkema): float
    {
        return max(0.0, (float) $this->nominal - ($this->include_booking_fee ? $bookingFee : 0.0) - $this->potonganDp($dpSkema));
    }
}
