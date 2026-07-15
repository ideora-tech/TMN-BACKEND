<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Contracts;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ArmadaVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null): LengthAwarePaginator;
    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?ArmadaVendorModel;
    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;
    public function milikVendor(string $id, string $idVendor): bool;
    public function create(array $data): ArmadaVendorModel;
    public function update(ArmadaVendorModel $model, array $data): ArmadaVendorModel;
    public function delete(ArmadaVendorModel $model): void;
}
