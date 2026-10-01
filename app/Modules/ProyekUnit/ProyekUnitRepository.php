<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit;

use App\Modules\ProyekUnit\Contracts\ProyekUnitRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProyekUnitRepository implements ProyekUnitRepositoryInterface
{
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->exists();
    }

    public function kunciProyek(string $idProyek): void
    {
        DB::table('proyek')->where('id_proyek', $idProyek)->lockForUpdate()->first();
    }

    public function listByProyek(string $idProyek, string $idPerusahaan): array
    {
        return DB::table('proyek_unit as pu')
            ->leftJoin('armada as a', function ($join) {
                $join->on('a.id_armada', '=', 'pu.id_armada')->whereNull('a.dihapus_pada');
            })
            ->leftJoin('armada_vendor as av', function ($join) {
                $join->on('av.id_armada_vendor', '=', 'pu.id_armada_vendor')->whereNull('av.dihapus_pada');
            })
            ->leftJoin('vendor as v', 'v.id_vendor', '=', 'av.id_vendor')
            ->leftJoin('jenis_kendaraan as jka', 'jka.id_jenis_kendaraan', '=', 'a.id_jenis_kendaraan')
            ->leftJoin('jenis_kendaraan as jkv', 'jkv.id_jenis_kendaraan', '=', 'av.id_jenis_kendaraan')
            ->leftJoin('supir_vendor as sv', function ($join) {
                $join->on('sv.id_supir_vendor', '=', 'av.id_supir_vendor_default')->whereNull('sv.dihapus_pada');
            })
            ->where('pu.id_proyek', $idProyek)
            ->where('pu.id_perusahaan', $idPerusahaan)
            ->whereNull('pu.dihapus_pada')
            ->where(fn (Builder $q) => $q->whereNotNull('a.id_armada')->orWhereNotNull('av.id_armada_vendor'))
            ->select(['pu.id_proyek_unit', 'pu.sumber', 'pu.id_armada', 'pu.id_armada_vendor', 'v.nama_vendor', 'sv.nama as nama_supir_vendor'])
            ->selectRaw('COALESCE(a.nopol, av.nopol) as nopol')
            ->selectRaw('COALESCE(a.merk, av.merk) as merk')
            ->selectRaw('COALESCE(jka.nama_jenis, jkv.nama_jenis, av.jenis) as nama_jenis')
            ->selectSub($this->pemegangInternal('a.id_armada'), 'nama_supir_internal')
            ->orderBy('pu.dibuat_pada')
            ->orderByRaw('COALESCE(a.nopol, av.nopol)')
            ->get()
            ->all();
    }

    public function kunciUnitProyek(string $idProyek): array
    {
        $kunci = [];
        foreach (ProyekUnitModel::active()->where('id_proyek', $idProyek)->get(['sumber', 'id_armada', 'id_armada_vendor']) as $unit) {
            $kunci[ProyekUnitModel::kunci($unit->sumber, $unit->sumber === 'vendor' ? $unit->id_armada_vendor : $unit->id_armada)] = true;
        }
        return $kunci;
    }

    public function armadaTersedia(string $idPerusahaan): array
    {
        return DB::table('armada as a')
            ->leftJoin('jenis_kendaraan as jk', 'jk.id_jenis_kendaraan', '=', 'a.id_jenis_kendaraan')
            ->where('a.id_perusahaan', $idPerusahaan)
            ->where('a.aktif', 1)
            ->where('a.status', '!=', 'tidak_aktif')
            ->whereNull('a.dihapus_pada')
            ->select(['a.id_armada', 'a.nopol', 'a.merk', 'jk.nama_jenis'])
            ->selectSub($this->pemegangInternal('a.id_armada'), 'nama_supir')
            ->orderBy('a.nopol')
            ->get()
            ->all();
    }

    public function namaSupirVendor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('supir_vendor')
            ->whereIn('id_supir_vendor', $ids)
            ->whereNull('dihapus_pada')
            ->pluck('nama', 'id_supir_vendor')
            ->all();
    }

    public function namaJenisArmadaVendor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('armada_vendor as av')
            ->leftJoin('jenis_kendaraan as jk', 'jk.id_jenis_kendaraan', '=', 'av.id_jenis_kendaraan')
            ->whereIn('av.id_armada_vendor', $ids)
            ->select('av.id_armada_vendor')
            ->selectRaw('COALESCE(jk.nama_jenis, av.jenis) as nama_jenis')
            ->get()
            ->pluck('nama_jenis', 'id_armada_vendor')
            ->all();
    }

    public function create(array $data): ProyekUnitModel
    {
        return ProyekUnitModel::create($data);
    }

    public function findMilikProyek(string $idProyek, string $id): ?ProyekUnitModel
    {
        return ProyekUnitModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_proyek_unit', $id)
            ->first();
    }

    public function findBanyakMilikProyek(string $idProyek, array $ids): array
    {
        return ProyekUnitModel::active()
            ->where('id_proyek', $idProyek)
            ->whereIn('id_proyek_unit', $ids)
            ->get()
            ->all();
    }

    private function pemegangInternal(string $kolomArmada): Builder
    {
        return DB::table('supir as s')
            ->whereColumn('s.id_armada_default', $kolomArmada)
            ->where('s.status', 'aktif')
            ->whereNull('s.dihapus_pada')
            ->orderBy('s.nama')
            ->limit(1)
            ->select('s.nama');
    }
}
