<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran;

use App\Modules\TipePembayaran\Contracts\TipePembayaranRepositoryInterface;

class TipePembayaranService
{
    public function __construct(private readonly TipePembayaranRepositoryInterface $repo) {}

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
            abort(404, 'Tipe pembayaran tidak ditemukan');
        }
        return $record;
    }

    public function kodeAktifTerdaftar(string $idPerusahaan, string $kode): bool
    {
        $record = $this->repo->findByKode($idPerusahaan, $kode);
        return $record !== null && (bool) $record->aktif;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_tipe'])) {
            abort(409, 'Kode tipe pembayaran sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['kode_tipe']) && $data['kode_tipe'] !== $record->kode_tipe) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_tipe'])) {
                abort(409, 'Kode tipe pembayaran sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiInvoiceVendor($record->id_perusahaan, $record->kode_tipe)) {
            abort(422, 'Tipe pembayaran masih dipakai di invoice vendor — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }
}
