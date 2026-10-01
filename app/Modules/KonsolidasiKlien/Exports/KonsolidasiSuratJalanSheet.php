<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KonsolidasiSuratJalanSheet implements FromArray, WithHeadings, ShouldAutoSize, WithEvents, WithTitle
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly string $namaKlien,
        private readonly string $periode,
        private readonly Collection $trips,
    ) {}

    public function title(): string
    {
        return 'Surat Jalan';
    }

    public function judulLaporan(): string
    {
        return 'DAFTAR SURAT JALAN ' . mb_strtoupper($this->namaKlien);
    }

    public function subjudulLaporan(): string
    {
        return $this->periode;
    }

    public function headings(): array
    {
        return ['No', 'Tanggal', 'Nama Project', 'Nopol', 'Nama Driver', 'Rute', 'Titik Drop', 'No Surat Jalan'];
    }

    public function array(): array
    {
        $rows  = [];
        $nomor = 0;

        foreach ($this->trips->values() as $t) {
            $infoTrip = [
                $t['tanggal'],
                trim(($t['kode_proyek'] ?? '') . ' ' . ($t['nama_proyek'] ?? '')) ?: '-',
                $t['nopol'] ?? '-',
                $t['supir_nama'] ?? '-',
                $t['rute'] ?? '-',
            ];

            $daftar = $t['surat_jalan'] ?? [];
            if ($daftar === []) {
                $rows[] = array_merge([++$nomor], $infoTrip, ['-', $t['no_surat_jalan'] ?? '-']);
                continue;
            }

            foreach ($daftar as $sj) {
                $drop = $sj['lokasi_drop'] !== null
                    ? trim('Drop ' . ($sj['urutan_drop'] ?? '') . ': ' . $sj['lokasi_drop'])
                    : '-';
                $rows[] = array_merge([++$nomor], $infoTrip, [$drop, $sj['no_surat_jalan']]);
            }
        }

        return $rows;
    }
}
