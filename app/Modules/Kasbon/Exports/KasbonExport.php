<?php

declare(strict_types=1);

namespace App\Modules\Kasbon\Exports;

use App\Modules\Kasbon\KasbonService;
use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class KasbonExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public const LABEL_STATUS = [
        KasbonService::STATUS_MENUNGGU_APPROVAL  => 'Menunggu Approval',
        KasbonService::STATUS_MENUNGGU_PENCAIRAN => 'Menunggu Pencairan',
        KasbonService::STATUS_DITOLAK            => 'Ditolak',
        KasbonService::STATUS_BERJALAN           => 'Berjalan',
        KasbonService::STATUS_LUNAS              => 'Lunas',
    ];

    private int $nomor = 0;

    /** @param list<object> $kasbon */
    public function __construct(
        private readonly array $kasbon,
        private readonly ?string $status = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'DAFTAR KASBON KARYAWAN';
    }

    public function subjudulLaporan(): string
    {
        $teks = 'Per ' . now()->format('d/m/Y');

        return isset(self::LABEL_STATUS[$this->status]) ? $teks . ' — Status: ' . self::LABEL_STATUS[$this->status] : $teks;
    }

    public function collection(): Collection
    {
        return collect($this->kasbon);
    }

    public function headings(): array
    {
        return [
            'No', 'No. Kasbon', 'Tanggal', 'NIK', 'Karyawan', 'Jabatan', 'Keperluan', 'Jenis',
            'Nominal', 'Cicilan per Periode', 'Mulai Dipotong', 'Terbayar', 'Sisa', 'Status',
        ];
    }

    public function map($row): array
    {
        $status = KasbonService::statusDari($row);
        $cair   = in_array($status, [KasbonService::STATUS_BERJALAN, KasbonService::STATUS_LUNAS], true);

        return [
            ++$this->nomor,
            $row->nomor_kasbon,
            date('d/m/Y', strtotime((string) $row->tanggal)),
            $this->teks($row->nik ?? ''),
            $this->teks($row->nama_karyawan ?? ''),
            $this->teks($row->nama_jabatan ?? ''),
            $this->teks($row->keperluan),
            (int) $row->saldo_awal === 1 ? 'Kasbon Lama' : 'Kasbon Baru',
            (float) $row->nominal,
            (float) $row->cicilan_per_periode,
            Carbon::parse($row->mulai_potong)->locale('id')->translatedFormat('F Y'),
            $cair ? round((float) ($row->terbayar ?? 0), 2) : '',
            $cair ? KasbonService::sisaDari($row) : '',
            self::LABEL_STATUS[$status] ?? '',
        ];
    }

    private function teks(?string $nilai): string
    {
        $nilai = (string) $nilai;

        return str_starts_with($nilai, '=') ? ' ' . $nilai : $nilai;
    }
}
