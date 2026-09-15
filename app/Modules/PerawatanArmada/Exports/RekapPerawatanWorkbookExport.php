<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RekapPerawatanWorkbookExport implements WithMultipleSheets
{
    /** @param array{rekap: array, riwayat: array, sparepart: array} $data */
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
        $periode = $this->periode();

        return [
            new RekapPerawatanUnitExport(collect($this->data['rekap']), $this->dari, $this->sampai),
            new RiwayatPerawatanSheet(collect($this->data['riwayat']), $periode),
            new SparepartPerUnitSheet(collect($this->data['sparepart']), 'SPARE PART YANG DIPAKAI PER UNIT', $periode, true),
        ];
    }
}
