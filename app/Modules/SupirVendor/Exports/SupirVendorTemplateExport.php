<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SupirVendorTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['VDR-001', 'Joko Susilo', '081234567890', '1234567890123456', '2027-06-30'],
        ];
    }

    public function headings(): array
    {
        return [
            'kode_vendor', 'nama', 'telepon', 'no_sim', 'masa_berlaku_sim',
        ];
    }
}
