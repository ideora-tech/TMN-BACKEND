<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LaporanProyekExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly Collection $data) {}

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['ID Laporan', 'ID Proyek', 'Ringkasan', 'Total Trip', 'Diserahkan Pada', 'Dibuat Pada'];
    }

    public function map($row): array
    {
        return [
            $row->id_laporan ?? '',
            $row->id_proyek ?? '',
            $row->ringkasan ?? '',
            $row->total_trip ?? 0,
            $row->diserahkan_pada ? $row->diserahkan_pada->format('d/m/Y H:i') : '',
            $row->dibuat_pada ?? '',
        ];
    }
}