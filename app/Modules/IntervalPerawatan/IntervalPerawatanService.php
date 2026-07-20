<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use Illuminate\Support\Str;

class IntervalPerawatanService
{
    public function __construct(private readonly IntervalPerawatanRepositoryInterface $repo) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $idJenisPerawatan = null,
        ?string $idJenisKendaraan = null,
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idJenisPerawatan, $idJenisKendaraan);

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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Interval perawatan tidak ditemukan');
        }
        return $record;
    }

    public function findDetailOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findDetailById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Interval perawatan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];
        $this->validasiReferensi($data, $idPerusahaan);

        if ($this->repo->findByKombinasi($idPerusahaan, $data['id_jenis_perawatan'], $data['id_jenis_kendaraan']) !== null) {
            abort(422, 'Interval untuk kombinasi jenis perawatan dan jenis kendaraan ini sudah ada');
        }

        $data['id_interval_perawatan'] = (string) Str::uuid();
        $created = $this->repo->create($data);

        return $this->repo->findDetailById($created->id_interval_perawatan);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->validasiReferensi($data, $idPerusahaan);

        $idJenisPerawatan = $data['id_jenis_perawatan'] ?? $record->id_jenis_perawatan;
        $idJenisKendaraan = $data['id_jenis_kendaraan'] ?? $record->id_jenis_kendaraan;

        if ($this->repo->findByKombinasi($idPerusahaan, $idJenisPerawatan, $idJenisKendaraan, $id) !== null) {
            abort(422, 'Interval untuk kombinasi jenis perawatan dan jenis kendaraan ini sudah ada');
        }

        $this->repo->update($record, $data);

        return $this->repo->findDetailById($id);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    public function resolusi(string $idPerusahaan, string $idJenisPerawatan, string $idJenisKendaraan): ?int
    {
        $interval = $this->repo->findByKombinasi($idPerusahaan, $idJenisPerawatan, $idJenisKendaraan);

        return $interval !== null ? (int) $interval->interval_hari : null;
    }

    private function validasiReferensi(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_jenis_perawatan'])
            && $this->repo->jenisPerawatanMilik($data['id_jenis_perawatan'], $idPerusahaan) === null) {
            abort(404, 'Jenis perawatan tidak ditemukan');
        }
        if (isset($data['id_jenis_kendaraan'])
            && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
    }
}
