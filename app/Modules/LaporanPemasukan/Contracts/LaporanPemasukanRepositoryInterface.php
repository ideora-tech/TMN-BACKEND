<?php

declare(strict_types=1);

namespace App\Modules\LaporanPemasukan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface LaporanPemasukanRepositoryInterface
{
    public function paginate(string $idPerusahaan, string $dari, string $sampai, array $filter, int $page, int $limit): LengthAwarePaginator;

    public function semua(string $idPerusahaan, string $dari, string $sampai, array $filter): Collection;

    public function rekapKategori(string $idPerusahaan, string $dari, string $sampai, array $filter): Collection;

    public function invoiceTerbit(string $idPerusahaan, string $dari, string $sampai): object;
}
