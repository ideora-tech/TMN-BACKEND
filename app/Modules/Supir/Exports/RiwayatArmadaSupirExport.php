<?php

declare(strict_types=1);

namespace App\Modules\Supir\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class RiwayatArmadaSupirExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents, WithTitle
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly string $namaSupir,
    ) {}

    public function title(): string
    {
        return 'Riwayat Armada';
    }

    public function judulLaporan(): string
    {
        return 'RIWAYAT ARMADA';
    }

    public function subjudulLaporan(): string
    {
        return 'Supir: ' . $this->namaSupir;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Tanggal Tugas', 'Armada', 'Merk / Model', 'Proyek', 'Kode Proyek', 'Sumber', 'Status'];
    }

    public function map($row): array
    {
        $merkModel = trim(($row->merk ?? '') . ' ' . ($row->model ?? ''));

        return [
            $row->tanggal_tugas ? date('d/m/Y', strtotime((string) $row->tanggal_tugas)) : '-',
            $row->nopol ?? '-',
            $merkModel !== '' ? $merkModel : '-',
            $row->nama_proyek ?? '-',
            $row->kode_proyek ?? '-',
            ($row->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal',
            ucfirst((string) ($row->status ?? '-')),
        ];
    }
}
