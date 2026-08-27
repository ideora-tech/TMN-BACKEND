<?php

declare(strict_types=1);

namespace App\Modules\Approval\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class RiwayatKeputusanSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents, WithTitle
{
    use DenganGayaLaporan;

    public function __construct(private readonly Collection $data) {}

    public function title(): string
    {
        return 'Riwayat Keputusan';
    }

    public function judulLaporan(): string
    {
        return 'PERSETUJUAN SAYA';
    }

    public function subjudulLaporan(): string
    {
        return 'Riwayat Keputusan';
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Jenis', 'Nomor', 'Keterangan', 'Pihak', 'Diajukan Oleh', 'Nominal', 'Diajukan Pada', 'Keputusan Saya', 'Catatan', 'Diputuskan Pada', 'Status Akhir'];
    }

    public function map($row): array
    {
        return [
            $row->nama_event_type ?? '-',
            $row->nomor_referensi ?? '-',
            $row->keterangan_referensi ?? '-',
            $row->pihak_referensi ?? '-',
            $row->nama_pengaju ?? '-',
            $row->nominal !== null ? (float) $row->nominal : null,
            $row->diajukan_pada ? date('d/m/Y H:i', strtotime((string) $row->diajukan_pada)) : '-',
            ucfirst((string) $row->keputusan_saya),
            $row->catatan_saya ?? '-',
            $row->diputuskan_pada ? date('d/m/Y H:i', strtotime((string) $row->diputuskan_pada)) : '-',
            ucfirst((string) $row->status_pengajuan),
        ];
    }
}
