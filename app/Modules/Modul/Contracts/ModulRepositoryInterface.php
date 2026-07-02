<?php

declare(strict_types=1);

namespace App\Modules\Modul\Contracts;

use App\Modules\Modul\ModulModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ModulRepositoryInterface
{
    public function all(): array;
    public function paginate(int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?ModulModel;
    public function findByKode(string $kodeModul): ?ModulModel;
    public function create(array $data): ModulModel;
    public function update(ModulModel $model, array $data): ModulModel;
    public function delete(ModulModel $model): void;
}
