<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface LaporanPengeluaranRepositoryInterface
{
    public function paginate(string $idPerusahaan, string $dari, string $sampai, array $filter, int $page, int $limit): LengthAwarePaginator;

    public function semua(string $idPerusahaan, string $dari, string $sampai, array $filter): Collection;

    public function rekapKategori(string $idPerusahaan, string $dari, string $sampai, array $filter): Collection;
}
