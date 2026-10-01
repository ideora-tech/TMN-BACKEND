<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KonsolidasiKlienWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly string $namaKlien,
        private readonly string $periode,
        private readonly Collection $trips,
    ) {}

    public function sheets(): array
    {
        return [
            new KonsolidasiKlienExport($this->namaKlien, $this->periode, $this->trips),
            new KonsolidasiSuratJalanSheet($this->namaKlien, $this->periode, $this->trips),
        ];
    }
}
