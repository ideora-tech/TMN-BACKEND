<?php

declare(strict_types=1);

namespace App\Modules\Pengguna;

use App\Models\Pengguna;
use App\Modules\Pengguna\Contracts\PenggunaRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class PenggunaService
{
    public function __construct(private readonly PenggunaRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): Pengguna
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Pengguna tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): Pengguna
    {
        if ($this->repo->findByUsername($data['username'])) {
            abort(409, 'Username sudah digunakan');
        }
        if ($this->repo->findByEmail($data['email'])) {
            abort(409, 'Email sudah digunakan');
        }
        $data['kata_sandi'] = Hash::make($data['password']);
        unset($data['password']);
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): Pengguna
    {
        $record = $this->findOrFail($id);

        // Check username uniqueness if being changed
        if (isset($data['username']) && $data['username'] !== $record->username) {
            $existing = $this->repo->findByUsername($data['username']);
            if ($existing !== null && $existing->id_pengguna !== $record->id_pengguna) {
                abort(409, 'Username sudah digunakan');
            }
        }

        // Check email uniqueness if being changed
        if (isset($data['email']) && $data['email'] !== $record->email) {
            $existing = $this->repo->findByEmail($data['email']);
            if ($existing !== null && $existing->id_pengguna !== $record->id_pengguna) {
                abort(409, 'Email sudah digunakan');
            }
        }

        // Hash password if provided
        if (isset($data['password'])) {
            $data['kata_sandi'] = Hash::make($data['password']);
            unset($data['password']);
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function changePassword(string $id, string $oldPassword, string $newPassword): void
    {
        $pengguna = $this->findOrFail($id);
        if (!Hash::check($oldPassword, $pengguna->kata_sandi)) {
            abort(422, 'Password lama tidak sesuai');
        }
        $this->repo->update($pengguna, ['kata_sandi' => Hash::make($newPassword)]);
    }
}
