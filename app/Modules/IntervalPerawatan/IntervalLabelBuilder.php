<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

final class IntervalLabelBuilder
{
    /** "Tiap 10.000 km / 6 bulan", "Tiap 10.000 km", "Tiap 6 bulan", atau '' bila keduanya kosong. */
    public static function build(?int $intervalKm, ?int $intervalBulan): string
    {
        $bagian = [];
        if ($intervalKm !== null) {
            $bagian[] = number_format($intervalKm, 0, ',', '.') . ' km';
        }
        if ($intervalBulan !== null) {
            $bagian[] = $intervalBulan . ' bulan';
        }

        if ($bagian === []) {
            return '';
        }

        return 'Tiap ' . implode(' / ', $bagian);
    }

    /** Sama seperti build(), tapi kembalikan $fallback bila tidak ada paket tertaut (dipakai tampilan legacy: servis jatuh tempo dashboard, perawatan aktif, servis terakhir papan-unit). */
    public static function buildOrFallback(?int $intervalKm, ?int $intervalBulan, string $fallback = 'Perbaikan'): string
    {
        $label = self::build($intervalKm, $intervalBulan);
        return $label !== '' ? $label : $fallback;
    }
}
