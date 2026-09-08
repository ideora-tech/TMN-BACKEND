<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Marker import class — dipakai lewat Excel::toArray() agar baris pertama
 * file diperlakukan sebagai heading dan tiap baris data menjadi array
 * asosiatif (pola yang sama dengan ArmadaVendorImport).
 */
class PenugasanUnitImport implements WithHeadingRow
{
}
