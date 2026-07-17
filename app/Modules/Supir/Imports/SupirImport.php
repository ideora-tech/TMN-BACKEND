<?php

declare(strict_types=1);

namespace App\Modules\Supir\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Marker import class — dipakai lewat Excel::toArray() di SupirService::import()
 * agar baris pertama file diperlakukan sebagai heading (nama, no_sim, jenis_sim,
 * tgl_kadaluarsa_sim, telepon, status, nopol_armada_default) dan setiap baris data
 * dikembalikan sebagai array asosiatif.
 */
class SupirImport implements WithHeadingRow
{
}
