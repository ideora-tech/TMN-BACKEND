<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KonsolidasiKlienExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly string $namaKlien,
        private readonly string $periode,
        private readonly Collection $trips,
    ) {}

    public function judulLaporan(): string
    {
        return 'KONSOLIDASI KLIEN ' . mb_strtoupper($this->namaKlien);
    }

    public function subjudulLaporan(): string
    {
        return $this->periode;
    }

    public function headings(): array
    {
        return ['No', 'Tanggal', 'Proyek', 'Rute', 'Asal', 'Tujuan', 'Nopol', 'Supir', 'Sumber', 'Jarak (km)', 'Tarif (Rp)', 'Biaya Tambahan (Rp)', 'Total (Rp)', 'Status Tagihan'];
    }

    public function array(): array
    {
        $rows = $this->trips->values()->map(fn ($t, $i) => [
            $i + 1,
            $t['tanggal'],
            trim(($t['kode_proyek'] ?? '') . ' ' . ($t['nama_proyek'] ?? '')) ?: '-',
            $t['rute'] ?? '-',
            $t['asal'] ?? '-',
            !empty($t['titik_drop']) ? implode(' → ', $t['titik_drop']) : ($t['tujuan'] ?? '-'),
            $t['nopol'] ?? '-',
            $t['supir_nama'] ?? '-',
            ($t['sumber'] ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal',
            $t['jarak_tempuh_km'] ?? 0,
            $t['tarif']['harga'] ?? 0,
            $t['biaya_tambahan'] ?? 0,
            ($t['tarif']['harga'] ?? 0) + ($t['biaya_tambahan'] ?? 0),
            $t['sudah_difakturkan'] ? 'Sudah difakturkan' : 'Belum',
        ])->all();

        $rows[] = [
            '',
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            $this->trips->count() . ' rit',
            '',
            $this->trips->sum(fn ($t) => $t['jarak_tempuh_km'] ?? 0),
            $this->trips->sum(fn ($t) => $t['tarif']['harga'] ?? 0),
            $this->trips->sum(fn ($t) => $t['biaya_tambahan'] ?? 0),
            $this->trips->sum(fn ($t) => ($t['tarif']['harga'] ?? 0) + ($t['biaya_tambahan'] ?? 0)),
            '',
        ];

        return $rows;
    }
}
