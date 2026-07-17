<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm\Contracts;

use App\Modules\JenisBbm\HargaBbmModel;
use App\Modules\JenisBbm\JenisBbmModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface JenisBbmRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator;
    public function findById(string $id): ?JenisBbmModel;
    public function findByIdMilik(string $id, string $idPerusahaan): ?JenisBbmModel;
    public function create(array $data): JenisBbmModel;
    public function update(JenisBbmModel $model, array $data): JenisBbmModel;
    public function delete(JenisBbmModel $model): void;
    public function hargaEfektif(string $idJenisBbm): ?float;
    public function riwayatHarga(string $idJenisBbm): array;
    public function createHarga(string $idJenisBbm, array $data): HargaBbmModel;
}
