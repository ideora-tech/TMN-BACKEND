<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ArmadaExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly Collection $data) {}

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Nopol', 'Merk', 'Model', 'Tahun', 'Kepemilikan', 'Status', 'Aktif'];
    }

    public function map($row): array
    {
        return [
            $row->nopol ?? '',
            $row->merk ?? '-',
            $row->model ?? '-',
            $row->tahun ?? '-',
            $row->kepemilikan ?? '',
            $row->status ?? '',
            ((bool) $row->aktif) ? 'Ya' : 'Tidak',
        ];
    }
}
