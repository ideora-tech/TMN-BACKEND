<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip\Contracts;

interface PenagihanTripRepositoryInterface
{
    public function proyekInfo(string $idProyek, string $idPerusahaan): ?object;
    public function tripSiapTagih(string $idPerusahaan, string $idProyek, ?string $dari, ?string $sampai, bool $lock = false): array;
    public function insertFakturTrip(string $idFaktur, string $idTrip): void;
}
