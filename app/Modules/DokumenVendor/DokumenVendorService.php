<?php

declare(strict_types=1);

namespace App\Modules\DokumenVendor;

use App\Modules\DokumenVendor\Contracts\DokumenVendorRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DokumenVendorService
{
    public function __construct(private readonly DokumenVendorRepositoryInterface $repo) {}

    public function listByVendor(string $idVendor, int $page = 1, int $limit = 100): array
    {
        $result = $this->repo->paginateByVendor($idVendor, $page, $limit);
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

    public function findOrFail(string $id): DokumenVendorModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Dokumen vendor tidak ditemukan');
        }
        return $record;
    }

    public function findOrFailUntukVendor(string $id, string $idVendor, string $idPerusahaan): DokumenVendorModel
    {
        $record = $this->repo->findByIdUntukVendor($id, $idVendor, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Dokumen vendor tidak ditemukan');
        }
        return $record;
    }

    public function getExpiring(string $idPerusahaan, int $days): array
    {
        return $this->repo->findExpiring($idPerusahaan, $days);
    }

    public function create(string $idVendor, array $data, ?UploadedFile $file = null): DokumenVendorModel
    {
        if ($file) {
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
        }
        unset($data['file']);
        return $this->repo->create(array_merge($data, ['id_vendor' => $idVendor]));
    }

    public function update(string $id, string $idVendor, string $idPerusahaan, array $data, ?UploadedFile $file = null): DokumenVendorModel
    {
        if ($file) {
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
        }
        unset($data['file']);
        $record = $this->findOrFailUntukVendor($id, $idVendor, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idVendor, string $idPerusahaan): void
    {
        $record = $this->findOrFailUntukVendor($id, $idVendor, $idPerusahaan);
        $this->repo->delete($record);
    }
}
