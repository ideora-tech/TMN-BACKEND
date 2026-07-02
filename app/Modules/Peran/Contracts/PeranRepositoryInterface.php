<?php

declare(strict_types=1);

namespace App\Modules\Peran\Contracts;

use App\Modules\Peran\PeranModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PeranRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?PeranModel;
    public function findByKode(string $kodePeran): ?PeranModel;
    public function create(array $data): PeranModel;
    public function update(PeranModel $model, array $data): PeranModel;
    public function delete(PeranModel $model): void;
}
