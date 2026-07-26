<?php

declare(strict_types=1);

namespace App\Support\Exports;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

trait DenganGayaLaporan
{
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet   = $event->sheet->getDelegate();
                $lastCol = $sheet->getHighestColumn();

                $sheet->insertNewRowBefore(1, 2);

                $sheet->setCellValue('A1', $this->judulLaporan());
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->setCellValue('A2', $this->subjudulLaporan());
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->getStyle('A2')->getFont()->setBold(true);
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $barisHeader = 3;
                $sheet->getStyle("A{$barisHeader}:{$lastCol}{$barisHeader}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'D9D9D9'],
                    ],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $lastRow = $sheet->getHighestRow();
                $sheet->getStyle("A{$barisHeader}:{$lastCol}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                    ],
                ]);
            },
        ];
    }

    abstract public function judulLaporan(): string;

    abstract public function subjudulLaporan(): string;
}
