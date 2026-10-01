<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit\Contracts;

use App\Modules\ProyekUnit\ProyekUnitModel;

interface ProyekUnitRepositoryInterface
{
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool;

    public function kunciProyek(string $idProyek): void;

    /** @return object[] */
    public function listByProyek(string $idProyek, string $idPerusahaan): array;

    /** @return array<string, true> kunci "sumber:id_unit" unit aktif di proyek */
    public function kunciUnitProyek(string $idProyek): array;

    /** @return object[] armada internal yang bisa dipakai beserta nama pemegang */
    public function armadaTersedia(string $idPerusahaan): array;

    /** @return array<string, string> id_supir_vendor => nama */
    public function namaSupirVendor(array $ids): array;

    /** @return array<string, string|null> id_armada_vendor => nama jenis */
    public function namaJenisArmadaVendor(array $ids): array;

    public function create(array $data): ProyekUnitModel;

    public function findMilikProyek(string $idProyek, string $id): ?ProyekUnitModel;

    /** @return ProyekUnitModel[] */
    public function findBanyakMilikProyek(string $idProyek, array $ids): array;
}
