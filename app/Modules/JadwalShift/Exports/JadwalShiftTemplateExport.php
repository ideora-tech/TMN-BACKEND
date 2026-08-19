<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class JadwalShiftTemplateExport implements FromArray, WithColumnWidths, WithEvents
{
    /**
     * @param array<int, object> $supirList
     * @param array<int, string> $tanggalList
     * @param array<string, array{shift_nama: string, tanggal: array<string, bool>}> $jadwalPerSupir
     */
    public function __construct(
        private readonly array $supirList,
        private readonly array $tanggalList,
        private readonly string $namaProyek,
        private readonly string $periode,
        private readonly array $jadwalPerSupir = [],
    ) {}

    public function array(): array
    {
        $header = array_merge(['No SIM', 'Nama Supir', 'Shift'], $this->tanggalList);

        $rows = array_map(function ($s) {
            $jadwal = $this->jadwalPerSupir[(string) $s->id_supir] ?? null;
            $tanggalTerjadwal = $jadwal['tanggal'] ?? [];

            return array_merge(
                [$s->no_sim, $s->nama, $jadwal['shift_nama'] ?? ''],
                array_map(fn ($tgl) => ($tanggalTerjadwal[$tgl] ?? false) ? 'H' : '', $this->tanggalList)
            );
        }, $this->supirList);

        return array_merge([$header], $rows);
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 18, 'B' => 26, 'C' => 12];
        foreach (array_keys(array_values($this->tanggalList)) as $i) {
            $widths[Coordinate::stringFromColumnIndex($i + 4)] = 5;
        }

        return $widths;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet   = $event->sheet->getDelegate();
                $lastCol = $sheet->getHighestColumn();

                $sheet->insertNewRowBefore(1, 2);

                $sheet->setCellValue('A1', $this->namaProyek);
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 14],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFC000']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                $sheet->setCellValue('A2', $this->periode);
                $sheet->mergeCells("A2:{$lastCol}2");

                $barisHeader = 3;
                foreach (array_values($this->tanggalList) as $i => $tanggal) {
                    $kolom = Coordinate::stringFromColumnIndex($i + 4);
                    $sheet->setCellValue("{$kolom}{$barisHeader}", ExcelDate::PHPToExcel(new \DateTime($tanggal)));
                    $sheet->getStyle("{$kolom}{$barisHeader}")->getNumberFormat()->setFormatCode('d');
                }

                $sheet->getStyle("A{$barisHeader}:{$lastCol}{$barisHeader}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);

                $lastRow = max($sheet->getHighestRow(), $barisHeader + 1);
                $sheet->getStyle("A{$barisHeader}:{$lastCol}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                    ],
                ]);
            },
        ];
    }
}
