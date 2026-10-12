<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Exports;

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
use PhpOffice\PhpSpreadsheet\Style\Fill;

class RekapInvoiceBulananExport implements FromArray, WithTitle, WithEvents, WithColumnWidths, WithCustomValueBinder, WithStrictNullComparison
{
    private const KOLOM_TEKS     = ['A', 'B', 'C', 'D', 'E'];
    private const KOLOM_NOMINAL  = 'F';
    private const KOLOM_TERAKHIR = 'J';
    private const BARIS_HEADER   = 3;
    private const FORMAT_RUPIAH  = '_("Rp"* #,##0_);_("Rp"* (#,##0);_("Rp"* "-"_);_(@_)';

    public function __construct(private readonly array $rekap) {}

    public function title(): string
    {
        return mb_substr('Piutang ' . $this->namaBulan(false), 0, 31);
    }

    public function namaFile(): string
    {
        return 'piutang-' . $this->rekap['bulan'] . '.xlsx';
    }

    public function array(): array
    {
        $baris = [
            ['PIUTANG BULAN ' . $this->namaBulan(true)],
            [''],
            [
                'TANGGAL', 'NO FAKTUR PAJAK', 'NO INVOICE', 'NAMA PERUSAHAAN', 'PROJECT',
                'DPP', 'PPN', 'TOTAL', 'PAJAK PPH ' . $this->persenPph() . '%', 'SUB TOTAL',
            ],
        ];

        foreach ($this->rekap['baris'] as $satu) {
            $baris[] = [
                mb_strtoupper(Carbon::parse($satu['tanggal'])->locale('id')->translatedFormat('d F Y')),
                (string) ($satu['nomor_faktur_pajak'] ?? ''),
                (string) $satu['nomor_faktur'],
                mb_strtoupper((string) ($satu['nama_klien'] ?? '')),
                mb_strtoupper((string) ($satu['nama_proyek'] ?? '')),
                (float) $satu['dpp'],
                (float) $satu['ppn'],
                (float) $satu['total'],
                (float) $satu['pph'],
                (float) $satu['bersih'],
            ];
        }

        $jumlah  = $this->rekap['jumlah'];
        $baris[] = [
            'TOTAL', '', '', '', '',
            (float) $jumlah['dpp'], (float) $jumlah['ppn'], (float) $jumlah['total'], (float) $jumlah['pph'], (float) $jumlah['bersih'],
        ];

        return $baris;
    }

    public function columnWidths(): array
    {
        return ['A' => 20, 'B' => 24, 'C' => 28, 'D' => 42, 'E' => 36, 'F' => 20, 'G' => 18, 'H' => 20, 'I' => 18, 'J' => 20];
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
                $akhir      = self::KOLOM_TERAKHIR;
                $header     = self::BARIS_HEADER;
                $barisTotal = $header + count($this->rekap['baris']) + 1;

                $sheet->mergeCells("A1:{$akhir}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle("A{$header}:{$akhir}{$header}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EBC7EB']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);
                $sheet->getRowDimension($header)->setRowHeight(30);

                $sheet->getStyle("A{$header}:{$akhir}{$barisTotal}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                ]);
                $sheet->getStyle('A' . ($header + 1) . ":E{$barisTotal}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle(self::KOLOM_NOMINAL . ($header + 1) . ":{$akhir}{$barisTotal}")
                    ->getNumberFormat()->setFormatCode(self::FORMAT_RUPIAH);

                $sheet->mergeCells("A{$barisTotal}:E{$barisTotal}");
                $sheet->getStyle("A{$barisTotal}:{$akhir}{$barisTotal}")->getFont()->setBold(true);
            },
        ];
    }

    private function namaBulan(bool $kapital): string
    {
        $nama = Carbon::createFromFormat('Y-m-d', $this->rekap['bulan'] . '-01')->locale('id')->translatedFormat('F Y');

        return $kapital ? mb_strtoupper($nama) : $nama;
    }

    private function persenPph(): string
    {
        return str_replace('.', ',', (string) (float) $this->rekap['persen_pph']);
    }
}
