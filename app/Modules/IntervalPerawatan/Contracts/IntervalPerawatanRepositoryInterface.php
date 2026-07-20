<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface IntervalPerawatanRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisPerawatan,
        ?string $idJenisKendaraan,
    ): LengthAwarePaginator;

    public function findById(string $id): ?object;

    /** findById + kolom nama_jenis_perawatan & nama_jenis_kendaraan (untuk Resource). */
    public function findDetailById(string $id): ?object;

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisPerawatan,
        string $idJenisKendaraan,
        ?string $excludeId = null,
    ): ?object;

    public function jenisPerawatanMilik(string $id, string $idPerusahaan): ?object;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): object;

    public function update(object $record, array $data): object;

    public function delete(object $record): void;
}
