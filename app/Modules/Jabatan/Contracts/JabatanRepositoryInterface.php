<?php

declare(strict_types=1);

namespace App\Modules\Jabatan\Contracts;

use App\Modules\Jabatan\JabatanModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface JabatanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null): LengthAwarePaginator;
    public function findById(string $id): ?JabatanModel;
    public function create(array $data): JabatanModel;
    public function update(JabatanModel $model, array $data): JabatanModel;
    public function delete(JabatanModel $model): void;
}
