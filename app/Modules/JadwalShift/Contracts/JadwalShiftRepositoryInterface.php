<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Contracts;

interface JadwalShiftRepositoryInterface
{
    public function listByProyek(string $idProyek, ?string $dari, ?string $sampai): array;
    public function findById(string $id): ?object;
    public function findAktifBySupirTanggal(string $idSupir, string $tanggal): ?object;
    public function supirPunyaPenugasan(string $idProyek, string $idSupir): bool;
    public function listShiftSupir(string $idSupir, string $dari, string $sampai): array;
    public function supirTerdaftarDiProyek(string $idProyek): array;
    public function supirByNoSim(string $noSim, string $idPerusahaan): ?object;
    public function shiftByNama(string $nama, string $idPerusahaan): ?object;
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool;
    public function create(array $data): object;
    public function updateShift(object $record, string $idShift): object;
    public function delete(object $record): void;
}
