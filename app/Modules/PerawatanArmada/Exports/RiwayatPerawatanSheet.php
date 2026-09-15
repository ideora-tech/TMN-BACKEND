<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class RiwayatPerawatanSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $subjudul,
    ) {}

    public function title(): string
    {
        return 'Riwayat Perawatan';
    }

    public function judulLaporan(): string
    {
        return 'RIWAYAT PERAWATAN SELURUH UNIT';
    }

    public function subjudulLaporan(): string
    {
        return $this->subjudul;
    }

    public function headings(): array
    {
        return ['Armada', 'Merk', 'Tanggal', 'Jenis Perawatan', 'Status', 'KM Odometer', 'Bengkel / Supplier', 'Biaya Jasa', 'Biaya Sparepart', 'Total'];
    }

    public function collection(): Collection
    {
        $data = $this->rows->map(fn (array $r) => [
            $r['nopol'],
            $r['merk'] ?? '',
            date('d/m/Y', strtotime((string) $r['tanggal'])),
            $r['jenis_perawatan'] ?? '',
            str_replace('_', ' ', (string) $r['status']),
            $r['km_odometer'] ?? '',
            $r['nama_supplier'] ?? '',
            (float) $r['biaya_jasa'],
            (float) $r['biaya_sparepart'],
            (float) $r['total_biaya'],
        ]);

        $data->push([
            'TOTAL', '', '', '', '', '', '',
            $this->rows->sum(fn (array $r) => (float) $r['biaya_jasa']),
            $this->rows->sum(fn (array $r) => (float) $r['biaya_sparepart']),
            $this->rows->sum(fn (array $r) => (float) $r['total_biaya']),
        ]);

        return $data;
    }
}
