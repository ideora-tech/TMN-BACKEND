<?php

declare(strict_types=1);

namespace App\Modules\Approval\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PersetujuanSayaWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Collection $menunggu,
        private readonly Collection $riwayat,
    ) {}

    public function sheets(): array
    {
        return [
            new MenungguKeputusanSheet($this->menunggu),
            new RiwayatKeputusanSheet($this->riwayat),
        ];
    }
}
