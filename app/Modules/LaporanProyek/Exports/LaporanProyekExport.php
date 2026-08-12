<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LaporanProyekExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(private readonly Collection $data) {}

    public function judulLaporan(): string
    {
        return 'LAPORAN PROYEK';
    }

    public function subjudulLaporan(): string
    {
        return 'Dicetak: ' . now()->format('d M Y H:i');
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['No', 'Kode Proyek', 'Nama Proyek', 'Klien', 'Trip Selesai', 'Total Jarak (km)', 'Total Biaya Ops (Rp)', 'Diserahkan Oleh', 'Diserahkan Pada', 'Ringkasan'];
    }

    public function map($row): array
    {
        static $no = 0;
        $no++;

        return [
            $no,
            $row->kode_proyek ?? '-',
            $row->nama_proyek ?? '-',
            $row->nama_klien ?? '-',
            (int) ($row->total_trip ?? 0),
            (float) ($row->total_jarak_km ?? 0),
            (float) ($row->total_biaya ?? 0),
            $row->diserahkan_oleh ?? '-',
            $row->diserahkan_pada ? \Carbon\Carbon::parse($row->diserahkan_pada)->format('d/m/Y H:i') : '-',
            $row->ringkasan ?? '-',
        ];
    }
}