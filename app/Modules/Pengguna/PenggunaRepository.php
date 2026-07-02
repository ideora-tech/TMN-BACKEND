<?php

declare(strict_types=1);

namespace App\Modules\Pengguna;

use App\Models\Pengguna;
use App\Modules\Pengguna\Contracts\PenggunaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PenggunaRepository implements PenggunaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return Pengguna::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?Pengguna
    {
        return Pengguna::active()->find($id);
    }

    public function findByUsername(string $username): ?Pengguna
    {
        return Pengguna::active()->where('username', $username)->first();
    }

    public function findByEmail(string $email): ?Pengguna
    {
        return Pengguna::active()->where('email', $email)->first();
    }

    public function create(array $data): Pengguna
    {
        return Pengguna::create($data);
    }

    public function update(Pengguna $model, array $data): Pengguna
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(Pengguna $model): void
    {
        $model->softDelete();
    }
}
