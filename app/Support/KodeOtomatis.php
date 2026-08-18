<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KodeOtomatis
{
    public const DEFAULT = [
        'proyek'    => ['prefix' => 'PRJ', 'panjang_digit' => 4, 'reset' => 'tahunan'],
        'rute'      => ['prefix' => 'RT',  'panjang_digit' => 4, 'reset' => 'tidak'],
        'penawaran' => ['prefix' => 'PNW', 'panjang_digit' => 4, 'reset' => 'bulanan'],
    ];

    public static function berikutnya(string $idPerusahaan, string $entitas): string
    {
        $aturan = DB::table('pengaturan_kode')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('entitas', $entitas)
            ->whereNull('dihapus_pada')
            ->first();

        $prefix  = $aturan->prefix ?? self::DEFAULT[$entitas]['prefix'] ?? strtoupper(substr($entitas, 0, 3));
        $digit   = (int) ($aturan->panjang_digit ?? self::DEFAULT[$entitas]['panjang_digit'] ?? 4);
        $reset   = $aturan->reset ?? self::DEFAULT[$entitas]['reset'] ?? 'tidak';
        $periode = match ($reset) {
            'tahunan' => now()->format('Y'),
            'bulanan' => now()->format('Ym'),
            default   => '',
        };

        return DB::transaction(function () use ($idPerusahaan, $entitas, $periode, $prefix, $digit) {
            $baris = DB::table('kode_sequence')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('entitas', $entitas)
                ->where('periode', $periode)
                ->lockForUpdate()
                ->first();

            if ($baris === null) {
                DB::table('kode_sequence')->insert([
                    'id_sequence'    => (string) Str::uuid(),
                    'id_perusahaan'  => $idPerusahaan,
                    'entitas'        => $entitas,
                    'periode'        => $periode,
                    'nilai_terakhir' => 1,
                ]);
                $nilai = 1;
            } else {
                $nilai = (int) $baris->nilai_terakhir + 1;
                DB::table('kode_sequence')->where('id_sequence', $baris->id_sequence)
                    ->update(['nilai_terakhir' => $nilai]);
            }

            $urut = str_pad((string) $nilai, $digit, '0', STR_PAD_LEFT);
            return $periode === '' ? "{$prefix}-{$urut}" : "{$prefix}-{$periode}-{$urut}";
        });
    }
}
