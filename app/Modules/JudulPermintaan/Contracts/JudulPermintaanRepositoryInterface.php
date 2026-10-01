<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface JudulPermintaanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator;
    public function listAktifByPerusahaan(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function findByNama(string $idPerusahaan, string $nama, ?string $kecualiId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function dipakaiPermintaanPembelian(string $idJudulPermintaan): bool;
    public function tipePermintaanMilik(string $idTipePermintaan, string $idPerusahaan): ?object;
    public function tipePermintaanBawaan(string $idPerusahaan, string $jenisForm): ?object;
}
