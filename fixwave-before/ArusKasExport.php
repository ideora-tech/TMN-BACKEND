<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class ArusKasExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents, WithTitle
{
    use DenganGayaLaporan;

    private const LABEL_SUMBER = [
        'faktur'                => 'Invoice',
        'pengajuan_pengeluaran' => 'Pengajuan Pengeluaran',
        'pembayaran_vendor'     => 'Pembayaran Vendor',
        'payroll_periode'       => 'Payroll',
        'pembelian_sparepart'   => 'Pembelian Sparepart',
    ];

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function title(): string
    {
        return 'Arus Kas';
    }

    public function judulLaporan(): string
    {
        return 'REKAP ARUS KAS';
    }

    public static function labelPeriode(?string $dari, ?string $sampai): string
    {
        if ($dari && $sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($dari)) . ' — ' . date('d/m/Y', strtotime($sampai));
        }
        if ($dari) {
            return 'Periode: sejak ' . date('d/m/Y', strtotime($dari));
        }
        if ($sampai) {
            return 'Periode: s.d. ' . date('d/m/Y', strtotime($sampai));
        }
        return 'Semua Periode';
    }

    public function subjudulLaporan(): string
    {
        return self::labelPeriode($this->dari, $this->sampai);
    }

    public function collection(): Collection
    {
        $rows = $this->data;

        $pemasukan   = (float) $rows->where('arah', 'masuk')->sum(fn ($r) => (float) $r->nominal);
        $pengeluaran = (float) $rows->where('arah', 'keluar')->sum(fn ($r) => (float) $r->nominal);

        return $rows
            ->push((object) ['is_total' => 'pemasukan', 'nominal' => $pemasukan])
            ->push((object) ['is_total' => 'pengeluaran', 'nominal' => $pengeluaran])
            ->push((object) ['is_total' => 'netto', 'nominal' => $pemasukan - $pengeluaran]);
    }

    public function headings(): array
    {
        return ['Tanggal', 'Sumber', 'Referensi', 'Keterangan', 'Arah', 'Nominal'];
    }

    public function map($row): array
    {
        if (isset($row->is_total)) {
            return match ($row->is_total) {
                'pemasukan'   => ['', '', '', 'TOTAL PEMASUKAN', 'Masuk', (float) $row->nominal],
                'pengeluaran' => ['', '', '', 'TOTAL PENGELUARAN', 'Keluar', (float) $row->nominal],
                default       => ['', '', '', 'NETTO', '-', (float) $row->nominal],
            };
        }

        return [
            date('d/m/Y', strtotime($row->tanggal)),
            self::LABEL_SUMBER[$row->sumber] ?? ucfirst(str_replace('_', ' ', (string) $row->sumber)),
            $row->label ?? '-',
            $row->keterangan ?? '-',
            $row->arah === 'masuk' ? 'Masuk' : 'Keluar',
            (float) $row->nominal,
        ];
    }
}
