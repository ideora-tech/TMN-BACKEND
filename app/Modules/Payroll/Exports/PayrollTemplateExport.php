<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Template import gaji — header & urutan kolom mengikuti lembar "GAJI DRIVER"
 * sehingga file hasil unduhan bisa langsung diupload balik ke endpoint import.
 */
class PayrollTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'NO', 'NAMA', 'PROJECT', 'TYPE TRUCK', 'JABATAN', 'STATUS', 'BANK', 'NO REKENING',
            'Absen Masuk', 'GAJI POKOK', 'UANG MAKAN', 'TUNJANGAN', 'JUMLAH GAJI', 'GAJI PRORATE',
            'UANG MAKAN MINGGUAN', 'KASBON', 'UJ TERPAKAI', 'TILANGAN', 'TOTAL GAJI', 'KETERANGAN', 'CATATAN',
        ];
    }
}
