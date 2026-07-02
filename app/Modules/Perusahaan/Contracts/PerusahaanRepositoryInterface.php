<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan\Contracts;

use App\Modules\Perusahaan\PerusahaanModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PerusahaanRepositoryInterface
{
    public function paginate(int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?PerusahaanModel;
    public function create(array $data): PerusahaanModel;
    public function update(PerusahaanModel $model, array $data): PerusahaanModel;
    public function delete(PerusahaanModel $model): void;
}
