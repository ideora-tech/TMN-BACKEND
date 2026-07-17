<?php

declare(strict_types=1);

namespace App\Modules\Armada\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Marker import class — dipakai lewat Excel::toArray() di ArmadaService::import()
 * agar baris pertama file diperlakukan sebagai heading (nopol, merk, model, tahun, status)
 * dan setiap baris data dikembalikan sebagai array asosiatif.
 */
class ArmadaImport implements WithHeadingRow
{
}
