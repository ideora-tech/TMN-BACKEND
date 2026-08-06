<?php

declare(strict_types=1);

namespace App\Modules\AlokasiArmada\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface AlokasiArmadaRepositoryInterface
{
    public function paginate(string $idPerusahaan, int $page, int $limit, ?string $dari = null, ?string $sampai = null, ?string $search = null, ?string $idArmada = null, ?string $idProyek = null): LengthAwarePaginator;
    public function riwayatPerArmada(string $idArmada, ?string $dari = null, ?string $sampai = null): array;
    public function findArmadaMilikPerusahaan(string $idArmada, string $idPerusahaan): ?object;
    public function getPerusahaan(string $idPerusahaan): ?object;
    public function findAktifBySupirTanggal(string $idSupir, string $tanggal): ?object;
    public function create(array $data): object;
    public function softDeleteById(string $idAlokasi): void;
    public function softDeleteSemua(string $idSupir, string $tanggal): void;
    public function jadwalMendatangSupirProyek(string $idSupir, string $idProyek, string $dariTanggal): array;
    public function alokasiNopolMap(string $idSupir, string $dari, string $sampai): array;
    public function penugasanAktifSupirProyek(string $idSupir, string $idProyek): ?object;
    public function kandidatArmadaNganggur(string $idSupir, string $tanggal, string $idProyek): array;
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool;
    public function pasanganSupirTanggalUntukProyek(string $idProyek, string $dari, string $sampai): array;
}
