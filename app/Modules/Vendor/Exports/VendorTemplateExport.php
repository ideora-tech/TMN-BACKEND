<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VendorTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            [
                'VDR-001', 'PT Mitra Angkutan Jaya', 'transportir', 'Budi Santoso',
                'mitra@example.com', '081234567890', 'Jl. Raya Bekasi No. 1', '01.234.567.8-901.000', '2026-01-15',
            ],
        ];
    }

    public function headings(): array
    {
        return [
            'kode_vendor', 'nama_vendor', 'jenis_vendor', 'pic_nama',
            'email', 'telepon', 'alamat', 'npwp', 'tanggal_bergabung',
        ];
    }
}
