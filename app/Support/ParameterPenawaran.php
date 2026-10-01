<?php

declare(strict_types=1);

namespace App\Support;

final class ParameterPenawaran
{
    public const DAFTAR = [
        'biaya_overnight'     => ['label' => 'Overnight', 'satuan' => 'per malam'],
        'biaya_cancellation'  => ['label' => 'Cancellation', 'satuan' => 'per trip'],
        'biaya_add_drop'      => ['label' => 'Add Drop', 'satuan' => 'per titik tambahan'],
        'biaya_cross_cluster' => ['label' => 'Cross Cluster', 'satuan' => 'per trip'],
    ];

    /** @return string[] */
    public static function kolom(): array
    {
        return array_keys(self::DAFTAR);
    }

    /** @return array<string, array<int, string>> */
    public static function aturanValidasi(): array
    {
        return array_fill_keys(self::kolom(), ['sometimes', 'nullable', 'numeric', 'min:0']);
    }

    /** @return array<string, float|null> */
    public static function nilai(?object $sumber): array
    {
        $hasil = [];
        foreach (self::kolom() as $kolom) {
            $v = $sumber?->{$kolom} ?? null;
            $hasil[$kolom] = $v !== null ? (float) $v : null;
        }
        return $hasil;
    }

    /** @return array<int, array{kolom: string, label: string, satuan: string, nilai: float}> */
    public static function terisi(?object $sumber): array
    {
        $hasil = [];
        foreach (self::nilai($sumber) as $kolom => $nilai) {
            if ($nilai === null) {
                continue;
            }
            $hasil[] = ['kolom' => $kolom, 'nilai' => $nilai] + self::DAFTAR[$kolom];
        }
        return $hasil;
    }
}
