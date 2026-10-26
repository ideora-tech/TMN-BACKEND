<?php

declare(strict_types=1);

namespace App\Support;

final class JenisSim
{
    public const DAFTAR = ['A', 'A Umum', 'B1', 'B1 Umum', 'B2', 'B2 Umum'];

    private const PETA = [
        'A'       => 'A',
        'AUMUM'   => 'A Umum',
        'B1'      => 'B1',
        'BI'      => 'B1',
        'B1UMUM'  => 'B1 Umum',
        'BIUMUM'  => 'B1 Umum',
        'B2'      => 'B2',
        'BII'     => 'B2',
        'B2UMUM'  => 'B2 Umum',
        'BIIUMUM' => 'B2 Umum',
    ];

    public static function normalisasi(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $kunci = mb_strtoupper((string) preg_replace('/[\s._\-]+/u', '', $nilai));

        return self::PETA[$kunci] ?? null;
    }

    public static function teksDaftar(): string
    {
        $daftar = self::DAFTAR;
        $terakhir = array_pop($daftar);

        return implode(', ', $daftar) . ', atau ' . $terakhir;
    }
}
