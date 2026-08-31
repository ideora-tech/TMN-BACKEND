<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PasanganUnitDriverTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            [
                'B 5678 VND', 'Hino', 'Dump Truck', 'Tronton', '20 ton', 2021,
                '2027-03-01', '2026-12-15',
                'Budi Santoso', '081255501234', '320124000123',
            ],
        ];
    }

    public function headings(): array
    {
        return [
            'nopol', 'merk', 'jenis', 'jenis_kendaraan', 'kapasitas', 'tahun',
            'masa_berlaku_stnk', 'masa_berlaku_kir',
            'nama_driver', 'telepon_driver', 'no_sim_driver',
        ];
    }
}
