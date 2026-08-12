<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiVendor\Contracts;

interface KonsolidasiVendorRepositoryInterface
{
    public function vendorInfo(string $idVendor, string $idPerusahaan): ?object;
    public function tripVendor(string $idPerusahaan, string $idVendor, ?string $dari, ?string $sampai): array;
    public function kontrakVendor(string $idVendor): array;
}
