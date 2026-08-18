<?php

declare(strict_types=1);

namespace App\Modules\Trip;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Cuti\Contracts\CutiRepositoryInterface;
use App\Modules\AbsensiSupir\Contracts\AbsensiSupirRepositoryInterface;
use App\Modules\AlokasiArmada\Contracts\AlokasiArmadaRepositoryInterface;
use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\Penugasan\PenugasanService;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Collection;
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
        private readonly CutiRepositoryInterface $cutiRepo,
        private readonly PenugasanRepositoryInterface $penugasanRepo,
        private readonly JadwalShiftRepositoryInterface $jadwalShiftRepo,
        private readonly AlokasiArmadaRepositoryInterface $alokasiRepo,
        private readonly AbsensiSupirRepositoryInterface $absensiRepo,
        private readonly LaporanOperasionalRepositoryInterface $laporanRepo,
        private readonly ArusKasService $arusKasService
    ) {}

    /**
     * Armada efektif sebuah penugasan: kolom armada penugasan bila diisi;
     * untuk supir shift (penugasan tanpa armada) diambil dari alokasi harian.
     */
    private function armadaEfektif(?object $penugasan): ?string
    {
        if ($penugasan === null) {
            return null;
        }
        if (!empty($penugasan->id_armada)) {
            return (string) $penugasan->id_armada;
        }
        if (empty($penugasan->id_supir)) {
            return null;
        }
        $alokasi = $this->alokasiRepo->findAktifBySupirTanggal((string) $penugasan->id_supir, now()->toDateString());
        return $alokasi?->id_armada;
    }

    private function resolvePenugasanPengganti(
        string $idSupirPengganti, string $idProyek, string $idPerusahaan, ?string $idArmadaOverride, object $penugasanAsal
    ): array {
        $existing = $this->penugasanService->findAktifUntukSupirDiProyek($idSupirPengganti, $idProyek);
        if ($existing !== null) {
            return ['penugasan' => $existing, 'dibuatBaru' => false];
        }

        $baru = $this->penugasanService->create([
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupirPengganti,
            'id_armada'     => $idArmadaOverride ?? $penugasanAsal->id_armada,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
            'sumber'        => 'internal',
        ], $idPerusahaan);

        return ['penugasan' => $baru, 'dibuatBaru' => true];
    }

    /**
     * Jadwal harian supir dari dua sumber: penugasan (tanggal_tugas) dan papan
     * jadwal shift (jadwal_shift.tanggal). Entri shift di-map ke penugasan
     * supir pada proyek yang sama agar trip tetap bisa dimulai via id_penugasan;
     * entri dengan kunci (id_penugasan, tanggal) yang sama digabung.
     */
    public function jadwalUntukSupir(string $idSupir, string $dari, string $sampai): array
    {
        $penugasanTanggal = $this->penugasanRepo->listJadwalSupir($idSupir, $dari, $sampai);
        $shiftRows = $this->jadwalShiftRepo->listShiftSupir($idSupir, $dari, $sampai);

        $idProyekShift = collect($shiftRows)->pluck('id_proyek')->unique()->values()->all();
        $urutanStatus = ['aktif' => 0, 'pending' => 1, 'selesai' => 2, 'batal' => 3];
        $penugasanPerProyek = [];
        foreach ($this->penugasanRepo->listBySupirUntukProyek($idSupir, $idProyekShift) as $p) {
            $ada = $penugasanPerProyek[$p->id_proyek] ?? null;
            if ($ada === null || ($urutanStatus[$p->status] ?? 9) < ($urutanStatus[$ada->status] ?? 9)) {
                $penugasanPerProyek[$p->id_proyek] = $p;
            }
        }

        $entri = [];
        foreach ($penugasanTanggal as $p) {
            $tanggal = substr((string) $p->tanggal_tugas, 0, 10);
            $entri[$p->id_penugasan . '|' . $tanggal] = ['penugasan' => $p, 'tanggal' => $tanggal, 'shift' => null];
        }
        foreach ($shiftRows as $row) {
            $p = $penugasanPerProyek[$row->id_proyek] ?? null;
            if ($p === null) {
                continue;
            }
            $tanggal = substr((string) $row->tanggal, 0, 10);
            $kunci = $p->id_penugasan . '|' . $tanggal;
            $entri[$kunci] ??= ['penugasan' => $p, 'tanggal' => $tanggal, 'shift' => null];
            $entri[$kunci]['shift'] = [
                'nama'        => $row->shift_nama,
                'jam_mulai'   => $row->jam_mulai,
                'jam_selesai' => $row->jam_selesai,
            ];
        }

        $tripMap = $this->repo->tripAktifSupir($idSupir);

        $penugasanById = [];
        foreach ($entri as $e) {
            $penugasanById[$e['penugasan']->id_penugasan] = $e['penugasan'];
        }
        foreach ($tripMap as $idPenugasan => $trip) {
            $kunci = $idPenugasan . '|' . $trip['tanggal'];
            if (isset($entri[$kunci])) {
                continue;
            }
            $p = $penugasanById[$idPenugasan] ?? $this->penugasanRepo->findById($idPenugasan);
            if ($p === null) {
                continue;
            }
            $penugasanById[$idPenugasan] = $p;
            $entri[$kunci] = ['penugasan' => $p, 'tanggal' => $trip['tanggal'], 'shift' => null];
        }

        $selesaiMap = $this->repo->tripSelesaiPerPenugasanTanggal(array_keys($penugasanById));
        $laporanMap = $this->repo->laporanTerisiPerPenugasanTanggal(array_keys($penugasanById));
        $alokasiMap = $this->alokasiRepo->alokasiNopolMap($idSupir, $dari, $sampai);
        $klienMap = $this->repo->namaKlienPerProyek(
            array_values(array_unique(array_map(fn ($e) => (string) $e['penugasan']->id_proyek, $entri)))
        );

        $hasil = [];
        foreach ($entri as $e) {
            $trip = $tripMap[$e['penugasan']->id_penugasan] ?? null;
            if ($trip !== null && $trip['tanggal'] !== $e['tanggal']) {
                $trip = null;
            }
            $hasil[] = [
                'id_penugasan'  => $e['penugasan']->id_penugasan,
                'tanggal_tugas' => $e['tanggal'],
                'status'        => $e['penugasan']->status,
                'proyek'        => $e['penugasan']->proyek === null ? null : [
                    'id_proyek'   => $e['penugasan']->proyek->id_proyek,
                    'nama_proyek' => $e['penugasan']->proyek->nama_proyek,
                    'nama_klien'  => $klienMap[$e['penugasan']->proyek->id_proyek] ?? null,
                ],
                'armada'        => $e['penugasan']->armada !== null ? [
                    'id_armada' => $e['penugasan']->armada->id_armada,
                    'nopol'     => $e['penugasan']->armada->nopol,
                ] : ($alokasiMap[$e['tanggal']] ?? null),
                'shift'         => $e['shift'],
                'trip_berjalan' => $trip === null ? null : ['id_trip' => $trip['id_trip'], 'status' => $trip['status']],
                'jumlah_trip_selesai' => $selesaiMap[$e['penugasan']->id_penugasan . '|' . $e['tanggal']] ?? 0,
                'jumlah_laporan_terisi' => $laporanMap[$e['penugasan']->id_penugasan . '|' . $e['tanggal']] ?? 0,
            ];
        }

        usort($hasil, fn ($a, $b) =>
            [$a['tanggal_tugas'], $a['shift']['jam_mulai'] ?? ''] <=> [$b['tanggal_tugas'], $b['shift']['jam_mulai'] ?? '']);

        return $hasil;
    }

    private function catatRiwayatStatus(string $idTrip, string $status, string $keterangan): void
    {
        $this->statusTripRepo->create([
            'id_trip'    => $idTrip,
            'status'     => $status,
            'keterangan' => $keterangan,
        ]);
    }

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null, ?string $search = null, ?string $status = null, ?string $idProyek = null, ?string $tanggalDari = null, ?string $tanggalSampai = null, ?string $sumber = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $idJadwal, $idPenugasan, $idSupir, $search, $status, $idProyek, $tanggalDari, $tanggalSampai, $sumber);

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

    public function riwayatUntukExport(string $idPerusahaan, array $filter): Collection
    {
        $result = $this->repo->paginate(
            $idPerusahaan,
            1,
            10000,
            null,
            null,
            null,
            $filter['search'] ?? null,
            ($filter['status'] ?? null) ?: 'selesai,dibatalkan',
            null,
            $filter['dari'] ?? null,
            $filter['sampai'] ?? null,
            $filter['sumber'] ?? null,
        );

        return collect($result->items());
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

    public function findPenugasanUntukTrip(string $idTrip): ?object
    {
        return $this->repo->findPenugasanDariTrip($idTrip);
    }

    public function detailPenugasanUntukSupir(string $idPenugasan, string $idSupir, string $idPerusahaan, ?string $tanggal = null): array
    {
        $penugasan = $this->penugasanService->findOrFail($idPenugasan);
        if ((string) $penugasan->id_supir !== $idSupir) {
            abort(403, 'Penugasan ini bukan milik Anda');
        }

        $penugasan->load(['proyek', 'armada']);

        $tripResult = $this->list($idPerusahaan, 1, 1, null, $idPenugasan, null, null, 'belum_mulai,berjalan');
        $tripAktif = $tripResult['data'][0] ?? null;

        $ruteTersedia = $this->proyekRuteRepo->listByProyek((string) $penugasan->id_proyek);

        $hariIni = $tanggal ?? now()->toDateString();
        $shiftHariIni = null;
        foreach ($this->jadwalShiftRepo->listShiftSupir($idSupir, $hariIni, $hariIni) as $row) {
            if ((string) $row->id_proyek === (string) $penugasan->id_proyek) {
                $shiftHariIni = [
                    'nama'        => $row->shift_nama,
                    'jam_mulai'   => $row->jam_mulai,
                    'jam_selesai' => $row->jam_selesai,
                ];
                break;
            }
        }

        $armadaHariIni = $penugasan->armada !== null
            ? ['id_armada' => $penugasan->armada->id_armada, 'nopol' => $penugasan->armada->nopol]
            : ($this->alokasiRepo->alokasiNopolMap($idSupir, $hariIni, $hariIni)[$hariIni] ?? null);

        return [
            'penugasan' => $penugasan,
            'trip' => $tripAktif,
            'rute_tersedia' => $ruteTersedia,
            'nama_klien' => $this->repo->namaKlienPerProyek([(string) $penugasan->id_proyek])[(string) $penugasan->id_proyek] ?? null,
            'shift_hari_ini' => $shiftHariIni,
            'armada_hari_ini' => $armadaHariIni,
            'jumlah_trip_selesai_hari_ini' => $this->repo->tripSelesaiPerPenugasanTanggal([$idPenugasan])[$idPenugasan . '|' . $hariIni] ?? 0,
        ];
    }

    public function mulaiDariPenugasanUntukSupir(array $data, string $idSupir, string $idPerusahaan): TripModel
    {
        $penugasan = $this->penugasanService->findOrFail((string) $data['id_penugasan']);
        if ((string) $penugasan->id_supir !== $idSupir) {
            abort(403, 'Penugasan ini bukan milik Anda');
        }

        $absen = $this->absensiRepo->findBySupirTanggal($idSupir, now()->toDateString());
        if ($absen === null) {
            abort(422, 'Anda belum absen hari ini — lakukan absensi terlebih dahulu sebelum memulai trip');
        }
        if ($absen->status !== 'hadir') {
            abort(422, 'Absensi Anda hari ini berstatus berhalangan — trip tidak dapat dimulai');
        }

        unset($data['uang_jalan_alokasi']);

        $tripBelumMulai = $this->list($idPerusahaan, 1, 1, null, (string) $data['id_penugasan'], null, null, 'belum_mulai')['data'][0] ?? null;
        if ($tripBelumMulai !== null) {
            return $this->checkin($tripBelumMulai->id_trip, $idPerusahaan);
        }

        return $this->mulaiDariPenugasan($data, $idPerusahaan, false);
    }

    public function checkoutUntukSupir(string $idTrip, string $idSupir, string $idPerusahaan): TripModel
    {
        $trip = $this->findOrFail($idTrip, $idPerusahaan);

        $penugasan = $this->findPenugasanUntukTrip($idTrip);
        if ($penugasan === null || (string) $penugasan->id_supir !== $idSupir) {
            abort(403, 'Trip ini bukan milik Anda');
        }

        if ($trip->status === 'selesai') {
            return $trip;
        }

        return $this->checkout($idTrip, $idPerusahaan, false);
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

        $trip = $this->repo->create($data);
        $this->repo->salinTitikDropDariPenugasan((string) $penugasan->id_penugasan, (string) $trip->id_trip);
        return $trip;
    }

    public function mulaiDariPenugasan(array $data, string $idPerusahaan, bool $terapkanOverride = true): TripModel
    {
        return DB::transaction(function () use ($data, $idPerusahaan, $terapkanOverride) {
            $penugasan = $this->repo->findPenugasanMilikPerusahaan($data['id_penugasan'], $idPerusahaan);
            if ($penugasan === null) {
                abort(404, 'Penugasan tidak ditemukan');
            }

            if ($penugasan->status === 'pending') {
                abort(422, 'Penugasan masih pending — armada dan supir belum lengkap, trip tidak dapat dimulai');
            }
            if (in_array($penugasan->status, ['selesai', 'batal'], true)) {
                abort(422, 'Penugasan sudah berstatus ' . $penugasan->status . ' — trip tidak dapat dimulai');
            }

            $penugasanAsal = $penugasan;
            $tanggalIni    = now()->toDateString();
            $override      = $terapkanOverride
                ? $this->jadwalShiftRepo->findOverrideAktif(
                    (string) $penugasanAsal->id_supir, (string) $penugasanAsal->id_proyek, $tanggalIni
                  )
                : null;

            $penugasanBaruUntukDitutup = null;
            $idArmadaOverrideLangsung  = null;

            if ($override !== null && $override->id_supir_pengganti !== null) {
                $hasil = $this->resolvePenugasanPengganti(
                    (string) $override->id_supir_pengganti, (string) $penugasanAsal->id_proyek,
                    $idPerusahaan, $override->id_armada_override, $penugasanAsal
                );
                $penugasan = $hasil['penugasan'];
                if ($hasil['dibuatBaru']) {
                    $penugasanBaruUntukDitutup = (string) $penugasan->id_penugasan;
                }
            } elseif ($override !== null && $override->id_armada_override !== null) {
                $idArmadaOverrideLangsung = (string) $override->id_armada_override;
            }

            $armadaUntukCek = $idArmadaOverrideLangsung ?? $this->armadaEfektif($penugasan);
            $tripAktif = $this->repo->findTripAktifUntukAktor(
                $armadaUntukCek,
                $penugasan->id_supir,
                $penugasan->id_armada_vendor,
                $penugasan->id_supir_vendor
            );
            if ($tripAktif !== null) {
                $status = str_replace('_', ' ', $tripAktif->status);
                abort(422, "Supir/armada masih memiliki trip aktif di proyek {$tripAktif->nama_proyek} (status: {$status}) — selesaikan atau batalkan trip tersebut dahulu");
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

            $titikDropOverride = $override !== null
                ? $this->jadwalShiftRepo->listTitikDropOverride((string) $override->id_jadwal_shift)
                : [];

            if ($titikDropOverride !== []) {
                $this->repo->syncTitikDropTrip((string) $trip->id_trip, $titikDropOverride);
            } else {
                $this->repo->salinTitikDropDariPenugasan((string) $penugasan->id_penugasan, (string) $trip->id_trip);
            }

            $tripBerjalan = $this->checkin($trip->id_trip, $idPerusahaan);

            if ($penugasanBaruUntukDitutup !== null) {
                $this->penugasanService->update($penugasanBaruUntukDitutup, ['status' => 'selesai'], $idPerusahaan);
            }

            $this->arusKasService->buatPengajuanUangJalanOtomatis($tripBerjalan);

            return $tripBerjalan;
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
            $armadaEfektif = $this->armadaEfektif($penugasan);

            if ($penugasan !== null && $this->repo->adaTripBerjalanUntukAktorLain(
                $armadaEfektif,
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

            if ($penugasan !== null && !empty($armadaEfektif)) {
                if (!$this->armadaRepo->tandaiDigunakanJikaSiap($armadaEfektif)) {
                    $armada = $this->armadaRepo->findById($armadaEfektif);
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
        $armadaEfektif = $this->armadaEfektif($penugasan);
        if ($armadaEfektif === null) {
            return;
        }

        if ($this->repo->adaTripBerjalanUntukAktorLain($armadaEfektif, null, null, null, $excludeTripId)) {
            return;
        }

        $this->armadaRepo->lepaskanJikaDigunakan($armadaEfektif);
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

    public function updateUangJalan(string $id, string $idPerusahaan, ?float $alokasi): TripModel
    {
        $trip = $this->findOrFail($id, $idPerusahaan);

        $updated = $this->repo->update($trip, ['uang_jalan_alokasi' => $alokasi]);

        $this->arusKasService->sinkronNominalPengajuanTrip($id, $alokasi);

        return $updated;
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

    public function updateTitikDrop(string $idTrip, array $lokasiList, string $idPerusahaan): TripModel
    {
        $trip = $this->findOrFail($idTrip, $idPerusahaan);
        if ($this->repo->tripPunyaFakturAktif($idTrip)) {
            abort(422, 'Trip sudah masuk invoice — titik drop tidak dapat diubah');
        }
        $this->repo->syncTitikDropTrip($idTrip, $lokasiList);
        return $trip;
    }

    public function titikDropTrip(string $idTrip): array
    {
        return $this->repo->titikDropTrip($idTrip);
    }

    public function titikDropTripBanyak(array $idTrips): array
    {
        return $this->repo->titikDropTripBanyak($idTrips);
    }

    public function tripPunyaFakturAktif(string $idTrip): bool
    {
        return $this->repo->tripPunyaFakturAktif($idTrip);
    }

    public function infoPengajuanUangJalan(string $idTrip): ?array
    {
        return $this->arusKasService->infoPengajuanTrip($idTrip);
    }

    public function rekapSupir(string $idPerusahaan, array $filter): Collection
    {
        return $this->laporanRepo->rekapTripPerSupir($idPerusahaan, $filter);
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->laporanRepo->getPerusahaan($idPerusahaan);
    }
}
