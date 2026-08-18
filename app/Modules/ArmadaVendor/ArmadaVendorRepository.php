<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ArmadaVendorRepository implements ArmadaVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null, ?string $search = null): LengthAwarePaginator
    {
        return ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'armada_vendor.id_jenis_kendaraan')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->when($idVendor, fn ($q) => $q->where('armada_vendor.id_vendor', $idVendor))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('armada_vendor.nopol', 'like', "%{$search}%")
                   ->orWhere('armada_vendor.merk', 'like', "%{$search}%");
            }))
            ->select('armada_vendor.*', 'vendor.nama_vendor', 'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan')
            ->orderBy('armada_vendor.nopol')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?ArmadaVendorModel
    {
        return ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'armada_vendor.id_jenis_kendaraan')
            ->where('armada_vendor.id_armada_vendor', $id)
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->select('armada_vendor.*', 'vendor.nama_vendor', 'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan')
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

    public function jenisKendaraanMilikPerusahaan(string $idJenisKendaraan, string $idPerusahaan): bool
    {
        return DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function milikVendor(string $id, string $idVendor): bool
    {
        return ArmadaVendorModel::active()
            ->where('id_armada_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->exists();
    }

    /**
     * Unit vendor yang tersedia untuk dipilih di Penugasan Operasional — hanya unit
     * aktif milik vendor yang punya kontrak bermekanisme 'unit_only' (vendor hanya
     * menyewakan unit, supirnya tetap internal). id_kontrak_vendor diresolusi otomatis
     * di sini (kontrak terbaru per unit) supaya form Operasional tidak perlu meminta
     * dispatcher memilih kontrak secara manual.
     */
    public function listOpsiUnitOnly(string $idPerusahaan): array
    {
        $rows = ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->join('kontrak_vendor', function ($join) use ($idPerusahaan) {
                $join->on('kontrak_vendor.id_vendor', '=', 'armada_vendor.id_vendor')
                    ->where('kontrak_vendor.mekanisme', '=', 'unit_only')
                    ->where('kontrak_vendor.id_perusahaan', '=', $idPerusahaan)
                    ->whereNull('kontrak_vendor.dihapus_pada');
            })
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->where('armada_vendor.aktif', 1)
            ->whereNull('vendor.dihapus_pada')
            ->select(
                'armada_vendor.id_armada_vendor', 'armada_vendor.nopol', 'armada_vendor.merk', 'armada_vendor.jenis',
                'armada_vendor.id_vendor', 'vendor.nama_vendor', 'kontrak_vendor.id_kontrak_vendor',
            )
            ->orderBy('armada_vendor.nopol')
            ->orderByDesc('kontrak_vendor.dibuat_pada')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            if (isset($result[$row->id_armada_vendor])) {
                continue;
            }
            $result[$row->id_armada_vendor] = [
                'id_armada_vendor'  => $row->id_armada_vendor,
                'id_kontrak_vendor' => $row->id_kontrak_vendor,
                'nopol'             => $row->nopol,
                'merk'              => $row->merk,
                'jenis'             => $row->jenis,
                'id_vendor'         => $row->id_vendor,
                'nama_vendor'       => $row->nama_vendor,
            ];
        }

        return array_values($result);
    }

    public function create(array $data): ArmadaVendorModel
    {
        return ArmadaVendorModel::create($data);
    }

    public function update(ArmadaVendorModel $model, array $data): ArmadaVendorModel
    {
        $model->update($data);

        $fresh = ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->where('armada_vendor.id_armada_vendor', $model->id_armada_vendor)
            ->select('armada_vendor.*', 'vendor.nama_vendor')
            ->first();

        return $fresh ?? $model;
    }

    public function delete(ArmadaVendorModel $model): void
    {
        $model->softDelete();
    }
}
