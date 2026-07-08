<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class FakturExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly Collection $data) {}

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['No. Faktur', 'Klien', 'Status', 'Total', 'Tanggal Faktur', 'Jatuh Tempo', 'Dibuat Pada'];
    }

    public function map($row): array
    {
        return [
            $row->nomor_faktur ?? '',
            $row->klien->nama_klien ?? '-',
            $row->status ?? '',
            $row->total ?? 0,
            $row->tanggal_faktur ? $row->tanggal_faktur->format('d/m/Y') : '',
            $row->jatuh_tempo ? $row->jatuh_tempo->format('d/m/Y') : '',
            $row->dibuat_pada ?? '',
        ];
    }
}