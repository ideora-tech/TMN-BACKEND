<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Contracts;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ArmadaVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null, ?string $search = null): LengthAwarePaginator;
    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?ArmadaVendorModel;
    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;
    public function jenisKendaraanMilikPerusahaan(string $idJenisKendaraan, string $idPerusahaan): bool;
    public function milikVendor(string $id, string $idVendor): bool;
    public function findIdVendorByKode(string $kodeVendor, string $idPerusahaan): ?string;
    public function findIdJenisKendaraanByNama(string $namaJenis, string $idPerusahaan): ?string;
    public function nopolTerdaftar(string $nopol, string $idPerusahaan): bool;

    /** @return array<int, array{id_armada_vendor: string, id_kontrak_vendor: string, nopol: string, merk: ?string, jenis: ?string, id_vendor: string, nama_vendor: string}> */
    public function listOpsiUnitOnly(string $idPerusahaan): array;
    public function listOpsiBoard(string $idPerusahaan): array;
    public function create(array $data): ArmadaVendorModel;
    public function update(ArmadaVendorModel $model, array $data): ArmadaVendorModel;
    public function delete(ArmadaVendorModel $model): void;
}
