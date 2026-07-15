<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Contracts;

use App\Modules\SupirVendor\SupirVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface SupirVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null): LengthAwarePaginator;
    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?SupirVendorModel;
    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;
    public function milikVendor(string $id, string $idVendor): bool;
    public function create(array $data): SupirVendorModel;
    public function update(SupirVendorModel $model, array $data): SupirVendorModel;
    public function delete(SupirVendorModel $model): void;
}
