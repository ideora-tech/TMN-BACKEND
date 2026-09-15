<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SparepartTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['SP-001', 'Filter Oli', 'FO-12345', 'Sakura', 2024, 'Filter', 'pcs', 85000, 10],
        ];
    }

    public function headings(): array
    {
        return ['kode', 'nama', 'serial_number', 'merek', 'tahun', 'kategori', 'satuan', 'harga_standar', 'stok_awal'];
    }
}
