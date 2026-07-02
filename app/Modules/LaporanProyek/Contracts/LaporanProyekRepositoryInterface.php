<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Contracts;

use App\Modules\LaporanProyek\LaporanProyekModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface LaporanProyekRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?LaporanProyekModel;
    public function findByProyek(string $idProyek): ?LaporanProyekModel;
    public function existsByProyek(string $idProyek): bool;
    public function create(array $data): LaporanProyekModel;
    public function update(LaporanProyekModel $model, array $data): LaporanProyekModel;
}
