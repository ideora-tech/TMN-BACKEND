<?php

declare(strict_types=1);

namespace App\Support\Impor;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class LembarImporBerjudul implements WithHeadingRow, WithCalculatedFormulas
{
}
