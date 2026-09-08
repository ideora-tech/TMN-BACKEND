<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PenugasanUnitTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['B 9001 TMN', 'Budi Santoso', 'RT-0001'],
        ];
    }

    public function headings(): array
    {
        return ['nopol', 'nama_supir', 'kode_rute'];
    }
}
