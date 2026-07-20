<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SparepartService
{
    public function __construct(private readonly SparepartRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $idKategoriSparepart = null): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $idKategoriSparepart));
    }

    public function listMutasi(string $idSparepart, int $page = 1, int $limit = 10): array
    {
        $this->findOrFail($idSparepart);
        return $this->toPagedArray($this->repo->paginateMutasi($idSparepart, $page, $limit));
    }

    private function toPagedArray(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Spare part tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode'])) {
            abort(409, 'Kode spare part sudah digunakan');
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode']) && $data['kode'] !== $record->kode) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode'], $id)) {
                abort(409, 'Kode spare part sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

        $dipakai = $this->repo->countActiveUsage($id);
        if ($dipakai > 0) {
            abort(422, "Spare part masih dipakai di {$dipakai} item catatan servis aktif, tidak bisa dihapus");
        }

        $this->repo->delete($record);
    }

    /**
     * jenis 'masuk'  : qty wajib > 0 (divalidasi Request), menambah stok.
     * jenis 'penyesuaian' : qty delta bertanda (koreksi opname), boleh negatif.
     * Stok hasil akhir tidak boleh negatif → 422.
     */
    public function mutasiStok(string $id, array $data): object
    {
        return DB::transaction(function () use ($id, $data) {
            $record = $this->repo->findByIdForUpdate($id);
            if ($record === null) {
                abort(404, 'Spare part tidak ditemukan');
            }

            $stokBaru = (int) $record->stok + (int) $data['qty'];
            if ($stokBaru < 0) {
                abort(422, "Stok tidak boleh negatif (stok saat ini {$record->stok}, perubahan {$data['qty']})");
            }

            $this->repo->setStok($id, $stokBaru);
            $this->repo->insertMutasi([
                'id_sparepart' => $id,
                'jenis'        => $data['jenis'],
                'qty'          => (int) $data['qty'],
                'harga'        => $data['harga'] ?? null,
                'keterangan'   => $data['keterangan'] ?? null,
                'tanggal'      => now()->toDateString(),
            ]);

            return $this->findOrFail($id);
        });
    }
}
