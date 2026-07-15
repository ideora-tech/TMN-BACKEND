<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional;

use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class LaporanOperasionalService
{
    public function __construct(private readonly LaporanOperasionalRepositoryInterface $repo) {}

    public function listTrip(string $idPerusahaan, array $filter, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->queryTrip($idPerusahaan, $filter)
            ->paginate($limit, ['*'], 'page', $page);

        return [
            'data' => collect($result->items())->map(fn ($row) => $this->castRow($row))->all(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function ringkasanTrip(string $idPerusahaan, array $filter): array
    {
        return $this->repo->ringkasanTrip($idPerusahaan, $filter);
    }

    public function exportTrip(string $idPerusahaan, array $filter): Collection
    {
        return $this->repo->queryTrip($idPerusahaan, $filter)
            ->get()
            ->map(fn ($row) => $this->castRow($row));
    }

    public function karyawanAktif(string $idPerusahaan): EloquentCollection
    {
        return $this->repo->karyawanAktif($idPerusahaan);
    }

    public function armadaAktif(string $idPerusahaan): EloquentCollection
    {
        return $this->repo->armadaAktif($idPerusahaan);
    }

    private function castRow(object $row): object
    {
        $row->jarak_tempuh_km = $row->jarak_tempuh_km !== null ? (float) $row->jarak_tempuh_km : null;
        $row->total_biaya     = (float) $row->total_biaya;

        return $row;
    }
}
