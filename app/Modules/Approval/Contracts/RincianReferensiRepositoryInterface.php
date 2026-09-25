<?php

declare(strict_types=1);

namespace App\Modules\Approval\Contracts;

interface RincianReferensiRepositoryInterface
{
    public function rincian(string $kode, string $idReferensi, string $idPerusahaan): ?array;
}
