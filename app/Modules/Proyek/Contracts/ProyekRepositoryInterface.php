<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Contracts;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ProyekRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function paginateByKlien(string $idKlien, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?ProyekModel;
    public function findByKode(string $idPerusahaan, string $kode): ?ProyekModel;
    public function create(array $data): ProyekModel;
    public function update(ProyekModel $model, array $data): ProyekModel;
    public function delete(ProyekModel $model): void;
}
