<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;

class PenugasanService
{
    public function __construct(private readonly PenugasanRepositoryInterface $repo) {}

    public function list(string $idProyek, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByProyek($idProyek, $page, $limit);

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

    public function findOrFail(string $id): PenugasanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Penugasan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PenugasanModel
    {
        if (!empty($data['id_armada'])) {
            $armada = ArmadaModel::active()->find($data['id_armada']);
            if ($armada === null) {
                abort(422, 'Armada tidak ditemukan');
            }
            if ($armada->status !== 'tersedia') {
                abort(422, 'Armada tidak tersedia untuk ditugaskan');
            }
        }

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            if ($this->repo->hasConflict($data['id_karyawan'], $data['tanggal_tugas'])) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        $penugasan = $this->repo->create($data);

        if (!empty($data['id_armada'])) {
            ArmadaModel::active()->find($data['id_armada'])?->update(['status' => 'digunakan']);
        }

        return $penugasan;
    }

    public function update(string $id, array $data): PenugasanModel
    {
        $record = $this->findOrFail($id);

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            $idKaryawan  = $data['id_karyawan'] ?? $record->id_karyawan;
            $tanggalTugas = $data['tanggal_tugas'] ?? $record->tanggal_tugas;
            if ($this->repo->hasConflict($idKaryawan, $tanggalTugas, $id)) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
