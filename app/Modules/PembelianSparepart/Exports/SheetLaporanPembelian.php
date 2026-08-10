<?php

declare(strict_types=1);

namespace App\Modules\PembelianSparepart\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class SheetLaporanPembelian implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly string $namaSheet,
        private readonly string $judul,
        private readonly string $subjudul,
        private readonly array $headings,
        private readonly Collection $rows,
    ) {}

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
}
