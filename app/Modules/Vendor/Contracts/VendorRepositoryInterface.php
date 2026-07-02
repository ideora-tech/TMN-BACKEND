<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Contracts;

use App\Modules\Vendor\VendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface VendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?VendorModel;
    public function findByKode(string $idPerusahaan, string $kode): ?VendorModel;
    public function create(array $data): VendorModel;
    public function update(VendorModel $model, array $data): VendorModel;
    public function delete(VendorModel $model): void;
}
