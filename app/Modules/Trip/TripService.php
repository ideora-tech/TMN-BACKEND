<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Cuti\Contracts\CutiRepositoryInterface;
use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\Penugasan\PenugasanService;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Facades\DB;

class TripService
{
    public function __construct(
        private readonly TripRepositoryInterface $repo,
        private readonly JadwalKeberangkatanRepositoryInterface $jadwalRepo,
        private readonly RuteRepositoryInterface $ruteRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly PenugasanService $penugasanService,
        private readonly ArmadaRepositoryInterface $armadaRepo,
        private readonly StatusTripRepositoryInterface $statusTripRepo,
        private readonly CutiRepositoryInterface $cutiRepo
    ) {}

    private function catatRiwayatStatus(string $idTrip, string $status, string $keterangan): void
    {
        $this->statusTripRepo->create([
            'id_trip'    => $idTrip,
            'status'     => $status,
            'keterangan' => $keterangan,
        ]);
    }

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null, ?string $search = null, ?string $status = null, ?string $idProyek = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $idJadwal, $idPenugasan, $idSupir, $search, $status, $idProyek);

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

    public function ringkasanProyek(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null): array
    {
        $result = $this->repo->paginateProyekSummary($idPerusahaan, $page, $limit, $search, $status);

        return [
            'data' => collect($result->items())->map(fn ($row) => [
                'id_proyek'          => $row->id_proyek,
                'kode_proyek'        => $row->kode_proyek,
                'nama_proyek'        => $row->nama_proyek,
                'nama_klien'         => $row->nama_klien,
                'jumlah_trip'        => (int) $row->jumlah_trip,
                'aktivitas_terakhir' => $row->aktivitas_terakhir,
            ])->all(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): TripModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Trip tidak ditemukan');
        }
        if ($idPerusahaan !== null && !$this->repo->milikPerusahaan($id, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): TripModel
    {
        $idJadwal = $data['id_jadwal'];

        $penugasan = $this->repo->findPenugasanDariJadwal($idJadwal, $idPerusahaan);
        if ($penugasan === null) {
            abort(404, 'Jadwal tidak ditemukan');
        }
        if (in_array($penugasan->status, ['selesai', 'batal'], true)) {
            abort(422, 'Penugasan sudah berstatus ' . $penugasan->status . ' — trip tidak dapat dibuat');
        }

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

            if (in_array($penugasan->status, ['selesai', 'batal'], true)) {
                abort(422, 'Penugasan sudah berstatus ' . $penugasan->status . ' — trip tidak dapat dimulai');
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
                'id_jadwal'          => $jadwal->id_jadwal,
                'catatan'            => $data['catatan'] ?? null,
                'uang_jalan_alokasi' => $data['uang_jalan_alokasi'] ?? null,
            ]);

            return $this->checkin($trip->id_trip, $idPerusahaan);
        });
    }

    public function checkin(string $id, ?string $idPerusahaan = null): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if ($trip->status !== 'belum_mulai') {
            abort(422, 'Trip tidak dapat di-checkin pada status saat ini');
        }

        $penugasan = $this->repo->findPenugasanDariTrip($id);
        if ($penugasan !== null && in_array($penugasan->status, ['selesai', 'batal'], true)) {
            abort(422, 'Penugasan sudah berstatus ' . $penugasan->status . ' — trip tidak dapat di-checkin');
        }

        if ($penugasan !== null && !empty($penugasan->id_supir)
            && $this->cutiRepo->supirSedangCuti($penugasan->id_supir, now()->toDateString())) {
            abort(422, 'Supir sedang cuti hari ini — trip tidak dapat dimulai');
        }

        return DB::transaction(function () use ($trip, $penugasan) {
            if ($penugasan !== null && $this->repo->adaTripBerjalanUntukAktorLain(
                $penugasan->id_armada,
                $penugasan->id_supir,
                $penugasan->id_armada_vendor,
                $penugasan->id_supir_vendor,
                $trip->id_trip
            )) {
                abort(422, 'Supir/armada masih memiliki trip yang sedang berjalan');
            }

            $updated = $this->repo->update($trip, [
                'waktu_checkin' => now(),
                'status'        => 'berjalan',
            ]);

            $this->catatRiwayatStatus($trip->id_trip, 'berjalan', 'Check-in — trip dimulai');

            if ($penugasan !== null && !empty($penugasan->id_armada)) {
                if (!$this->armadaRepo->tandaiDigunakanJikaSiap($penugasan->id_armada)) {
                    $armada = $this->armadaRepo->findById($penugasan->id_armada);
                    if ($armada !== null) {
                        abort(422, 'Armada sedang ' . str_replace('_', ' ', $armada->status) . ' — trip tidak dapat dimulai');
                    }
                }
            }

            return $updated;
        });
    }

    public function checkout(string $id, ?string $idPerusahaan = null, bool $selesaikanPenugasan = false): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if ($trip->status !== 'berjalan') {
            abort(422, 'Trip tidak dapat di-checkout pada status saat ini');
        }

        return DB::transaction(function () use ($trip, $selesaikanPenugasan) {
            $updated = $this->repo->update($trip, [
                'waktu_checkout' => now(),
                'status'         => 'selesai',
            ]);

            $this->catatRiwayatStatus($trip->id_trip, 'selesai', 'Check-out — trip selesai');

            $penugasan = $this->repo->findPenugasanDariTrip($trip->id_trip);
            $this->lepasArmadaJikaAman($penugasan, $trip->id_trip);

            if ($selesaikanPenugasan) {
                if ($penugasan === null) {
                    abort(422, 'Penugasan untuk trip ini tidak ditemukan — trip selesai tanpa menutup penugasan tidak dapat diproses');
                }
                if ($this->repo->adaTripNonFinalUntukPenugasan($penugasan->id_penugasan, $trip->id_trip)) {
                    abort(422, 'Masih ada trip lain yang belum selesai pada penugasan ini — penugasan tidak dapat diselesaikan');
                }
                $this->penugasanService->selesaikanDariTrip($penugasan->id_penugasan);
            }

            return $updated;
        });
    }

    /**
     * Sejak armada lintas proyek, status armada murni cermin kondisi fisik:
     * dikunci 'digunakan' saat trip berjalan, dilepas saat checkout/batal.
     * 'perawatan'/'tidak_aktif' di-set manual dan menang atas lifecycle trip.
     * Pelepasan di-skip bila masih ada trip berjalan lain yang memakai armada
     * yang sama (multi-penugasan lintas proyek).
     */
    private function lepasArmadaJikaAman(?object $penugasan, string $excludeTripId): void
    {
        if ($penugasan === null || empty($penugasan->id_armada)) {
            return;
        }

        if ($this->repo->adaTripBerjalanUntukAktorLain($penugasan->id_armada, null, null, null, $excludeTripId)) {
            return;
        }

        $this->armadaRepo->lepaskanJikaDigunakan($penugasan->id_armada);
    }

    public function update(string $id, array $data): TripModel
    {
        $trip = $this->findOrFail($id);
        return $this->repo->update($trip, $data);
    }

    public function batalkan(string $id, string $idPerusahaan): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if ($trip->status === 'selesai') {
            abort(422, 'Trip yang sudah selesai tidak dapat dibatalkan');
        }

        $sedangBerjalan = $trip->status === 'berjalan';

        return DB::transaction(function () use ($trip, $sedangBerjalan) {
            $updated = $this->repo->update($trip, [
                'status' => 'dibatalkan',
            ]);

            $this->catatRiwayatStatus($trip->id_trip, 'dibatalkan', 'Trip dibatalkan');

            if ($sedangBerjalan) {
                $this->lepasArmadaJikaAman($this->repo->findPenugasanDariTrip($trip->id_trip), $trip->id_trip);
            }

            return $updated;
        });
    }

    public function settlementList(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idSupir = null, ?string $statusSettlement = null, ?string $tanggalDari = null, ?string $tanggalSampai = null, ?string $search = null): array
    {
        $result = $this->repo->paginateSettlement($idPerusahaan, $page, $limit, $idSupir, $statusSettlement, $tanggalDari, $tanggalSampai, $search);

        $data = collect($result->items())->map(function ($row) {
            $alokasi   = $row->uang_jalan_alokasi !== null ? (float) $row->uang_jalan_alokasi : null;
            $realisasi = (float) $row->total_realisasi;

            return [
                'id_trip'            => $row->id_trip,
                'rute'               => $row->rute,
                'waktu_berangkat'    => $row->waktu_berangkat,
                'waktu_checkout'     => $row->waktu_checkout,
                'nama_proyek'        => $row->nama_proyek,
                'kode_proyek'        => $row->kode_proyek,
                'supir_nama'         => $row->supir_nama,
                'armada_nopol'       => $row->armada_nopol,
                'uang_jalan_alokasi' => $alokasi,
                'total_realisasi'    => $realisasi,
                'selisih'            => $alokasi !== null ? $alokasi - $realisasi : null,
                'ada_laporan'        => (bool) $row->ada_laporan,
                'status_settlement'  => $row->status_settlement,
                'settlement_pada'    => $row->settlement_pada,
                'catatan_settlement' => $row->catatan_settlement,
            ];
        })->values()->toArray();

        return [
            'data' => $data,
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function tandaiLunas(string $id, string $idPerusahaan, ?string $catatan = null): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if ($trip->status !== 'selesai') {
            abort(422, 'Hanya trip yang sudah selesai yang dapat di-settle');
        }
        if ($trip->status_settlement === 'lunas') {
            abort(422, 'Trip sudah ditandai lunas');
        }

        return $this->repo->update($trip, [
            'status_settlement'  => 'lunas',
            'settlement_pada'    => now(),
            'settlement_oleh'    => auth()->id(),
            'catatan_settlement' => $catatan,
        ]);
    }

    public function batalkanLunas(string $id, string $idPerusahaan): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if ($trip->status_settlement !== 'lunas') {
            abort(422, 'Trip belum berstatus lunas');
        }

        return $this->repo->update($trip, [
            'status_settlement'  => 'belum',
            'settlement_pada'    => null,
            'settlement_oleh'    => null,
            'catatan_settlement' => null,
        ]);
    }

    public function updateUangJalan(string $id, string $idPerusahaan, ?float $alokasi): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if ($trip->status_settlement === 'lunas') {
            abort(422, 'Uang jalan tidak dapat diubah — trip sudah di-settle');
        }

        return $this->repo->update($trip, ['uang_jalan_alokasi' => $alokasi]);
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($trip->status, ['selesai', 'dibatalkan'], true)) {
            abort(422, 'Trip yang belum selesai/dibatalkan tidak dapat dihapus — selesaikan atau batalkan trip terlebih dahulu');
        }

        $this->repo->delete($trip);
    }

    public function rekapBiaya(string $id, string $idPerusahaan): array
    {
        $this->findOrFail($id, $idPerusahaan);

        return $this->repo->rekapBiaya($id);
    }
}
