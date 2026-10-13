<?php

declare(strict_types=1);

namespace App\Support\Impor;

use App\Support\Exports\PengikatNilaiAman;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LembarPetunjukImpor implements FromArray, WithTitle, WithEvents, WithColumnWidths, WithCustomValueBinder
{
    public function __construct(
        private readonly array $kolom,
        private readonly array $catatan,
    ) {}

    public function title(): string
    {
        return 'Petunjuk';
    }

    public function array(): array
    {
        $baris = [];
        foreach ($this->catatan as $catatan) {
            $baris[] = [$catatan];
        }
        $baris[] = [''];
        $baris[] = ['Kolom', 'Wajib', 'Keterangan', 'Contoh'];

        foreach ($this->kolom as $kolom) {
            $baris[] = [$kolom['kunci'], $kolom['wajib'] ? 'Ya' : 'Tidak', $kolom['keterangan'], $kolom['contoh']];
        }

        return $baris;
    }

    public function columnWidths(): array
    {
        return ['A' => 30, 'B' => 10, 'C' => 78, 'D' => 30];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new PengikatNilaiAman())->bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet  = $event->sheet->getDelegate();
                $header = count($this->catatan) + 2;

                $sheet->getStyle("A{$header}:D{$header}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
                ]);
                $sheet->freezePane('A' . ($header + 1));
            },
        ];
    }
}
