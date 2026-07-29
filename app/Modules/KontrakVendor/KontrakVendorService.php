<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;

class KontrakVendorService
{
    public function __construct(private readonly KontrakVendorRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idVendor = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idVendor, $search);

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

    public function listByProyek(string $idPerusahaan, string $idProyek, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByProyek($idPerusahaan, $idProyek, $page, $limit);

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

    public function findOrFail(string $id, string $idPerusahaan): KontrakVendorModel
    {
        $record = $this->repo->findAktifMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Kontrak vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): KontrakVendorModel
    {
        if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $data['id_perusahaan'])) {
            abort(404, 'Vendor tidak ditemukan');
        }

        // Kolom nilai_kontrak NOT NULL DEFAULT 0 — input kosong dari klien
        // (null) dinormalisasi supaya tidak meledak di constraint DB.
        $data['nilai_kontrak'] = (float) ($data['nilai_kontrak'] ?? 0);

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): KontrakVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['id_vendor']) && $data['id_vendor'] !== $record->id_vendor) {
            if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
                abort(404, 'Vendor tidak ditemukan');
            }
        }

        if (array_key_exists('nilai_kontrak', $data) && $data['nilai_kontrak'] === null) {
            $data['nilai_kontrak'] = 0;
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }
}
