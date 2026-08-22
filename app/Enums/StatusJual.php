<?php

namespace App\Enums;

enum StatusJual: string
{
    case Available            = 'available';
    case Hold                 = 'hold';
    case Booked               = 'booked';
    case Sold                 = 'sold';
    case CancellationRequested = 'cancellation_requested';
    case SwapRequested        = 'swap_requested';

    public function label(): string
    {
        return match($this) {
            self::Available             => 'Tersedia',
            self::Hold                  => 'Tidak Tersedia',
            self::Booked                => 'Dipesan',
            self::Sold                  => 'Terjual',
            self::CancellationRequested => 'Proses Pembatalan',
            self::SwapRequested         => 'Proses Tukar Unit',
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
            self::SwapRequested         => 'purple',
        };
    }

    /**
     * Status yang diizinkan untuk transisi. Available <-> Hold hanya toggle
     * ketersediaan oleh admin proyek (lihat KavlingController::updateStatusJual);
     * kavling harus Available dulu sebelum bisa dibooking sales — Hold
     * ("Tidak Tersedia") tidak bisa langsung dibooking.
     */
    public function allowedTransitions(): array
    {
        return match($this) {
            self::Available             => [self::Hold, self::Booked],
            self::Hold                  => [self::Available],
            self::Booked                => [self::Hold, self::Sold, self::Available, self::CancellationRequested, self::SwapRequested],
            self::Sold                  => [self::CancellationRequested, self::SwapRequested],
            self::CancellationRequested => [self::Sold, self::Booked, self::Available], // approved = available, ditolak/reject = kembali ke status sebelumnya, tapi itu dihandle controller
            self::SwapRequested         => [self::Sold, self::Booked, self::Available], // approved = kavling lama available & kavling baru ambil alih status, ditolak = kembali ke status sebelumnya
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
