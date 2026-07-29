<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip;

use App\Modules\EvaluasiTrip\Contracts\EvaluasiTripRepositoryInterface;
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
}
