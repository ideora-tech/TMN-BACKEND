<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Contracts;

use App\Modules\Faktur\FakturModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface FakturRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?FakturModel;
    public function findByNomor(string $nomor, string $idPerusahaan): ?FakturModel;
    public function create(array $data): FakturModel;
    public function update(FakturModel $model, array $data): FakturModel;
    public function delete(FakturModel $model): void;
}
