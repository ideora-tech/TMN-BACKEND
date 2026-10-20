<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SupirService
{
    public function __construct(
        private readonly SupirRepositoryInterface $repo,
        private readonly ArmadaRepositoryInterface $armadaRepo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    /**
     * Bila id_karyawan dikirim & tidak null: karyawan harus ada, satu
     * perusahaan, dan belum ditautkan ke supir lain (1 karyawan = 1 supir).
     */
    private function assertKaryawanRules(array $data, string $idPerusahaan, ?string $excludeIdSupir = null): void
    {
        if (!array_key_exists('id_karyawan', $data) || $data['id_karyawan'] === null) {
            return;
        }

        $karyawan = $this->karyawanRepo->findById($data['id_karyawan']);
        if ($karyawan === null || $karyawan->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Karyawan tidak ditemukan');
        }

        $tertaut = $this->repo->findByKaryawan($data['id_karyawan'], $excludeIdSupir);
        if ($tertaut !== null) {
            abort(422, "Karyawan sudah ditautkan ke supir {$tertaut->nama}");
        }
    }

    /**
     * Bila id_pengguna dikirim & tidak null: akun harus ada di perusahaan yang
     * sama dan belum ditautkan ke supir lain (1 akun = 1 supir). Tautan ini
     * yang dipakai login aplikasi mobile supir (endpoint /supir/me).
     */
    private function assertPenggunaRules(array $data, string $idPerusahaan, ?string $excludeIdSupir = null): void
    {
        if (!array_key_exists('id_pengguna', $data) || $data['id_pengguna'] === null) {
            return;
        }

        $pengguna = $this->repo->findPenggunaMilikPerusahaan((string) $data['id_pengguna'], $idPerusahaan);
        if ($pengguna === null) {
            abort(404, 'Akun pengguna tidak ditemukan');
        }

        $tertaut = $this->repo->findByPengguna((string) $data['id_pengguna'], $excludeIdSupir);
        if ($tertaut !== null) {
            abort(422, "Akun sudah ditautkan ke supir {$tertaut->nama}");
        }
    }

    public function listOpsiPengguna(string $idPerusahaan): array
    {
        return $this->repo->listOpsiPengguna($idPerusahaan);
    }

    public function listOpsiKaryawan(string $idPerusahaan, ?string $idSupir = null): array
    {
        $idKaryawanTertaut = $idSupir !== null ? ($this->findOrFail($idSupir, $idPerusahaan)->id_karyawan ?? null) : null;

        return $this->repo->listOpsiKaryawan($idPerusahaan, $idKaryawanTertaut !== null ? (string) $idKaryawanTertaut : null);
    }

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $status = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $status, $search);
        return [
            'data' => $this->lampirkanArmadaDefault($result->items()),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Supir tidak ditemukan');
        }
        return $record;
    }

    public function detail(string $id, string $idPerusahaan): object
    {
        [$record] = $this->lampirkanArmadaDefault([$this->findOrFail($id, $idPerusahaan)]);
        return $record;
    }

    /** @param object[] $records */
    private function lampirkanArmadaDefault(array $records): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r->id_armada_default ?? null, $records))));
        $armadaMap = $ids === [] ? collect() : DB::table('armada')
            ->whereIn('id_armada', $ids)
            ->whereNull('dihapus_pada')
            ->get(['id_armada', 'nopol', 'merk', 'model'])
            ->keyBy('id_armada');

        foreach ($records as $record) {
            $armada = ($record->id_armada_default ?? null) !== null ? $armadaMap->get($record->id_armada_default) : null;
            $record->armada_default = $armada !== null ? [
                'id_armada' => $armada->id_armada,
                'nopol'     => $armada->nopol,
                'merk'      => $armada->merk,
                'model'     => $armada->model,
            ] : null;
        }

        return $records;
    }

    public function findByPenggunaOrFail(string $idPengguna): object
    {
        $record = $this->repo->findByPengguna($idPengguna);
        if ($record === null) { abort(404, 'Supir tidak ditemukan'); }

        if ($record->id_armada_default !== null) {
            $armada = $this->armadaRepo->findById($record->id_armada_default);
            $record->armada_default = $armada !== null ? [
                'id_armada' => $armada->id_armada,
                'nopol'     => $armada->nopol,
                'merk'      => $armada->merk,
                'model'     => $armada->model,
            ] : null;
        } else {
            $record->armada_default = null;
        }

        return $record;
    }

    public function create(array $data): object
    {
        $this->assertArmadaDefaultRules($data, (string) $data['id_perusahaan']);
        $this->assertKaryawanRules($data, (string) $data['id_perusahaan']);
        $this->assertPenggunaRules($data, (string) $data['id_perusahaan']);
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->assertArmadaDefaultRules($data, $idPerusahaan, $record->id_supir);
        $this->assertKaryawanRules($data, $idPerusahaan, $record->id_supir);
        $this->assertPenggunaRules($data, $idPerusahaan, $record->id_supir);
        return $this->repo->update($record, $data);
    }

    /**
     * Bila id_armada_default dikirim & tidak null, pastikan armada ada
     * dan milik perusahaan yang sama dengan user (pola guard existing:
     * ArmadaRepository::findById tidak scoped perusahaan, jadi
     * dibandingkan manual di sini). Lalu pastikan armada belum jadi
     * armada default supir lain — aturan "1 armada = 1 supir pegangan".
     * $excludeIdSupir dipakai saat update supaya supir boleh mempertahankan
     * armada default miliknya sendiri.
     */
    private function assertArmadaDefaultRules(array $data, string $idPerusahaan, ?string $excludeIdSupir = null): void
    {
        if (!array_key_exists('id_armada_default', $data) || $data['id_armada_default'] === null) {
            return;
        }

        $idArmada = (string) $data['id_armada_default'];

        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || (string) $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }

        $pemegang = $this->repo->findPemegangArmadaDefault($idArmada, $excludeIdSupir);
        if ($pemegang !== null) {
            abort(422, "Armada sudah menjadi armada default supir {$pemegang->nama}");
        }
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiRelasiAktif($id)) {
            abort(422, 'Supir masih punya riwayat penugasan/jadwal shift — ubah statusnya menjadi tidak aktif saja');
        }

        $this->repo->delete($record);
    }
}
