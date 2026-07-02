<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Contracts;

use App\Modules\DokumenArmada\DokumenArmadaModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface DokumenArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?DokumenArmadaModel;
    public function findExpiring(string $idPerusahaan, int $days): array;
    public function create(array $data): DokumenArmadaModel;
    public function delete(DokumenArmadaModel $model): void;
}
