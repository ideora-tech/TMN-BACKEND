<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;

class KaryawanService
{
    public function __construct(
        private readonly KaryawanRepositoryInterface $repo,
        private readonly JabatanRepositoryInterface $jabatanRepo,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $status = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $status, $search);

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

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Karyawan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $existing = $this->repo->findByNik($data['nik']);
        if ($existing !== null) {
            abort(422, 'NIK sudah terdaftar');
        }

        $record = $this->repo->create($data);
        $this->buatSupirJikaPerlu($record);

        return $record;
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);

        $jabatanBerubah = array_key_exists('id_jabatan', $data)
            && ($data['id_jabatan'] ?? null) !== $record->id_jabatan;

        $updated = $this->repo->update($record, $data);

        if ($jabatanBerubah) {
            $this->repo->insertRiwayatJabatan(
                (string) $record->id_perusahaan,
                $record->id_karyawan,
                $record->id_jabatan,
                $data['id_jabatan'] ?? null,
            );
            $this->buatSupirJikaPerlu($updated);
        }

        return $updated;
    }

    public function riwayatJabatan(string $id): array
    {
        $this->findOrFail($id);
        return $this->repo->riwayatJabatan($id);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function exitHistory(string $id): array
    {
        $this->findOrFail($id);
        return $this->repo->exitHistory($id);
    }

    private function buatSupirJikaPerlu(object $karyawan): void
    {
        if (($karyawan->id_jabatan ?? null) === null) {
            return;
        }

        $jabatan = $this->jabatanRepo->findById((string) $karyawan->id_jabatan);
        if ($jabatan === null || (int) $jabatan->is_supir !== 1) {
            return;
        }

        if ((string) $jabatan->id_perusahaan !== (string) $karyawan->id_perusahaan) {
            return;
        }

        if ($this->supirRepo->findByKaryawan($karyawan->id_karyawan) !== null) {
            return;
        }

        $this->supirRepo->create([
            'id_perusahaan' => (string) $karyawan->id_perusahaan,
            'id_karyawan'   => $karyawan->id_karyawan,
            'nama'          => $karyawan->nama_karyawan,
            'telepon'       => $karyawan->telepon ?? null,
            'no_sim'        => null,
            'status'        => 'aktif',
        ]);
    }
}
