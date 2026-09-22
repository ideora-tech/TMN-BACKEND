<?php

declare(strict_types=1);

namespace App\Support;

class TipeHarga
{
    public const PER_RIT = 'per_rit';

    public const NILAI_TETAP = ['borongan', 'unit_only', 'unit_driver', 'all_in'];

    public const SEMUA = ['per_rit', 'borongan', 'unit_only', 'unit_driver', 'all_in'];

    public static function nilaiTetap(?string $tipe): bool
    {
        return in_array($tipe, self::NILAI_TETAP, true);
    }

    public static function perRit(?string $tipe): bool
    {
        return !self::nilaiTetap($tipe);
    }
}
