<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor\Contracts;

use App\Modules\PermintaanVendor\PermintaanVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PermintaanVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator;
    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?PermintaanVendorModel;
    public function findForUpdate(string $id): ?PermintaanVendorModel;
    public function create(array $data, array $unitRows = []): PermintaanVendorModel;
    public function update(PermintaanVendorModel $model, array $data, ?array $unitRows = null): PermintaanVendorModel;
    public function delete(PermintaanVendorModel $model): void;
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool;
    public function jenisKendaraanMilikPerusahaan(string $idJenisKendaraan, string $idPerusahaan): bool;
    public function unitUntukBanyak(array $idPermintaanList): array;
    public function replaceUnit(string $idPermintaan, array $unitRows): void;
}
