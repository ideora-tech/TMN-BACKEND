<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok;

use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use App\Modules\ParameterBok\Contracts\ParameterBokRepositoryInterface;
use Illuminate\Support\Str;

class ParameterBokService
{
    public function __construct(
        private readonly ParameterBokRepositoryInterface $repo,
        private readonly JenisBbmRepositoryInterface $jenisBbmRepo,
    ) {}

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

    public function findOrFail(string $id, string $idPerusahaan): ParameterBokModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Parameter BOK tidak ditemukan');
        }
        return $record;
    }

    public function findDetailOrFail(string $id, string $idPerusahaan): ParameterBokModel
    {
        $record = $this->repo->findDetailById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Parameter BOK tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): ParameterBokModel
    {
        $idPerusahaan = $data['id_perusahaan'];
        $this->validasiReferensi($data, $idPerusahaan);

        if ($this->repo->findByJenisKendaraan($idPerusahaan, $data['id_jenis_kendaraan'])) {
            abort(422, 'Parameter BOK untuk jenis kendaraan ini sudah ada');
        }

        $data['id_parameter_bok'] = (string) Str::uuid();
        $created = $this->repo->create($data);

        return $this->repo->findDetailById($created->id_parameter_bok);
    }

    public function update(string $id, array $data, string $idPerusahaan): ParameterBokModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->validasiReferensi($data, $idPerusahaan);

        if (isset($data['id_jenis_kendaraan'])
            && $this->repo->findByJenisKendaraan($idPerusahaan, $data['id_jenis_kendaraan'], $id)) {
            abort(422, 'Parameter BOK untuk jenis kendaraan ini sudah ada');
        }

        $this->repo->update($record, $data);

        return $this->repo->findDetailById($id);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    private function validasiReferensi(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_jenis_kendaraan'])
            && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
        if (isset($data['id_jenis_bbm'])
            && $this->jenisBbmRepo->findByIdMilik($data['id_jenis_bbm'], $idPerusahaan) === null) {
            abort(404, 'Jenis BBM tidak ditemukan');
        }
    }
}
