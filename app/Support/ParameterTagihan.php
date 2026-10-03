<?php

declare(strict_types=1);

namespace App\Support;

final class ParameterTagihan
{
    public const DAFTAR = [
        'overnight'     => ['jumlah' => 'jumlah_overnight', 'tarif' => 'tarif_overnight', 'sumber' => 'biaya_overnight', 'satuan' => 'malam'],
        'add_drop'      => ['jumlah' => 'jumlah_add_drop', 'tarif' => 'tarif_add_drop', 'sumber' => 'biaya_add_drop', 'satuan' => 'drop'],
        'cross_cluster' => ['jumlah' => 'cross_cluster', 'tarif' => 'tarif_cross_cluster', 'sumber' => 'biaya_cross_cluster', 'satuan' => null],
        'cancellation'  => ['jumlah' => 'cancellation', 'tarif' => 'tarif_cancellation', 'sumber' => 'biaya_cancellation', 'satuan' => null],
    ];

    /** @return string[] */
    public static function kolomSelect(string $alias): array
    {
        $kolom = [];
        foreach (self::DAFTAR as $def) {
            $kolom[] = "{$alias}.{$def['jumlah']}";
            $kolom[] = "{$alias}.{$def['tarif']}";
        }
        $kolom[] = "{$alias}.keterangan as keterangan_parameter";
        return $kolom;
    }

    public static function label(string $kode): string
    {
        return ParameterPenawaran::DAFTAR[self::DAFTAR[$kode]['sumber']]['label'];
    }

    public static function cancellation(?object $baris): bool
    {
        return (int) ($baris?->cancellation ?? 0) === 1 && (float) ($baris?->tarif_cancellation ?? 0) > 0;
    }

    /**
     * @return array{cancellation: bool, harga_dasar: float|null, komponen: array<int, array{kode: string, label: string, satuan: string|null, jumlah: int, tarif: float, nominal: float}>, total_parameter: float, keterangan: string|null}
     */
    public static function rincian(?float $hargaDeal, ?object $baris): array
    {
        $cancellation = self::cancellation($baris);

        $komponen = [];
        foreach (self::DAFTAR as $kode => $def) {
            if ($cancellation !== ($kode === 'cancellation')) {
                continue;
            }
            $jumlah = (int) ($baris?->{$def['jumlah']} ?? 0);
            $tarif  = $baris?->{$def['tarif']} ?? null;
            if ($jumlah <= 0 || $tarif === null || (float) $tarif <= 0) {
                continue;
            }
            $komponen[] = [
                'kode'    => $kode,
                'label'   => self::label($kode),
                'satuan'  => $def['satuan'],
                'jumlah'  => $jumlah,
                'tarif'   => (float) $tarif,
                'nominal' => $jumlah * (float) $tarif,
            ];
        }

        return [
            'cancellation'    => $cancellation,
            'harga_dasar'     => $cancellation ? 0.0 : $hargaDeal,
            'komponen'        => $komponen,
            'total_parameter' => (float) array_sum(array_column($komponen, 'nominal')),
            'keterangan'      => $baris?->keterangan_parameter ?? $baris?->keterangan ?? null,
        ];
    }
}
