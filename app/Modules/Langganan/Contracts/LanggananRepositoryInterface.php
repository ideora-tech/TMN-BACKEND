<?php

declare(strict_types=1);

namespace App\Modules\Langganan\Contracts;

use App\Modules\Langganan\LanggananModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface LanggananRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?LanggananModel;
    public function findActiveByPerusahaan(string $idPerusahaan): ?LanggananModel;
    public function create(array $data): LanggananModel;
    public function update(LanggananModel $model, array $data): LanggananModel;
    public function delete(LanggananModel $model): void;
}
