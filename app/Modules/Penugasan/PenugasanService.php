<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PenugasanService
{
    public function __construct(
        private readonly PenugasanRepositoryInterface $repo,
        private readonly KontrakVendorRepositoryInterface $kontrakVendorRepo,
        private readonly ArmadaVendorRepositoryInterface $armadaVendorRepo,
        private readonly SupirVendorRepositoryInterface $supirVendorRepo,
        private readonly TripRepositoryInterface $tripRepo,
        private readonly ProyekRepositoryInterface $proyekRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly SupirRepositoryInterface $supirRepo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public function list(string $idProyek, int $page = 1, int $limit = 10, ?string $sumber = null, ?string $status = null): array
    {
        $result = $this->repo->paginateByProyek($idProyek, $page, $limit, $sumber, $status);

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

    public function listByPerusahaan(string $idPerusahaan, int $page = 1, int $limit = 20, ?string $sumber = null, ?string $status = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $sumber, $status);

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

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 20, ?string $sumber = null, ?string $status = null): array
    {
        $result = $this->repo->paginateByArmada($idArmada, $page, $limit, $sumber, $status);

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

    public function listBySupir(string $idSupir, int $page = 1, int $limit = 20, ?string $sumber = null, ?string $status = null): array
    {
        $result = $this->repo->paginateBySupir($idSupir, $page, $limit, $sumber, $status);

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

    /** Unit vendor unit_only siap-pakai untuk digabung ke dropdown Armada di Penugasan Operasional. */
    public function opsiArmadaVendor(string $idPerusahaan): array
    {
        return $this->armadaVendorRepo->listOpsiUnitOnly($idPerusahaan);
    }

    /**
     * Payload Board Unit: daftar unit (internal + vendor unit_only) digabung
     * dengan penugasan harian dalam rentang tanggal beserta trip-nya. Batas
     * 62 hari sama dengan assignHarian() supaya query tidak dipakai untuk
     * menarik seluruh histori sekaligus.
     */
    public function stempelAktivitasBoard(string $idPerusahaan): ?string
    {
        return $this->repo->stempelAktivitasBoard($idPerusahaan);
    }

    public function board(string $idPerusahaan, string $dari, string $sampai): array
    {
        $mulai   = Carbon::parse($dari);
        $selesai = Carbon::parse($sampai);
        if ($mulai->diffInDays($selesai) > 62) {
            abort(422, 'Rentang tanggal maksimal 62 hari');
        }

        $unitVendor = array_map(fn (array $baris) => [
            'tipe'                   => 'vendor',
            'id_armada_vendor'       => $baris['id_armada_vendor'],
            'nopol'                  => $baris['nopol'],
            'nama_jenis'             => $baris['jenis'],
            'nama_vendor'            => $baris['nama_vendor'],
            'id_vendor'              => $baris['id_vendor'],
            'mekanisme'              => $baris['mekanisme'],
            'id_kontrak_vendor'      => $baris['id_kontrak_vendor'],
            'id_kontrak_vendor_unit' => $baris['id_kontrak_vendor_unit'],
            'kontrak_habis'          => $baris['kontrak_habis'],
            'status_kontrak'         => $baris['status_kontrak'],
        ], $this->armadaVendorRepo->listOpsiBoard($idPerusahaan));

        $assignments = $this->repo->boardAssignments($idPerusahaan, $dari, $sampai);
        $idPenugasanList = array_map(fn (array $row) => (string) $row['id_penugasan'], $assignments);
        $tripsMap = $this->repo->tripsUntukPenugasanList($idPenugasanList);

        foreach ($assignments as &$row) {
            $row['trips'] = $tripsMap[$row['id_penugasan']] ?? [];
        }
        unset($row);

        return [
            'units'       => array_merge($this->repo->boardUnits($idPerusahaan), $unitVendor),
            'assignments' => $assignments,
        ];
    }

    public function findOrFail(string $id): PenugasanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Penugasan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): PenugasanModel
    {
        $data = $this->normalizeSumber($data);

        $this->assertVendorRules($data, $idPerusahaan);

        /**
         * Parity dengan penugasan internal (assignHarian): penugasan vendor
         * wajib punya rute terdaftar di proyek, dan uang jalan otomatis
         * diambil dari rate card proyek (per jenis kendaraan unit vendor)
         * bila tidak diisi manual.
         */
        if (($data['sumber'] ?? 'internal') === 'vendor') {
            if (empty($data['id_rute'])) {
                abort(422, 'Rute wajib dipilih untuk penugasan vendor');
            }
            if (!$this->proyekRuteRepo->ruteTerdaftarUntukProyek((string) $data['id_proyek'], (string) $data['id_rute'])) {
                abort(422, 'Rute tidak terdaftar di proyek ini');
            }
            if (!array_key_exists('estimasi_biaya', $data) || $data['estimasi_biaya'] === null) {
                $armadaVendor = !empty($data['id_armada_vendor'])
                    ? $this->armadaVendorRepo->findByIdMilikPerusahaan((string) $data['id_armada_vendor'], $idPerusahaan)
                    : null;
                $data['estimasi_biaya'] = $this->proyekRuteRepo->tarifUangJalanRute(
                    (string) $data['id_proyek'],
                    (string) $data['id_rute'],
                    $armadaVendor?->id_jenis_kendaraan,
                );
            }
        }

        if (!empty($data['id_armada'])) {
            $this->assertArmadaAdaOrFail($data['id_armada']);
        }

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            if ($this->repo->hasConflict($data['id_karyawan'], $data['tanggal_tugas'])) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        $this->assertAktorVendorTidakDobel($data, $data['tanggal_tugas'] ?? null);

        if (($data['status'] ?? 'pending') === 'pending') {
            $data['status'] = $this->sudahAdaSupir($data) ? 'aktif' : 'pending';
        }

        $titikDrop = $data['titik_drop'] ?? null;
        unset($data['titik_drop']);

        $record = $this->repo->create($data);

        if ($titikDrop !== null) {
            $this->repo->syncTitikDrop((string) $record->id_penugasan, $titikDrop);
        }
        $record->titik_drop = $this->lokasiSajaTitikDrop($titikDrop);
        $record->titik_drop_detail = $this->detailTitikDrop($titikDrop);

        if (!empty($record->id_supir)) {
            $this->notifikasiPenugasan($record);
        }

        return $record;
    }

    /**
     * Penugasan harian: 1 unit (internal ATAU vendor unit_only) × 1 supir ×
     * 1 rute, di-assign untuk satu rentang tanggal sekaligus (maks 62 hari,
     * pola sama dengan JadwalShiftService::createBatch). Gagal per-tanggal
     * (unit/supir dobel) tidak menggagalkan tanggal lain dalam rentang yang
     * sama. Uang jalan (manual atau hasil resolusi rate card) dikumpulkan
     * jadi SATU pengajuan per batch, bukan per baris penugasan.
     */
    public function assignHarian(array $data, string $idPerusahaan): array
    {
        return DB::transaction(function () use ($data, $idPerusahaan) {
            $proyek = $this->proyekRepo->findById((string) $data['id_proyek']);
            if ($proyek === null || (string) $proyek->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Proyek tidak ditemukan');
            }

            if (!$this->proyekRuteRepo->ruteTerdaftarUntukProyek((string) $data['id_proyek'], (string) $data['id_rute'])) {
                abort(422, 'Rute tidak terdaftar di proyek ini');
            }

            $idSupirInternal = !empty($data['id_supir']) ? (string) $data['id_supir'] : null;
            $idSupirVendor   = !empty($data['id_supir_vendor']) ? (string) $data['id_supir_vendor'] : null;

            if ($idSupirInternal !== null) {
                $supir = $this->supirRepo->findById($idSupirInternal);
                if ($supir === null || (string) $supir->id_perusahaan !== $idPerusahaan) {
                    abort(404, 'Supir tidak ditemukan');
                }
                if ($supir->status !== 'aktif') {
                    abort(422, 'Supir tidak aktif');
                }
            }

            $rowDasar = [
                'id_proyek'   => $data['id_proyek'],
                'id_rute'     => $data['id_rute'],
                'status'      => 'aktif',
                'keterangan'  => $data['keterangan'] ?? null,
            ];

            $idJenisKendaraanUnit = null;

            if (!empty($data['id_armada'])) {
                /**
                 * Scope id_perusahaan ditambahkan di sini (beda dari
                 * assertArmadaAdaOrFail milik create() lama yang tidak
                 * scoped) — penugasan harian dientry langsung dari dropdown
                 * Operasional per perusahaan, jadi wajib tenant-safe.
                 */
                $armada = ArmadaModel::active()->where('id_perusahaan', $idPerusahaan)->find($data['id_armada']);
                if ($armada === null) {
                    abort(404, 'Armada tidak ditemukan');
                }

                if ($idSupirInternal === null) {
                    abort(422, 'Unit internal harus ditugaskan ke supir internal');
                }

                $rowDasar['sumber']    = 'internal';
                $rowDasar['id_armada'] = $data['id_armada'];
                $rowDasar['id_supir']  = $idSupirInternal;
                $idJenisKendaraanUnit  = $armada->id_jenis_kendaraan;
            } else {
                $armadaVendor = $this->armadaVendorRepo->findByIdMilikPerusahaan((string) $data['id_armada_vendor'], $idPerusahaan);
                if ($armadaVendor === null) {
                    abort(404, 'Armada vendor tidak ditemukan');
                }

                $opsi = null;
                foreach ($this->armadaVendorRepo->listOpsiBoard($idPerusahaan) as $baris) {
                    if ($baris['id_armada_vendor'] === (string) $data['id_armada_vendor']) {
                        $opsi = $baris;
                        break;
                    }
                }
                if ($opsi === null) {
                    abort(422, 'Unit vendor ini tidak memiliki kontrak aktif');
                }
                if (in_array($opsi['status_kontrak'] ?? null, ['draft', 'menunggu_approval'], true)) {
                    abort(422, 'Kontrak unit ini belum disetujui — ajukan dan selesaikan approval kontrak terlebih dahulu');
                }

                if ($opsi['mekanisme'] === 'unit_only') {
                    if ($idSupirInternal === null) {
                        abort(422, 'Unit vendor Unit Only ditugaskan ke supir internal — pilih supir');
                    }
                    $rowDasar['id_supir'] = $idSupirInternal;
                } else {
                    if ($idSupirVendor === null) {
                        abort(422, 'Unit paket vendor ini ditugaskan ke supir vendor — pilih supir vendor');
                    }
                    if (!$this->supirVendorRepo->milikVendor($idSupirVendor, (string) $opsi['id_vendor'])) {
                        abort(422, 'Supir vendor tidak terdaftar pada vendor unit ini');
                    }
                    $this->assertKontrakSupirCocokDenganUnit(
                        !empty($armadaVendor->id_kontrak_vendor) ? (string) $armadaVendor->id_kontrak_vendor : null,
                        $idSupirVendor,
                        $idPerusahaan,
                    );
                    $rowDasar['id_supir_vendor'] = $idSupirVendor;
                }

                $rowDasar['sumber']            = 'vendor';
                $rowDasar['id_armada_vendor']  = $data['id_armada_vendor'];
                $rowDasar['id_kontrak_vendor'] = $opsi['id_kontrak_vendor'];
                $idJenisKendaraanUnit          = $armadaVendor->id_jenis_kendaraan;
            }

            $mulai   = Carbon::parse($data['tanggal']);
            $selesai = Carbon::parse($data['tanggal_sampai'] ?? $data['tanggal']);
            if ($mulai->diffInDays($selesai) > 62) {
                abort(422, 'Rentang tanggal maksimal 62 hari');
            }

            $periode = [];
            for ($t = $mulai->copy(); $t->lte($selesai); $t->addDay()) {
                $periode[] = $t->toDateString();
            }

            $tarif = array_key_exists('uang_jalan', $data) && $data['uang_jalan'] !== null
                ? (float) $data['uang_jalan']
                : $this->proyekRuteRepo->tarifUangJalanRute((string) $data['id_proyek'], (string) $data['id_rute'], $idJenisKendaraanUnit);

            $titikDrop = $data['titik_drop'] ?? null;

            $sukses        = 0;
            $gagal         = [];
            $tanggalSukses = [];
            $rekaman       = [];

            foreach ($periode as $tanggal) {
                if ($idSupirInternal !== null && $this->repo->adaPenugasanSupirPadaTanggal($idSupirInternal, $tanggal, (string) $data['id_proyek'], (string) $data['id_rute'])) {
                    $gagal[] = ['tanggal' => $tanggal, 'alasan' => 'Supir sudah memiliki penugasan pada proyek, rute, dan tanggal ini'];
                    continue;
                }

                $record = $this->repo->create(array_merge($rowDasar, [
                    'tanggal_tugas'  => $tanggal,
                    'estimasi_biaya' => $tarif,
                ]));

                if ($titikDrop !== null) {
                    $this->repo->syncTitikDrop((string) $record->id_penugasan, $titikDrop);
                }

                $sukses++;
                $tanggalSukses[] = $tanggal;
                $rekaman[]       = $record;

                if (!empty($record->id_supir) || !empty($record->id_supir_vendor)) {
                    $this->notifikasiPenugasan($record);
                }
            }

            $peringatan = [];

            if ($tanggalSukses !== [] && $idSupirInternal !== null) {
                if ($tarif !== null) {
                    $pengajuan = $this->arusKasService->buatPengajuanUangJalanPenugasan(
                        $idPerusahaan,
                        $idSupirInternal,
                        (string) $data['id_proyek'],
                        $tarif,
                        $tanggalSukses,
                    );

                    foreach ($rekaman as $record) {
                        $this->repo->update($record, ['id_pengajuan' => $pengajuan->id_pengajuan]);
                    }
                } else {
                    $peringatan[] = 'Tarif uang jalan rute tidak ditemukan — pengajuan tidak dibuat';
                }
            }

            /** Atribut virtual titik_drop diisi TERAKHIR — tidak boleh sebelum repo->update() di atas, karena Eloquent akan menganggapnya kolom dirty dan ikut ditulis (kolomnya memang tidak ada, hidup di tabel titik_drop_penugasan terpisah). */
            $lokasiSaja = $this->lokasiSajaTitikDrop($titikDrop);
            $detailDrop = $this->detailTitikDrop($titikDrop);
            foreach ($rekaman as $record) {
                $record->titik_drop = $lokasiSaja;
                $record->titik_drop_detail = $detailDrop;
            }

            return [
                'sukses'     => $sukses,
                'gagal'      => $gagal,
                'peringatan' => $peringatan,
                'penugasan'  => $rekaman,
            ];
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): PenugasanModel
    {
        $record = $this->findOrFail($id);
        $data   = $this->normalizeSumber($data);

        $titikDropDikirim = array_key_exists('titik_drop', $data);
        $titikDrop = $data['titik_drop'] ?? null;
        unset($data['titik_drop']);

        if ($record->status === 'batal'
            && array_key_exists('status', $data)
            && $data['status'] !== 'batal') {
            abort(422, 'Penugasan yang sudah dibatalkan tidak dapat diaktifkan kembali — buat penugasan baru');
        }

        $merged = [
            'sumber'            => array_key_exists('sumber', $data) ? $data['sumber'] : $record->sumber,
            'id_kontrak_vendor' => array_key_exists('id_kontrak_vendor', $data) ? $data['id_kontrak_vendor'] : $record->id_kontrak_vendor,
            'id_armada_vendor'  => array_key_exists('id_armada_vendor', $data) ? $data['id_armada_vendor'] : $record->id_armada_vendor,
            'id_supir_vendor'   => array_key_exists('id_supir_vendor', $data) ? $data['id_supir_vendor'] : $record->id_supir_vendor,
            'id_supir'          => array_key_exists('id_supir', $data) ? $data['id_supir'] : $record->id_supir,
            'id_armada'         => array_key_exists('id_armada', $data) ? $data['id_armada'] : $record->id_armada,
        ];
        $this->assertVendorRules($merged, $idPerusahaan);

        $tanggalTugasSebelum = $record->tanggal_tugas instanceof \DateTimeInterface
            ? $record->tanggal_tugas->format('Y-m-d')
            : $record->tanggal_tugas;
        $tanggalEfektif = $data['tanggal_tugas'] ?? $tanggalTugasSebelum;
        $tanggalTugasBerubah = array_key_exists('tanggal_tugas', $data) && $data['tanggal_tugas'] !== $tanggalTugasSebelum;
        $this->assertAktorVendorTidakDobel($merged, $tanggalEfektif, $id);

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            $idKaryawan  = $data['id_karyawan'] ?? $record->id_karyawan;
            $tanggalTugas = $data['tanggal_tugas'] ?? $record->tanggal_tugas;
            if ($this->repo->hasConflict($idKaryawan, $tanggalTugas, $id)) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        /**
         * Semantik: satu baris penugasan = satu unit x satu tanggal x satu
         * proyek x satu rute, jadi satu supir boleh punya banyak baris aktif
         * di tanggal yang sama SELAMA proyek ATAU rute-nya berbeda (mis. pagi
         * proyek A, sore proyek B; atau proyek sama tapi rute berbeda) —
         * dobel hanya dicegah pada kombinasi (supir, tanggal, proyek, rute)
         * yang sama persis (rute null dianggap tidak bisa dibedakan, jadi
         * tetap konservatif — dicegah selama proyek & tanggalnya sama). Guard
         * dijalankan terhadap TANGGAL/PROYEK/RUTE EFEKTIF baris (nilai baru
         * bila ikut diubah, else existing) memakai nilai supir efektif dari
         * $merged. Guard dipicu bila id_supir terkirim (bukan hanya saat
         * berubah — board selalu mengirim id_supir tiap PUT) ATAU
         * tanggal_tugas berubah — supir yang sama pun wajib divalidasi ulang
         * saat tanggalnya dipindah ke tanggal yang sudah dipakai supir itu di
         * proyek & rute yang sama.
         */
        $idProyekEfektif = array_key_exists('id_proyek', $data) ? $data['id_proyek'] : $record->id_proyek;
        $idRuteEfektif   = array_key_exists('id_rute', $data) ? $data['id_rute'] : $record->id_rute;
        if (($tanggalTugasBerubah || array_key_exists('id_supir', $data)) && !empty($merged['id_supir']) && !empty($tanggalEfektif) && !empty($idProyekEfektif)) {
            if ($this->repo->adaPenugasanSupirPadaTanggal((string) $merged['id_supir'], $tanggalEfektif, (string) $idProyekEfektif, $idRuteEfektif !== null ? (string) $idRuteEfektif : null, $id)) {
                abort(422, 'Supir sudah memiliki penugasan pada proyek, rute, dan tanggal tersebut');
            }
        }

        $aktorBerubah = false;
        foreach (['id_armada', 'id_supir', 'id_armada_vendor', 'id_supir_vendor'] as $kolomAktor) {
            if (array_key_exists($kolomAktor, $data) && $data[$kolomAktor] !== $record->{$kolomAktor}) {
                $aktorBerubah = true;
                break;
            }
        }

        if ($aktorBerubah) {
            if ($this->tripRepo->adaTripNonFinalUntukPenugasan($id)) {
                abort(422, 'Penugasan masih memiliki trip yang belum selesai — armada/supir tidak dapat diganti');
            }
            if (array_key_exists('id_armada', $data) && !empty($data['id_armada']) && $data['id_armada'] !== $record->id_armada) {
                $this->assertArmadaAdaOrFail($data['id_armada']);
            }
        }

        $statusTarget = array_key_exists('status', $data) ? $data['status'] : $record->status;
        if ($statusTarget === 'pending' && $this->sudahAdaSupir($merged)) {
            $data['status'] = 'aktif';
        }

        /**
         * Ganti supir pada baris yang masih ber-pengajuan status menunggu
         * approval/ditolak (atau diajukan legacy): lepas link id_pengajuan
         * baris ini lalu sinkronkan pengajuan lama (nominal/periode turun
         * otomatis dari sisa baris yang masih ter-link; soft-delete bila 0
         * sisa) — mirror semantik PenugasanService::delete(). Bila pengajuan
         * sudah disetujui/dicek/ditransfer, SENGAJA dibiarkan beku dan link
         * TIDAK dilepas — nominalnya sudah jadi acuan proses keuangan
         * berjalan (sama seperti perlakuan ArusKasService::
         * sinkronPengajuanSetelahPenugasanDihapus terhadap pengajuan yang
         * sudah beku statusnya).
         */
        $idSupirBerubah = array_key_exists('id_supir', $data) && $data['id_supir'] !== $record->id_supir;
        $idPengajuanUntukSinkron = null;
        if ($idSupirBerubah && !empty($record->id_pengajuan)) {
            $statusPengajuan = $this->arusKasService->statusPengajuan((string) $record->id_pengajuan);
            if ($statusPengajuan !== null && !in_array($statusPengajuan, [
                ArusKasService::STATUS_DISETUJUI,
                ArusKasService::STATUS_DICEK,
                ArusKasService::STATUS_SIAP_TRANSFER,
                ArusKasService::STATUS_DITRANSFER,
            ], true)) {
                $idPengajuanUntukSinkron = (string) $record->id_pengajuan;
                $data['id_pengajuan'] = null;
            }
        }

        $supirSebelum = $record->id_supir;

        return DB::transaction(function () use ($record, $data, $id, $titikDropDikirim, $titikDrop, $supirSebelum, $idPengajuanUntukSinkron) {
            $updated = $this->repo->update($record, $data);

            if ($titikDropDikirim) {
                $this->repo->syncTitikDrop($id, $titikDrop ?? []);
            }
            $updated->titik_drop = $this->repo->titikDropUntukBanyak([$id])[$id] ?? [];
            $updated->titik_drop_detail = $this->repo->titikDropDetailUntukBanyak([$id])[$id] ?? [];

            if ($idPengajuanUntukSinkron !== null) {
                $this->arusKasService->sinkronPengajuanSetelahPenugasanDihapus($idPengajuanUntukSinkron);
            }

            if (array_key_exists('id_supir', $data)
                && !empty($updated->id_supir)
                && $updated->id_supir !== $supirSebelum) {
                $this->notifikasiPenugasan($updated);
            }

            return $updated;
        });
    }

    private function notifikasiPenugasan(PenugasanModel $record): void
    {
        try {
            $proyek = $record->proyek;
            if ($proyek === null) {
                return;
            }

            $isi = "Anda ditugaskan pada proyek {$proyek->nama_proyek}";
            if (!empty($record->tanggal_tugas)) {
                $tanggal = $record->tanggal_tugas instanceof \DateTimeInterface
                    ? $record->tanggal_tugas->format('d M Y')
                    : (string) $record->tanggal_tugas;
                $isi .= " untuk tanggal {$tanggal}";
            }

            $notif = app(\App\Modules\Notifikasi\NotifikasiService::class);
            if (!empty($record->id_supir)) {
                $notif->kirimKeSupir(
                    (string) $record->id_supir,
                    (string) $proyek->id_perusahaan,
                    "Penugasan baru: {$proyek->nama_proyek}",
                    $isi,
                    'penugasan',
                    'penugasan',
                    (string) $record->id_penugasan,
                );
            } elseif (!empty($record->id_supir_vendor)) {
                $notif->kirimKeSupirVendor(
                    (string) $record->id_supir_vendor,
                    (string) $proyek->id_perusahaan,
                    "Penugasan baru: {$proyek->nama_proyek}",
                    $isi,
                    'penugasan',
                    'penugasan',
                    (string) $record->id_penugasan,
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Notifikasi penugasan gagal: ' . $e->getMessage());
        }
    }

    /**
     * Penyelesaian penugasan dari alur checkout trip. Sengaja TIDAK memakai
     * update(): perubahan status murni tidak boleh gagal karena
     * assertVendorRules (kontrak/armada vendor bisa saja sudah di-soft-delete
     * saat rit terakhir ditutup), dan 'batal' adalah status terminal yang
     * tidak boleh berubah jadi 'selesai'. Status armada tidak diurus di sini —
     * sejak armada lintas proyek, status armada murni digerakkan lifecycle trip.
     */
    public function selesaikanDariTrip(string $idPenugasan): void
    {
        $record = $this->findOrFail($idPenugasan);

        if ($record->status === 'batal') {
            abort(422, 'Penugasan sudah dibatalkan — tidak dapat diselesaikan dari trip');
        }
        if ($record->status === 'selesai') {
            return;
        }

        $this->repo->update($record, ['status' => 'selesai']);
    }

    /**
     * Penugasan otomatis naik dari 'pending' ke 'aktif' begitu ada orang yang
     * bertanggung jawab (supir internal, atau supir vendor/internal untuk
     * penugasan vendor). Armada SENGAJA tidak jadi syarat — supir shift tidak
     * pernah punya armada langsung di penugasan, unitnya ditentukan harian
     * lewat alokasi armada (papan jadwal), bukan kolom id_armada di sini.
     * tanggal_tugas juga bukan syarat, dengan alasan yang sama.
     */
    private function sudahAdaSupir(array $data): bool
    {
        if (($data['sumber'] ?? 'internal') === 'vendor') {
            return !empty($data['id_supir_vendor']) || !empty($data['id_supir']);
        }

        return !empty($data['id_supir']);
    }

    private function assertArmadaAdaOrFail(string $idArmada): void
    {
        if (ArmadaModel::active()->find($idArmada) === null) {
            abort(422, 'Armada tidak ditemukan');
        }
    }

    /**
     * Kolom DB `sumber` NOT NULL default 'internal'. Bila client mengirim
     * `sumber: null` secara eksplisit, request lolos FormRequest (rule
     * nullable) tapi akan crash 23000 saat fill ke Eloquent karena null
     * meng-override default kolom. Normalisasi null eksplisit → 'internal',
     * konsisten dengan `assertVendorRules()` yang menganggap sumber
     * kosong/null sebagai 'internal'.
     */
    private function normalizeSumber(array $data): array
    {
        if (array_key_exists('sumber', $data) && $data['sumber'] === null) {
            $data['sumber'] = 'internal';
        }

        return $data;
    }

    /**
     * Khusus penugasan bersumber vendor: satu armada vendor / supir (vendor
     * maupun internal) tidak boleh dobel pada tanggal tugas yang sama selama
     * penugasan lamanya masih pending/aktif. Penugasan internal tetap boleh
     * paralel lintas proyek (desain armada lintas proyek) — dijaga di level trip.
     */
    private function assertAktorVendorTidakDobel(array $data, ?string $tanggalTugas, ?string $excludeId = null): void
    {
        if (($data['sumber'] ?? 'internal') !== 'vendor' || empty($tanggalTugas)) {
            return;
        }

        $cek = [
            'id_armada_vendor' => 'Armada vendor sudah memiliki penugasan pada tanggal tersebut',
            'id_supir_vendor'  => 'Supir vendor sudah memiliki penugasan pada tanggal tersebut',
            'id_supir'         => 'Supir sudah memiliki penugasan pada tanggal tersebut',
        ];

        foreach ($cek as $kolom => $pesan) {
            if (!empty($data[$kolom])
                && $this->repo->adaKonflikAktorPadaTanggal($kolom, (string) $data[$kolom], $tanggalTugas, $excludeId)
            ) {
                abort(422, $pesan);
            }
        }
    }

    private function assertVendorRules(array $data, string $idPerusahaan): void
    {
        $sumber = $data['sumber'] ?? 'internal';

        if ($sumber !== 'vendor') {
            if (!empty($data['id_kontrak_vendor']) || !empty($data['id_armada_vendor']) || !empty($data['id_supir_vendor'])) {
                abort(422, 'Field vendor hanya untuk penugasan bersumber vendor');
            }
            return;
        }

        if (empty($data['id_kontrak_vendor'])) {
            abort(422, 'Kontrak vendor wajib dipilih');
        }

        $kontrak = $this->kontrakVendorRepo->findAktifMilikPerusahaan((string) $data['id_kontrak_vendor'], $idPerusahaan);
        if ($kontrak === null) {
            abort(404, 'Kontrak vendor tidak ditemukan');
        }

        if ($kontrak->mekanisme === 'unit_only') {
            if (empty($data['id_armada_vendor'])) {
                abort(422, 'Armada vendor wajib dipilih');
            }
            if (empty($data['id_supir']) || !empty($data['id_supir_vendor'])) {
                abort(422, 'Mekanisme Unit Only memakai supir internal');
            }
        } else {
            // unit_driver | full
            if (empty($data['id_armada_vendor'])) {
                abort(422, 'Armada vendor wajib dipilih');
            }
            if (empty($data['id_supir_vendor']) || !empty($data['id_supir'])) {
                abort(422, 'Mekanisme ini memakai supir dari vendor');
            }
        }

        if (!empty($data['id_armada_vendor']) && !$this->armadaVendorRepo->milikVendor((string) $data['id_armada_vendor'], $kontrak->id_vendor)) {
            abort(422, 'Armada vendor tidak sesuai dengan vendor kontrak');
        }

        if (!empty($data['id_supir_vendor']) && !$this->supirVendorRepo->milikVendor((string) $data['id_supir_vendor'], $kontrak->id_vendor)) {
            abort(422, 'Supir vendor tidak sesuai dengan vendor kontrak');
        }

        if (!empty($data['id_armada_vendor']) && !empty($data['id_supir_vendor'])) {
            $armadaVendor = $this->armadaVendorRepo->findByIdMilikPerusahaan((string) $data['id_armada_vendor'], $idPerusahaan);
            $this->assertKontrakSupirCocokDenganUnit(
                $armadaVendor !== null && !empty($armadaVendor->id_kontrak_vendor) ? (string) $armadaVendor->id_kontrak_vendor : null,
                (string) $data['id_supir_vendor'],
                $idPerusahaan,
            );
        }
    }

    private function assertKontrakSupirCocokDenganUnit(?string $idKontrakUnit, string $idSupirVendor, string $idPerusahaan): void
    {
        if (empty($idKontrakUnit)) {
            return;
        }

        $supirVendor = $this->supirVendorRepo->findByIdMilikPerusahaan($idSupirVendor, $idPerusahaan);
        if ($supirVendor === null || empty($supirVendor->id_kontrak_vendor)) {
            return;
        }

        if ((string) $supirVendor->id_kontrak_vendor !== $idKontrakUnit) {
            abort(422, 'Supir vendor bukan bagian dari kontrak unit ini');
        }
    }

    public function titikDropUntuk(string $idPenugasan): array
    {
        return $this->repo->titikDropUntukBanyak([$idPenugasan])[$idPenugasan] ?? [];
    }

    public function titikDropDetailUntuk(string $idPenugasan): array
    {
        return $this->repo->titikDropDetailUntukBanyak([$idPenugasan])[$idPenugasan] ?? [];
    }

    /** Item titik_drop bisa berupa string polos atau objek {lokasi, uang_jalan_tambahan} — dipakai untuk atribut virtual titik_drop (selalu string[]). */
    private function lokasiSajaTitikDrop(?array $items): array
    {
        return collect($items ?? [])
            ->map(fn ($item) => trim((string) (is_array($item) ? ($item['lokasi'] ?? '') : $item)))
            ->filter()
            ->values()
            ->all();
    }

    /** Sama seperti lokasiSajaTitikDrop() tapi mempertahankan uang_jalan_tambahan — dipakai untuk atribut virtual titik_drop_detail. */
    private function detailTitikDrop(?array $items): array
    {
        return collect($items ?? [])
            ->map(function ($item) {
                $lokasi = trim((string) (is_array($item) ? ($item['lokasi'] ?? '') : $item));
                return $lokasi === '' ? null : [
                    'lokasi'              => $lokasi,
                    'uang_jalan_tambahan' => (float) (is_array($item) ? ($item['uang_jalan_tambahan'] ?? 0) : 0),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function titikDropBanyak(array $idPenugasanList): array
    {
        return $this->repo->titikDropUntukBanyak($idPenugasanList);
    }

    public function titikDropDetailBanyak(array $idPenugasanList): array
    {
        return $this->repo->titikDropDetailUntukBanyak($idPenugasanList);
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id);

        if ($idPerusahaan !== null && !$this->repo->milikPerusahaan($id, $idPerusahaan)) {
            abort(404, 'Penugasan tidak ditemukan');
        }

        if ($record->status === 'selesai') {
            abort(422, 'Penugasan yang sudah selesai tidak dapat dihapus — buka kembali statusnya bila memang perlu diubah');
        }

        if ($this->tripRepo->adaTripNonFinalUntukPenugasan($id)) {
            abort(422, 'Penugasan masih memiliki trip yang belum selesai — selesaikan atau batalkan trip terlebih dahulu');
        }

        if ($this->tripRepo->adaTripSelesaiUntukPenugasan($id)) {
            abort(422, 'Penugasan sudah punya trip selesai — jejaknya dipakai laporan & penagihan, tidak bisa dihapus');
        }

        $idPengajuan = $record->id_pengajuan;

        DB::transaction(function () use ($record, $idPengajuan) {
            $this->repo->delete($record);

            if (!empty($idPengajuan)) {
                $this->arusKasService->sinkronPengajuanSetelahPenugasanDihapus((string) $idPengajuan);
            }
        });

        if (!empty($record->id_supir) || !empty($record->id_supir_vendor)) {
            $this->notifikasiPenugasanDibatalkan($record);
        }
    }

    private function notifikasiPenugasanDibatalkan(PenugasanModel $record): void
    {
        try {
            $proyek = $record->proyek;
            if ($proyek === null) {
                return;
            }

            $isi = "Penugasan Anda pada proyek {$proyek->nama_proyek}";
            if (!empty($record->tanggal_tugas)) {
                $tanggal = $record->tanggal_tugas instanceof \DateTimeInterface
                    ? $record->tanggal_tugas->format('d M Y')
                    : (string) $record->tanggal_tugas;
                $isi .= " tanggal {$tanggal}";
            }
            $isi .= ' telah dibatalkan oleh tim operasional';

            $notif = app(\App\Modules\Notifikasi\NotifikasiService::class);
            if (!empty($record->id_supir)) {
                $notif->kirimKeSupir(
                    (string) $record->id_supir,
                    (string) $proyek->id_perusahaan,
                    "Penugasan dibatalkan: {$proyek->nama_proyek}",
                    $isi,
                    'penugasan',
                    'penugasan',
                    (string) $record->id_penugasan,
                );
            } elseif (!empty($record->id_supir_vendor)) {
                $notif->kirimKeSupirVendor(
                    (string) $record->id_supir_vendor,
                    (string) $proyek->id_perusahaan,
                    "Penugasan dibatalkan: {$proyek->nama_proyek}",
                    $isi,
                    'penugasan',
                    'penugasan',
                    (string) $record->id_penugasan,
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Notifikasi pembatalan penugasan gagal: ' . $e->getMessage());
        }
    }
}
