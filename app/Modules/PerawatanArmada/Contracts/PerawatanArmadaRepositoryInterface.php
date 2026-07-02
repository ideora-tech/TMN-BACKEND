<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Contracts;

use App\Modules\PerawatanArmada\PerawatanArmadaModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PerawatanArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?PerawatanArmadaModel;
    public function create(array $data): PerawatanArmadaModel;
    public function update(PerawatanArmadaModel $model, array $data): PerawatanArmadaModel;
    public function delete(PerawatanArmadaModel $model): void;
}
