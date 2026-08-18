<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
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
        private readonly JadwalShiftRepositoryInterface $jadwalShiftRepo,
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

    public function findOrFail(string $id): PenugasanModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Penugasan tidak ditemukan');
        }
        return $record;
    }

    public function findAktifUntukSupirDiProyek(string $idSupir, string $idProyek): ?PenugasanModel
    {
        return $this->repo->findAktifUntukSupirDiProyek($idSupir, $idProyek);
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

        if (!empty($data['id_supir'])) {
            $this->bersihkanJadwalOrphanUntukSupirBaru((string) $data['id_proyek'], (string) $data['id_supir']);
        }

        $titikDrop = $data['titik_drop'] ?? null;
        unset($data['titik_drop']);

        $record = $this->repo->create($data);

        if ($titikDrop !== null) {
            $this->repo->syncTitikDrop((string) $record->id_penugasan, $titikDrop);
        }
        $record->titik_drop = $titikDrop ?? [];

        if (!empty($record->id_supir)) {
            $this->notifikasiPenugasan($record);
        }

        return $record;
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
        $supirSebelum  = $record->id_supir;
        $updated = $this->repo->update($record, $data);

        if ($titikDropDikirim) {
            $this->repo->syncTitikDrop($id, $titikDrop ?? []);
        }
        $updated->titik_drop = $this->repo->titikDropUntukBanyak([$id])[$id] ?? [];

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

        if (array_key_exists('id_supir', $data)
            && !empty($updated->id_supir)
            && $updated->id_supir !== $supirSebelum) {
            $this->notifikasiPenugasan($updated);

            if (!empty($supirSebelum)) {
                $this->pindahkanJadwalKeSupirBaru(
                    (string) $updated->id_proyek,
                    (string) $supirSebelum,
                    (string) $updated->id_supir,
                );
            }
        }

        return $updated;
    }

    /**
     * Supir penugasan diganti → jadwal shift mulai hari ini (papan jadwal)
     * ikut dipindah kepemilikannya ke supir baru, alih-alih jadi nyangkut tak
     * terlihat (baris papan diambil dari penugasan aktif, bukan jadwal_shift
     * langsung). Jadwal sebelum hari ini dibiarkan milik supir lama sebagai
     * riwayat. Tanggal yang bentrok dengan jadwal supir baru di proyek lain
     * dilewati otomatis oleh repository (aturan 1 shift/hari global).
     */
    private function pindahkanJadwalKeSupirBaru(string $idProyek, string $supirLama, string $supirBaru): void
    {
        $hasil = $this->jadwalShiftRepo->pindahkanKepemilikan($idProyek, $supirLama, $supirBaru, now()->toDateString());

        if ($hasil['dipindah'] === []) {
            return;
        }

        $alokasiService = app(\App\Modules\AlokasiArmada\AlokasiArmadaService::class);
        foreach ($hasil['dipindah'] as $tanggal) {
            $alokasiService->hapusUntukJadwal($supirLama, $tanggal);
        }
        $alokasiService->hitungUlangRentang($idProyek, min($hasil['dipindah']), max($hasil['dipindah']));
    }

    /**
     * Penugasan baru dibuat untuk supir yang mulai hari ini masih punya
     * jadwal_shift nyangkut dari penugasan lain (sumber apa pun) di proyek
     * yang sama yang sudah selesai/batal — jadwal itu dihapus supaya papan
     * bersih dan ops bisa assign shift dari nol untuk penugasan baru ini,
     * tanpa kejegal aturan 1-shift/hari atau kewarisan supir/armada pengganti
     * lama. Kalau masih ada penugasan aktif LAIN buat supir+proyek ini (mis.
     * unit_only vendor yang belum ditutup), jadwal yang ada memang masih
     * valid buat penugasan itu — dibiarkan.
     */
    private function bersihkanJadwalOrphanUntukSupirBaru(string $idProyek, string $idSupir): void
    {
        if ($this->repo->findAktifUntukSupirDiProyek($idSupir, $idProyek) !== null) {
            return;
        }

        $tanggalTerhapus = $this->jadwalShiftRepo->hapusOrphanUntukSupirProyek($idProyek, $idSupir, now()->toDateString());
        if ($tanggalTerhapus === []) {
            return;
        }

        $alokasiService = app(\App\Modules\AlokasiArmada\AlokasiArmadaService::class);
        foreach ($tanggalTerhapus as $tanggal) {
            $alokasiService->hapusUntukJadwal($idSupir, $tanggal);
        }
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

            app(\App\Modules\Notifikasi\NotifikasiService::class)->kirimKeSupir(
                (string) $record->id_supir,
                (string) $proyek->id_perusahaan,
                "Penugasan baru: {$proyek->nama_proyek}",
                $isi,
                'penugasan',
                'penugasan',
                (string) $record->id_penugasan,
            );
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
    }

    public function titikDropUntuk(string $idPenugasan): array
    {
        return $this->repo->titikDropUntukBanyak([$idPenugasan])[$idPenugasan] ?? [];
    }

    public function titikDropBanyak(array $idPenugasanList): array
    {
        return $this->repo->titikDropUntukBanyak($idPenugasanList);
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
