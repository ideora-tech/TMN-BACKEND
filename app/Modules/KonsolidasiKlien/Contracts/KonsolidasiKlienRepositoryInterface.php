<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien\Contracts;

interface KonsolidasiKlienRepositoryInterface
{
    public function klienInfo(string $idKlien, string $idPerusahaan): ?object;
    public function tripKlien(string $idPerusahaan, string $idKlien, ?string $dari, ?string $sampai, ?string $sumber = null): array;
}
