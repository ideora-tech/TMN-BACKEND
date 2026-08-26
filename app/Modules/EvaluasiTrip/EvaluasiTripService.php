<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;

class EvaluasiTripService
{
    public function __construct(private readonly EvaluasiTripRepositoryInterface $repo) {}

    public function getByPenugasan(string $idPenugasan, ?string $idPerusahaan = null): EvaluasiTripModel
    {
        $this->pastikanPenugasanMilik($idPenugasan, $idPerusahaan);
        $record = $this->repo->findByPenugasan($idPenugasan);
        if ($record === null) {
            abort(404, 'Evaluasi trip tidak ditemukan');
        }
        return $record;
    }

    public function findOrFail(string $id): EvaluasiTripModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Evaluasi trip tidak ditemukan');
        }
        return $record;
    }

    public function create(string $idPenugasan, array $data, ?string $idPerusahaan = null): EvaluasiTripModel
    {
        $this->pastikanPenugasanMilik($idPenugasan, $idPerusahaan);

        if ($this->repo->existsByPenugasan($idPenugasan)) {
            abort(409, 'Evaluasi untuk penugasan ini sudah ada');
        }

        return $this->repo->create(array_merge($data, [
            'id_penugasan'       => $idPenugasan,
            'id_dievaluasi_oleh' => auth()->id(),
        ]));
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): EvaluasiTripModel
    {
        $record = $this->findOrFail($id);
        $this->pastikanPenugasanMilik((string) $record->id_penugasan, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function listPenugasanUntukEvaluasi(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->listPenugasanVendorSelesai($idPerusahaan, $page, $limit, $search);

        return [
            'data' => collect($result->items())->map(static fn ($row) => [
                'id_penugasan'          => $row->id_penugasan,
                'tanggal_tugas'         => $row->tanggal_tugas,
                'id_vendor'             => $row->id_vendor,
                'nama_vendor'           => $row->nama_vendor,
                'kode_proyek'           => $row->kode_proyek,
                'nama_proyek'           => $row->nama_proyek,
                'nopol'                 => $row->nopol,
                'nama_supir'            => $row->nama_supir,
                'id_evaluasi'           => $row->id_evaluasi,
                'nilai_ketepatan_waktu' => $row->nilai_ketepatan_waktu === null ? null : (int) $row->nilai_ketepatan_waktu,
                'nilai_kualitas'        => $row->nilai_kualitas === null ? null : (int) $row->nilai_kualitas,
                'nilai_harga'           => $row->nilai_harga === null ? null : (int) $row->nilai_harga,
                'nilai_responsif'       => $row->nilai_responsif === null ? null : (int) $row->nilai_responsif,
                'catatan'               => $row->catatan,
            ])->all(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    private function pastikanPenugasanMilik(string $idPenugasan, ?string $idPerusahaan): void
    {
        if ($idPerusahaan !== null && !$this->repo->penugasanMilikPerusahaan($idPenugasan, $idPerusahaan)) {
            abort(404, 'Penugasan tidak ditemukan');
        }
    }

    public function rekapVendor(string $idPerusahaan): array
    {
        return $this->repo->rekapPerVendor($idPerusahaan)->map(function ($row) {
            $rata = [
                'rata_ketepatan_waktu' => $this->rataDuaDesimal($row->rata_ketepatan_waktu),
                'rata_kualitas'        => $this->rataDuaDesimal($row->rata_kualitas),
                'rata_harga'           => $this->rataDuaDesimal($row->rata_harga),
                'rata_responsif'       => $this->rataDuaDesimal($row->rata_responsif),
            ];
            $terisi = array_values(array_filter($rata, static fn ($nilai) => $nilai !== null));

            return array_merge([
                'id_vendor'       => $row->id_vendor,
                'nama_vendor'     => $row->nama_vendor,
                'jumlah_evaluasi' => (int) $row->jumlah_evaluasi,
            ], $rata, [
                'rata_keseluruhan' => $terisi === [] ? null : round(array_sum($terisi) / count($terisi), 2),
            ]);
        })->all();
    }

    public function listEvaluasiVendor(string $idVendor, string $idPerusahaan): array
    {
        if (!$this->repo->vendorMilikPerusahaan($idVendor, $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }

        return $this->repo->listByVendor($idVendor)->map(static function ($row) {
            return [
                'id_evaluasi'           => $row->id_evaluasi,
                'id_penugasan'          => $row->id_penugasan,
                'tanggal_tugas'         => $row->tanggal_tugas,
                'nama_proyek'           => $row->nama_proyek,
                'nilai_ketepatan_waktu' => $row->nilai_ketepatan_waktu === null ? null : (int) $row->nilai_ketepatan_waktu,
                'nilai_kualitas'        => $row->nilai_kualitas === null ? null : (int) $row->nilai_kualitas,
                'nilai_harga'           => $row->nilai_harga === null ? null : (int) $row->nilai_harga,
                'nilai_responsif'       => $row->nilai_responsif === null ? null : (int) $row->nilai_responsif,
                'nilai_armada'          => $row->nilai_armada === null ? null : (int) $row->nilai_armada,
                'nilai_supir'           => $row->nilai_supir === null ? null : (int) $row->nilai_supir,
                'catatan'               => $row->catatan,
                'dibuat_pada'           => $row->dibuat_pada,
            ];
        })->all();
    }

    private function rataDuaDesimal(mixed $nilai): ?float
    {
        return $nilai === null ? null : round((float) $nilai, 2);
    }
}
