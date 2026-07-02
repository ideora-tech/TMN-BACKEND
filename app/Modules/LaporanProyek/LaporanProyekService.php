<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Modules\LaporanProyek\Contracts\LaporanProyekRepositoryInterface;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;

class LaporanProyekService
{
    public function __construct(
        private readonly LaporanProyekRepositoryInterface $repo,
        private readonly PenugasanRepositoryInterface $penugasanRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit);

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

    public function findOrFail(string $id): LaporanProyekModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Laporan proyek tidak ditemukan');
        }
        return $record;
    }

    public function getByProyek(string $idProyek): LaporanProyekModel
    {
        $record = $this->repo->findByProyek($idProyek);
        if ($record === null) {
            abort(404, 'Laporan proyek tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): LaporanProyekModel
    {
        $idProyek = $data['id_proyek'];

        if ($this->repo->existsByProyek($idProyek)) {
            abort(409, 'Laporan untuk proyek ini sudah ada');
        }

        $totalTrip = $this->penugasanRepo->countSelesaiByProyek($idProyek);

        return $this->repo->create(array_merge($data, [
            'total_trip'         => $totalTrip,
            'id_diserahkan_oleh' => auth()->id(),
            'diserahkan_pada'    => now(),
        ]));
    }

    public function update(string $id, array $data): LaporanProyekModel
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }
}
