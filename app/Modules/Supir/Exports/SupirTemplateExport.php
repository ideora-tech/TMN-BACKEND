<?php

declare(strict_types=1);

namespace App\Modules\Supir\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SupirTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['Budi Santoso', '1234567890', 'B2', '2027-01-31', '081234567890', 'aktif', 'B 1234 XYZ'],
        ];
    }

    public function headings(): array
    {
        return ['nama', 'no_sim', 'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'nopol_armada_default'];
    }
}
