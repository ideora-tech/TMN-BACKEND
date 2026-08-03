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

class RekapPerawatanUnitExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'REKAP BIAYA PERAWATAN PER UNIT';
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

        $total = [
            'is_total'         => true,
            'jumlah_perawatan' => $rows->sum(fn ($r) => (int) $r['jumlah_perawatan']),
            'biaya_jasa'       => $rows->sum(fn ($r) => (float) $r['biaya_jasa']),
            'biaya_sparepart'  => $rows->sum(fn ($r) => (float) $r['biaya_sparepart']),
            'total_biaya'      => $rows->sum(fn ($r) => (float) $r['total_biaya']),
        ];

        return $rows->push($total);
    }

    public function headings(): array
    {
        return ['Armada', 'Merk', 'Jumlah Perawatan', 'Biaya Jasa', 'Biaya Sparepart', 'Total Biaya'];
    }

    public function map($row): array
    {
        if (isset($row['is_total'])) {
            return [
                'TOTAL', '',
                (int) $row['jumlah_perawatan'],
                (float) $row['biaya_jasa'],
                (float) $row['biaya_sparepart'],
                (float) $row['total_biaya'],
            ];
        }

        return [
            $row['nopol'],
            $row['merk'] ?? '',
            (int) $row['jumlah_perawatan'],
            (float) $row['biaya_jasa'],
            (float) $row['biaya_sparepart'],
            (float) $row['total_biaya'],
        ];
    }
}
