<?php

declare(strict_types=1);

namespace App\Modules\Pengguna\Contracts;

use App\Models\Pengguna;
use Illuminate\Pagination\LengthAwarePaginator;

interface PenggunaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?Pengguna;
    public function findByUsername(string $username): ?Pengguna;
    public function findByEmail(string $email): ?Pengguna;
    public function create(array $data): Pengguna;
    public function update(Pengguna $model, array $data): Pengguna;
    public function delete(Pengguna $model): void;
}
