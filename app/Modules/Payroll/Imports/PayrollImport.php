<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

/**
 * Marker import class — dipakai lewat Excel::toArray() di PayrollService::importExcel().
 * Sengaja TANPA WithHeadingRow karena header file gaji tidak berada di baris pertama;
 * seluruh baris dikembalikan mentah (indeks numerik) lalu posisi header dicari sendiri.
 * WithCalculatedFormulas wajib: kolom seperti UANG MAKAN MINGGUAN dan GAJI PRORATE
 * pada file gaji nyata berisi formula, bukan angka literal.
 */
class PayrollImport implements WithCalculatedFormulas
{
}
