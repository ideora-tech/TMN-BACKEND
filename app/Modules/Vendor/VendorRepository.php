<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Modules\Vendor\Contracts\VendorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class VendorRepository implements VendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return VendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_vendor', 'like', "%{$search}%")
                   ->orWhere('telepon', 'like', "%{$search}%");
            }))
            ->orderBy('nama_vendor')
            ->select('vendor.*')
            ->addSelect($this->ringkasanRelasi())
            ->paginate($limit, ['*'], 'page', $page);
    }

    private function ringkasanRelasi(): array
    {
        $kontrakAktif = fn () => DB::table('kontrak_vendor')
            ->whereColumn('kontrak_vendor.id_vendor', 'vendor.id_vendor')
            ->whereNull('kontrak_vendor.dihapus_pada')
            ->where('kontrak_vendor.status', 'aktif');

        return [
            'jumlah_unit' => DB::table('armada_vendor')
                ->selectRaw('COUNT(*)')
                ->whereColumn('armada_vendor.id_vendor', 'vendor.id_vendor')
                ->whereNull('armada_vendor.dihapus_pada'),
            'jumlah_driver' => DB::table('supir_vendor')
                ->selectRaw('COUNT(*)')
                ->whereColumn('supir_vendor.id_vendor', 'vendor.id_vendor')
                ->whereNull('supir_vendor.dihapus_pada'),
            'jumlah_kontrak_aktif' => $kontrakAktif()->selectRaw('COUNT(*)'),
            'nilai_kontrak_aktif' => $kontrakAktif()->selectRaw('COALESCE(SUM(nilai_kontrak), 0)'),
            'kontrak_berakhir_terdekat' => $kontrakAktif()
                ->selectRaw('MIN(tanggal_selesai)')
                ->whereNotNull('tanggal_selesai')
                ->whereDate('tanggal_selesai', '>=', now()->toDateString()),
        ];
    }

    public function findById(string $id): ?VendorModel
    {
        return VendorModel::active()->find($id);
    }

    public function findByIdMilikPerusahaan(string $id, string $idPerusahaan): ?VendorModel
    {
        return VendorModel::active()
            ->where('id_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?VendorModel
    {
        return VendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_vendor', $kode)
            ->first();
    }

    public function create(array $data): VendorModel
    {
        return VendorModel::create($data);
    }

    public function update(VendorModel $model, array $data): VendorModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(VendorModel $model): void
    {
        $model->softDelete();
    }

    public function dipakaiRelasiAktif(string $idVendor): bool
    {
        foreach (['kontrak_vendor', 'armada_vendor', 'supir_vendor', 'invoice_vendor'] as $tabel) {
            $dipakai = DB::table($tabel)
                ->whereNull('dihapus_pada')
                ->where('id_vendor', $idVendor)
                ->exists();
            if ($dipakai) {
                return true;
            }
        }

        return false;
    }
}
