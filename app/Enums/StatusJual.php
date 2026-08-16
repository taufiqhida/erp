<?php

namespace App\Enums;

enum StatusJual: string
{
    case Available            = 'available';
    case Hold                 = 'hold';
    case Booked               = 'booked';
    case Sold                 = 'sold';
    case CancellationRequested = 'cancellation_requested';

    public function label(): string
    {
        return match($this) {
            self::Available             => 'Tersedia',
            self::Hold                  => 'Ditahan',
            self::Booked                => 'Dipesan',
            self::Sold                  => 'Terjual',
            self::CancellationRequested => 'Proses Pembatalan',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Available             => 'green',
            self::Hold                  => 'yellow',
            self::Booked                => 'blue',
            self::Sold                  => 'red',
            self::CancellationRequested => 'orange',
        };
    }

    /** Status yang diizinkan untuk transisi */
    public function allowedTransitions(): array
    {
        return match($this) {
            self::Available             => [self::Hold, self::Booked],
            self::Hold                  => [self::Available, self::Booked],
            self::Booked                => [self::Hold, self::Sold, self::Available],
            self::Sold                  => [self::CancellationRequested],
            self::CancellationRequested => [self::Sold], // approved = available, tapi itu dihandle controller
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
