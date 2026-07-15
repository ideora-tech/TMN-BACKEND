<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class KaryawanExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly Collection $data) {}

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['NIK', 'Nama Karyawan', 'Email', 'Telepon', 'Jenis Kelamin', 'Status Kepegawaian', 'Tanggal Masuk', 'Aktif'];
    }

    public function map($row): array
    {
        return [
            $row->nik ?? '',
            $row->nama_karyawan ?? '',
            $row->email ?? '-',
            $row->telepon ?? '-',
            $row->jenis_kelamin ?? '-',
            $row->status_kepegawaian ?? '',
            $row->tanggal_masuk ? date('d/m/Y', strtotime($row->tanggal_masuk)) : '-',
            ((bool) $row->aktif) ? 'Ya' : 'Tidak',
        ];
    }
}
