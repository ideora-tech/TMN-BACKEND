<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;

class PenugasanService
{
    public function __construct(
        private readonly PenugasanRepositoryInterface $repo,
        private readonly KontrakVendorRepositoryInterface $kontrakVendorRepo,
        private readonly ArmadaVendorRepositoryInterface $armadaVendorRepo,
        private readonly SupirVendorRepositoryInterface $supirVendorRepo,
        private readonly TripRepositoryInterface $tripRepo,
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

        if (!empty($data['id_armada'])) {
            $this->assertArmadaAdaOrFail($data['id_armada']);
        }

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            if ($this->repo->hasConflict($data['id_karyawan'], $data['tanggal_tugas'])) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        if (($data['sumber'] ?? 'internal') === 'internal' && !empty($data['id_supir'])) {
            if ($this->repo->existsAktifUntukSupirProyek($data['id_proyek'], $data['id_supir'])) {
                abort(422, 'Supir sudah di-assign ke proyek ini');
            }
        }

        $this->assertAktorVendorTidakDobel($data, $data['tanggal_tugas'] ?? null);

        if (($data['status'] ?? 'pending') === 'pending') {
            $data['status'] = $this->sudahAdaSupir($data) ? 'aktif' : 'pending';
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): PenugasanModel
    {
        $record = $this->findOrFail($id);
        $data   = $this->normalizeSumber($data);

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

        $tanggalEfektif = $data['tanggal_tugas']
            ?? ($record->tanggal_tugas instanceof \DateTimeInterface
                ? $record->tanggal_tugas->format('Y-m-d')
                : $record->tanggal_tugas);
        $this->assertAktorVendorTidakDobel($merged, $tanggalEfektif, $id);

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            $idKaryawan  = $data['id_karyawan'] ?? $record->id_karyawan;
            $tanggalTugas = $data['tanggal_tugas'] ?? $record->tanggal_tugas;
            if ($this->repo->hasConflict($idKaryawan, $tanggalTugas, $id)) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        if ($merged['sumber'] === 'internal'
            && array_key_exists('id_supir', $data)
            && !empty($data['id_supir'])
            && $data['id_supir'] !== $record->id_supir) {
            if ($this->repo->existsAktifUntukSupirProyek((string) $record->id_proyek, $data['id_supir'], $id)) {
                abort(422, 'Supir sudah di-assign ke proyek ini');
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

        $armadaSebelum = $record->id_armada;
        $updated = $this->repo->update($record, $data);

        // Armada penugasan diubah user → alokasi jadwal ke depan dihitung ulang
        // (penugasan = satu-satunya sumber kepemilikan harian armada).
        if (array_key_exists('id_armada', $data)
            && $data['id_armada'] !== $armadaSebelum
            && !empty($updated->id_supir)) {
            app(\App\Modules\AlokasiArmada\AlokasiArmadaService::class)->hitungUlangUntukPenugasan(
                (string) $updated->id_supir,
                (string) $updated->id_proyek,
                now()->toDateString(),
            );
        }

        return $updated;
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
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

        if ($record->status === 'selesai') {
            abort(422, 'Penugasan yang sudah selesai tidak dapat dihapus — buka kembali statusnya bila memang perlu diubah');
        }

        if ($this->tripRepo->adaTripNonFinalUntukPenugasan($id)) {
            abort(422, 'Penugasan masih memiliki trip yang belum selesai — selesaikan atau batalkan trip terlebih dahulu');
        }

        $this->repo->delete($record);
    }
}
