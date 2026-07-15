<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor\Contracts;

use App\Modules\DokumenVendor\DokumenVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface DokumenVendorRepositoryInterface
{
    public function paginateByVendor(string $idVendor, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?DokumenVendorModel;
    public function findByIdUntukVendor(string $id, string $idVendor, string $idPerusahaan): ?DokumenVendorModel;
    public function findExpiring(string $idPerusahaan, int $days): array;
    public function create(array $data): DokumenVendorModel;
    public function update(DokumenVendorModel $model, array $data): DokumenVendorModel;
    public function delete(DokumenVendorModel $model): void;
}
