<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface TipePembayaranRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator;
    public function listAktifByPerusahaan(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
