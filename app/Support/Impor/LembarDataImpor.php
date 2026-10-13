<?php

declare(strict_types=1);

namespace App\Support\Impor;

use App\Support\Exports\PengikatNilaiAman;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class LembarDataImpor implements FromArray, WithHeadings, WithTitle, WithEvents, WithCustomValueBinder, WithStrictNullComparison
{
    private array $indeksTeks = [];

    public function __construct(
        private readonly string $judulSheet,
        private readonly array $kolom,
        private readonly array $baris = [],
    ) {
        foreach ($kolom as $i => $satu) {
            if ($satu['teks']) {
                $this->indeksTeks[] = $i + 1;
            }
        }
    }

    public function title(): string
    {
        return $this->judulSheet;
    }

    public function headings(): array
    {
        return array_column($this->kolom, 'kunci');
    }

    public function array(): array
    {
        return $this->baris;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($value === null) {
            return (new PengikatNilaiAman())->bindValue($cell, $value);
        }

        $kolom = Coordinate::columnIndexFromString($cell->getColumn());
        if ($cell->getRow() === 1 || in_array($kolom, $this->indeksTeks, true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return (new PengikatNilaiAman())->bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $akhir = Coordinate::stringFromColumnIndex(count($this->kolom));

                $sheet->getStyle("A1:{$akhir}1")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
                ]);
                $sheet->freezePane(count($this->kolom) > 2 ? 'C2' : 'A2');

                foreach ($this->kolom as $i => $kolom) {
                    $huruf = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getColumnDimension($huruf)->setWidth(max(16, mb_strlen($kolom['kunci']) + 4));

                    if ($kolom['teks']) {
                        $sheet->getStyle("{$huruf}:{$huruf}")
                            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                    }
                    if ($kolom['wajib']) {
                        $sheet->getStyle("{$huruf}1")->getFont()->getColor()->setRGB('B91C1C');
                    }
                }
            },
        ];
    }
}
