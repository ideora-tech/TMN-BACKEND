<?php
declare(strict_types=1);
namespace App\Modules\Supir\Contracts;

use App\Modules\Supir\SupirModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface SupirRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?SupirModel;
    public function findByPengguna(string $idPengguna): ?SupirModel;
    public function create(array $data): SupirModel;
    public function update(SupirModel $model, array $data): SupirModel;
    public function delete(SupirModel $model): void;
}
