<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ArmadaVendorTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            [
                'VDR-001', 'B 5678 VND', 'Hino', 'Dump Truck', 'Tronton',
                '20 ton', 2021, '2027-03-01', '2026-12-15',
            ],
        ];
    }

    public function headings(): array
    {
        return [
            'kode_vendor', 'nopol', 'merk', 'jenis', 'jenis_kendaraan',
            'kapasitas', 'tahun', 'masa_berlaku_stnk', 'masa_berlaku_kir',
        ];
    }
}
