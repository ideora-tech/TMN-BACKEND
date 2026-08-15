<?php

declare(strict_types=1);

namespace App\Modules\Trip\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class RiwayatTripSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents, WithTitle
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function title(): string
    {
        return 'Riwayat Trip';
    }

    public function judulLaporan(): string
    {
        return 'RIWAYAT TRIP';
    }

    public function subjudulLaporan(): string
    {
        return RekapTripSupirExport::labelPeriode($this->dari, $this->sampai);
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Berangkat', 'Selesai', 'Proyek', 'Kode Proyek', 'Klien', 'Rute', 'Supir', 'Armada', 'Sumber', 'Status'];
    }

    public function map($row): array
    {
        return [
            $row->waktu_berangkat ? date('d/m/Y H:i', strtotime((string) $row->waktu_berangkat)) : '-',
            $row->waktu_checkout ? date('d/m/Y H:i', strtotime((string) $row->waktu_checkout)) : '-',
            $row->nama_proyek ?? '-',
            $row->kode_proyek ?? '-',
            $row->nama_klien ?? '-',
            $row->rute ?? '-',
            $row->supir_nama ?? '-',
            $row->armada_nopol ?? '-',
            ($row->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal',
            ucfirst((string) $row->status),
        ];
    }
}
