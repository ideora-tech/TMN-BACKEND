<?php
declare(strict_types=1);
namespace App\Modules\Supir\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface SupirRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $status = null, ?string $search = null): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByPengguna(string $idPengguna, ?string $excludeIdSupir = null): ?object;
    public function findPenggunaMilikPerusahaan(string $idPengguna, string $idPerusahaan): ?object;
    public function listOpsiPengguna(string $idPerusahaan): array;
    public function findByKaryawan(string $idKaryawan, ?string $excludeIdSupir = null): ?object;
    public function findByNoSim(string $idPerusahaan, string $noSim): ?object;
    public function findPemegangArmadaDefault(string $idArmada, ?string $excludeIdSupir = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function dipakaiRelasiAktif(string $idSupir): bool;
}
