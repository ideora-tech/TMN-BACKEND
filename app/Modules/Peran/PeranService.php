<?php

declare(strict_types=1);

namespace App\Modules\Peran;

use App\Modules\Peran\Contracts\PeranRepositoryInterface;

class PeranService
{
    public function __construct(private readonly PeranRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $aktif = null, bool $sertakanPlatform = true): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $search, $aktif, $sertakanPlatform);

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

    public function findOrFail(string $id, ?string $batasPerusahaan = null): PeranModel
    {
        $record = $this->repo->findById($id);
        $milikLain = $record !== null && $batasPerusahaan !== null
            && (int) $record->is_platform !== 1
            && (string) $record->id_perusahaan !== $batasPerusahaan;
        if ($record === null || $milikLain) {
            abort(404, 'Peran tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, ?string $batasPerusahaan = null): PeranModel
    {
        if ($batasPerusahaan !== null) {
            if (strtoupper(trim((string) ($data['kode_peran'] ?? ''))) === 'SUPERADMIN') {
                abort(403, 'Peran Superadmin hanya bisa dibuat oleh Superadmin');
            }
            $data['is_platform'] = 0;
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, ?string $batasPerusahaan = null): PeranModel
    {
        $record = $this->findOrFail($id, $batasPerusahaan);
        $this->pastikanBukanPlatform($record, $batasPerusahaan);
        if ($batasPerusahaan !== null) {
            unset($data['is_platform']);
        }
        if (array_key_exists('kode_peran', $data)
            && strtoupper(trim((string) $data['kode_peran'])) !== strtoupper((string) $record->kode_peran)) {
            abort(422, 'Kode peran tidak bisa diubah karena dipakai akun pengguna dan izin akses');
        }
        unset($data['kode_peran']);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, ?string $batasPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $batasPerusahaan);
        $this->pastikanBukanPlatform($record, $batasPerusahaan);
        $this->repo->delete($record);
    }

    private function pastikanBukanPlatform(PeranModel $record, ?string $batasPerusahaan): void
    {
        if ($batasPerusahaan !== null && (int) $record->is_platform === 1) {
            abort(403, 'Peran platform hanya bisa diubah oleh Superadmin');
        }
    }
}
