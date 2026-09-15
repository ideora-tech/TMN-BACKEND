<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PerawatanUnitWorkbookExport implements WithMultipleSheets
{
    /** @param array{armada: object, items: array, sparepart: array} $data */
    public function __construct(
        private readonly array $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    private function periode(): string
    {
        if ($this->dari && $this->sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($this->dari)) . ' — ' . date('d/m/Y', strtotime($this->sampai));
        }
        return 'Semua Periode';
    }

    public function sheets(): array
    {
        $nopol = strtoupper((string) $this->data['armada']->nopol);

        return [
            new PerawatanUnitExport(collect($this->data['items']), $this->data['armada'], $this->dari, $this->sampai),
            new SparepartPerUnitSheet(collect($this->data['sparepart']), 'SPARE PART YANG DIPAKAI — ' . $nopol, $this->periode(), false),
        ];
    }
}
