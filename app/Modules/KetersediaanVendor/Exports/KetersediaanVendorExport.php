<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class KetersediaanVendorExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    private const LABEL_STATUS = [
        'tersedia'  => 'Tersedia',
        'terjadwal' => 'Terjadwal',
        'dipakai'   => 'Sedang Dipakai',
        'perawatan' => 'Dalam Perawatan',
    ];

    private const LABEL_SUMBER = [
        'aset'   => 'Aset Milik',
        'vendor' => 'Vendor',
    ];

    private int $nomor = 0;

    /** @param array<int, array<string, mixed>> $unit */
    public function __construct(
        private readonly array $unit,
        private readonly string $tanggal,
        private readonly ?string $labelStatus = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'KETERSEDIAAN UNIT ASET DAN VENDOR';
    }

    public function subjudulLaporan(): string
    {
        $teks = 'Per ' . $this->format($this->tanggal);

        return $this->labelStatus ? $teks . ' — Status: ' . $this->labelStatus : $teks;
    }

    public function collection(): Collection
    {
        return collect($this->unit);
    }

    public function headings(): array
    {
        return [
            'No', 'Nopol', 'Merk / Model', 'Jenis Kendaraan', 'Kapasitas', 'Tahun', 'Sumber', 'Pemilik / Vendor', 'Telepon Vendor',
            'Status', 'Proyek Terakhir', 'Klien Terakhir', 'Terakhir Dipakai', 'Jumlah Proyek', 'Hari Dipakai',
            'Jadwal / Perkiraan Bebas', 'STNK s/d', 'KIR s/d',
        ];
    }

    public function map($row): array
    {
        return [
            ++$this->nomor,
            $row['nopol'],
            trim(($row['merk'] ?? '') . ' ' . ($row['model'] ?? '')),
            $row['nama_jenis_kendaraan'] ?? '',
            $row['kapasitas'] ?? '',
            $row['tahun'] ?? '',
            self::LABEL_SUMBER[$row['sumber']] ?? $row['sumber'],
            $row['sumber'] === 'aset' ? 'Aset Milik' : ($row['nama_vendor'] ?? ''),
            $row['telepon_vendor'] ?? '',
            self::LABEL_STATUS[$row['status_ketersediaan']] ?? $row['status_ketersediaan'],
            $row['proyek_terakhir']['nama_proyek'] ?? '',
            $row['proyek_terakhir']['nama_klien'] ?? '',
            $this->format($row['terakhir_dipakai']),
            $row['jumlah_proyek'],
            $row['hari_pakai'],
            $this->keteranganJadwal($row),
            $this->format($row['masa_berlaku_stnk']),
            $this->format($row['masa_berlaku_kir']),
        ];
    }

    private function keteranganJadwal(array $row): string
    {
        $servis = $row['perawatan_berikutnya'] ? 'servis ' . $this->format($row['perawatan_berikutnya']) : '';

        if ($row['status_ketersediaan'] === 'perawatan') {
            return 'Dalam perawatan';
        }

        if ($row['status_ketersediaan'] === 'tersedia') {
            return trim('Kosong, belum ada jadwal' . ($servis !== '' ? ', ' . $servis : ''));
        }

        $bebas = $row['perkiraan_bebas'] ? 'bebas ' . $this->format($row['perkiraan_bebas']) : '';

        if ($row['status_ketersediaan'] === 'terjadwal') {
            $mulai = 'dipakai mulai ' . $this->format($row['jadwal_berikutnya']);

            return trim($mulai . ($bebas !== '' ? ', ' . $bebas : ''));
        }

        return $bebas !== '' ? 'Sedang dipakai, ' . $bebas : 'Sedang dipakai';
    }

    private function format(?string $tanggal): string
    {
        return $tanggal ? date('d/m/Y', strtotime($tanggal)) : '';
    }
}
