<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;

class JabatanService
{
    public function __construct(private readonly JabatanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idDepartemen = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idDepartemen, $search);

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
            abort(404, 'Jabatan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $this->validasiJabatanInduk($data['id_jabatan_induk'] ?? null, (string) $data['id_perusahaan'], null);
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);
        if (array_key_exists('id_jabatan_induk', $data)) {
            $this->validasiJabatanInduk($data['id_jabatan_induk'], (string) $record->id_perusahaan, $id);
        }
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function strukturOrganisasi(string $idPerusahaan): array
    {
        return $this->repo->strukturOrganisasi($idPerusahaan);
    }

    /**
     * Jabatan atasan wajib aktif & milik perusahaan yang sama; tidak boleh
     * menunjuk ke diri sendiri atau membentuk siklus (atasan melingkar
     * kembali ke bawahannya sendiri).
     */
    private function validasiJabatanInduk(?string $idJabatanInduk, string $idPerusahaan, ?string $idJabatanSedangDiedit): void
    {
        if ($idJabatanInduk === null) {
            return;
        }
        if ($idJabatanInduk === $idJabatanSedangDiedit) {
            abort(422, 'Jabatan tidak boleh menjadi atasan untuk dirinya sendiri');
        }

        $induk = $this->repo->findAktifMilikPerusahaan($idJabatanInduk, $idPerusahaan);
        if ($induk === null) {
            abort(422, 'Jabatan atasan tidak ditemukan atau tidak aktif');
        }

        if ($idJabatanSedangDiedit === null) {
            return;
        }

        $dikunjungi = [];
        $cur = $induk;
        while ($cur !== null && $cur->id_jabatan_induk !== null) {
            if ($cur->id_jabatan_induk === $idJabatanSedangDiedit) {
                abort(422, 'Struktur jabatan tidak boleh membentuk siklus — atasan tidak boleh melingkar kembali ke bawahannya');
            }
            if (isset($dikunjungi[$cur->id_jabatan_induk])) {
                break;
            }
            $dikunjungi[$cur->id_jabatan_induk] = true;
            $cur = $this->repo->findAktifMilikPerusahaan($cur->id_jabatan_induk, $idPerusahaan);
        }
    }
}
