<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use Carbon\Carbon;

class JadwalKeberangkatanService
{
    public function __construct(
        private readonly JadwalKeberangkatanRepositoryInterface $repo,
        private readonly PenugasanRepositoryInterface $penugasanRepo,
        private readonly RuteRepositoryInterface $ruteRepo
    ) {}

    public function list(string $idPenugasan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPenugasan($idPenugasan, $page, $limit);
        return $this->toPagedArray($result);
    }

    public function listByPerusahaan(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);
        return $this->toPagedArray($result);
    }

    private function toPagedArray($paginator): array
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

    public function findOrFail(string $id): JadwalKeberangkatanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jadwal keberangkatan tidak ditemukan');
        }
        return $record;
    }

    public function listBySupir(string $idSupir, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->findBySupir($idSupir, $page, $limit);

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

    public function create(array $data): JadwalKeberangkatanModel
    {
        $data = $this->applyRuteSnapshot($data);
        $this->assertTidakBentrok($data);
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): JadwalKeberangkatanModel
    {
        $record = $this->findOrFail($id);
        $data   = $this->applyRuteSnapshot($data);

        $merged = [
            'id_penugasan'    => $data['id_penugasan'] ?? $record->id_penugasan,
            'waktu_berangkat' => array_key_exists('waktu_berangkat', $data) ? $data['waktu_berangkat'] : $record->waktu_berangkat,
            'estimasi_tiba'   => array_key_exists('estimasi_tiba', $data) ? $data['estimasi_tiba'] : $record->estimasi_tiba,
        ];
        $this->assertTidakBentrok($merged, $id);

        return $this->repo->update($record, $data);
    }

    /**
     * id_rute adalah sumber kebenaran (dipilih via dropdown master data Rute).
     * Kolom 'rute' tetap disimpan sebagai snapshot nama_rute agar konsumen lama
     * (tabel riwayat jadwal, halaman detail jadwal, Trip list) tetap tampil
     * tanpa perlu join, dan otomatis sinkron setiap kali id_rute berubah.
     */
    private function applyRuteSnapshot(array $data): array
    {
        if (!array_key_exists('id_rute', $data)) {
            return $data;
        }

        $data['rute'] = $data['id_rute'] !== null
            ? $this->ruteRepo->findById($data['id_rute'])?->nama_rute
            : null;

        return $data;
    }

    private function assertTidakBentrok(array $data, ?string $excludeJadwalId = null): void
    {
        $idPenugasan = $data['id_penugasan'] ?? null;
        $mulaiRaw    = $data['waktu_berangkat'] ?? null;
        if (!$idPenugasan || !$mulaiRaw) {
            return;
        }

        $penugasan = $this->penugasanRepo->findById($idPenugasan);
        if (!$penugasan) {
            return;
        }

        $mulai   = Carbon::parse($mulaiRaw);
        $selesai = isset($data['estimasi_tiba']) && $data['estimasi_tiba']
            ? Carbon::parse($data['estimasi_tiba']) : $mulai->copy()->addHours(8);

        foreach ($this->repo->findKandidatBentrok(
            $penugasan->id_armada,
            $penugasan->id_supir,
            $penugasan->id_armada_vendor,
            $penugasan->id_supir_vendor,
            $excludeJadwalId
        ) as $j) {
            $jMulai   = Carbon::parse($j->waktu_berangkat);
            $jSelesai = $j->estimasi_tiba ? Carbon::parse($j->estimasi_tiba) : $jMulai->copy()->addHours(8);
            if ($mulai->lt($jSelesai) && $jMulai->lt($selesai)) {
                abort(422, "Jadwal bentrok: armada/supir sudah dijadwalkan pada {$jMulai->format('d/m/Y H:i')} (jadwal {$j->id_jadwal})");
            }
        }
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
