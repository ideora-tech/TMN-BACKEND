<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ArusKasWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $laporan,
        private readonly Collection $transaksi,
        private readonly string $namaPerusahaan,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function sheets(): array
    {
        return [
            new LaporanArusKasSheet($this->laporan, $this->namaPerusahaan, $this->dari, $this->sampai),
            new ArusKasExport($this->transaksi, $this->dari, $this->sampai),
        ];
    }
}
