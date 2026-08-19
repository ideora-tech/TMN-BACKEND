<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Marker import class — dipakai lewat Excel::toArray() di
 * ArmadaVendorService::import() agar baris pertama file diperlakukan sebagai
 * heading dan setiap baris data dikembalikan sebagai array asosiatif.
 */
class ArmadaVendorImport implements WithHeadingRow
{
}
