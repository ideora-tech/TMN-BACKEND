<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;

class JenisPerawatanService
{
    public function __construct(private readonly JenisPerawatanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);

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

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Jenis perawatan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $dipakai = $this->repo->countActiveUsage($id);
        if ($dipakai > 0) {
            abort(422, "Jenis perawatan masih dipakai di {$dipakai} catatan perawatan aktif, tidak bisa dihapus");
        }

        if ($this->repo->dipakaiRelasiLain($id)) {
            abort(422, 'Jenis perawatan masih dipakai di interval/paket perawatan — hapus dulu konfigurasinya');
        }

        $this->repo->delete($record);
    }
}
