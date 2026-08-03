<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class JadwalShiftTemplateExport implements FromArray, ShouldAutoSize
{
    /**
     * @param array<int, object> $supirList
     * @param array<int, string> $tanggalList
     */
    public function __construct(
        private readonly array $supirList,
        private readonly array $tanggalList,
    ) {}

    public function array(): array
    {
        $header = array_merge(['No SIM', 'Nama Supir', 'Shift'], $this->tanggalList);

        $rows = array_map(
            fn ($s) => array_merge(
                [$s->no_sim, $s->nama, ''],
                array_fill(0, count($this->tanggalList), '')
            ),
            $this->supirList
        );

        return array_merge([$header], $rows);
    }
}
