<?php

declare(strict_types=1);

namespace App\Support\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class SheetTabelTautan implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithEvents, WithCustomValueBinder, WithStrictNullComparison
{
    use DenganGayaLaporan {
        registerEvents as private registerEventsGaya;
    }

    public const BATAS_BARIS = 5000;

    private const BARIS_DATA_PERTAMA = 4;
    private const WARNA_TAUTAN       = '1D4ED8';

    /**
     * @param list<string> $headings
     * @param Collection<int, array<int, mixed>> $rows
     * @param list<int> $kolomRupiah
     * @param array<int, array<int, string|null>> $tautan
     */
    public function __construct(
        private readonly string $namaSheet,
        private readonly string $judul,
        private readonly string $subjudul,
        private readonly array $headings,
        private readonly Collection $rows,
        private readonly array $kolomRupiah = [],
        private readonly array $tautan = [],
    ) {}

    public static function pastikanBisaDiexport(int $jumlahBaris): void
    {
        if ($jumlahBaris > self::BATAS_BARIS) {
            abort(422, 'Data terlalu banyak untuk satu file Excel (' . number_format($jumlahBaris, 0, ',', '.')
                . ' baris, maksimal ' . number_format(self::BATAS_BARIS, 0, ',', '.') . ') — persempit periode atau filternya');
        }
    }

    public function title(): string
    {
        return $this->namaSheet;
    }

    public function judulLaporan(): string
    {
        return $this->judul;
    }

    public function subjudulLaporan(): string
    {
        return $this->subjudul;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder())->bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        $gaya = $this->registerEventsGaya()[AfterSheet::class];

        return [
            AfterSheet::class => function (AfterSheet $event) use ($gaya) {
                $gaya($event);

                $sheet      = $event->sheet->getDelegate();
                $barisAkhir = max(self::BARIS_DATA_PERTAMA, $sheet->getHighestRow());

                foreach ($this->kolomRupiah as $kolom) {
                    $huruf = Coordinate::stringFromColumnIndex($kolom);
                    $sheet->getStyle($huruf . self::BARIS_DATA_PERTAMA . ':' . $huruf . $barisAkhir)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                }

                foreach ($this->tautan as $kolom => $daftar) {
                    $huruf   = Coordinate::stringFromColumnIndex($kolom);
                    $terisi  = false;

                    foreach ($daftar as $indeks => $url) {
                        if (!is_string($url) || preg_match('#^https?://#i', $url) !== 1) {
                            continue;
                        }
                        $sheet->getCell($huruf . (self::BARIS_DATA_PERTAMA + $indeks))->getHyperlink()->setUrl($url);
                        $terisi = true;
                    }

                    if ($terisi) {
                        $sheet->getStyle($huruf . self::BARIS_DATA_PERTAMA . ':' . $huruf . $barisAkhir)
                            ->getFont()
                            ->setUnderline(true)
                            ->getColor()
                            ->setRGB(self::WARNA_TAUTAN);
                    }
                }
            },
        ];
    }
}
