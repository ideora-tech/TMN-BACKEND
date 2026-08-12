<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiVendor\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KonsolidasiVendorExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly string $namaVendor,
        private readonly string $periode,
        private readonly Collection $trips,
    ) {}

    public function judulLaporan(): string
    {
        return 'KONSOLIDASI VENDOR ' . mb_strtoupper($this->namaVendor);
    }

    public function subjudulLaporan(): string
    {
        return $this->periode;
    }

    public function headings(): array
    {
        return ['No', 'Tanggal', 'Nopol', 'Supir', 'Rute', 'Jarak (km)', 'Mekanisme'];
    }

    public function array(): array
    {
        $rows = $this->trips->values()->map(fn ($t, $i) => [
            $i + 1,
            $t['tanggal'],
            $t['nopol'] ?? '-',
            $t['supir_nama'] ?? '-',
            $t['rute'] ?? '-',
            $t['jarak_tempuh_km'] ?? 0,
            $t['mekanisme'] ?? '-',
        ])->all();

        $rows[] = [
            '',
            'TOTAL',
            '',
            '',
            $this->trips->count() . ' rit',
            $this->trips->sum(fn ($t) => $t['jarak_tempuh_km'] ?? 0),
            '',
        ];

        return $rows;
    }
}
