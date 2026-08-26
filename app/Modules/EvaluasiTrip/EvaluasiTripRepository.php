<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EvaluasiTripRepository implements EvaluasiTripRepositoryInterface
{
    public function findByPenugasan(string $idPenugasan): ?EvaluasiTripModel
    {
        return EvaluasiTripModel::active()->where('id_penugasan', $idPenugasan)->first();
    }

    public function existsByPenugasan(string $idPenugasan): bool
    {
        return EvaluasiTripModel::active()->where('id_penugasan', $idPenugasan)->exists();
    }

    public function findById(string $id): ?EvaluasiTripModel
    {
        return EvaluasiTripModel::active()->find($id);
    }

    public function create(array $data): EvaluasiTripModel
    {
        return EvaluasiTripModel::create($data);
    }

    public function update(EvaluasiTripModel $model, array $data): EvaluasiTripModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function rekapPerVendor(string $idPerusahaan): Collection
    {
        return DB::table('evaluasi_trip')
            ->join('penugasan', 'penugasan.id_penugasan', '=', 'evaluasi_trip.id_penugasan')
            ->join('kontrak_vendor', 'kontrak_vendor.id_kontrak_vendor', '=', 'penugasan.id_kontrak_vendor')
            ->join('vendor', 'vendor.id_vendor', '=', 'kontrak_vendor.id_vendor')
            ->whereNull('evaluasi_trip.dihapus_pada')
            ->whereNull('penugasan.dihapus_pada')
            ->whereNull('vendor.dihapus_pada')
            ->where('penugasan.sumber', 'vendor')
            ->where('vendor.id_perusahaan', $idPerusahaan)
            ->groupBy('vendor.id_vendor', 'vendor.nama_vendor')
            ->orderBy('vendor.nama_vendor')
            ->select([
                'vendor.id_vendor',
                'vendor.nama_vendor',
                DB::raw('COUNT(evaluasi_trip.id_evaluasi) as jumlah_evaluasi'),
                DB::raw('AVG(evaluasi_trip.nilai_ketepatan_waktu) as rata_ketepatan_waktu'),
                DB::raw('AVG(evaluasi_trip.nilai_kualitas) as rata_kualitas'),
                DB::raw('AVG(evaluasi_trip.nilai_harga) as rata_harga'),
                DB::raw('AVG(evaluasi_trip.nilai_responsif) as rata_responsif'),
            ])
            ->get();
    }

    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function listByVendor(string $idVendor): Collection
    {
        return DB::table('evaluasi_trip')
            ->join('penugasan', 'penugasan.id_penugasan', '=', 'evaluasi_trip.id_penugasan')
            ->join('kontrak_vendor', 'kontrak_vendor.id_kontrak_vendor', '=', 'penugasan.id_kontrak_vendor')
            ->leftJoin('proyek', function ($join) {
                $join->on('proyek.id_proyek', '=', 'penugasan.id_proyek')
                    ->whereNull('proyek.dihapus_pada');
            })
            ->whereNull('evaluasi_trip.dihapus_pada')
            ->whereNull('penugasan.dihapus_pada')
            ->where('penugasan.sumber', 'vendor')
            ->where('kontrak_vendor.id_vendor', $idVendor)
            ->orderByDesc('evaluasi_trip.dibuat_pada')
            ->select([
                'evaluasi_trip.id_evaluasi',
                'evaluasi_trip.id_penugasan',
                'penugasan.tanggal_tugas',
                'proyek.nama_proyek',
                'evaluasi_trip.nilai_ketepatan_waktu',
                'evaluasi_trip.nilai_kualitas',
                'evaluasi_trip.nilai_harga',
                'evaluasi_trip.nilai_responsif',
                'evaluasi_trip.nilai_armada',
                'evaluasi_trip.nilai_supir',
                'evaluasi_trip.catatan',
                'evaluasi_trip.dibuat_pada',
            ])
            ->get();
    }

    public function listPenugasanVendorSelesai(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return DB::table('penugasan as p')
            ->join('kontrak_vendor as kv', 'kv.id_kontrak_vendor', '=', 'p.id_kontrak_vendor')
            ->join('vendor as v', 'v.id_vendor', '=', 'kv.id_vendor')
            ->leftJoin('proyek as pr', function ($join) {
                $join->on('pr.id_proyek', '=', 'p.id_proyek')->whereNull('pr.dihapus_pada');
            })
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->leftJoin('supir_vendor as sv', 'sv.id_supir_vendor', '=', 'p.id_supir_vendor')
            ->leftJoin('supir as s', 's.id_supir', '=', 'p.id_supir')
            ->leftJoin('evaluasi_trip as e', function ($join) {
                $join->on('e.id_penugasan', '=', 'p.id_penugasan')->whereNull('e.dihapus_pada');
            })
            ->whereNull('p.dihapus_pada')
            ->whereNull('v.dihapus_pada')
            ->where('p.sumber', 'vendor')
            ->where('v.id_perusahaan', $idPerusahaan)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('trip as t')
                    ->join('jadwal_keberangkatan as jk', 'jk.id_jadwal', '=', 't.id_jadwal')
                    ->whereColumn('jk.id_penugasan', 'p.id_penugasan')
                    ->where('t.status', 'selesai')
                    ->whereNull('t.dihapus_pada')
                    ->whereNull('jk.dihapus_pada');
            })
            ->when($search, function ($q, $v) {
                $q->where(function ($q2) use ($v) {
                    $q2->where('v.nama_vendor', 'like', "%{$v}%")
                        ->orWhere('pr.nama_proyek', 'like', "%{$v}%")
                        ->orWhere('av.nopol', 'like', "%{$v}%");
                });
            })
            ->orderByDesc('p.tanggal_tugas')
            ->orderByDesc('p.dibuat_pada')
            ->select([
                'p.id_penugasan',
                'p.tanggal_tugas',
                'v.id_vendor',
                'v.nama_vendor',
                'pr.kode_proyek',
                'pr.nama_proyek',
                'av.nopol',
                DB::raw('COALESCE(sv.nama, s.nama) as nama_supir'),
                'e.id_evaluasi',
                'e.nilai_ketepatan_waktu',
                'e.nilai_kualitas',
                'e.nilai_harga',
                'e.nilai_responsif',
                'e.catatan',
            ])
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function penugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): bool
    {
        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->where('p.id_penugasan', $idPenugasan)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->exists();
    }
}
