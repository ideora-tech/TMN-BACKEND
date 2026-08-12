<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek;

use App\Modules\LaporanProyek\Contracts\LaporanProyekRepositoryInterface;

class LaporanProyekService
{
    public function __construct(
        private readonly LaporanProyekRepositoryInterface $repo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $search);

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

    public function dataExport(string $idPerusahaan): \Illuminate\Support\Collection
    {
        return collect($this->repo->semuaUntukExport($idPerusahaan))->map(function ($laporan) {
            $statistik = $this->repo->statistikProyek((string) $laporan->id_proyek);

            return (object) [
                'kode_proyek'     => $laporan->kode_proyek,
                'nama_proyek'     => $laporan->nama_proyek,
                'nama_klien'      => $laporan->nama_klien,
                'total_trip'      => $statistik['total_trip'],
                'total_jarak_km'  => $statistik['total_jarak_km'],
                'total_biaya'     => $statistik['total_biaya'],
                'ringkasan'       => $laporan->ringkasan,
                'diserahkan_oleh' => $laporan->diserahkan_oleh,
                'diserahkan_pada' => $laporan->diserahkan_pada,
            ];
        });
    }

    public function detail(string $id, string $idPerusahaan): array
    {
        $laporan = $this->repo->detailById($id, $idPerusahaan);
        if ($laporan === null) {
            abort(404, 'Laporan proyek tidak ditemukan');
        }

        return [
            'id_laporan'      => $laporan->id_laporan,
            'id_proyek'       => $laporan->id_proyek,
            'kode_proyek'     => $laporan->kode_proyek,
            'nama_proyek'     => $laporan->nama_proyek,
            'nama_klien'      => $laporan->nama_klien,
            'ringkasan'       => $laporan->ringkasan,
            'diserahkan_oleh' => $laporan->diserahkan_oleh,
            'diserahkan_pada' => $laporan->diserahkan_pada,
            'statistik'       => $this->repo->statistikProyek((string) $laporan->id_proyek),
        ];
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

        $totalTrip = $this->repo->countTripSelesaiByProyek($idProyek);

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
