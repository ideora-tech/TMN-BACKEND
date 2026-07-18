<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Contracts;

use App\Modules\Penawaran\PenawaranModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PenawaranRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search,
        ?string $status,
        ?string $idProyek = null
    ): LengthAwarePaginator;

    public function findById(string $id): ?PenawaranModel;

    public function findByNomor(string $idPerusahaan, string $nomor, ?string $excludeId = null): ?PenawaranModel;

    public function create(array $data): PenawaranModel;

    public function update(PenawaranModel $model, array $data): PenawaranModel;

    public function delete(PenawaranModel $model): void;
}