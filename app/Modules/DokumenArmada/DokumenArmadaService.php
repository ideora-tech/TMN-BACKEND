<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class DokumenArmadaService
{
    public function __construct(private readonly DokumenArmadaRepositoryInterface $repo) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 100): array
    {
        return $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $jenisDokumen));
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
            abort(404, 'Dokumen armada tidak ditemukan');
        }
        return $record;
    }

    public function getExpiring(string $idPerusahaan, int $days): array
    {
        return $this->repo->findExpiring($idPerusahaan, $days);
    }

    public function create(string $idArmada, array $data, ?UploadedFile $file = null): object
    {
        if ($file) {
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
        }
        unset($data['file']);
        return $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
    }

    public function update(string $id, array $data, ?UploadedFile $file = null): object
    {
        if ($file) {
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
        }
        unset($data['file']);
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
