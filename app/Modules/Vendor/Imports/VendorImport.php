<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Marker import class — dipakai lewat Excel::toArray() di VendorService::import()
 * agar baris pertama file diperlakukan sebagai heading dan setiap baris data
 * dikembalikan sebagai array asosiatif.
 */
class VendorImport implements WithHeadingRow
{
}
