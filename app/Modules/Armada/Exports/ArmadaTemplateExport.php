<?php

declare(strict_types=1);

namespace App\Modules\Armada\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ArmadaTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            [
                'B 1234 XYZ', 'Toyota', 'Kijang Innova', 2022, 'tersedia',
                'MHFXW42G5N0000001', '1TR-1234567', 'Putih', 'bensin', 1000,
                '2023-05-15', 350000000, 'baru', 'Unit operasional Jakarta',
            ],
        ];
    }

    public function headings(): array
    {
        return [
            'nopol', 'merk', 'model', 'tahun', 'status',
            'nomor_rangka', 'nomor_mesin', 'warna', 'jenis_bahan_bakar', 'kapasitas_muatan_kg',
            'tanggal_beli', 'harga_beli', 'kondisi_beli', 'keterangan',
        ];
    }
}
