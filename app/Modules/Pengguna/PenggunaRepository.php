<?php

declare(strict_types=1);

namespace App\Modules\Pengguna;

use App\Models\Pengguna;
use App\Modules\Pengguna\Contracts\PenggunaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PenggunaRepository implements PenggunaRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $aktif = null): LengthAwarePaginator
    {
        return Pengguna::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('username', 'like', "%{$search}%")
                   ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($aktif !== null, fn ($q) => $q->where('aktif', (int) $aktif))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?Pengguna
    {
        return Pengguna::active()->find($id);
    }

    public function findByUsername(string $username): ?Pengguna
    {
        return Pengguna::active()->where('username', $username)->first();
    }

    public function findByEmail(string $email): ?Pengguna
    {
        return Pengguna::active()->where('email', $email)->first();
    }

    public function create(array $data): Pengguna
    {
        return Pengguna::create($data);
    }

    public function update(Pengguna $model, array $data): Pengguna
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(Pengguna $model): void
    {
        $model->softDelete();
    }

    public function terdaftarSebagaiApprover(string $idPengguna): bool
    {
        return DB::table('approval_config_approver')
            ->whereNull('dihapus_pada')
            ->where('tipe', 'pengguna')
            ->where('id_pengguna', $idPengguna)
            ->exists();
    }

    /**
     * Mencerminkan rantai resolusi approver jabatan di ApprovalRepository:
     * config jabatan → karyawan aktif pemegang jabatan → pengguna aktif.
     * Pengguna nonaktif sudah lepas dari resolusi, jadi boleh dihapus.
     */
    public function jadiApproverLewatJabatan(string $idPengguna): bool
    {
        return DB::table('pengguna as p')
            ->join('karyawan as k', function ($join) {
                $join->on('k.id_karyawan', '=', 'p.id_karyawan')
                    ->where('k.aktif', 1)
                    ->whereNull('k.dihapus_pada');
            })
            ->join('approval_config_approver as ac', function ($join) {
                $join->on('ac.id_jabatan', '=', 'k.id_jabatan')
                    ->where('ac.tipe', 'jabatan')
                    ->whereNull('ac.dihapus_pada');
            })
            ->where('p.id_pengguna', $idPengguna)
            ->where('p.aktif', 1)
            ->exists();
    }

    private function supirDenganAkun(string $idPerusahaan): Builder
    {
        return DB::table('supir as s')
            ->leftJoin('pengguna as p', function ($join) {
                $join->on('p.id_pengguna', '=', 's.id_pengguna')
                    ->whereNull('p.dihapus_pada')
                    ->where('p.kode_peran', 'SUPIR');
            })
            ->whereNull('s.dihapus_pada')
            ->where('s.id_perusahaan', $idPerusahaan);
    }

    private function supirVendorDenganAkun(string $idPerusahaan): Builder
    {
        return DB::table('supir_vendor as sv')
            ->join('vendor as v', function ($join) {
                $join->on('v.id_vendor', '=', 'sv.id_vendor')->whereNull('v.dihapus_pada');
            })
            ->leftJoin('pengguna as p', function ($join) {
                $join->on('p.id_pengguna', '=', 'sv.id_pengguna')
                    ->whereNull('p.dihapus_pada')
                    ->where('p.kode_peran', 'SUPIR_VENDOR');
            })
            ->whereNull('sv.dihapus_pada')
            ->where('v.id_perusahaan', $idPerusahaan);
    }

    public function opsiSupir(string $idPerusahaan): array
    {
        return $this->supirDenganAkun($idPerusahaan)
            ->leftJoin('karyawan as k', function ($join) {
                $join->on('k.id_karyawan', '=', 's.id_karyawan')->whereNull('k.dihapus_pada');
            })
            ->orderBy('s.nama')
            ->get([
                's.id_supir', 's.nama', 's.no_sim', 's.telepon', 's.status',
                'k.nama_karyawan',
                'p.id_pengguna', 'p.username as username_pengguna',
            ])
            ->all();
    }

    public function opsiSupirVendor(string $idPerusahaan): array
    {
        return $this->supirVendorDenganAkun($idPerusahaan)
            ->orderBy('sv.nama')
            ->get([
                'sv.id_supir_vendor', 'sv.nama', 'sv.telepon', 'sv.aktif', 'v.nama_vendor',
                'p.id_pengguna', 'p.username as username_pengguna',
            ])
            ->all();
    }

    public function supirUntukTautan(string $idSupir, string $idPerusahaan): ?object
    {
        return $this->supirDenganAkun($idPerusahaan)
            ->where('s.id_supir', $idSupir)
            ->lockForUpdate()
            ->first(['s.id_supir', 's.nama', 'p.id_pengguna', 'p.username as username_pengguna']);
    }

    public function supirVendorUntukTautan(string $idSupirVendor, string $idPerusahaan): ?object
    {
        return $this->supirVendorDenganAkun($idPerusahaan)
            ->where('sv.id_supir_vendor', $idSupirVendor)
            ->lockForUpdate()
            ->first(['sv.id_supir_vendor', 'sv.nama', 'p.id_pengguna', 'p.username as username_pengguna']);
    }

    public function gantiTautanSupir(string $idPengguna, ?string $idSupir): void
    {
        $this->gantiTautan('supir', 'id_supir', $idPengguna, $idSupir);
    }

    public function gantiTautanSupirVendor(string $idPengguna, ?string $idSupirVendor): void
    {
        $this->gantiTautan('supir_vendor', 'id_supir_vendor', $idPengguna, $idSupirVendor);
    }

    private function gantiTautan(string $tabel, string $kunci, string $idPengguna, ?string $idTujuan): void
    {
        DB::table($tabel)
            ->whereNull('dihapus_pada')
            ->where('id_pengguna', $idPengguna)
            ->when($idTujuan !== null, fn ($q) => $q->where($kunci, '!=', $idTujuan))
            ->update(RecordHelper::stampUpdate(['id_pengguna' => null]));

        if ($idTujuan === null) {
            return;
        }

        DB::table($tabel)
            ->whereNull('dihapus_pada')
            ->where($kunci, $idTujuan)
            ->where(function ($q) use ($idPengguna) {
                $q->whereNull('id_pengguna')->orWhere('id_pengguna', '!=', $idPengguna);
            })
            ->update(RecordHelper::stampUpdate(['id_pengguna' => $idPengguna]));
    }
}
