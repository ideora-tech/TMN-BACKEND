<?php

declare(strict_types=1);

namespace App\Modules\Armada\Contracts;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface ArmadaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status, ?string $search = null): LengthAwarePaginator;
    public function findById(string $id): ?ArmadaModel;
    public function countPenugasanAktif(string $idArmada): int;
    public function tandaiDigunakanJikaSiap(string $idArmada): bool;
    public function lepaskanJikaDigunakan(string $idArmada): void;
    public function findByNopol(string $nopol): ?ArmadaModel;
    public function findByNopolMilikPerusahaan(string $nopol, string $idPerusahaan): ?ArmadaModel;
    public function findByNomorRangka(string $nomorRangka): ?ArmadaModel;
    public function create(array $data): ArmadaModel;
    public function update(ArmadaModel $model, array $data): ArmadaModel;
    public function delete(ArmadaModel $model): void;
    public function dipakaiRelasiAktif(string $idArmada): bool;
    public function lepasArmadaDefaultSupir(string $idArmada): void;
    public function findServisJatuhTempo(string $idPerusahaan, int $days): array;
    public function findServisJatuhTempoKm(string $idPerusahaan): array;
    public function hitungStatusArmada(string $idPerusahaan): array;
    public function findPerawatanAktif(string $idPerusahaan): array;
}
