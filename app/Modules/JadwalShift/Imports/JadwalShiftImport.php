<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Imports;

/**
 * Marker import class — dipakai lewat Excel::toArray() di
 * JadwalShiftService::importMatriks(). Tanpa WithHeadingRow karena format
 * matriks di-parse manual (baris pertama = header berisi kolom tanggal dinamis).
 */
class JadwalShiftImport
{
}
