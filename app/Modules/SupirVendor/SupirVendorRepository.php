<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupirVendorRepository implements SupirVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null, ?string $search = null): LengthAwarePaginator
    {
        return SupirVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'supir_vendor.id_vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->when($idVendor, fn ($q) => $q->where('supir_vendor.id_vendor', $idVendor))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('supir_vendor.nama', 'like', "%{$search}%")
                   ->orWhere('supir_vendor.no_sim', 'like', "%{$search}%");
            }))
            ->select('supir_vendor.*', 'vendor.nama_vendor')
            ->orderBy('supir_vendor.nama')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findByPengguna(string $idPengguna): ?SupirVendorModel
    {
        return SupirVendorModel::active()
            ->where('id_pengguna', $idPengguna)
            ->first();
    }

    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?SupirVendorModel
    {
        return SupirVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'supir_vendor.id_vendor')
            ->where('supir_vendor.id_supir_vendor', $id)
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->select('supir_vendor.*', 'vendor.nama_vendor')
            ->first();
    }

    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function milikVendor(string $id, string $idVendor): bool
    {
        return SupirVendorModel::active()
            ->where('id_supir_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->exists();
    }

    public function findIdVendorByKode(string $kodeVendor, string $idPerusahaan): ?string
    {
        $id = DB::table('vendor')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->whereRaw('UPPER(TRIM(kode_vendor)) = ?', [mb_strtoupper(trim($kodeVendor))])
            ->value('id_vendor');

        return $id !== null ? (string) $id : null;
    }

    public function findIdVendorByKontrak(string $idKontrakVendor, string $idPerusahaan): ?string
    {
        $id = DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('id_vendor');

        return $id !== null ? (string) $id : null;
    }

    public function noSimTerdaftar(string $noSim, string $idPerusahaan): bool
    {
        return SupirVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'supir_vendor.id_vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->whereRaw('UPPER(TRIM(supir_vendor.no_sim)) = ?', [mb_strtoupper(trim($noSim))])
            ->exists();
    }

    public function create(array $data): SupirVendorModel
    {
        return SupirVendorModel::create($data);
    }

    public function update(SupirVendorModel $model, array $data): SupirVendorModel
    {
        $model->update($data);

        $fresh = SupirVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'supir_vendor.id_vendor')
            ->where('supir_vendor.id_supir_vendor', $model->id_supir_vendor)
            ->select('supir_vendor.*', 'vendor.nama_vendor')
            ->first();

        return $fresh ?? $model;
    }

    public function listAktifByKontrak(string $idKontrakVendor): \Illuminate\Support\Collection
    {
        return SupirVendorModel::active()
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->get()
            ->toBase();
    }

    public function delete(SupirVendorModel $model): void
    {
        $model->softDelete();
    }
}
