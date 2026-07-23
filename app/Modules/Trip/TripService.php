<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Facades\DB;

class TripService
{
    public function __construct(
        private readonly TripRepositoryInterface $repo,
        private readonly JadwalKeberangkatanRepositoryInterface $jadwalRepo,
        private readonly RuteRepositoryInterface $ruteRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $idJadwal, $idPenugasan, $idSupir);

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

    public function findOrFail(string $id): TripModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Trip tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): TripModel
    {
        $idJadwal = $data['id_jadwal'];

        $existing = $this->repo->findByJadwal($idJadwal);
        if ($existing !== null) {
            abort(409, 'Trip untuk jadwal ini sudah ada');
        }

        return $this->repo->create($data);
    }

    public function mulaiDariPenugasan(array $data, string $idPerusahaan): TripModel
    {
        return DB::transaction(function () use ($data, $idPerusahaan) {
            $penugasan = $this->repo->findPenugasanMilikPerusahaan($data['id_penugasan'], $idPerusahaan);
            if ($penugasan === null) {
                abort(404, 'Penugasan tidak ditemukan');
            }

            if ($this->repo->adaTripAktifUntukAktor(
                $penugasan->id_armada,
                $penugasan->id_supir,
                $penugasan->id_armada_vendor,
                $penugasan->id_supir_vendor
            )) {
                abort(422, 'Supir/armada masih memiliki trip aktif');
            }

            $idRute = $data['id_rute'] ?? null;
            $namaRute = null;
            if ($idRute !== null) {
                $rute = $this->ruteRepo->findById($idRute);
                if ($rute === null || $rute->id_perusahaan !== $idPerusahaan) {
                    abort(404, 'Rute tidak ditemukan');
                }
                if (!$this->proyekRuteRepo->ruteTerdaftarUntukProyek($penugasan->id_proyek, $idRute)) {
                    abort(422, 'Rute tidak terdaftar untuk proyek penugasan ini');
                }
                $namaRute = $rute->nama_rute;
            }

            $jadwal = $this->jadwalRepo->create([
                'id_penugasan'    => $penugasan->id_penugasan,
                'id_rute'         => $idRute,
                'rute'            => $namaRute,
                'waktu_berangkat' => now(),
            ]);

            $trip = $this->repo->create([
                'id_jadwal' => $jadwal->id_jadwal,
                'catatan'   => $data['catatan'] ?? null,
            ]);

            return $this->checkin($trip->id_trip);
        });
    }

    public function checkin(string $id): TripModel
    {
        $trip = $this->findOrFail($id);

        if ($trip->status !== 'belum_mulai') {
            abort(422, 'Trip tidak dapat di-checkin pada status saat ini');
        }

        return $this->repo->update($trip, [
            'waktu_checkin' => now(),
            'status'        => 'berjalan',
        ]);
    }

    public function checkout(string $id): TripModel
    {
        $trip = $this->findOrFail($id);

        if ($trip->status !== 'berjalan') {
            abort(422, 'Trip tidak dapat di-checkout pada status saat ini');
        }

        return $this->repo->update($trip, [
            'waktu_checkout' => now(),
            'status'         => 'selesai',
        ]);
    }

    public function update(string $id, array $data): TripModel
    {
        $trip = $this->findOrFail($id);
        return $this->repo->update($trip, $data);
    }

    public function batalkan(string $id, string $idPerusahaan): TripModel
    {
        $trip = $this->findOrFail($id);

        if (!$this->repo->milikPerusahaan($id, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }

        if ($trip->status === 'selesai') {
            abort(422, 'Trip yang sudah selesai tidak dapat dibatalkan');
        }

        return $this->repo->update($trip, [
            'status' => 'dibatalkan',
        ]);
    }

    public function delete(string $id): void
    {
        $trip = $this->findOrFail($id);
        $this->repo->delete($trip);
    }

    public function rekapBiaya(string $id, string $idPerusahaan): array
    {
        $this->findOrFail($id); // ensures 404 if trip doesn't exist

        if (!$this->repo->milikPerusahaan($id, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }

        return $this->repo->rekapBiaya($id);
    }
}
