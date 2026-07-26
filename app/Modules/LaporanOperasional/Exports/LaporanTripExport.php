<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LaporanTripExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'LAPORAN TRIP';
    }

    public function subjudulLaporan(): string
    {
        if ($this->dari && $this->sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($this->dari)) . ' — ' . date('d/m/Y', strtotime($this->sampai));
        }
        return 'Semua Periode';
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Tanggal Berangkat', 'Proyek', 'Klien', 'Armada', 'Supir', 'Sumber', 'Status', 'Jarak (km)', 'Total Biaya'];
    }

    public function map($row): array
    {
        return [
            $row->waktu_berangkat ? date('d/m/Y H:i', strtotime($row->waktu_berangkat)) : '',
            $row->nama_proyek ?? '',
            $row->nama_klien ?? '-',
            $row->nopol ?? '-',
            $row->nama_supir ?? '-',
            ($row->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal',
            $row->status ?? '',
            $row->jarak_tempuh_km ?? 0,
            $row->total_biaya ?? 0,
        ];
    }
}
