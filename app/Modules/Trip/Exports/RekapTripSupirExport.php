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

class RekapTripSupirExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'REKAP TRIP PER SUPIR';
    }

    public static function labelPeriode(?string $dari, ?string $sampai): string
    {
        if ($dari && $sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($dari)) . ' — ' . date('d/m/Y', strtotime($sampai));
        }
        if ($dari) {
            return 'Periode: sejak ' . date('d/m/Y', strtotime($dari));
        }
        if ($sampai) {
            return 'Periode: s.d. ' . date('d/m/Y', strtotime($sampai));
        }
        return 'Semua Periode';
    }

    public function subjudulLaporan(): string
    {
        return self::labelPeriode($this->dari, $this->sampai);
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Nama Supir', 'Sumber', 'Jumlah Trip', 'Selesai', 'Dibatalkan', 'Total Jarak (km)', 'Total Biaya', 'Trip Terakhir'];
    }

    public function map($row): array
    {
        return [
            $row->nama_supir,
            ($row->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal',
            (int) $row->jumlah_trip,
            (int) $row->selesai,
            (int) $row->dibatalkan,
            (float) $row->total_jarak_km,
            (float) $row->total_biaya,
            $row->trip_terakhir ? date('d/m/Y H:i', strtotime($row->trip_terakhir)) : '-',
        ];
    }
}
