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
            ->leftJoin('supir_vendor as sv_default', function ($join) {
                $join->on('sv_default.id_supir_vendor', '=', 'armada_vendor.id_supir_vendor_default')
                    ->whereNull('sv_default.dihapus_pada');
            })
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->when($idVendor, fn ($q) => $q->where('armada_vendor.id_vendor', $idVendor))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('armada_vendor.nopol', 'like', "%{$search}%")
                   ->orWhere('armada_vendor.merk', 'like', "%{$search}%");
            }))
            ->select('armada_vendor.*', 'vendor.nama_vendor', 'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan', 'sv_default.nama as nama_supir_default')
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

    public function findIdVendorByKode(string $kodeVendor, string $idPerusahaan): ?string
    {
        $id = DB::table('vendor')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->whereRaw('UPPER(TRIM(kode_vendor)) = ?', [mb_strtoupper(trim($kodeVendor))])
            ->value('id_vendor');

        return $id !== null ? (string) $id : null;
    }

    public function findIdJenisKendaraanByNama(string $namaJenis, string $idPerusahaan): ?string
    {
        $id = DB::table('jenis_kendaraan')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->whereRaw('UPPER(TRIM(nama_jenis)) = ?', [mb_strtoupper(trim($namaJenis))])
            ->value('id_jenis_kendaraan');

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

    public function nopolTerdaftar(string $nopol, string $idPerusahaan): bool
    {
        return ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->whereNull('vendor.dihapus_pada')
            ->whereRaw('UPPER(TRIM(armada_vendor.nopol)) = ?', [mb_strtoupper(trim($nopol))])
            ->exists();
    }

    /**
     * Semua unit vendor aktif untuk papan Penugasan — satu baris per unit. Kontrak
     * diresolusi HANYA dari tautan eksplisit armada_vendor.id_kontrak_vendor (left join,
     * tanpa fallback ke kontrak lain milik vendor yang sama). Unit yang tautannya
     * kosong/mengarah ke kontrak yang sudah dihapus tetap ikut tampil (tidak hilang
     * dari board) tapi dengan mekanisme/status_kontrak null — frontend menandainya
     * sebagai "tidak ada kontrak" alih-alih diam-diam menampilkan info kontrak lain.
     */
    public function listOpsiBoard(string $idPerusahaan): array
    {
        $rows = ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->leftJoin('kontrak_vendor', $this->joinKontrakResolusiUnit($idPerusahaan))
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->where('armada_vendor.aktif', 1)
            ->whereNull('vendor.dihapus_pada')
            ->select(
                'armada_vendor.id_armada_vendor', 'armada_vendor.nopol', 'armada_vendor.merk', 'armada_vendor.jenis',
                'armada_vendor.id_vendor', 'armada_vendor.id_kontrak_vendor as id_kontrak_vendor_unit', 'vendor.nama_vendor',
                'armada_vendor.id_supir_vendor_default',
                'kontrak_vendor.id_kontrak_vendor', 'kontrak_vendor.mekanisme',
                'kontrak_vendor.status as status_kontrak', 'kontrak_vendor.tanggal_selesai as tanggal_selesai_kontrak',
            )
            ->orderBy('armada_vendor.nopol')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            if (isset($result[$row->id_armada_vendor])) {
                continue;
            }
            $result[$row->id_armada_vendor] = [
                'id_armada_vendor'       => $row->id_armada_vendor,
                'id_kontrak_vendor'      => $row->id_kontrak_vendor,
                'id_kontrak_vendor_unit' => $row->id_kontrak_vendor_unit,
                'mekanisme'              => $row->mekanisme,
                'kontrak_habis'          => $this->kontrakHabis($row->status_kontrak, $row->tanggal_selesai_kontrak),
                'status_kontrak'         => $row->status_kontrak,
                'nopol'                  => $row->nopol,
                'merk'                   => $row->merk,
                'jenis'                  => $row->jenis,
                'id_vendor'              => $row->id_vendor,
                'nama_vendor'            => $row->nama_vendor,
                'id_supir_vendor_default' => $row->id_supir_vendor_default,
            ];
        }

        return array_values($result);
    }

    /**
     * Unit vendor yang tersedia untuk dipilih di Penugasan Operasional — hanya unit
     * aktif milik vendor yang punya kontrak bermekanisme 'unit_only' (vendor hanya
     * menyewakan unit, supirnya tetap internal), ditautkan lewat id_kontrak_vendor
     * eksplisit. Unit tanpa tautan kontrak unit_only yang valid sengaja dikecualikan
     * di sini (inner join) — operasional butuh kepastian mekanisme sebelum menugaskan.
     */
    public function listOpsiUnitOnly(string $idPerusahaan): array
    {
        $rows = ArmadaVendorModel::active()
            ->join('vendor', 'vendor.id_vendor', '=', 'armada_vendor.id_vendor')
            ->join('kontrak_vendor', $this->joinKontrakResolusiUnit($idPerusahaan, 'unit_only'))
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->where('armada_vendor.aktif', 1)
            ->whereNull('vendor.dihapus_pada')
            ->select(
                'armada_vendor.id_armada_vendor', 'armada_vendor.nopol', 'armada_vendor.merk', 'armada_vendor.jenis',
                'armada_vendor.id_vendor', 'armada_vendor.id_kontrak_vendor as id_kontrak_vendor_unit', 'vendor.nama_vendor',
                'kontrak_vendor.id_kontrak_vendor',
                'kontrak_vendor.status as status_kontrak', 'kontrak_vendor.tanggal_selesai as tanggal_selesai_kontrak',
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
                'id_armada_vendor'       => $row->id_armada_vendor,
                'id_kontrak_vendor'      => $row->id_kontrak_vendor,
                'id_kontrak_vendor_unit' => $row->id_kontrak_vendor_unit,
                'kontrak_habis'          => $this->kontrakHabis($row->status_kontrak, $row->tanggal_selesai_kontrak),
                'nopol'                  => $row->nopol,
                'merk'                   => $row->merk,
                'jenis'                  => $row->jenis,
                'id_vendor'              => $row->id_vendor,
                'nama_vendor'            => $row->nama_vendor,
            ];
        }

        return array_values($result);
    }

    /**
     * Resolusi kontrak murni berdasarkan tautan eksplisit di armada_vendor.id_kontrak_vendor
     * — TIDAK ADA fallback "pinjam kontrak lain milik vendor yang sama". Unit yang
     * tautannya null/mengarah ke kontrak yang sudah dihapus (mis. kontraknya baru saja
     * dihapus karena belum pernah dipakai) harus tampil apa adanya sebagai "tidak ada
     * kontrak" — bukan diam-diam menampilkan info kontrak lain yang tidak relevan.
     */
    private function joinKontrakResolusiUnit(string $idPerusahaan, ?string $mekanisme = null): \Closure
    {
        return function ($join) use ($idPerusahaan, $mekanisme) {
            $join->where('kontrak_vendor.id_perusahaan', '=', $idPerusahaan)
                ->whereNull('kontrak_vendor.dihapus_pada')
                ->when($mekanisme, fn ($q) => $q->where('kontrak_vendor.mekanisme', '=', $mekanisme))
                ->whereColumn('kontrak_vendor.id_kontrak_vendor', 'armada_vendor.id_kontrak_vendor');
        };
    }

    private function kontrakHabis(?string $status, mixed $tanggalSelesai): bool
    {
        if ($status !== null && $status !== 'aktif') {
            return true;
        }
        if (empty($tanggalSelesai)) {
            return false;
        }

        return substr((string) $tanggalSelesai, 0, 10) < now()->toDateString();
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

    public function listAktifByKontrak(string $idKontrakVendor): \Illuminate\Support\Collection
    {
        return ArmadaVendorModel::active()
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->get()
            ->toBase();
    }

    public function lepasSupirDefault(string $idSupirVendor): void
    {
        ArmadaVendorModel::active()
            ->where('id_supir_vendor_default', $idSupirVendor)
            ->update(['id_supir_vendor_default' => null]);
    }

    public function delete(ArmadaVendorModel $model): void
    {
        $model->softDelete();
    }
}
