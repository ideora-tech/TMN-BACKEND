<?php

declare(strict_types=1);

namespace App\Modules\Absensi\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class RekapAbsensiExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize, WithEvents, WithTitle
{
    use DenganGayaLaporan;

    private const KOLOM_ANGKA = ['hadir', 'terlambat', 'izin', 'sakit', 'cuti', 'alpha'];

    public function __construct(
        private readonly Collection $data,
        private readonly string $bulan,
    ) {}

    public function title(): string
    {
        return 'Rekap Absensi';
    }

    public function judulLaporan(): string
    {
        return 'REKAP ABSENSI BULANAN';
    }

    public function subjudulLaporan(): string
    {
        return 'Periode: ' . Carbon::parse($this->bulan . '-01')->locale('id')->translatedFormat('F Y');
    }

    public function collection(): Collection
    {
        $total = ['is_total' => true];
        foreach ([...self::KOLOM_ANGKA, 'lembur_menit', 'lembur_rupiah'] as $kolom) {
            $total[$kolom] = $this->data->sum(fn ($r) => (int) $r[$kolom]);
        }

        return $this->data->values()->push($total);
    }

    public function headings(): array
    {
        return ['NIK', 'Nama Karyawan', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Cuti', 'Alpha', 'Lembur', 'Upah Lembur (Rp)'];
    }

    public function map($row): array
    {
        $angka = array_map(fn ($kolom) => (int) $row[$kolom], self::KOLOM_ANGKA);

        return [
            isset($row['is_total']) ? '' : (string) $row['nik'],
            isset($row['is_total']) ? 'TOTAL' : (string) $row['nama'],
            ...$angka,
            $this->labelLembur((int) $row['lembur_menit']),
            (int) $row['lembur_rupiah'],
        ];
    }

    public function columnFormats(): array
    {
        return ['J' => '#,##0'];
    }

    private function labelLembur(int $menit): string
    {
        if ($menit <= 0) {
            return '-';
        }

        $jam  = intdiv($menit, 60);
        $sisa = $menit % 60;

        return trim(($jam > 0 ? "{$jam} jam " : '') . ($sisa > 0 ? "{$sisa} menit" : ''));
    }
}
