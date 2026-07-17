<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm;

use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;

class JenisBbmService
{
    public function __construct(private readonly JenisBbmRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);

        foreach ($result->items() as $item) {
            $this->attachHargaEfektif($item);
        }

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

    public function findOrFail(string $id, string $idPerusahaan): JenisBbmModel
    {
        $record = $this->repo->findByIdMilik($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Jenis BBM tidak ditemukan');
        }
        return $record;
    }

    public function attachHargaEfektif(JenisBbmModel $model): JenisBbmModel
    {
        $model->harga_per_liter = $this->repo->hargaEfektif($model->id_jenis_bbm);
        return $model;
    }

    public function create(array $data): JenisBbmModel
    {
        $record = $this->repo->create($data);
        return $this->attachHargaEfektif($record);
    }

    public function update(string $id, array $data, string $idPerusahaan): JenisBbmModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $updated = $this->repo->update($record, $data);
        return $this->attachHargaEfektif($updated);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    public function riwayatHarga(string $id, string $idPerusahaan): array
    {
        $this->findOrFail($id, $idPerusahaan);
        return $this->repo->riwayatHarga($id);
    }

    public function tambahHarga(string $id, string $idPerusahaan, array $data): HargaBbmModel
    {
        $this->findOrFail($id, $idPerusahaan);
        return $this->repo->createHarga($id, $data);
    }
}
