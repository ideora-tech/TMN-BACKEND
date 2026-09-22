<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor\Contracts;

interface KetersediaanVendorRepositoryInterface
{
    /** @return object[] */
    public function listUnit(string $idPerusahaan, string $hariIni): array;

    public function findUnit(string $sumber, string $idUnit, string $idPerusahaan, string $hariIni): ?object;

    /** @return object[] */
    public function riwayatProyek(string $sumber, string $idUnit, string $idPerusahaan, string $hariIni): array;
}
