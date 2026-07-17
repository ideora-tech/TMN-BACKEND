<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;

class PenugasanService
{
    public function __construct(
        private readonly PenugasanRepositoryInterface $repo,
        private readonly KontrakVendorRepositoryInterface $kontrakVendorRepo,
        private readonly ArmadaVendorRepositoryInterface $armadaVendorRepo,
        private readonly SupirVendorRepositoryInterface $supirVendorRepo,
    ) {}

    public function list(string $idProyek, int $page = 1, int $limit = 10, ?string $sumber = null): array
    {
        $result = $this->repo->paginateByProyek($idProyek, $page, $limit, $sumber);

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

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 20, ?string $sumber = null): array
    {
        $result = $this->repo->paginateByArmada($idArmada, $page, $limit, $sumber);

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

    public function listBySupir(string $idSupir, int $page = 1, int $limit = 20, ?string $sumber = null): array
    {
        $result = $this->repo->paginateBySupir($idSupir, $page, $limit, $sumber);

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

        $armada = null;
        if (!empty($data['id_armada'])) {
            $armada = $this->assertArmadaTersediaOrFail($data['id_armada']);
        }

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            if ($this->repo->hasConflict($data['id_karyawan'], $data['tanggal_tugas'])) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        $penugasan = $this->repo->create($data);

        if ($armada !== null) {
            $armada->update(['status' => 'digunakan']);
        }

        return $penugasan;
    }

    public function update(string $id, array $data, string $idPerusahaan): PenugasanModel
    {
        $record = $this->findOrFail($id);
        $data   = $this->normalizeSumber($data);

        $merged = [
            'sumber'            => array_key_exists('sumber', $data) ? $data['sumber'] : $record->sumber,
            'id_kontrak_vendor' => array_key_exists('id_kontrak_vendor', $data) ? $data['id_kontrak_vendor'] : $record->id_kontrak_vendor,
            'id_armada_vendor'  => array_key_exists('id_armada_vendor', $data) ? $data['id_armada_vendor'] : $record->id_armada_vendor,
            'id_supir_vendor'   => array_key_exists('id_supir_vendor', $data) ? $data['id_supir_vendor'] : $record->id_supir_vendor,
            'id_supir'          => array_key_exists('id_supir', $data) ? $data['id_supir'] : $record->id_supir,
        ];
        $this->assertVendorRules($merged, $idPerusahaan);

        if (!empty($data['id_karyawan']) && !empty($data['tanggal_tugas'])) {
            $idKaryawan  = $data['id_karyawan'] ?? $record->id_karyawan;
            $tanggalTugas = $data['tanggal_tugas'] ?? $record->tanggal_tugas;
            if ($this->repo->hasConflict($idKaryawan, $tanggalTugas, $id)) {
                abort(422, 'Karyawan sudah memiliki penugasan aktif pada tanggal tersebut');
            }
        }

        // --- Siklus hidup status armada internal (id_armada_vendor tidak disentuh) ---
        $idArmadaLama  = $record->id_armada;
        $armadaBerubah = array_key_exists('id_armada', $data) && $data['id_armada'] !== $idArmadaLama;
        $idArmadaBaru  = $armadaBerubah ? $data['id_armada'] : $idArmadaLama;

        $armadaBaruUntukDikunci = null;
        if ($armadaBerubah && !empty($idArmadaBaru)) {
            // Armada baru wajib 'tersedia' sebelum data disimpan.
            $armadaBaruUntukDikunci = $this->assertArmadaTersediaOrFail($idArmadaBaru);
        }

        $statusLama    = $record->status;
        $statusBaru    = array_key_exists('status', $data) ? $data['status'] : $statusLama;
        $statusBerubah = array_key_exists('status', $data) && $statusBaru !== $statusLama;

        $statusFinal = ['selesai', 'batal'];
        $statusAktif = ['pending', 'aktif'];

        $armadaUntukDikunciUlang = null;
        if (!$armadaBerubah && $statusBerubah && !empty($idArmadaLama)
            && in_array($statusLama, $statusFinal, true) && in_array($statusBaru, $statusAktif, true)) {
            // Reaktivasi dari selesai/batal -> armada wajib masih 'tersedia'.
            $armadaUntukDikunciUlang = $this->assertArmadaTersediaOrFail($idArmadaLama);
        }

        $updated = $this->repo->update($record, $data);

        if ($armadaBerubah) {
            if (!empty($idArmadaLama)) {
                $this->releaseArmadaIfUnused($idArmadaLama, $id);
            }
            $armadaBaruUntukDikunci?->update(['status' => 'digunakan']);
        } elseif ($statusBerubah && !empty($idArmadaLama)) {
            if (in_array($statusBaru, $statusFinal, true)) {
                $this->releaseArmadaIfUnused($idArmadaLama, $id);
            } elseif ($armadaUntukDikunciUlang !== null) {
                $armadaUntukDikunciUlang->update(['status' => 'digunakan']);
            }
        }

        return $updated;
    }

    /**
     * Validasi armada internal siap ditugaskan: harus ada & berstatus
     * 'tersedia'. Sama persis dengan guard create() — dipakai ulang saat
     * update() mengganti armada atau mereaktivasi penugasan.
     */
    private function assertArmadaTersediaOrFail(string $idArmada): ArmadaModel
    {
        $armada = ArmadaModel::active()->find($idArmada);
        if ($armada === null) {
            abort(422, 'Armada tidak ditemukan');
        }
        if ($armada->status !== 'tersedia') {
            abort(422, 'Armada tidak tersedia untuk ditugaskan (status: ' . $armada->status . ')');
        }
        return $armada;
    }

    /**
     * Set armada -> 'tersedia' HANYA bila tidak ada penugasan aktif lain
     * (pending/aktif, id_penugasan != $excludeId) yang masih memakainya.
     * Pertahanan ekstra walau guard create() harusnya mencegah dobel pakai.
     */
    private function releaseArmadaIfUnused(string $idArmada, string $excludeId): void
    {
        if ($this->repo->hasOtherActiveArmadaUsage($idArmada, $excludeId)) {
            return;
        }
        ArmadaModel::active()->find($idArmada)?->update(['status' => 'tersedia']);
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
        $record   = $this->findOrFail($id);
        $idArmada = $record->id_armada;

        $this->repo->delete($record);

        if (!empty($idArmada)) {
            $this->releaseArmadaIfUnused($idArmada, $id);
        }
    }
}
