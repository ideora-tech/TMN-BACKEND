<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor\Contracts;

use App\Modules\LokasiKantor\LokasiKantorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface LokasiKantorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?LokasiKantorModel;
    public function create(array $data): LokasiKantorModel;
    public function update(LokasiKantorModel $model, array $data): LokasiKantorModel;
    public function delete(LokasiKantorModel $model): void;
}
