<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class ExcelCellHelper
{
    public static function cellToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        return (string) $value;
    }

    public static function parseHarga(?string $raw): float|false|null
    {
        if ($raw === null) {
            return null;
        }

        $bersih = (string) preg_replace('/^(rp|idr)\.?/i', '', trim($raw));
        $bersih = (string) preg_replace('/,-$/', '', (string) preg_replace('/\s+/', '', $bersih));
        if ($bersih === '') {
            return null;
        }

        $adaTitik = str_contains($bersih, '.');
        $adaKoma  = str_contains($bersih, ',');
        if ($adaTitik && $adaKoma) {
            $desimal = strrpos($bersih, '.') > strrpos($bersih, ',') ? '.' : ',';
            $ribuan  = $desimal === '.' ? ',' : '.';
            $bersih  = str_replace($desimal, '.', str_replace($ribuan, '', $bersih));
        } elseif ($adaTitik && preg_match('/^\d{1,3}(\.\d{3})+$/', $bersih) === 1) {
            $bersih = str_replace('.', '', $bersih);
        } elseif ($adaKoma) {
            $bersih = preg_match('/^\d{1,3}(,\d{3})+$/', $bersih) === 1
                ? str_replace(',', '', $bersih)
                : str_replace(',', '.', $bersih);
        }
        if (!is_numeric($bersih) || (float) $bersih < 0 || (float) $bersih > 999999999999) {
            return false;
        }

        $nilai = round((float) $bersih);
        if ($nilai <= 0) {
            return null;
        }

        return $nilai >= 1000 ? $nilai : false;
    }

    public static function parseTanggal(string $raw): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            [$y, $m, $d] = array_map('intval', explode('-', $raw));

            return checkdate($m, $d, $y) ? $raw : null;
        }

        if (is_numeric($raw)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
