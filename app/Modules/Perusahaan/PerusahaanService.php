<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan;

use App\Modules\KomponenUangJalan\KomponenUangJalanService;
use App\Modules\Perusahaan\Contracts\PerusahaanRepositoryInterface;

class PerusahaanService
{
    public function __construct(
        private readonly PerusahaanRepositoryInterface $repo,
        private readonly KomponenUangJalanService $komponenUangJalanService,
    ) {}

    public function list(int $page = 1, int $limit = 10, ?string $hanyaPerusahaan = null): array
    {
        $result = $this->repo->paginate($page, $limit, $hanyaPerusahaan);
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

    public function findOrFail(string $id, ?string $hanyaPerusahaan = null): PerusahaanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($hanyaPerusahaan !== null && (string) $record->id_perusahaan !== $hanyaPerusahaan)) {
            abort(404, 'Perusahaan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PerusahaanModel
    {
        $record = $this->repo->create($data);
        $this->komponenUangJalanService->pastikanBawaan((string) $record->id_perusahaan);

        return $record;
    }

    public function update(string $id, array $data, ?string $hanyaPerusahaan = null): PerusahaanModel
    {
        $record = $this->findOrFail($id, $hanyaPerusahaan);
        if ($hanyaPerusahaan !== null) {
            unset($data['aktif']);
        }
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
