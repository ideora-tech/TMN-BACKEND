<?php

declare(strict_types=1);

namespace App\Support\Impor;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class BukuTemplateImpor implements WithMultipleSheets
{
    public function __construct(
        private readonly string $judulSheet,
        private readonly array $kolom,
        private readonly array $catatanKhusus = [],
        private readonly array $referensi = [],
    ) {}

    public function sheets(): array
    {
        $catatan = array_merge([
            "Isi data mulai baris 2 di sheet \"{$this->judulSheet}\". Jangan mengubah nama kolom di baris 1.",
            'Kolom berjudul merah wajib diisi. Kolom lain boleh dikosongkan.',
        ], $this->catatanKhusus, [
            'Baris yang gagal dilaporkan beserta alasannya; baris lain tetap masuk.',
        ]);

        $lembar = [
            new LembarDataImpor($this->judulSheet, $this->kolom),
            new LembarPetunjukImpor($this->kolom, $catatan),
        ];
        if ($this->referensi !== []) {
            $lembar[] = new LembarReferensiImpor($this->referensi);
        }

        return $lembar;
    }
}
