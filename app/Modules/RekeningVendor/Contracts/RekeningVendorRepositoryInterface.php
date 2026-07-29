<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor\Contracts;

use App\Modules\RekeningVendor\RekeningVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface RekeningVendorRepositoryInterface
{
    public function paginateByVendor(string $idVendor, int $page, int $limit): LengthAwarePaginator;
    public function findByIdUntukVendor(string $id, string $idVendor, string $idPerusahaan): ?RekeningVendorModel;
    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;
    public function create(array $data): RekeningVendorModel;
    public function update(RekeningVendorModel $model, array $data): RekeningVendorModel;
    public function delete(RekeningVendorModel $model): void;
}
