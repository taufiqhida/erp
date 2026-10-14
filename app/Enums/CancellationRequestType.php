<?php

namespace App\Enums;

enum CancellationRequestType: string
{
    case Cancellation = 'cancellation';
    case UnitSwap     = 'unit_swap';
    case BuyerTransfer = 'buyer_transfer';
    case RincianChange = 'rincian_change';

    public function label(): string
    {
        return match($this) {
            self::Cancellation => 'Pembatalan',
            self::UnitSwap     => 'Tukar Unit',
            self::BuyerTransfer => 'Alih Pembeli',
            self::RincianChange => 'Ubah Rincian',
        };
    }
}
