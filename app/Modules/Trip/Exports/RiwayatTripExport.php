<?php

declare(strict_types=1);

namespace App\Modules\Trip\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RiwayatTripExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Collection $trips,
        private readonly Collection $rekap,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function sheets(): array
    {
        return [
            new RiwayatTripSheet($this->trips, $this->dari, $this->sampai),
            new RekapTripSupirExport($this->rekap, $this->dari, $this->sampai),
        ];
    }
}
