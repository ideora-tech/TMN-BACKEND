<?php

declare(strict_types=1);

namespace App\Modules\Klien\Contracts;

use App\Modules\Klien\KlienModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface KlienRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?KlienModel;
    public function findByKode(string $idPerusahaan, string $kode): ?KlienModel;
    public function create(array $data): KlienModel;
    public function update(KlienModel $model, array $data): KlienModel;
    public function delete(KlienModel $model): void;

    /**
     * Riwayat proyek milik satu klien, terbaru lebih dulu.
     */
    public function paginateProyek(string $idKlien, int $page, int $limit): LengthAwarePaginator;
}
