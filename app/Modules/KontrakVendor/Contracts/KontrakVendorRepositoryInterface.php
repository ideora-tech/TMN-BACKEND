<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Contracts;

use App\Modules\KontrakVendor\KontrakVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface KontrakVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null): LengthAwarePaginator;
    public function paginateByProyek(string $idPerusahaan, string $idProyek, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?KontrakVendorModel;
    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?KontrakVendorModel;
    public function create(array $data): KontrakVendorModel;
    public function update(KontrakVendorModel $model, array $data): KontrakVendorModel;
    public function delete(KontrakVendorModel $model): void;
}
