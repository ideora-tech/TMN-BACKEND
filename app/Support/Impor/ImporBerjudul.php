<?php

declare(strict_types=1);

namespace App\Support\Impor;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImporBerjudul implements WithMultipleSheets
{
    public function __construct(private readonly string $namaSheet) {}

    public function sheets(): array
    {
        return [$this->namaSheet => new LembarImporBerjudul()];
    }
}
