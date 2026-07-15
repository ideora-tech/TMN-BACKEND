<?php

declare(strict_types=1);

namespace App\Modules\Lokasi\Contracts;

use App\Modules\Lokasi\LokasiModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface LokasiRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator;
    public function findById(string $id): ?LokasiModel;
    public function create(array $data): LokasiModel;
    public function update(LokasiModel $model, array $data): LokasiModel;
    public function delete(LokasiModel $model): void;
}
