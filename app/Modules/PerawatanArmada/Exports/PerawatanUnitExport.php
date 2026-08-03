<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PerawatanUnitExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly object $armada,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'LAPORAN PERAWATAN ARMADA — ' . strtoupper((string) $this->armada->nopol);
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
        $rows = $this->data;

        $total = (object) [
            'is_total'        => true,
            'biaya'           => $rows->sum(fn ($r) => (float) $r->biaya),
            'total_sparepart' => $rows->sum(fn ($r) => (float) $r->total_sparepart),
        ];

        return $rows->push($total);
    }

    public function headings(): array
    {
        return ['Tanggal', 'Jenis Perawatan', 'Status', 'KM Odometer', 'Biaya Jasa', 'Biaya Sparepart', 'Total'];
    }

    public function map($row): array
    {
        if (isset($row->is_total)) {
            return [
                'TOTAL', '', '', '',
                (float) $row->biaya,
                (float) $row->total_sparepart,
                (float) $row->biaya + (float) $row->total_sparepart,
            ];
        }

        return [
            date('d/m/Y', strtotime((string) $row->tanggal)),
            $row->jenis_perawatan ?? '',
            str_replace('_', ' ', (string) ($row->status ?? 'selesai')),
            $row->km_odometer ?? '',
            (float) $row->biaya,
            (float) $row->total_sparepart,
            (float) $row->biaya + (float) $row->total_sparepart,
        ];
    }
}
