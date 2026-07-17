<?php

declare(strict_types=1);

namespace App\Modules\Klien\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KlienRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;

    /**
     * Riwayat proyek milik satu klien, terbaru lebih dulu.
     */
    public function paginateProyek(string $idKlien, int $page, int $limit): LengthAwarePaginator;
}
