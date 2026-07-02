<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi\Contracts;

use App\Modules\Rekonsiliasi\RekonsiliasiModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface RekonsiliasiRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function paginateByFaktur(string $idFaktur, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?RekonsiliasiModel;
    public function create(array $data): RekonsiliasiModel;
    public function update(RekonsiliasiModel $model, array $data): RekonsiliasiModel;
    public function delete(RekonsiliasiModel $model): void;
}
