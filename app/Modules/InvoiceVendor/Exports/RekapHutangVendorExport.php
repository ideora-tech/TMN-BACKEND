<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Exports;

use App\Support\Exports\PengikatNilaiAman;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class RekapHutangVendorExport implements FromArray, WithTitle, WithEvents, WithColumnWidths, WithCustomValueBinder, WithStrictNullComparison
{
    private const KOLOM_TEKS    = ['B', 'C', 'D', 'E'];
    private const KOLOM_NOMINAL = 'F';
    private const BARIS_HEADER  = 3;
    private const FORMAT_RUPIAH = '_("Rp"* #,##0_);_("Rp"* (#,##0);_("Rp"* "-"_);_(@_)';

    private readonly bool $adaPpn;

    public function __construct(private readonly array $rekap)
    {
        $this->adaPpn = (float) $rekap['jumlah']['ppn'] > 0;
    }

    public function title(): string
    {
        return mb_substr('Hutang ' . $this->namaBulan(), 0, 31);
    }

    public function namaFile(): string
    {
        return 'hutang-pph-vendor-' . $this->rekap['bulan'] . '.xlsx';
    }

    public function array(): array
    {
        $baris = [
            ['RINCIAN HUTANG DAN PPH VENDOR'],
            ['PERIODE ' . mb_strtoupper($this->namaBulan())],
            array_merge(['NO', 'TANGGAL', 'INVOICE', 'VENDOR', 'KETERANGAN'], $this->nominal('DPP', 'PPN', 'PPH', 'TOTAL')),
        ];

        foreach ($this->rekap['baris'] as $i => $satu) {
            $baris[] = array_merge([
                $i + 1,
                Carbon::parse($satu['tanggal'])->locale('id')->translatedFormat('d F Y'),
                (string) $satu['nomor_invoice'],
                mb_strtoupper((string) ($satu['nama_vendor'] ?? '')),
                mb_strtoupper((string) ($satu['keterangan'] ?? '')),
            ], $this->nominal((float) $satu['dpp'], (float) $satu['ppn'], (float) $satu['pph'], (float) $satu['total']));
        }

        $jumlah  = $this->rekap['jumlah'];
        $baris[] = [''];
        $baris[] = array_merge(
            ['TOTAL', '', '', '', ''],
            $this->nominal((float) $jumlah['dpp'], (float) $jumlah['ppn'], (float) $jumlah['pph'], (float) $jumlah['total']),
        );

        return $baris;
    }

    public function columnWidths(): array
    {
        return ['A' => 7, 'B' => 22, 'C' => 28, 'D' => 38, 'E' => 34, 'F' => 20, 'G' => 20, 'H' => 20, 'I' => 20];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && in_array($cell->getColumn(), self::KOLOM_TEKS, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new PengikatNilaiAman())->bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet      = $event->sheet->getDelegate();
                $akhir      = $this->adaPpn ? 'I' : 'H';
                $header     = self::BARIS_HEADER;
                $dataAkhir  = $header + count($this->rekap['baris']);
                $barisTotal = $dataAkhir + 2;

                foreach ([1, 2] as $nomor) {
                    $sheet->mergeCells("A{$nomor}:{$akhir}{$nomor}");
                    $sheet->getStyle("A{$nomor}")->getFont()->setBold(true);
                    $sheet->getStyle("A{$nomor}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $sheet->getStyle('A1')->getFont()->setSize(14);

                $sheet->getStyle("A{$header}:{$akhir}{$header}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension($header)->setRowHeight(28);
                $sheet->setAutoFilter("A{$header}:{$akhir}" . max($header, $dataAkhir));

                $sheet->getStyle("A{$header}:{$akhir}{$barisTotal}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                ]);
                $sheet->getStyle('A' . ($header + 1) . ":D{$barisTotal}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle(self::KOLOM_NOMINAL . ($header + 1) . ":{$akhir}{$barisTotal}")
                    ->getNumberFormat()->setFormatCode(self::FORMAT_RUPIAH);

                $sheet->mergeCells("A{$barisTotal}:E{$barisTotal}");
                $sheet->getStyle("A{$barisTotal}:{$akhir}{$barisTotal}")->getFont()->setBold(true);
            },
        ];
    }

    private function nominal(mixed $dpp, mixed $ppn, mixed $pph, mixed $total): array
    {
        return $this->adaPpn ? [$dpp, $ppn, $pph, $total] : [$dpp, $pph, $total];
    }

    private function namaBulan(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->rekap['bulan'] . '-01')->locale('id')->translatedFormat('F Y');
    }
}
