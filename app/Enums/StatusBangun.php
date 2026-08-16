<?php

namespace App\Enums;

enum StatusBangun: string
{
    case NotStarted    = 'not_started';
    case Foundation    = 'foundation';
    case Structure     = 'structure';
    case Roofing       = 'roofing';
    case Finishing     = 'finishing';
    case HandoverReady = 'handover_ready';

    public function label(): string
    {
        return match($this) {
            self::NotStarted    => 'Belum Mulai',
            self::Foundation    => 'Pondasi',
            self::Structure     => 'Struktur',
            self::Roofing       => 'Atap',
            self::Finishing     => 'Finishing',
            self::HandoverReady => 'Siap Serah Terima',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::NotStarted    => 'gray',
            self::Foundation    => 'orange',
            self::Structure     => 'blue',
            self::Roofing       => 'indigo',
            self::Finishing     => 'purple',
            self::HandoverReady => 'green',
        };
    }

    public function progressPercent(): int
    {
        return match($this) {
            self::NotStarted    => 0,
            self::Foundation    => 20,
            self::Structure     => 40,
            self::Roofing       => 60,
            self::Finishing     => 80,
            self::HandoverReady => 100,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
