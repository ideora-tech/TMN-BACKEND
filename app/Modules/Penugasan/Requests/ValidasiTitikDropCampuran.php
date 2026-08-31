<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Requests;

use Closure;

/**
 * Endpoint dasar (Store/Update Penugasan) menerima titik_drop dalam 2 bentuk
 * sekaligus untuk backward compat: string polos (caller lama, mis.
 * PenugasanVendorTab) atau objek {lokasi, uang_jalan_tambahan} (dialog
 * Penugasan Harian). syncTitikDrop() di repository menormalkan keduanya.
 */
trait ValidasiTitikDropCampuran
{
    protected function aturanTitikDropCampuran(): array
    {
        return [static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value)) {
                if (trim($value) === '' || mb_strlen($value) > 200) {
                    $fail('Titik drop tidak valid (maks 200 karakter).');
                }
                return;
            }

            if (is_array($value)) {
                $lokasi = $value['lokasi'] ?? null;
                if (!is_string($lokasi) || trim($lokasi) === '' || mb_strlen($lokasi) > 200) {
                    $fail('Lokasi titik drop wajib diisi (maks 200 karakter).');
                    return;
                }
                $nominal = $value['uang_jalan_tambahan'] ?? null;
                if ($nominal !== null && (!is_numeric($nominal) || (float) $nominal < 0)) {
                    $fail('Uang jalan tambahan tidak valid.');
                }
                return;
            }

            $fail('Titik drop tidak valid.');
        }];
    }
}
