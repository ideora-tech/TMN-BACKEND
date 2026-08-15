<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class LaporanArusKasSheet implements FromCollection, WithEvents, WithTitle
{
    private array $rows = [];
    private array $barisTebal = [];
    private int $barisPertamaAngka = 0;

    public function __construct(
        private readonly array $laporan,
        private readonly string $namaPerusahaan,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {
        $this->susunBaris();
    }

    public function title(): string
    {
        return 'Laporan Arus Kas';
    }

    private function tambah(array $baris, bool $tebal = false): void
    {
        $this->rows[] = $baris;
        if ($tebal) {
            $this->barisTebal[] = count($this->rows);
        }
    }

    private function susunBaris(): void
    {
        $this->tambah([$this->namaPerusahaan], true);
        $this->tambah(['LAPORAN ARUS KAS'], true);
        $this->tambah([ArusKasExport::labelPeriode($this->dari, $this->sampai)]);
        $this->tambah(['(Metode Langsung)']);
        $this->tambah([]);
        $this->barisPertamaAngka = count($this->rows) + 1;

        foreach ($this->laporan['kelompok'] as $kelompok) {
            $this->tambah([$kelompok['judul']], true);
            foreach ($kelompok['baris'] as $baris) {
                if ((float) $baris['nominal'] === 0.0) {
                    continue;
                }
                $nominal = $baris['arah'] === 'keluar' ? -(float) $baris['nominal'] : (float) $baris['nominal'];
                $this->tambah(['    ' . $baris['label'], $nominal]);
            }
            $this->tambah([$kelompok['subtotal_label'], (float) $kelompok['subtotal']], true);
            $this->tambah([]);
        }

        $this->tambah(['KENAIKAN/(PENURUNAN) KAS BERSIH', (float) $this->laporan['kenaikan_bersih']], true);
        $this->tambah(['SALDO KAS AWAL PERIODE', (float) $this->laporan['saldo_awal']], true);
        $this->tambah(['SALDO KAS AKHIR PERIODE', (float) $this->laporan['saldo_akhir']], true);
    }

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet   = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();

                foreach ([1, 2, 3, 4] as $r) {
                    $sheet->mergeCells("A{$r}:B{$r}");
                    $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $sheet->getStyle('A2')->getFont()->setSize(14);

                foreach ($this->barisTebal as $r) {
                    $sheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
                }

                $sheet->getStyle("B{$this->barisPertamaAngka}:B{$lastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0;(#,##0)');

                $sheet->getColumnDimension('A')->setWidth(45);
                $sheet->getColumnDimension('B')->setWidth(20);
            },
        ];
    }
}
