<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan;

use App\Modules\TipePermintaan\Contracts\TipePermintaanRepositoryInterface;
use Illuminate\Support\Facades\DB;

class TipePermintaanService
{
    public const JENIS_FORM = ['umum', 'sparepart', 'aset'];

    public function __construct(private readonly TipePermintaanRepositoryInterface $repo) {}

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
            abort(404, 'Tipe permintaan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $data['nama_tipe'] = trim((string) $data['nama_tipe']);

        if ($this->repo->findByNama($data['id_perusahaan'], $data['nama_tipe'])) {
            abort(409, 'Tipe permintaan sudah ada');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['nama_tipe'])) {
            $data['nama_tipe'] = trim((string) $data['nama_tipe']);
            if ($this->repo->findByNama($idPerusahaan, $data['nama_tipe'], $id)) {
                abort(409, 'Tipe permintaan sudah ada');
            }
        }

        $jenisFormBerubah = isset($data['jenis_form']) && $data['jenis_form'] !== $record->jenis_form;

        return DB::transaction(function () use ($record, $data, $id, $jenisFormBerubah) {
            $diperbarui = $this->repo->update($record, $data);
            if ($jenisFormBerubah) {
                $this->repo->sinkronJenisFormJudul($id, (string) $data['jenis_form']);
            }
            return $diperbarui;
        });
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->jumlahJudulPemakai($id) > 0) {
            abort(422, 'Tipe permintaan masih dipakai judul permintaan — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }
}
