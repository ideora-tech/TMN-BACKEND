<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Marker import class — dipakai lewat Excel::toArray() di
 * SupirVendorService::import() agar baris pertama file diperlakukan sebagai
 * heading dan setiap baris data dikembalikan sebagai array asosiatif.
 */
class SupirVendorImport implements WithHeadingRow
{
}
