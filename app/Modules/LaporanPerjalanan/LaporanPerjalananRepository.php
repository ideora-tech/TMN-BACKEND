<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Modules\LaporanPerjalanan\Contracts\LaporanPerjalananRepositoryInterface;
use Illuminate\Support\Facades\DB;

class LaporanPerjalananRepository implements LaporanPerjalananRepositoryInterface
{
    public function findByTrip(string $idTrip): ?LaporanPerjalananModel
    {
        return LaporanPerjalananModel::active()
            ->with(['biayaLain', 'foto'])
            ->where('id_trip', $idTrip)
            ->first();
    }

    public function findById(string $id): ?LaporanPerjalananModel
    {
        return LaporanPerjalananModel::active()
            ->with(['biayaLain', 'foto'])
            ->find($id);
    }

    public function findByIdMilik(string $id, string $idPerusahaan): ?LaporanPerjalananModel
    {
        return LaporanPerjalananModel::active()
            ->with(['biayaLain', 'foto'])
            ->where('id_perusahaan', $idPerusahaan)
            ->find($id);
    }

    public function create(array $data): LaporanPerjalananModel
    {
        return LaporanPerjalananModel::create($data);
    }

    public function update(LaporanPerjalananModel $model, array $data): LaporanPerjalananModel
    {
        $model->update($data);
        return $model;
    }

    public function reload(LaporanPerjalananModel $model): LaporanPerjalananModel
    {
        return $model->fresh(['biayaLain', 'foto']);
    }

    public function syncBiayaLain(LaporanPerjalananModel $laporan, array $biayaLain): void
    {
        BiayaLainTripModel::active()
            ->where('id_laporan', $laporan->id_laporan)
            ->each(fn (BiayaLainTripModel $item) => $item->softDelete());

        foreach ($biayaLain as $item) {
            BiayaLainTripModel::create([
                'id_laporan' => $laporan->id_laporan,
                'nama_biaya' => $item['nama_biaya'],
                'nominal'    => $item['nominal'],
            ]);
        }
    }

    public function addFoto(string $idLaporan, array $data): FotoLaporanPerjalananModel
    {
        return FotoLaporanPerjalananModel::create(array_merge($data, ['id_laporan' => $idLaporan]));
    }

    public function findFotoById(string $idLaporan, string $idFoto): ?FotoLaporanPerjalananModel
    {
        return FotoLaporanPerjalananModel::active()
            ->where('id_laporan', $idLaporan)
            ->where('id_foto', $idFoto)
            ->first();
    }

    public function deleteFoto(FotoLaporanPerjalananModel $foto): void
    {
        $foto->softDelete();
    }

    public function tripMilikPerusahaan(string $idTrip, string $idPerusahaan): bool
    {
        return DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('trip.id_trip', $idTrip)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('trip.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->exists();
    }
}
