<?php

declare(strict_types=1);

namespace App\Support\Impor;

use App\Support\Exports\PengikatNilaiAman;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LembarReferensiImpor implements FromArray, WithTitle, WithEvents, WithCustomValueBinder
{
    public function __construct(private readonly array $blok) {}

    public function title(): string
    {
        return 'Referensi';
    }

    public function array(): array
    {
        $jumlah = 0;
        foreach ($this->blok as $satu) {
            $jumlah = max($jumlah, count($satu['baris']));
        }

        $hasil = [];
        for ($i = -1; $i < $jumlah; $i++) {
            $baris = [];
            foreach ($this->blok as $n => $satu) {
                if ($n > 0) {
                    $baris[] = '';
                }
                $lebar = count($satu['judul']);
                $isi   = $i === -1 ? $satu['judul'] : ($satu['baris'][$i] ?? []);
                for ($k = 0; $k < $lebar; $k++) {
                    $baris[] = (string) ($isi[$k] ?? '');
                }
            }
            $hasil[] = $baris;
        }

        return $hasil;
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
                $sheet = $event->sheet->getDelegate();
                $awal  = 1;

                foreach ($this->blok as $satu) {
                    $lebar = count($satu['judul']);
                    $dari  = Coordinate::stringFromColumnIndex($awal);
                    $ke    = Coordinate::stringFromColumnIndex($awal + $lebar - 1);

                    $sheet->getStyle("{$dari}1:{$ke}1")->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
                    ]);
                    for ($k = 0; $k < $lebar; $k++) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($awal + $k))->setWidth($k === 0 ? 20 : 34);
                    }
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($awal + $lebar))->setWidth(4);

                    $awal += $lebar + 1;
                }
                $sheet->freezePane('A2');
            },
        ];
    }
}
