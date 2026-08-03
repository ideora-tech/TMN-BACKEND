<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RiwayatArmadaExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    private const LABEL_SUMBER = [
        'default'  => 'Default (mobil sendiri)',
        'otomatis' => 'Otomatis (pinjaman)',
        'manual'   => 'Manual (diubah ops)',
    ];

    public function __construct(
        private readonly Collection $data,
        private readonly object $armada,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'RIWAYAT PEMEGANG ARMADA — ' . strtoupper((string) $this->armada->nopol);
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
        return $this->data;
    }

    public function headings(): array
    {
        return ['Tanggal', 'Supir Pemegang', 'Proyek', 'Sumber', 'Pemilik Asal', 'Keterangan', 'Status', 'Dicatat', 'Digantikan'];
    }

    public function map($row): array
    {
        return [
            date('d/m/Y', strtotime((string) $row->tanggal)),
            $row->supir_nama ?? '',
            $row->nama_proyek ?? '-',
            self::LABEL_SUMBER[$row->sumber] ?? $row->sumber,
            $row->pemilik_nama ?? '-',
            $row->keterangan ?? '-',
            $row->dihapus_pada === null ? 'Berlaku' : 'Digantikan/dibatalkan',
            $row->dibuat_pada ? date('d/m/Y H:i', strtotime((string) $row->dibuat_pada)) : '-',
            $row->dihapus_pada ? date('d/m/Y H:i', strtotime((string) $row->dihapus_pada)) : '-',
        ];
    }
}
