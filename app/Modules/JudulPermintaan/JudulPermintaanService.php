<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan;

use App\Modules\JudulPermintaan\Contracts\JudulPermintaanRepositoryInterface;

class JudulPermintaanService
{
    public const TIPE = ['umum', 'sparepart', 'aset'];

    public function __construct(private readonly JudulPermintaanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?bool $aktif = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif);

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

    public function listAktif(string $idPerusahaan): array
    {
        return $this->repo->listAktifByPerusahaan($idPerusahaan);
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Kategori permintaan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $data['nama_judul'] = trim((string) $data['nama_judul']);

        if ($this->repo->findByNama($data['id_perusahaan'], $data['nama_judul'])) {
            abort(409, 'Kategori permintaan sudah ada');
        }

        $this->terapkanTipe($data, (string) $data['id_perusahaan'], null);

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['nama_judul'])) {
            $data['nama_judul'] = trim((string) $data['nama_judul']);
            if ($this->repo->findByNama($idPerusahaan, $data['nama_judul'], $id)) {
                abort(409, 'Kategori permintaan sudah ada');
            }
        }

        $this->terapkanTipe($data, $idPerusahaan, $record);

        return $this->repo->update($record, $data);
    }

    private function terapkanTipe(array &$data, string $idPerusahaan, ?object $record): void
    {
        if (!empty($data['id_tipe_permintaan'])) {
            $tipe = $this->repo->tipePermintaanMilik((string) $data['id_tipe_permintaan'], $idPerusahaan);
            if ($tipe === null) {
                abort(404, 'Tipe permintaan tidak ditemukan');
            }
            $tipeBaru = $record === null || (string) $record->id_tipe_permintaan !== (string) $tipe->id_tipe_permintaan;
            if ($tipeBaru && (int) $tipe->aktif !== 1) {
                abort(422, 'Tipe permintaan sedang nonaktif');
            }
            $data['tipe'] = $tipe->jenis_form;
            return;
        }

        unset($data['id_tipe_permintaan']);
        if (!empty($data['tipe'])) {
            $data['id_tipe_permintaan'] = $this->repo->tipePermintaanBawaan($idPerusahaan, (string) $data['tipe'])?->id_tipe_permintaan;
        }
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiPermintaanPembelian($id)) {
            abort(422, 'Kategori permintaan masih dipakai di permintaan pembelian — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }
}
