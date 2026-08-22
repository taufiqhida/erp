<?php

namespace App\Enums;

enum CancellationRequestType: string
{
    case Cancellation = 'cancellation';
    case UnitSwap     = 'unit_swap';

    public function label(): string
    {
        return match($this) {
            self::Cancellation => 'Pembatalan',
            self::UnitSwap     => 'Tukar Unit',
        };
    }
}
