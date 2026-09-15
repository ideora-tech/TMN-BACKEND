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

class SparepartPerUnitSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    private const LABEL_SUMBER = [
        'bengkel'      => 'Pembelian Langsung',
        'stok_sendiri' => 'Stok Sendiri',
        'campuran'     => 'Campuran',
    ];

    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $judul,
        private readonly string $subjudul,
        private readonly bool $denganArmada = true,
    ) {}

    public function title(): string
    {
        return $this->denganArmada ? 'Spare Part per Unit' : 'Spare Part';
    }

    public function judulLaporan(): string
    {
        return $this->judul;
    }

    public function subjudulLaporan(): string
    {
        return $this->subjudul;
    }

    public function headings(): array
    {
        $kolom = ['Kode', 'Nama Sparepart', 'Satuan', 'Sumber', 'Total Qty', 'Harga Rata-rata', 'Total Biaya', 'Jumlah Perawatan', 'Terakhir Dipakai'];

        return $this->denganArmada ? array_merge(['Armada', 'Merk'], $kolom) : $kolom;
    }

    public function collection(): Collection
    {
        $data = $this->rows->map(function (array $r) {
            $baris = [
                $r['kode_sparepart'] ?? '',
                $r['nama_sparepart'],
                $r['satuan'] ?? '',
                self::LABEL_SUMBER[$r['sumber']] ?? $r['sumber'],
                (int) $r['total_qty'],
                (float) $r['harga_rata'],
                (float) $r['total_biaya'],
                (int) $r['jumlah_perawatan'],
                $r['terakhir_dipakai'] ? date('d/m/Y', strtotime((string) $r['terakhir_dipakai'])) : '',
            ];

            return $this->denganArmada ? array_merge([$r['nopol'], $r['merk'] ?? ''], $baris) : $baris;
        });

        $inti = [
            $this->rows->sum(fn (array $r) => (int) $r['total_qty']),
            '',
            $this->rows->sum(fn (array $r) => (float) $r['total_biaya']),
            '', '',
        ];
        $awal = $this->denganArmada ? ['TOTAL', '', '', '', '', ''] : ['TOTAL', '', '', ''];
        $data->push(array_merge($awal, $inti));

        return $data;
    }
}
